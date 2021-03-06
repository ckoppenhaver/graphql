<?php

namespace Drupal\graphql\GraphQL\Execution;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\graphql\Plugin\SchemaPluginManager;
use Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface;
use GraphQL\Error\Error;
use GraphQL\Error\FormattedError;
use GraphQL\Executor\ExecutionResult;
use GraphQL\Executor\Executor;
use GraphQL\Executor\Promise\Adapter\SyncPromiseAdapter;
use GraphQL\Executor\Promise\PromiseAdapter;
use GraphQL\Language\AST\DocumentNode;
use GraphQL\Language\Parser;
use GraphQL\Language\Visitor;
use GraphQL\Server\Helper;
use GraphQL\Server\OperationParams;
use GraphQL\Server\RequestError;
use GraphQL\Server\ServerConfig;
use GraphQL\Utils\AST;
use GraphQL\Utils\TypeInfo;
use GraphQL\Utils\Utils;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\AbstractValidationRule;
use GraphQL\Validator\ValidationContext;

// TODO: Refactor this and clean it up.
class QueryProcessor {

  /**
   * The current user account.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The schema plugin manager.
   *
   * @var \Drupal\graphql\Plugin\SchemaPluginManager
   */
  protected $pluginManager;

  /**
   * The query provider service.
   *
   * @var \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface
   */
  protected $queryProvider;

  /**
   * The cache backend for caching query results.
   *
   * @var \Drupal\Core\Cache\CacheBackendInterface
   */
  protected $cacheBackend;

  /**
   * The cache contexts manager service.
   *
   * @var \Drupal\Core\Cache\Context\CacheContextsManager
   */
  protected $contextsManager;

  /**
   * The configuration service parameter.
   *
   * @var array
   */
  protected $config;

  /**
   * Processor constructor.
   *
   * @param \Drupal\Core\Session\AccountProxyInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Cache\Context\CacheContextsManager $contextsManager
   *   The cache contexts manager service.
   * @param \Drupal\graphql\Plugin\SchemaPluginManager $pluginManager
   *   The schema plugin manager.
   * @param \Drupal\graphql\GraphQL\QueryProvider\QueryProviderInterface $queryProvider
   *   The query provider service.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cacheBackend
   *   The cache backend for caching query results.
   * @param array $config
   *   The configuration service parameter.
   */
  public function __construct(
    AccountProxyInterface $currentUser,
    CacheContextsManager $contextsManager,
    SchemaPluginManager $pluginManager,
    QueryProviderInterface $queryProvider,
    CacheBackendInterface $cacheBackend,
    array $config
  ) {
    $this->currentUser = $currentUser;
    $this->contextsManager = $contextsManager;
    $this->pluginManager = $pluginManager;
    $this->queryProvider = $queryProvider;
    $this->cacheBackend = $cacheBackend;
    $this->config = $config;
  }

  /**
   * Processes one or multiple graphql operations.
   *
   * @param string $schema
   *   The plugin id of the schema to use.
   * @param \GraphQL\Server\OperationParams|\GraphQL\Server\OperationParams[] $params
   *   The graphql operation(s) to execute.
   * @param array $globals
   *   The query context.
   *
   * @return \Drupal\graphql\GraphQL\Execution\QueryResult|\Drupal\graphql\GraphQL\Execution\QueryResult[]
   *   The query result.
   *
   */
  public function processQuery($schema, $params, array $globals = []) {
    // Load the plugin from the schema manager.
    $plugin = $this->pluginManager->createInstance($schema);
    $schema = $plugin->getSchema();

    // If the current user has appropriate permissions, allow to bypass
    // the secure fields restriction.
    $globals['bypass field security'] = $this->currentUser->hasPermission('bypass graphql field security');

    // Create the server config.
    $config = ServerConfig::create();
    $config->setDebug(!empty($this->config['development']));
    $config->setSchema($schema);
    $config->setQueryBatching(TRUE);
    $config->setContext(function () use ($globals, $plugin) {
      // Each document (e.g. in a batch query) gets its own resolve context but
      // the global parameters are shared. This allows us to collect the cache
      // metadata and contextual values (e.g. inheritance for language) for each
      // query separately.
      $context = new ResolveContext($globals);
      if ($plugin instanceof CacheableDependencyInterface) {
        $context->addCacheableDependency($plugin)->addCacheTags(['graphql_response']);
      }

      return $context;
    });

    $config->setValidationRules(function (OperationParams $params, DocumentNode $document, $operation) {
      if (isset($params->queryId)) {
        // Assume that pre-parsed documents are already validated. This allows
        // us to store pre-validated query documents e.g. for persisted queries
        // effectively improving performance by skipping run-time validation.
        return [];
      }

      return array_values(DocumentValidator::defaultRules());
    });

    $config->setPersistentQueryLoader(function ($id, OperationParams $params) {
      if ($query = $this->queryProvider->getQuery($id, $params)) {
        return $query;
      }

      throw new RequestError(sprintf("Failed to load query map for id '%s'.", $id));
    });

    if (is_array($params)) {
      return $this->executeBatch($config, $params);
    }

    return $this->executeSingle($config, $params);
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   *
   * @return mixed
   */
  public function executeSingle(ServerConfig $config, OperationParams $params) {
    $adapter = new SyncPromiseAdapter();
    $result = $this->executeOperationWithReporting($adapter, $config, $params, FALSE);
    return $adapter->wait($result);
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param array $params
   *
   * @return mixed
   */
  public function executeBatch(ServerConfig $config, array $params) {
    $adapter = new SyncPromiseAdapter();
    $result = array_map(function ($params) use ($adapter, $config) {
      return $this->executeOperationWithReporting($adapter, $config, $params, TRUE);
    }, $params);

    $result = $adapter->all($result);
    return $adapter->wait($result);
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param bool $batching
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function executeOperationWithReporting(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, $batching = FALSE) {
    $result = $this->executeOperation($adapter, $config, $params, $batching);

    // Format and print errors.
    return $result->then(function(QueryResult $result) use ($config) {
      if ($config->getErrorsHandler()) {
        $result->setErrorsHandler($config->getErrorsHandler());
      }

      if ($config->getErrorFormatter() || $config->getDebug()) {
        $result->setErrorFormatter(FormattedError::prepareFormatter($config->getErrorFormatter(), $config->getDebug()));
      }

      return $result;
    });
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param bool $batching
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function executeOperation(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, $batching = FALSE) {
    try {
      if (!$config->getSchema()) {
        throw new \LogicException('Missing schema for query execution.');
      }

      if ($batching && !$config->getQueryBatching()) {
        throw new RequestError('Batched queries are not supported by this server.');
      }

      if ($errors = $this->validateOperationParams($params)) {
        return $adapter->createFulfilled(new QueryResult(NULL, $errors));
      }

      $document = $params->queryId ? $this->loadPersistedQuery($config, $params) : $params->query;
      if (!$document instanceof DocumentNode) {
        $document = Parser::parse($document);
      }

      // Read the operation type from the document. Subscriptions and mutations
      // only work through POST requests. One cannot have mutations and queries
      // in the same document, hence this check is sufficient.
      $operation = $params->operation;
      $type = AST::getOperation($document, $operation);
      if ($params->isReadOnly() && $type !== 'query') {
        throw new RequestError('GET requests are only supported for query operations.');
      }

      // If one of the validation rules found any problems, do not resolve the
      // query and bail out early instead.
      if ($errors = $this->validateOperation($config, $params, $document)) {
        return $adapter->createFulfilled(new QueryResult(NULL, $errors));
      }

      // Only queries can be cached (mutations and subscriptions can't).
      if ($type === 'query') {
        return $this->executeCacheableOperation($adapter, $config, $params, $document);
      }

      return $this->executeUncachableOperation($adapter, $config, $params, $document);
    }
    catch (RequestError $exception) {
      return $adapter->createFulfilled(new QueryResult(NULL, [Error::createLocatedError($exception)]));
    }
    catch (Error $exception) {
      return $adapter->createFulfilled(new QueryResult(NULL, [$exception]));
    }
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   *
   * @return \GraphQL\Executor\Promise\Promise|mixed
   */
  protected function executeCacheableOperation(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, DocumentNode $document) {
    $contextCacheId = 'ccid:' . $this->cacheIdentifier($params, $document, new CacheableMetadata());

    if (!$config->getDebug() && ($contextCache = $this->cacheBackend->get($contextCacheId)) && $contexts = $contextCache->data) {
      $cacheId = 'cid:' . $this->cacheIdentifier($params, $document, (new CacheableMetadata())->addCacheContexts($contexts));
      if (($cache = $this->cacheBackend->get($cacheId)) && $result = $cache->data) {
        return $adapter->createFulfilled($result);
      }
    }

    $result = $this->doExecuteOperation($adapter, $config, $params, $document);

    return $result->then(function (QueryResult $result) use ($contextCacheId, $params, $document) {
      // Write this query into the cache if it is cacheable.
      if ($result->getCacheMaxAge() !== 0) {
        $cacheId = 'cid:' . $this->cacheIdentifier($params, $document, (new CacheableMetadata())->addCacheContexts($result->getCacheContexts()));
        $this->cacheBackend->set($contextCacheId, $result->getCacheContexts(), $result->getCacheMaxAge(), $result->getCacheTags());
        $this->cacheBackend->set($cacheId, $result, $result->getCacheMaxAge(), $result->getCacheTags());
      }
      return $result;
    });
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function executeUncachableOperation(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, DocumentNode $document) {
    $result = $this->doExecuteOperation($adapter, $config, $params, $document);
    return $result->then(function (QueryResult $result) {
      // Mark the query result as uncacheable.
      $result->mergeCacheMaxAge(0);
      return $result;
    });
  }

  /**
   * @param \GraphQL\Executor\Promise\PromiseAdapter $adapter
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   *
   * @return \GraphQL\Executor\Promise\Promise
   */
  protected function doExecuteOperation(PromiseAdapter $adapter, ServerConfig $config, OperationParams $params, DocumentNode $document) {
    $operation = $params->operation;
    $variables = $params->variables;
    $context = $this->resolveContextValue($config, $params, $document, $operation);
    $root = $this->resolveRootValue($config, $params, $document, $operation);
    $resolver = $config->getFieldResolver();
    $schema = $config->getSchema();

    $promise = Executor::promiseToExecute(
      $adapter,
      $schema,
      $document,
      $root,
      $context,
      $variables,
      $operation,
      $resolver
    );

    return $promise->then(function (ExecutionResult $result) use ($context) {

      $metadata = (new CacheableMetadata())
        ->addCacheContexts($this->filterCacheContexts($context->getCacheContexts()))
        ->addCacheTags($context->getCacheTags())
        ->setCacheMaxAge($context->getCacheMaxAge());

      // Do not cache in development mode or if there are any errors.
      if ($context->getGlobal('development') || !empty($result->errors)) {
        $metadata->setCacheMaxAge(0);
      }

      return new QueryResult($result->data, $result->errors, $result->extensions, $metadata);
    });
  }

  /**
   * @param \GraphQL\Server\OperationParams $params
   *
   * @return array
   */
  protected function validateOperationParams(OperationParams $params) {
    $errors = (new Helper())->validateOperationParams($params);
    return array_map(function (RequestError $error) {
      return Error::createLocatedError($error, NULL, NULL);
    }, $errors);
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   *
   * @return \GraphQL\Error\Error[]
   */
  protected function validateOperation(ServerConfig $config, OperationParams $params, DocumentNode $document) {
    $operation = $params->operation;
    // Skip validation if there are no validation rules to be applied.
    if (!$rules = $this->resolveValidationRules($config, $params, $document, $operation)) {
      return [];
    }

    $schema = $config->getSchema();
    $info = new TypeInfo($schema);
    $validation = new ValidationContext($schema, $document, $info);
    $visitors = array_values(array_map(function (AbstractValidationRule $rule) use ($validation) {
      return $rule($validation);
    }, $rules));

    // Run the query visitor with the prepared validation rules and the cache
    // metadata collector and query complexity calculator.
    Visitor::visit($document, Visitor::visitWithTypeInfo($info, Visitor::visitInParallel($visitors)));

    // Return any possible errors collected during validation.
    return $validation->getErrors();
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return mixed
   */
  protected function resolveRootValue(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    $root = $config->getRootValue();
    if (is_callable($root)) {
      $root = $root($params, $document, $operation);
    }

    return $root;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return mixed
   */
  protected function resolveContextValue(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    $context = $config->getContext();
    if (is_callable($context)) {
      $context = $context($params, $document, $operation);
    }

    return $context;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param $operation
   *
   * @return array
   */
  protected function resolveValidationRules(ServerConfig $config, OperationParams $params, DocumentNode $document, $operation) {
    // Allow customizing validation rules per operation:
    $rules = $config->getValidationRules();
    if (is_callable($rules)) {
      $rules = $rules($params, $document, $operation);
      if (!is_array($rules)) {
        throw new \LogicException(sprintf("Expecting validation rules to be array or callable returning array, but got: %s", Utils::printSafe($rules)));
      }
    }

    return $rules;
  }

  /**
   * @param \GraphQL\Server\ServerConfig $config
   * @param \GraphQL\Server\OperationParams $params
   *
   * @return mixed
   * @throws \GraphQL\Server\RequestError
   */
  protected function loadPersistedQuery(ServerConfig $config, OperationParams $params) {
    if (!$loader = $config->getPersistentQueryLoader()) {
      throw new RequestError('Persisted queries are not supported by this server.');
    }

    $source = $loader($params->queryId, $params);
    if (!is_string($source) && !$source instanceof DocumentNode) {
      throw new \LogicException(sprintf('The persisted query loader must return query string or instance of %s but got: %s.', DocumentNode::class, Utils::printSafe($source)));
    }

    return $source;
  }

  /**
   * @param \GraphQL\Language\AST\DocumentNode $document
   *
   * @return array
   */
  protected function serializeDocument(DocumentNode $document) {
    return $this->sanitizeRecursive(AST::toArray($document));
  }

  /**
   * @param array $item
   *
   * @return array
   */
  protected function sanitizeRecursive(array $item) {
    unset($item['loc']);

    foreach ($item as &$value) {
      if (is_array($value)) {
        $value = $this->sanitizeRecursive($value);
      }
    }

    return $item;
  }

  /**
   * @param \GraphQL\Server\OperationParams $params
   * @param \GraphQL\Language\AST\DocumentNode $document
   * @param \Drupal\Core\Cache\CacheableMetadata $metadata
   *
   * @return string
   */
  protected function cacheIdentifier(OperationParams $params, DocumentNode $document, CacheableMetadata $metadata) {
    // Ignore language contexts since they are handled by graphql internally.
    $contexts = $this->filterCacheContexts($metadata->getCacheContexts());
    $keys = $this->contextsManager->convertTokensToKeys($contexts)->getKeys();

    // Sorting the variables will cause fewer cache vectors.
    $variables = $params->variables ?: [];
    ksort($variables);

    // Prepend the hash of the serialized document to the cache contexts.
    $hash = hash('sha256', json_encode([
      'query' => $this->serializeDocument($document),
      'variables' => $variables,
    ]));

    return implode(':', array_values(array_merge([$hash], $keys)));
  }

  /**
   * Filter unused contexts.
   *
   * Removes the language contexts from a list of context ids.
   *
   * @param string[] $contexts
   *   The list of context id's.
   *
   * @return string[]
   *   The filtered list of context id's.
   */
  protected function filterCacheContexts(array $contexts) {
    return array_filter($contexts, function ($context) {
      return strpos($context, 'languages:') !== 0;
    });
  }
}
