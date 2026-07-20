<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\token_engine\TokenResolutionEngine;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves that token spellings survive field-storage cardinality changes.
 *
 * The same token string written when a field had cardinality 1 must still
 * resolve after cardinality is raised to 3 (Rule B), and a ":0:" spelling
 * written when the field was multi-value must survive a flip back to
 * single-value (Rule C: identity zero on a non-list type).
 *
 * Rule C: when the current resolved type is NOT a list and the segment is
 * the literal "0", the engine consumes the segment and leaves value and type
 * unchanged. This closes the "I refactored the field to single-value but all
 * my token strings are broken" scenario.
 *
 * @see \Drupal\token_engine\TokenResolutionEngine
 * @see \Drupal\token_engine\ListDeltaResolver
 */
#[CoversClass(TokenResolutionEngine::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenCardinalityChangeTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'filter', 'text'];

  /**
   * The admin user used for access-checked replacements.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // Start with single-value string field (cardinality 1).
    FieldStorageConfig::create([
      'field_name' => 'field_card',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => 1,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_card',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Cardinality test field',
    ])->save();

    $this->admin = $this->createUser([], NULL, TRUE);
  }

  /**
   * Creates a node with the field_card value set.
   */
  private function createNode(array|string $value): \Drupal\node\NodeInterface {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Cardinality node',
      'field_card' => is_array($value) ? $value : [['value' => $value]],
      'status' => 1,
    ]);
    $node->save();
    return $node;
  }

  /**
   * Invalidates the registry cache to force re-discovery after config changes.
   */
  private function invalidateRegistry(): void {
    $this->container->get('token_engine.registry')->invalidate();
  }

  /**
   * Tests that the :0: spelling resolves when cardinality is 1.
   *
   * With cardinality 1, FieldValueToken returns a plain string (not a list).
   * Rule C ensures the ":0:" spelling still resolves by consuming the "0"
   * segment as an identity operation and leaving value and type unchanged.
   *
   * Note: a bare single-segment [node:field_card] (no colon chain) routes to
   * the legacy bridge for all entity root types; it is not handled by the new
   * engine and is not asserted here. The relevant token spellings are those with
   * at least one additional segment after the field name.
   */
  public function testExplicitZeroResolveAtCardinalityOne(): void {
    $node = $this->createNode('single-value');

    $withZero = $this->tokenService->replace(
      '[node:field_card:0]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );

    $this->assertSame('single-value', $withZero, 'Rule C: :0: spelling resolves with cardinality 1 (identity zero).');
  }

  /**
   * Tests that :0: token strings still resolve after cardinality rises.
   *
   * Token strings authored when the field had cardinality 1 must keep working
   * after the field is reconfigured to cardinality 3 (multi-value). The :0:
   * spelling selects the first item via the numeric-delta branch.
   */
  public function testExplicitZeroStillResolvesAfterCardinalityIncrease(): void {
    // Change storage to cardinality 3 (multi-value).
    $storage = FieldStorageConfig::loadByName('node', 'field_card');
    $storage->setCardinality(3)->save();
    $this->invalidateRegistry();

    $node = $this->createNode([
      ['value' => 'first'],
      ['value' => 'second'],
    ]);

    $withZero = $this->tokenService->replace(
      '[node:field_card:0]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );

    // :0: explicitly selects the first item via the numeric-delta branch.
    $this->assertSame('first', $withZero, ':0: selects the first value after cardinality increase.');
  }

  /**
   * Tests that the second value is accessible after cardinality increase.
   *
   * This is a regression guard for the multi-value case: delta 1 must reach the
   * second value after the field gains multi-value storage.
   */
  public function testDeltaOneReachesSecondValueAfterCardinalityIncrease(): void {
    $storage = FieldStorageConfig::loadByName('node', 'field_card');
    $storage->setCardinality(3)->save();
    $this->invalidateRegistry();

    $node = $this->createNode([
      ['value' => 'first'],
      ['value' => 'second'],
    ]);

    $delta1 = $this->tokenService->replace(
      '[node:field_card:1]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );

    $this->assertSame('second', $delta1, 'Delta 1 reaches the second value after cardinality increase.');
  }

  /**
   * Tests Rule C: :0: on a single-value field after flip back from multi-value.
   *
   * A field that was multi-value (so token authors wrote ":0:" spellings) is
   * reconfigured back to single-value. Rule C must make ":0:" survive by
   * treating it as an identity operation when the resolved type is not a list.
   *
   * Note: a bare single-segment [node:field_card] routes to the legacy bridge
   * for all entity root types; it is not asserted here. Only the :0: spelling
   * is within the new engine's scope.
   */
  public function testExplicitZeroSurvivesFlipBackToSingleValue(): void {
    // Phase 1: set to multi-value so ":0:" spellings are authored.
    $storage = FieldStorageConfig::loadByName('node', 'field_card');
    $storage->setCardinality(3)->save();
    $this->invalidateRegistry();

    // Phase 2: flip back to single-value with a single value present.
    $storage = FieldStorageConfig::loadByName('node', 'field_card');
    $storage->setCardinality(1)->save();
    $this->invalidateRegistry();

    $node = $this->createNode('only-value');

    $withZero = $this->tokenService->replace(
      '[node:field_card:0]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );

    $this->assertSame('only-value', $withZero, 'Rule C: :0: still resolves after flip back to single-value.');
  }

}
