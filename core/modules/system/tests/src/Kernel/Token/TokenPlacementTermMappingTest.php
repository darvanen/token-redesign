<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Token\Plugin\Validation\Constraint\TokenPlacementConstraintValidator;
use Drupal\Core\Token\TokenEntityTypeMapper;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the placement validator's entity-type-mapper fallback for [term:*].
 *
 * The token type name 'term' does not match its entity type id
 * 'taxonomy_term' (nor does 'vocabulary' match 'taxonomy_vocabulary'), unlike
 * [user:*] or [node:*] where the token type IS the entity type id. Before this
 * fix, the validator's first-segment lookup only tried the token type name
 * itself as an entity type id, so entityTypeManager->hasDefinition('term')
 * failed and every [term:*] chain fell through as an unrecognised first
 * segment: left ungated, not because it was verified safe, but because the
 * validator could not identify it as a chain at all. The lookup now retries
 * through TokenEntityTypeMapper before giving up, closing the same class of
 * placement gap for taxonomy tokens that Spec A closes for [current-user:*].
 *
 * @see \Drupal\Core\Token\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see \Drupal\Core\Token\TokenEntityTypeMapper
 */
#[CoversClass(TokenPlacementConstraintValidator::class)]
#[CoversClass(TokenEntityTypeMapper::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenPlacementTermMappingTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'taxonomy',
    'entity_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['filter', 'system']);
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Tests [term:description] is gated once the mapper resolves 'term'.
   *
   * Taxonomy's own access control handlers place no field-level restriction
   * on 'description' (no core taxonomy field restricts view access without a
   * loaded entity, unlike user.mail), so entity_test's generic
   * hook_entity_field_access() -- gated by field name via state, not by entity
   * type -- stands in for a genuinely restricted field: it forbids view
   * access to whichever field name is named in
   * 'views_field_access_test-field' unless the account holds 'view test
   * entity field'. This is sufficient to prove the mapper fallback actually
   * reaches the 'description' base field definition on 'entity:taxonomy_term'
   * and that its access result determines the outcome, which is the property
   * under test, not the specific policy of any one field.
   */
  public function testMappedTypeFieldIsGated(): void {
    $this->container->get('state')->set('views_field_access_test-field', 'description');

    $this->setUpCurrentUser(permissions: []);
    $blocked = $this->violationsFor('[term:description]');
    $this->assertCount(1, $blocked, 'Without the permission, [term:description] is blocked via the term-to-taxonomy_term mapper fallback.');
    $this->assertStringContainsString('[term:description]', (string) $blocked[0]->getMessage());

    $this->setUpCurrentUser(permissions: ['view test entity field']);
    $this->assertCount(0, $this->violationsFor('[term:description]'), 'With the permission, [term:description] is allowed.');
  }

  /**
   * Tests [term:name], an unrestricted mapped-type field, is never blocked.
   *
   * Confirms the mapper fallback finding a definition does not over-gate: a
   * field with no access restriction of its own stays placeable, so the
   * previous test's block is attributable to the field's access result, not
   * to the mapper path itself.
   */
  public function testMappedTypeUngatedFieldIsAllowed(): void {
    $this->setUpCurrentUser(permissions: []);
    $this->assertCount(0, $this->violationsFor('[term:name]'), 'An unrestricted mapped-type field is never blocked.');
  }

  /**
   * Tests an unmapped, non-existent root type stays ungated.
   *
   * 'bogus_type' has no root definition, is not a real entity type id, and has
   * no mapper alias, so the chain never identifies a type to walk and is left
   * ungated exactly as before this change: an unrecognised first segment is
   * treated as a legacy or unknown token, not a chain the validator entered.
   */
  public function testUnmappedBogusTypeStaysUngated(): void {
    $this->setUpCurrentUser(permissions: []);
    $this->assertCount(0, $this->violationsFor('[bogus_type:anything]'), 'An unmapped, non-existent root type is left ungated.');
  }

}
