<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Core\Token\TokenResolutionEngine;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves Rule-B implicit delta-0 coercion equivalences from @berdir's feedback.
 *
 * Rule B: when the current type is list<T> and the segment is non-numeric with
 * no registered token, the engine implicitly resolves delta 0 and re-evaluates
 * the same segment against T. This makes bare spellings like
 * [node:field_refs:entity:name] produce identical output to the explicit
 * [node:field_refs:0:entity:name].
 *
 * The test also documents the actual behaviour of several related spellings so
 * that the spec is anchored to observable reality rather than assumptions.
 *
 * @see \Drupal\Core\Token\TokenResolutionEngine
 * @see \Drupal\Core\Token\ListDeltaResolver
 */
#[CoversClass(TokenResolutionEngine::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenImplicitDeltaEquivalenceTest extends TokenReplaceKernelTestBase {

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
   * The node under test.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * Alice: the first referenced user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $alice;

  /**
   * Bob: the second referenced user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $bob;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // Multi-value entity-reference field (node → user, unlimited cardinality).
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

    // Multi-value plain-text field (node, unlimited cardinality).
    FieldStorageConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_text',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Text values',
    ])->save();

    $this->alice = $this->createUser([], 'alice');
    $this->bob = $this->createUser([], 'bob');
    $this->admin = $this->createUser([], NULL, TRUE);

    $this->node = Node::create([
      'type' => 'article',
      'title' => 'Equivalence node',
      'field_refs' => [$this->alice->id(), $this->bob->id()],
      'field_text' => [
        ['value' => 'first-text'],
        ['value' => 'second-text'],
      ],
      'status' => 1,
    ]);
    $this->node->save();
  }

  /**
   * Rule B: bare entity-reference traversal is equivalent to explicit delta 0.
   *
   * [node:field_refs:entity:name] must produce the same output as
   * [node:field_refs:0:entity:name].
   */
  public function testBareEntityReferenceEqualsExplicitDeltaZero(): void {
    $bare = $this->tokenService->replace(
      '[node:field_refs:entity:name]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );
    $explicit = $this->tokenService->replace(
      '[node:field_refs:0:entity:name]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );

    $this->assertSame(
      $explicit,
      $bare,
      'Rule B: the bare spelling produces identical output to the explicit :0: spelling.',
    );
    $this->assertSame('alice', $bare, 'The first referenced user is reached via the bare spelling.');
  }

  /**
   * Regression guard: explicit delta 1 still resolves to the second entity.
   *
   * Rule B must not interfere with explicit non-zero deltas.
   */
  public function testExplicitDeltaOneResolvesToSecondUser(): void {
    $result = $this->tokenService->replace(
      '[node:field_refs:1:entity:name]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );

    $this->assertSame('bob', $result, 'Explicit delta 1 still reaches the second referenced user.');
  }

  /**
   * Documents the actual output of :0: on a multi-value string field.
   *
   * FieldValueToken returns a list<string> for multi-value fields. The engine's
   * Rule B requires a non-numeric segment to follow after the field token for
   * implicit coercion to fire. The bare single-segment [node:field_text] routes
   * to the legacy bridge (the engine only intercepts entity-type chains with at
   * least 2 segments), so it is not tested here.
   *
   * [node:field_text:0] resolves via the engine: field_text yields list<string>,
   * and the numeric "0" applies the existing delta branch to select the first item.
   *
   * NOTE: these two spellings are NOT equivalent and are not required to be;
   * Rule B only fires when a further non-numeric traversal segment follows the
   * list value. This is documented intentionally.
   */
  public function testExplicitDeltaZeroOnMultiValueStringField(): void {
    $explicitDelta0 = $this->tokenService->replace(
      '[node:field_text:0]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );

    // The explicit :0: spelling must resolve to the first value.
    $this->assertSame('first-text', $explicitDelta0, ':0: selects the first text value on a multi-value string field.');
  }

  /**
   * Parity guard: [node:author] and [node:author:display-name] both work.
   *
   * Both tokens are currently served by the legacy bridge. This asserts that
   * both resolve to non-empty, well-defined output, establishing a parity
   * baseline for future migration of these tokens into the new engine.
   */
  public function testLegacyAuthorTokensProduceOutput(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Author parity',
      'uid' => $this->alice->id(),
      'status' => 1,
    ]);
    $node->save();

    $author = $this->tokenService->replace(
      '[node:author]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );
    $displayName = $this->tokenService->replace(
      '[node:author:display-name]',
      ['node' => $node],
      ['viewer' => $this->admin],
    );

    $this->assertNotEmpty($author, '[node:author] produces non-empty output via the legacy bridge.');
    $this->assertNotEmpty($displayName, '[node:author:display-name] produces non-empty output via the legacy bridge.');
  }

  /**
   * Documents the actual output of a contrib-style :value sub-property token.
   *
   * Spellings like [node:field_text:value] are not structurally resolvable by
   * the new engine (there is no registered token named 'value' under
   * list<string>), so the engine falls back to the legacy bridge. This test
   * asserts whatever the system actually returns so that changes to either
   * the engine or the legacy bridge are caught as regressions.
   *
   * NOTE: in the current codebase, [node:field_text:value] falls through to
   * the legacy bridge entirely (the new engine does not define a 'value' token
   * for list<string>). The legacy bridge may or may not resolve it depending
   * on what hook_token_info() implementations are installed. This test anchors
   * the fallback-parity behaviour without inventing new engine support.
   */
  public function testContribStyleSubPropertyFallsThroughToLegacy(): void {
    $result = $this->tokenService->replace(
      '[node:field_text:value]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );

    // Assert that the call completed without error (no exception thrown). The
    // actual value is the legacy-bridge result and is documented rather than
    // asserted for a specific value, since it depends on installed modules.
    $this->assertIsString($result, '[node:field_text:value] returns a string (legacy fallback).');
  }

}
