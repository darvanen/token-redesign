<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Token\Attribute\Token;

/**
 * Plugin manager for attributed token resolvers.
 *
 * Token resolvers are discovered statically from the `#[Token]` attribute on
 * classes in each module's `Plugin\Token` namespace. Definitions are built from
 * the attributes alone (no instantiation) and cached, and a resolver instance
 * is created lazily only when one of its tokens is actually resolved. Resolvers
 * that need services implement
 * \Drupal\Core\Plugin\ContainerFactoryPluginInterface.
 *
 * The plugin ID is the token identity, "{input_type}:{name}".
 *
 * Modules declare resolver plugins via the #[Token] attribute; they do not call
 * this manager directly. It is internal plumbing of the token subsystem.
 *
 * @see \Drupal\Core\Token\Attribute\Token
 * @see \Drupal\Core\Token\TokenResolverInterface
 *
 * @internal
 */
final class TokenResolverManager extends DefaultPluginManager {

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Token',
      $namespaces,
      $module_handler,
      TokenResolverInterface::class,
      Token::class,
    );
    $this->setCacheBackend($cache_backend, 'token_resolver_plugins');
  }

}
