<?php

declare(strict_types=1);

namespace Drupal\Tests\eca\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\eca\Plugin\Token\NodeEntityTypeToken;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the [node:entity_type] migration to an attributed resolver works.
 *
 * Four independent proofs combine into a deductive guarantee:
 *  1. The resolver computes the correct value in isolation.
 *  2. The engine routes the (node, entity_type) token to that resolver class.
 *  3. The token resolves end to end through the public Token::replace() API.
 *  4. The migrated output is identical to the retained legacy hook output.
 *
 * Proofs 1 and 2 together are deductive: the engine calls this resolver class
 * for the token, and this resolver class returns the entity type id, so the
 * token resolves through the new pipeline. Proof 4 shows the migration did not
 * change behaviour.
 *
 * @group eca
 */
#[Group('eca')]
#[Group('eca_core')]
#[RunTestsInSeparateProcesses]
class NodeEntityTypeTokenMigrationProofTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'eca',
    'modeler_api',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(static::$modules);
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
    // The engine enforces root entity view access against the viewer; allow
    // anonymous (the default viewer here) to view published nodes.
    Role::load(RoleInterface::ANONYMOUS_ID)->grantPermission('access content')->save();
  }

  /**
   * Creates a saved article node.
   */
  private function createNode(): Node {
    $node = Node::create(['type' => 'article', 'title' => 'Proof node', 'uid' => 0, 'status' => 1]);
    $node->save();
    return $node;
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
   * Proof 1: the resolver returns the entity type id in isolation.
   */
  public function testResolverProducesValueInIsolation(): void {
    // The resolver is an attributed Plugin\Token plugin, created lazily by the
    // token resolver plugin manager.
    $resolver = $this->container->get('plugin.manager.token_resolver')->createInstance('node:entity_type');
    $this->assertInstanceOf(NodeEntityTypeToken::class, $resolver);

    $result = $resolver->resolve($this->createNode(), ['name' => 'entity_type'], $this->context());
    $this->assertSame('node', $result->value, 'The resolver returns the entity type id.');

    // A non-entity input degrades gracefully to an empty string.
    $this->assertSame('', $resolver->resolve('not-an-entity', ['name' => 'entity_type'], $this->context())->value);
  }

  /**
   * Proof 2: the engine routes (node, entity_type) to the migrated resolver.
   */
  public function testEngineRoutesTokenToResolver(): void {
    /** @var \Drupal\Core\Token\TokenRegistryInterface $registry */
    $registry = $this->container->get('token.registry');
    $definition = $registry->getResolvableToken('node', 'entity_type');

    $this->assertNotNull($definition, 'The token is registered as a resolvable new-system token.');
    $this->assertSame(NodeEntityTypeToken::class, $definition->resolverClass, 'The engine routes the token to NodeEntityTypeToken.');
  }

  /**
   * Proof 3: the token resolves end to end through Token::replace().
   */
  public function testTokenResolvesEndToEnd(): void {
    $result = \Drupal::token()->replace('[node:entity_type]', ['node' => $this->createNode()]);
    $this->assertSame('node', $result, 'The token resolves end to end via the public API.');
  }

  /**
   * Proof 4: the migrated output matches the retained legacy implementation.
   */
  public function testMigratedOutputMatchesLegacyImplementation(): void {
    $data = ['node' => $this->createNode()];
    $tokens = ['entity_type' => '[node:entity_type]'];

    $engine = \Drupal::token()->generate('node', $tokens, $data, [], new BubbleableMetadata());

    /** @var \Drupal\Core\Token\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token.legacy_bridge');
    $legacy = $bridge->generate('node', $tokens, $data, [], new BubbleableMetadata());

    $this->assertSame(
      (string) ($legacy['[node:entity_type]'] ?? ''),
      (string) ($engine['[node:entity_type]'] ?? ''),
      'The migrated resolver output is identical to the legacy hook output.',
    );
    $this->assertSame('node', (string) $engine['[node:entity_type]']);
  }

}
