<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator;
use Drupal\token_engine\TokenResolutionEngine;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the placement validator's traversal parity with the resolution engine.
 *
 * TokenPlacementConstraintValidator::walk() statically re-derives the same
 * chain the resolution engine walks structurally at TokenResolutionEngine::
 * resolveChain() time, so an author-side "can this be placed" answer has to
 * agree with the engine's own traversal rules or the gate can be walked
 * around: a chain the validator treats as unverifiable (or wrongly considers
 * complete) while the engine actually resolves it further is a gap in the
 * placement gate, not merely a cosmetic mismatch. The engine has two
 * traversal rules beyond a plain registered-token lookup and the
 * numeric-delta-on-a-list case:
 *  - Rule B (~resolveChain() lines 364-394): on a list<T> type, a non-numeric
 *    segment is implicitly re-evaluated against T (delta 0), so
 *    [node:field_refs:entity:name] behaves like
 *    [node:field_refs:0:entity:name].
 *  - Rule C (~resolveChain() lines 396-405): on a non-list type, the literal
 *    segment '0' is consumed as an identity no-op, so a ":0:" spelling
 *    survives a field that used to be multi-value becoming single-value.
 * Both are mirrored in TokenPlacementConstraintValidator::walk() so a chain
 * that reaches restricted data via either rule is still gated on the
 * author's access, not silently misclassified as unverifiable (or worse,
 * allowed).
 *
 * @see \Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see \Drupal\token_engine\TokenResolutionEngine
 */
#[CoversClass(TokenPlacementConstraintValidator::class)]
#[CoversClass(TokenResolutionEngine::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenPlacementTraversalParityTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token_engine',
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['filter', 'system', 'node']);
    $this->createContentType(['type' => 'article']);

    // A multi-value entity-reference field (node -> entity_test, unlimited),
    // discovered as 'list<entity_reference:entity_test>': the list type Rule
    // B needs.
    FieldStorageConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'entity_test'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'References',
    ])->save();
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Tests Rule B: implicit delta-0 coercion reaches the field-access gate.
   *
   * [node:field_refs:entity:name] has no delta segment, so 'entity' is
   * evaluated directly against 'list<entity_reference:entity_test>' and finds
   * nothing there (only a numeric delta or a further list operation would
   * match a bare list type). The validator's Rule B branch coerces to the
   * item type 'entity_reference:entity_test' and re-evaluates 'entity' there,
   * finding EntityDerefToken's definition and advancing to
   * 'entity:entity_test', exactly mirroring the engine's own implicit-delta-0
   * coercion (see TokenImplicitDeltaEquivalenceTest for the engine-side
   * equivalence this traversal is required to agree with). The chain then
   * reaches the 'name' base field on 'entity:entity_test', gated here via
   * entity_test's generic hook_entity_field_access() (see
   * TokenPlacementTermMappingTest for the same fixture pattern), standing in
   * for a genuinely restricted field.
   */
  public function testImplicitDeltaCoercionReachesFieldAccessGate(): void {
    $this->container->get('state')->set('views_field_access_test-field', 'name');

    $this->setUpCurrentUser(permissions: []);
    $blocked = Node::create([
      'type' => 'article',
      'title' => 'Rule B probe',
      'body' => [
        'value' => 'Ref: [node:field_refs:entity:name]',
        'format' => 'plain_text',
      ],
    ]);
    $blockedViolations = $blocked->validate()->getByField('body');
    $this->assertGreaterThan(0, $blockedViolations->count(), 'Without the permission, the bare (implicit delta-0) chain is blocked via the coerced item type.');

    $this->setUpCurrentUser(permissions: ['view test entity field']);
    $allowed = Node::create([
      'type' => 'article',
      'title' => 'Rule B probe allowed',
      'body' => [
        'value' => 'Ref: [node:field_refs:entity:name]',
        'format' => 'plain_text',
      ],
    ]);
    $this->assertCount(0, $allowed->validate()->getByField('body'), 'With the permission, the same bare chain is allowed.');
  }

  /**
   * Tests Rule C: the literal '0' segment reaches the mail-field gate.
   *
   * [node:uid:entity:0:mail] walks 'uid' (the node's owner reference,
   * 'entity_reference:user') through 'entity' to 'entity:user', a non-list
   * type. The literal '0' segment is then consumed as an identity no-op by
   * Rule C -- the type stays 'entity:user' -- and 'mail' resolves against it
   * exactly as it would without the '0' present, reaching the same 'mail'
   * base field access gate [current-user:mail] and [user:mail] already use
   * elsewhere in this test suite.
   */
  public function testIdentityZeroReachesMailFieldGate(): void {
    $this->setUpCurrentUser(permissions: []);
    $blocked = $this->violationsFor('[node:uid:entity:0:mail]');
    $this->assertCount(1, $blocked, 'Without the permission, the identity-zero chain still reaches and is blocked by the mail-field gate.');
    $this->assertStringContainsString('[node:uid:entity:0:mail]', (string) $blocked[0]->getMessage());

    $this->setUpCurrentUser(permissions: ['administer users']);
    $this->assertCount(0, $this->violationsFor('[node:uid:entity:0:mail]'), 'With the permission, the same identity-zero chain is allowed.');
  }

  /**
   * Control: a genuinely unknown segment stays unverifiable, not allowed.
   *
   * [node:uid:entity:not_a_real_field] lands on 'entity:user' (a non-list,
   * non-scalar type) with a segment that is neither a registered token, a
   * numeric delta, nor the Rule C literal '0'. Neither rule applies, so the
   * chain is genuinely unverifiable and is gated by the harden_placement
   * flag, same as any other unverifiable chain -- confirming the new Rule
   * B/C branches did not accidentally widen what counts as "resolved" to
   * cases the engine cannot actually complete either.
   */
  public function testUnknownSegmentStaysUnverifiable(): void {
    $this->setUpCurrentUser(permissions: []);

    $this->config('token_engine.settings')->set('harden_placement', TRUE)->save();
    $hardened = $this->violationsFor('[node:uid:entity:not_a_real_field]');
    $this->assertCount(1, $hardened, 'Hardened: the genuinely unknown segment is blocked as unverifiable.');
    $this->assertStringContainsString('place unverifiable tokens', (string) $hardened[0]->getMessage());

    $this->config('token_engine.settings')->set('harden_placement', FALSE)->save();
    $this->assertCount(0, $this->violationsFor('[node:uid:entity:not_a_real_field]'), 'Relaxed: the same unverifiable chain is allowed.');
  }

}
