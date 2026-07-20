<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\token_engine\TokenRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests assignability-based input-type matching in TokenRegistry.
 *
 * TokenRegistry::getResolvableToken() no longer requires an exact match
 * between a chain's current type and a definition's declared input type: on a
 * miss it walks the type's ancestors (progressively stripping a trailing
 * ':<segment>') and returns the first hit, most-specific-wins. This lets a
 * token be declared once against a general type (e.g. 'entity') and served at
 * every more specific type ('entity:node', 'entity:user', ...) without any
 * change to the resolution engine or the placement validator: both consume
 * TokenRegistryInterface::getResolvableToken() and inherit the new matching
 * through the API, with no special-casing of their own.
 *
 * GenericEntityMarkerResolver (declared at 'entity') and
 * NodeSpecificMarkerResolver (declared at 'entity:node', same token name) are
 * the fixtures used throughout: the first proves ancestor reach across
 * different concrete entity types, the second proves the concrete slice still
 * wins over the ancestor when both declare the same name.
 *
 * @see \Drupal\token_engine\TokenRegistry
 * @see \Drupal\token_context_test\Plugin\Token\GenericEntityMarkerResolver
 * @see \Drupal\token_context_test\Plugin\Token\NodeSpecificMarkerResolver
 */
#[CoversClass(TokenRegistry::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenTypeAssignabilityTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'filter',
    'token_context_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // A self-referencing entity-reference field (node -> node), used to reach
    // 'entity:node' through a chain, the same shape as the built-in 'uid'
    // field (node -> user) used to reach 'entity:user' below.
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related',
    ])->save();
  }

  /**
   * Creates a node authored by a freshly created user with the given name.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\user\UserInterface}
   *   The node and its author.
   */
  private function createAuthoredNode(string $authorName): array {
    $author = $this->createUser([], $authorName);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Assignability node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    return [$node, $author];
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Spec item (a): a token declared at 'entity' resolves at a node chain's end.
   *
   * [node:uid:entity:generic_marker] reaches entity:user, via the built-in
   * 'uid' reference field and the 'entity' deref. GenericEntityMarkerResolver
   * is declared at input_type 'entity', not 'entity:user', so this only
   * resolves through TokenRegistry::getResolvableToken()'s ancestor walk: proof
   * the reach is type-level (it is exercised here at entity:user), not
   * hard-coded to node.
   */
  public function testGenericEntityTokenResolvesViaAncestorMatch(): void {
    [$node, $author] = $this->createAuthoredNode('assignability_author');
    $admin = $this->createUser([], NULL, TRUE);

    $result = $this->tokenService->replace(
      '[node:uid:entity:generic_marker]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $this->assertSame('GENERIC:' . $author->label(), $result, 'The entity-level token resolved at entity:user through the ancestor walk.');
  }

  /**
   * Spec item (b): an 'entity:node' definition wins over the 'entity' ancestor.
   *
   * The concrete slice is always checked before any ancestor walk, so a chain
   * that reaches entity:node directly must return NodeSpecificMarkerResolver's
   * definition, not GenericEntityMarkerResolver's. A chain that reaches a
   * different concrete type (entity:user, as in test (a)) is unaffected: the
   * entity-level definition still serves it, proving the override at
   * entity:node did not shadow the ancestor definition for other types.
   */
  public function testEntityNodeDefinitionTakesPrecedenceOverAncestor(): void {
    [$node] = $this->createAuthoredNode('specificity_author');
    $related = Node::create([
      'type' => 'article',
      'title' => 'Related node',
      'status' => 1,
    ]);
    $related->save();
    $node->set('field_related', $related->id());
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $nodeChain = $this->tokenService->replace(
      '[node:field_related:entity:generic_marker]',
      ['node' => $node],
      ['viewer' => $admin],
    );
    $userChain = $this->tokenService->replace(
      '[node:uid:entity:generic_marker]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $this->assertSame('NODE_SPECIFIC:' . $related->label(), $nodeChain, 'The entity:node definition beats the entity-level ancestor for a node chain.');
    $this->assertSame('GENERIC:' . $node->getOwner()->label(), $userChain, 'The entity-level definition still serves entity:user, unaffected by the entity:node override.');
  }

  /**
   * Spec item (d): placement validator parity for an ancestor-matched step.
   *
   * [node:uid:entity:generic_marker] reaches GenericEntityMarkerResolver's
   * definition (place_permission 'place secret tokens', reused from
   * PlacementGatedSecretResolver) only through the same ancestor walk exercised
   * in test (a); no definition exists at the concrete 'entity:user' slice.
   * TokenPlacementConstraintValidator::walk() never special-cases assignability
   * of its own: it calls the identical
   * TokenRegistryInterface::getResolvableToken() the resolution engine uses,
   * so gating this chain proves both call sites inherit the ancestor walk
   * from the shared registry API.
   *
   * @see \Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator::walk()
   */
  public function testPlacementValidatorGatesAncestorMatchedStep(): void {
    $this->setUpCurrentUser(permissions: []);
    $violations = $this->violationsFor('[node:uid:entity:generic_marker]');
    $this->assertGreaterThan(0, $violations->count(), 'An author without the permission cannot place a token reached only via the ancestor match.');

    $this->setUpCurrentUser(permissions: ['place secret tokens']);
    $this->assertCount(0, $this->violationsFor('[node:uid:entity:generic_marker]'), 'An author with the permission may place the same ancestor-matched token.');
  }

  /**
   * Spec item (e): an unknown type has no ancestors, so a miss returns NULL.
   *
   * A colon-free type (however bogus) has no ancestors to walk, so the
   * ancestor stripping must never fall back to the root ('') slice for it.
   * 'current-user' is a real definition registered at the root type (input_type
   * ''), so looking it up against a bogus, colon-free type proves the root
   * slice is genuinely never consulted for such a type, not merely that no
   * matching name happens to exist there.
   */
  public function testUnknownTypeHasNoAncestorsAndReturnsNull(): void {
    $registry = $this->container->get('token_engine.registry');

    $this->assertNotNull($registry->getResolvableToken('', 'current-user'), 'Sanity check: the root definition used below actually exists.');
    $this->assertNull($registry->getResolvableToken('totally_bogus_type', 'current-user'), 'A bogus, colon-free type has no ancestors, so the root slice is never consulted for it.');
    $this->assertNull($registry->getResolvableToken('totally_bogus_type', 'generic_marker'), 'A bogus, colon-free type with no definition of its own returns NULL.');
  }

}
