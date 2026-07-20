<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\ActorContext;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\token_context_test\Plugin\Token\LazyProbeResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves attributed resolvers are discovered statically and built lazily.
 *
 * Token definitions come from a cached scan of the `#[Token]` attributes, so
 * learning a resolver's contract never instantiates it; an instance is created
 * only when one of its tokens is actually resolved. The probe resolver records
 * each instantiation in state, making both halves of the guarantee falsifiable.
 *
 * @see \Drupal\token_engine\TokenResolverManager
 * @see \Drupal\token_engine\Discovery\AttributedTokenDiscovery
 */
#[CoversClass(\Drupal\token_engine\Discovery\AttributedTokenDiscovery::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenResolverLazyInstantiationTest extends KernelTestBase {

  private const COUNT_KEY = 'token_context_test.lazy_probe_instantiations';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_engine', 'system', 'token_context_test'];

  /**
   * Tests discovery never instantiates, and resolution instantiates once.
   */
  public function testAttributedResolverIsDiscoveredStaticallyAndBuiltLazily(): void {
    $state = $this->container->get('state');
    $registry = $this->container->get('token_engine.registry');
    $engine = $this->container->get('token_engine.resolution_engine');

    // Discovering the definition reads the cached attribute scan; it must not
    // instantiate the resolver.
    $definition = $registry->getResolvableToken('lazy_probe', 'value');
    $this->assertNotNull($definition, 'The probe token is discovered.');
    $this->assertSame(LazyProbeResolver::class, $definition->resolverClass);
    $this->assertSame(
      0,
      (int) $state->get(self::COUNT_KEY, 0),
      'Discovery did not instantiate the resolver.',
    );

    // Resolving the token instantiates the resolver lazily, exactly once.
    $context = new TokenResolutionContext(
      [],
      ActorContext::fromSingleActor($this->container->get('current_user')),
      OutputContext::Html,
    );
    $result = $engine->resolveChain('lazy_probe', ['value'], NULL, $context);

    $this->assertSame('probed', $result->value);
    $this->assertSame(
      1,
      (int) $state->get(self::COUNT_KEY, 0),
      'The resolver was instantiated exactly once, on resolution.',
    );
  }

}
