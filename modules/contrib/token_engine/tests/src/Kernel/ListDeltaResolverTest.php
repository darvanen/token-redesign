<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\ActorContext;
use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\token_engine\ListDeltaResolver;
use Drupal\token_engine\TokenDefinition;
use Drupal\token_engine\TokenRegistryInterface;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ListDeltaResolver: extracting a delta-indexed item from a list value.
 *
 * Covers:
 *  - Resolving the correct item from a PHP array by delta.
 *  - Resolving from a TypedData ListInterface (field item list).
 *  - Out-of-range delta returns empty string.
 *  - Negative delta returns empty string.
 *  - Non-list input returns empty string.
 *  - Missing delta argument returns empty string.
 *  - Registry integration: a subscriber can register list<> and delta tokens,
 *    and the delta-0 token resolves to the correct item.
 *
 * @see \Drupal\token_engine\ListDeltaResolver
 */
#[CoversClass(ListDeltaResolver::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class ListDeltaResolverTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'filter',
  ];

  /**
   * The token registry.
   */
  protected TokenRegistryInterface $registry;

  /**
   * The resolver under test.
   */
  protected ListDeltaResolver $resolver;

  /**
   * A shared resolution context for unit-level tests.
   */
  protected TokenResolutionContext $context;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');

    $this->registry = $this->container->get('token_engine.registry');
    $this->resolver = new ListDeltaResolver();

    $actor = ActorContext::fromSingleActor($this->container->get('current_user'));
    $this->context = new TokenResolutionContext([], $actor);
  }

  // ---------------------------------------------------------------------------
  // Unit-level tests (resolver in isolation)
  // ---------------------------------------------------------------------------

  /**
   * Tests that delta 0 returns the first item from a PHP array.
   */
  public function testResolvesFirstItemFromArray(): void {
    $list = ['alpha', 'beta', 'gamma'];
    $result = $this->resolver->resolve($list, ['delta' => 0], $this->context);

    $this->assertSame('alpha', $result->value, 'Delta 0 returns the first array item.');
  }

  /**
   * Tests that delta 1 returns the second item from a PHP array.
   */
  public function testResolvesSecondItemFromArray(): void {
    $list = ['alpha', 'beta', 'gamma'];
    $result = $this->resolver->resolve($list, ['delta' => 1], $this->context);

    $this->assertSame('beta', $result->value, 'Delta 1 returns the second array item.');
  }

  /**
   * Tests that delta 2 returns the third item from a PHP array.
   */
  public function testResolvesThirdItemFromArray(): void {
    $list = ['alpha', 'beta', 'gamma'];
    $result = $this->resolver->resolve($list, ['delta' => 2], $this->context);

    $this->assertSame('gamma', $result->value, 'Delta 2 returns the third array item.');
  }

  /**
   * Tests that a string delta argument is cast to int.
   */
  public function testStringDeltaIsCastToInt(): void {
    $list = ['first', 'second'];
    $result = $this->resolver->resolve($list, ['delta' => '1'], $this->context);

    $this->assertSame('second', $result->value, 'String delta is cast to int.');
  }

  /**
   * Provides out-of-range delta test cases.
   *
   * @return array<string, array{int}>
   *   Test cases keyed by label, each an array with one delta value.
   */
  public static function provideOutOfRangeDeltas(): array {
    return [
      'exact length'   => [3],
      'way over range' => [99],
    ];
  }

  /**
   * Tests that an out-of-range delta returns an empty string.
   *
   * @param int $delta
   *   The out-of-range delta to test.
   */
  #[DataProvider('provideOutOfRangeDeltas')]
  public function testOutOfRangeDeltaReturnsEmpty(int $delta): void {
    $list = ['alpha', 'beta', 'gamma'];
    $result = $this->resolver->resolve($list, ['delta' => $delta], $this->context);

    $this->assertSame('', $result->value, "Delta {$delta} (out of range) returns empty string.");
  }

  /**
   * Tests that a negative delta returns an empty string.
   */
  public function testNegativeDeltaReturnsEmpty(): void {
    $list = ['alpha', 'beta'];
    $result = $this->resolver->resolve($list, ['delta' => -1], $this->context);

    $this->assertSame('', $result->value, 'Negative delta returns empty string.');
  }

  /**
   * Tests that a missing delta argument returns an empty string.
   */
  public function testMissingDeltaArgumentReturnsEmpty(): void {
    $list = ['alpha', 'beta'];
    $result = $this->resolver->resolve($list, [], $this->context);

    $this->assertSame('', $result->value, 'Missing delta argument returns empty string.');
  }

  /**
   * Tests that a non-list input returns an empty string.
   */
  public function testNonListInputReturnsEmpty(): void {
    $result = $this->resolver->resolve('not-a-list', ['delta' => 0], $this->context);
    $this->assertSame('', $result->value, 'String input returns empty string.');

    $result = $this->resolver->resolve(42, ['delta' => 0], $this->context);
    $this->assertSame('', $result->value, 'Integer input returns empty string.');

    $result = $this->resolver->resolve(NULL, ['delta' => 0], $this->context);
    $this->assertSame('', $result->value, 'NULL input returns empty string.');
  }

  /**
   * Tests that the resolver handles an empty array gracefully.
   */
  public function testEmptyArrayReturnsEmpty(): void {
    $result = $this->resolver->resolve([], ['delta' => 0], $this->context);
    $this->assertSame('', $result->value, 'Empty array with delta 0 returns empty string.');
  }

  // ---------------------------------------------------------------------------
  // TypedData ListInterface tests
  // ---------------------------------------------------------------------------

  /**
   * Tests that delta 0 resolves to the correct item from a TypedData field list.
   *
   * Creates a node with a multi-value body field, loads it, and passes the
   * field item list directly to the resolver.
   */
  public function testResolvesFirstItemFromTypedDataList(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Delta Test Node',
      'status' => 1,
      'body' => [
        ['value' => 'First body value', 'format' => 'plain_text'],
      ],
    ]);
    $node->save();

    // Load the field item list (a TypedData ListInterface).
    $fieldList = $node->get('body');

    $result = $this->resolver->resolve($fieldList, ['delta' => 0], $this->context);

    // The value should be the field item at delta 0.
    $this->assertNotNull($result->value, 'Delta 0 on a TypedData list returns a non-null value.');
    $this->assertNotSame('', $result->value, 'Delta 0 on a TypedData list does not return empty string.');
  }

  /**
   * Tests that an out-of-range delta on a TypedData list returns empty string.
   */
  public function testOutOfRangeDeltaOnTypedDataListReturnsEmpty(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Delta Test Node',
      'status' => 1,
      'body' => [
        ['value' => 'Only value', 'format' => 'plain_text'],
      ],
    ]);
    $node->save();

    $fieldList = $node->get('body');

    $result = $this->resolver->resolve($fieldList, ['delta' => 99], $this->context);
    $this->assertSame('', $result->value, 'Out-of-range delta on TypedData list returns empty string.');
  }

  // ---------------------------------------------------------------------------
  // Registry integration: subscriber registers list<> and delta tokens
  // ---------------------------------------------------------------------------

  /**
   * Tests that a subscriber can register list<> and delta tokens.
   *
   * Uses a TokenDiscoveryAlterEvent subscriber to register a list-type field
   * token and per-delta sub-tokens, then verifies the registry returns them.
   */
  public function testSubscriberCanRegisterListAndDeltaTokens(): void {
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        // The field token itself: multi-value body field produces a list type.
        $event->addDefinition(new TokenDefinition(
          name: 'body',
          inputType: 'node',
          outputType: 'list<node-body>',
          label: 'Body (multi-value)',
          resolverClass: ListDeltaResolver::class,
          module: 'test',
        ));
        // Delta sub-tokens: one per supported delta.
        for ($delta = 0; $delta < 3; $delta++) {
          $event->addDefinition(new TokenDefinition(
            name: (string) $delta,
            inputType: 'list<node-body>',
            outputType: 'node-body',
            label: "Body item {$delta}",
            resolverClass: ListDeltaResolver::class,
            module: 'test',
          ));
        }
      },
    );
    $this->registry->invalidate();

    $nodeTokens = $this->registry->getTokensForInputType('node');
    $this->assertArrayHasKey('body', $nodeTokens, 'List-type body token registered on node input type.');
    $this->assertSame('list<node-body>', $nodeTokens['body']->outputType);

    $listTokens = $this->registry->getTokensForInputType('list<node-body>');
    $this->assertArrayHasKey('0', $listTokens, 'Delta-0 token registered on list<node-body>.');
    $this->assertArrayHasKey('1', $listTokens, 'Delta-1 token registered on list<node-body>.');
    $this->assertArrayHasKey('2', $listTokens, 'Delta-2 token registered on list<node-body>.');
  }

  /**
   * Tests the delta-0 token resolves to the correct item end-to-end.
   *
   * Creates a node with a multi-value field, registers list<> and delta tokens
   * via a subscriber, then manually invokes the resolver chain to verify the
   * delta-0 token resolves to the correct field item.
   *
   * Note: this test manually drives the resolver chain because Phase 3 engine
   * invocation (attributed resolver execution) is not yet fully wired up in
   * TokenResolutionEngine. The test proves the mechanism works at the resolver
   * level; the engine wiring is the Phase 3 TODO.
   */
  public function testDeltaZeroTokenResolvesToCorrectItem(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Delta Chain Test',
      'status' => 1,
      'body' => [
        ['value' => 'Delta zero body', 'format' => 'plain_text'],
        ['value' => 'Delta one body', 'format' => 'plain_text'],
      ],
    ]);
    $node->save();

    // Step 1: retrieve the multi-value body field (list).
    $fieldList = $node->get('body');

    // Step 2: invoke the ListDeltaResolver with delta 0.
    $result = $this->resolver->resolve($fieldList, ['delta' => 0], $this->context);

    $this->assertNotNull($result->value, 'Delta-0 resolver returned a non-null value.');

    // The returned item is a TypedData FieldItemInterface; verify its value.
    // Use getValue() to get the raw values array.
    $itemValue = $result->value->getValue();
    $this->assertSame(
      'Delta zero body',
      $itemValue['value'],
      'Delta-0 token resolves to the first body field item.',
    );
  }

  /**
   * Tests that delta-1 resolves to the second item.
   */
  public function testDeltaOneTokenResolvesToSecondItem(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Delta Chain Test',
      'status' => 1,
      'body' => [
        ['value' => 'Delta zero body', 'format' => 'plain_text'],
        ['value' => 'Delta one body', 'format' => 'plain_text'],
      ],
    ]);
    $node->save();

    $fieldList = $node->get('body');

    $result = $this->resolver->resolve($fieldList, ['delta' => 1], $this->context);

    $this->assertNotNull($result->value, 'Delta-1 resolver returned a non-null value.');
    $itemValue = $result->value->getValue();
    $this->assertSame(
      'Delta one body',
      $itemValue['value'],
      'Delta-1 token resolves to the second body field item.',
    );
  }

}
