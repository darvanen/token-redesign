<?php

declare(strict_types=1);

namespace Drupal\Tests\pathauto\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Plugin\Token\ArrayJoinPathToken;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the [array:join-path] migration to an attributed resolver works.
 *
 * Four independent proofs combine into a deductive guarantee:
 *  1. The resolver computes the correct value in isolation.
 *  2. The engine routes the (array, join-path) token to that resolver class.
 *  3. The token resolves end to end through the public Token::replace() API.
 *  4. The migrated output is identical to the retained legacy hook output.
 *
 * Proofs 1 and 2 together are deductive: the engine calls this resolver class
 * for the token, and this resolver class returns the joined cleaned path, so
 * the token resolves through the new pipeline. Proof 4 shows the migration did
 * not change behaviour.
 *
 * @group pathauto
 */
#[Group('pathauto')]
#[RunTestsInSeparateProcesses]
class ArrayJoinPathTokenMigrationProofTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'filter',
    'node',
    'path',
    'path_alias',
    'pathauto',
    'system',
    'text',
    'token',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['pathauto', 'system', 'node']);
    $this->installSchema('node', ['node_access']);
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Builds a resolution context with the current user as a single actor.
   */
  private function context(): TokenResolutionContext {
    return new TokenResolutionContext(
      [],
      ActorContext::fromSingleActor($this->container->get('current_user')),
      OutputContext::Html,
    );
  }

  /**
   * Proof 1: the resolver computes the joined, cleaned path in isolation.
   */
  public function testResolverProducesValueInIsolation(): void {
    // The resolver is an attributed Plugin\Token plugin, created lazily by the
    // token resolver plugin manager (with dependency injection via create()).
    $resolver = $this->container->get('plugin.manager.token_resolver')->createInstance('array:join-path');
    $this->assertInstanceOf(ArrayJoinPathToken::class, $resolver);

    $result = $resolver->resolve(['First Item', 'Second Item'], ['name' => 'join-path'], $this->context());
    $this->assertSame('first-item/second-item', $result->value, 'The resolver joins and cleans the array values.');

    // A non-array input degrades gracefully to an empty string.
    $this->assertSame('', $resolver->resolve('not-an-array', ['name' => 'join-path'], $this->context())->value);
  }

  /**
   * Proof 2: the engine routes (array, join-path) to the migrated resolver.
   */
  public function testEngineRoutesTokenToResolver(): void {
    /** @var \Drupal\Core\Token\TokenRegistryInterface $registry */
    $registry = $this->container->get('token.registry');
    $definition = $registry->getResolvableToken('array', 'join-path');

    $this->assertNotNull($definition, 'The token is registered as a resolvable new-system token.');
    $this->assertSame(ArrayJoinPathToken::class, $definition->resolverClass, 'The engine routes the token to ArrayJoinPathToken.');
  }

  /**
   * Proof 3: the token resolves end to end through Token::replace().
   */
  public function testTokenResolvesEndToEnd(): void {
    $result = \Drupal::token()->replace('[array:join-path]', ['array' => ['First Item', 'Second Item']]);
    $this->assertSame('first-item/second-item', $result, 'The token resolves end to end via the public API.');
  }

  /**
   * Proof 4: the migrated output matches the retained legacy implementation.
   */
  public function testMigratedOutputMatchesLegacyImplementation(): void {
    $data = ['array' => ['Alpha Value', 'Beta Value']];
    $tokens = ['join-path' => '[array:join-path]'];

    $engine = \Drupal::token()->generate('array', $tokens, $data, [], new BubbleableMetadata());

    /** @var \Drupal\Core\Token\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token.legacy_bridge');
    $legacy = $bridge->generate('array', $tokens, $data, [], new BubbleableMetadata());

    $this->assertSame(
      array_map('strval', $legacy),
      array_map('strval', $engine),
      'The migrated resolver output is identical to the legacy hook output.',
    );
  }

}
