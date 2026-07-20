<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the delta and entity-reference traversal compose on multi-value fields.
 *
 * A multi-value reference field is discovered as a `list<entity_reference:...>`,
 * so a numeric delta segment selects the item at that delta, which the `entity`
 * deref then resolves. This makes deltas beyond 0 reachable through references,
 * e.g. [node:field_refs:1:entity:name].
 *
 * @see \Drupal\token_engine\Discovery\TypedDataFieldDiscovery
 * @see \Drupal\token_engine\EntityReferenceFieldToken
 * @see \Drupal\token_engine\ListDeltaResolver
 */
#[CoversClass(\Drupal\token_engine\EntityReferenceFieldToken::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenMultiValueReferenceDeltaTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // A multi-value entity-reference field (node -> user, unlimited).
    FieldStorageConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'References',
    ])->save();
  }

  /**
   * Tests the multi-value reference field is discovered as a list.
   */
  public function testMultiValueReferenceFieldIsDiscoveredAsList(): void {
    $definition = $this->container->get('token_engine.registry')->getResolvableToken('entity:node', 'field_refs');
    $this->assertNotNull($definition);
    $this->assertSame('list<entity_reference:user>', $definition->outputType, 'A multi-value reference field outputs a list type.');
  }

  /**
   * Tests that distinct deltas resolve to distinct referenced entities.
   */
  public function testDeltaSelectsTheCorrectReferencedEntity(): void {
    $alice = $this->createUser([], 'alice');
    $bob = $this->createUser([], 'bob');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Multi reference node',
      'field_refs' => [$alice->id(), $bob->id()],
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $delta0 = $this->tokenService->replace('[node:field_refs:0:entity:name]', ['node' => $node], ['viewer' => $admin]);
    $delta1 = $this->tokenService->replace('[node:field_refs:1:entity:name]', ['node' => $node], ['viewer' => $admin]);

    $this->assertSame('alice', $delta0, 'Delta 0 resolves to the first referenced user.');
    $this->assertSame('bob', $delta1, 'Delta 1 resolves to the second referenced user.');
    $this->assertNotSame($delta0, $delta1, 'Distinct deltas reach distinct referenced entities.');
  }

  /**
   * Tests that an out-of-range delta resolves to an empty string.
   */
  public function testOutOfRangeDeltaIsEmpty(): void {
    $alice = $this->createUser([], 'solo');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Single reference node',
      'field_refs' => [$alice->id()],
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $result = $this->tokenService->replace('Ref: [node:field_refs:5:entity:name]', ['node' => $node], ['viewer' => $admin]);
    $this->assertSame('Ref: ', $result, 'An out-of-range delta yields nothing.');
  }

}
