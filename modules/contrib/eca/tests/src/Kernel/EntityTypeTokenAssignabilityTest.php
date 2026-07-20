<?php

declare(strict_types=1);

namespace Drupal\Tests\eca\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\eca\Plugin\Token\EntityTypeToken;
use Drupal\eca\Plugin\Token\NodeEntityTypeToken;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the generic (entity, entity_type) resolver's assignability reach.
 *
 * EntityTypeToken is declared at the bare 'entity' input type, so
 * TokenRegistry::getResolvableToken()'s ancestor walk reaches it mid-chain
 * from any concrete entity type that has no more specific definition of its
 * own, for example entity:user via [node:uid:entity:entity_type]. It cannot
 * claim the root position for entity types other than node: a single-segment
 * token like [user:entity_type] never tries the entity:<type> branch (that
 * guard stops an auto-discovered field token from shadowing a curated legacy
 * token at the root), and a colon-free type such as 'user' has nothing for
 * the ancestor walk to strip, so it cannot bridge to 'entity' either. This is
 * a routing-model limitation of the resolution engine, not something this
 * test or resolver works around.
 *
 * Three proofs:
 *  (a) [node:uid:entity:entity_type] resolves to 'user' through the engine,
 *      and the registry lookup for ('entity:user', 'entity_type') returns a
 *      definition backed by EntityTypeToken, proving the ancestor-walk
 *      provenance (no definition exists at the concrete 'entity:user' slice).
 *  (b) [node:entity_type] output is unchanged, and the registry lookup for
 *      ('node', 'entity_type') still returns NodeEntityTypeToken, proving the
 *      root-position provenance for node is untouched by this addition.
 *  (c) [user:entity_type] equals LegacyTokenBridge::generate() output
 *      byte for byte: legacy parity, the engine does not claim root position
 *      on user.
 *
 * @see \Drupal\eca\Plugin\Token\EntityTypeToken
 * @see \Drupal\eca\Plugin\Token\NodeEntityTypeToken
 * @see \Drupal\eca\tests\src\Kernel\NodeEntityTypeTokenMigrationProofTest
 *
 * @group eca
 */
#[Group('eca')]
#[Group('eca_core')]
#[RunTestsInSeparateProcesses]
class EntityTypeTokenAssignabilityTest extends KernelTestBase {

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
    'token_engine',
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
   * Creates a saved user.
   */
  private function createSavedUser(string $name): User {
    $user = User::create(['name' => $name, 'status' => 1]);
    $user->save();
    return $user;
  }

  /**
   * Creates a saved article node authored by the given user.
   */
  private function createNode(User $author): Node {
    $node = Node::create(['type' => 'article', 'title' => 'Assignability node', 'uid' => $author->id(), 'status' => 1]);
    $node->save();
    return $node;
  }

  /**
   * Proof (a): the entity-level resolver is reached mid-chain at entity:user.
   *
   * [node:uid:entity:entity_type] dereferences the node's author (via the
   * 'uid' entity reference field) into an entity:user chain step. No
   * (entity:user, entity_type) definition is declared anywhere, so the value
   * can only come from EntityTypeToken's declaration at the ancestor type
   * 'entity', reached through TokenRegistry::getResolvableToken()'s ancestor
   * walk. The registry assertion is the provenance proof: it shows the
   * definition actually served for ('entity:user', 'entity_type') is backed
   * by EntityTypeToken, not merely that the output string happens to match.
   */
  public function testGenericResolverReachesEntityUserAncestor(): void {
    $author = $this->createSavedUser('assignability_author');
    $node = $this->createNode($author);

    $result = \Drupal::token()->replace('[node:uid:entity:entity_type]', ['node' => $node]);
    $this->assertSame('user', $result, 'The generic entity_type resolver resolves at entity:user through the ancestor walk.');

    /** @var \Drupal\token_engine\TokenRegistryInterface $registry */
    $registry = $this->container->get('token_engine.registry');
    $definition = $registry->getResolvableToken('entity:user', 'entity_type');

    $this->assertNotNull($definition, 'The (entity:user, entity_type) lookup resolves via the ancestor walk.');
    $this->assertSame(EntityTypeToken::class, $definition->resolverClass, 'The ancestor-matched definition is backed by EntityTypeToken, proving no concrete entity:user definition exists.');
  }

  /**
   * Proof (b): the node-scoped resolver still owns the root position on node.
   *
   * Adding the generic 'entity'-level resolver must not disturb the existing,
   * more specific 'node'-level one: TokenRegistry always checks the concrete
   * slice before walking ancestors, so [node:entity_type] keeps resolving
   * through NodeEntityTypeToken, unchanged, exactly as
   * NodeEntityTypeTokenMigrationProofTest already proves.
   */
  public function testNodeScopedResolverStillOwnsRootPosition(): void {
    $author = $this->createSavedUser('node_root_author');
    $node = $this->createNode($author);

    $result = \Drupal::token()->replace('[node:entity_type]', ['node' => $node]);
    $this->assertSame('node', $result, 'The [node:entity_type] output is unchanged by the new generic resolver.');

    /** @var \Drupal\token_engine\TokenRegistryInterface $registry */
    $registry = $this->container->get('token_engine.registry');
    $definition = $registry->getResolvableToken('node', 'entity_type');

    $this->assertNotNull($definition, 'The (node, entity_type) token remains resolvable on the new engine.');
    $this->assertSame(NodeEntityTypeToken::class, $definition->resolverClass, 'The concrete node-scoped definition still wins over the entity-level ancestor.');
  }

  /**
   * Proof (c): [user:entity_type] at root position stays legacy parity.
   *
   * A single-segment token never tries the entity:<type> branch, and 'user'
   * is colon-free so the ancestor walk has nothing to strip. EntityTypeToken
   * therefore cannot claim the root position on user, and this token keeps
   * resolving through ECA's retained legacy hook_tokens() implementation
   * (\Drupal\eca\Hook\TokenHooks::tokens). The registry lookup for
   * ('user', 'entity_type') returning NULL is the provenance proof that the
   * engine does not claim this position; the byte-for-byte comparison against
   * LegacyTokenBridge::generate() proves the output is unaffected.
   */
  public function testUserRootPositionStaysLegacyParity(): void {
    $user = $this->createSavedUser('root_position_user');
    $data = ['user' => $user];
    $tokens = ['entity_type' => '[user:entity_type]'];

    /** @var \Drupal\token_engine\TokenRegistryInterface $registry */
    $registry = $this->container->get('token_engine.registry');
    $this->assertNull($registry->getResolvableToken('user', 'entity_type'), 'The engine does not claim the root position on user; no resolvable definition exists there.');

    $engine = \Drupal::token()->generate('user', $tokens, $data, [], new BubbleableMetadata());

    /** @var \Drupal\token_engine\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token_engine.legacy_bridge');
    $legacy = $bridge->generate('user', $tokens, $data, [], new BubbleableMetadata());

    $this->assertSame(
      (string) ($legacy['[user:entity_type]'] ?? ''),
      (string) ($engine['[user:entity_type]'] ?? ''),
      'The [user:entity_type] output is identical to the legacy hook output: legacy parity at root position.',
    );
    $this->assertSame('user', (string) $engine['[user:entity_type]']);
  }

}
