<?php

declare(strict_types=1);

namespace Drupal\Core\Token\Discovery;

use Drupal\Core\Token\TokenDefinition;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResolverManager;

/**
 * Discovers attributed token resolvers and locates resolver instances.
 *
 * Definitions come from the token resolver plugin manager, which reads the
 * `#[Token]` attribute statically and caches the result. No resolver is
 * instantiated to learn its contract, and an instance is created lazily only
 * when its token is actually resolved. This is the discovery source for the
 * declared (attributed) tokens, and it doubles as the resolver locator the
 * engine uses to obtain a live resolver by class name during traversal.
 *
 * Two kinds of resolver class are located:
 *  - Attributed resolver plugins (one class, one (input_type, name)): created
 *    lazily via the plugin manager, with dependency injection through
 *    ContainerFactoryPluginInterface.
 *  - Generic shared resolvers referenced by class from other discovery sources
 *    (e.g. the field resolvers used by typed-data discovery, or ImageStyleToken
 *    used by an alter subscriber). These are a small, fixed, core-owned set
 *    injected via the `token.generic_resolver` tag.
 *
 * @internal
 *   Use TokenDiscoveryInterface; do not depend on this implementation directly.
 */
final class AttributedTokenDiscovery implements TokenDiscoveryInterface {

  /**
   * Generic shared resolver instances, keyed by class name.
   *
   * @var array<class-string, \Drupal\Core\Token\TokenResolverInterface>
   */
  private readonly array $genericResolvers;

  /**
   * Memoised resolver instances, keyed by class name.
   *
   * @var array<class-string, \Drupal\Core\Token\TokenResolverInterface>
   */
  private array $instances = [];

  /**
   * Map of resolver class name to plugin ID, lazily built from definitions.
   *
   * @var array<class-string, string>|null
   */
  private ?array $classToPluginId = NULL;

  /**
   * @param \Drupal\Core\Token\TokenResolverManager $manager
   *   The token resolver plugin manager.
   * @param iterable<object> $genericResolvers
   *   Generic shared resolver instances collected via the
   *   `token.generic_resolver` tag.
   */
  public function __construct(
    private readonly TokenResolverManager $manager,
    iterable $genericResolvers,
  ) {
    $map = [];
    foreach ($genericResolvers as $resolver) {
      if ($resolver instanceof TokenResolverInterface) {
        $map[get_class($resolver)] = $resolver;
      }
    }
    $this->genericResolvers = $map;
  }

  /**
   * {@inheritdoc}
   */
  public function discoverForInputType(string $inputType): array {
    $definitions = [];
    foreach ($this->manager->getDefinitions() as $definition) {
      if (($definition['input_type'] ?? NULL) !== $inputType) {
        continue;
      }
      $definitions[$definition['name']] = new TokenDefinition(
        name: $definition['name'],
        inputType: $definition['input_type'],
        outputType: $definition['output_type'],
        label: $definition['label'] ?? NULL,
        description: $definition['description'] ?? NULL,
        resolverClass: $definition['class'],
        path: $definition['path'] ?? NULL,
        argumentName: $definition['argument_name'] ?? NULL,
        placePermission: $definition['place_permission'] ?? NULL,
      );
    }
    return $definitions;
  }

  /**
   * Returns a live resolver instance for the given class name, or NULL.
   *
   * Attributed plugin classes are created lazily through the plugin manager;
   * generic shared resolver classes are returned from the injected set.
   *
   * @param string $class
   *   The FQN of the resolver class.
   *
   * @return \Drupal\Core\Token\TokenResolverInterface|null
   *   The resolver instance, or NULL when the class is unknown.
   */
  public function getResolver(string $class): ?TokenResolverInterface {
    if (isset($this->instances[$class])) {
      return $this->instances[$class];
    }

    $pluginId = $this->classToPluginId()[$class] ?? NULL;
    if ($pluginId !== NULL) {
      $instance = $this->manager->createInstance($pluginId);
      assert($instance instanceof TokenResolverInterface);
      return $this->instances[$class] = $instance;
    }

    if (isset($this->genericResolvers[$class])) {
      return $this->instances[$class] = $this->genericResolvers[$class];
    }

    return NULL;
  }

  /**
   * Builds and caches the class-name to plugin-ID map from the definitions.
   *
   * Reads the cached plugin definitions only; it does not instantiate any
   * resolver.
   *
   * @return array<class-string, string>
   *   Plugin IDs keyed by resolver class name.
   */
  private function classToPluginId(): array {
    if ($this->classToPluginId === NULL) {
      $map = [];
      foreach ($this->manager->getDefinitions() as $id => $definition) {
        if (isset($definition['class'])) {
          $map[$definition['class']] = $id;
        }
      }
      $this->classToPluginId = $map;
    }
    return $this->classToPluginId;
  }

}
