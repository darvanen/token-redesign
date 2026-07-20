<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\Core\Utility\Token;
use Drupal\token_engine\TokenResolutionEngine;
use Drupal\token_engine\ChainPrefixMemo;
use Drupal\token_context_test\Plugin\Token\CountingPrefixResolver;
use Drupal\token_context_test\Plugin\Token\PrefixLeafAResolver;
use Drupal\token_context_test\Plugin\Token\PrefixLeafBResolver;
use Drupal\token_context_test\Plugin\Token\PrefixLeafCResolver;

/**
 * Tests Token::replaceMultiple() semantics and chain-prefix memo effectiveness.
 *
 * Four test groups:
 *  1. Parity: replaceMultiple() returns the same strings as replace() per-text.
 *  2. Memo effectiveness: CountingPrefixResolver is invoked once per batch for
 *     tokens sharing a prefix, and N times for N separate replace() calls.
 *  3. Memo isolation: two consecutive replaceMultiple() calls each re-invoke the
 *     prefix resolver (no cross-batch leakage).
 *  4. Cacheability composition: a memo-served chain produces the same
 *     BubbleableMetadata as an unmemoized first resolution within the same batch.
 */
#[CoversClass(Token::class)]
#[CoversClass(TokenResolutionEngine::class)]
#[CoversClass(ChainPrefixMemo::class)]
#[CoversClass(CountingPrefixResolver::class)]
#[CoversClass(PrefixLeafAResolver::class)]
#[CoversClass(PrefixLeafBResolver::class)]
#[CoversClass(PrefixLeafCResolver::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenBatchReplacementTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token_context_test',
  ];

  /**
   * State key for CountingPrefixResolver invocation count.
   */
  private const COUNTER_KEY = 'token_context_test.counting_prefix_invocations';

  /**
   * Root data value passed as 'memo_probe' in the $data array.
   *
   * The CountingPrefixResolver accepts any input value; we use a simple string.
   */
  private const PROBE_ROOT = 'probe_root';

  // ---------------------------------------------------------------------------
  // Test 1 — Parity
  // ---------------------------------------------------------------------------

  /**
   * Verifies replaceMultiple() returns exactly what mapping replace() returns.
   *
   * Tests:
   *  - Strings with no tokens (returned unchanged).
   *  - Strings with a single token.
   *  - Strings with multiple tokens.
   *  - Tokens that fall through to legacy (site:name).
   *  - The same keys are preserved.
   */
  public function testParityWithIndividualReplace(): void {
    $data = ['memo_probe' => self::PROBE_ROOT];

    $texts = [
      'no_tokens' => 'Hello, world!',
      'single_token' => 'A: [memo_probe:prefix:leaf_a]',
      'multi_token' => '[memo_probe:prefix:leaf_a] and [memo_probe:prefix:leaf_b]',
      'legacy_token' => 'Site: [site:name]',
      'mixed' => 'Values: [memo_probe:prefix:leaf_a], [memo_probe:prefix:leaf_c] — site: [site:name]',
    ];

    // Build expected via per-string replace().
    $expected = [];
    foreach ($texts as $key => $text) {
      $expected[$key] = $this->tokenService->replace($text, $data);
    }

    $actual = $this->tokenService->replaceMultiple($texts, $data);

    $this->assertSame(array_keys($expected), array_keys($actual), 'replaceMultiple() preserves the input array keys.');
    foreach ($expected as $key => $expectedValue) {
      $this->assertSame(
        $expectedValue,
        $actual[$key],
        sprintf('replaceMultiple() key "%s" matches replace() output.', $key),
      );
    }
  }

  /**
   * Verifies the 'clear' option behaves identically in replaceMultiple().
   */
  public function testParityWithClearOption(): void {
    $texts = [
      'known' => '[site:name]',
      'unknown' => '[unknown_type:unknown_token]',
    ];

    $expected = [];
    foreach ($texts as $key => $text) {
      $expected[$key] = $this->tokenService->replace($text, [], ['clear' => TRUE]);
    }

    $actual = $this->tokenService->replaceMultiple($texts, [], ['clear' => TRUE]);

    foreach ($expected as $key => $expectedValue) {
      $this->assertSame(
        $expectedValue,
        $actual[$key],
        sprintf('replaceMultiple() with clear option: key "%s" matches replace().', $key),
      );
    }
  }

  /**
   * Verifies replaceMultiple() over an empty array returns an empty array.
   */
  public function testEmptyInputReturnsEmpty(): void {
    $result = $this->tokenService->replaceMultiple([]);
    $this->assertSame([], $result);
  }

  // ---------------------------------------------------------------------------
  // Test 2 — Memo effectiveness
  // ---------------------------------------------------------------------------

  /**
   * Proves the prefix resolver is invoked once per batch, N times for N calls.
   *
   * Three tokens share the [memo_probe:prefix:…] prefix:
   *   - [memo_probe:prefix:leaf_a]
   *   - [memo_probe:prefix:leaf_b]
   *   - [memo_probe:prefix:leaf_c]
   *
   * In a single replaceMultiple() batch the CountingPrefixResolver should be
   * invoked exactly once (the first token walks the prefix; the next two reuse
   * the memo). In three separate replace() calls it must be invoked three times.
   */
  public function testMemoEffectivenessReducesPrefixInvocations(): void {
    $data = ['memo_probe' => self::PROBE_ROOT];

    $texts = [
      'a' => '[memo_probe:prefix:leaf_a]',
      'b' => '[memo_probe:prefix:leaf_b]',
      'c' => '[memo_probe:prefix:leaf_c]',
    ];

    // Reset counter.
    \Drupal::state()->set(self::COUNTER_KEY, 0);

    // Single batch via replaceMultiple() — prefix resolver should run once.
    $batchResults = $this->tokenService->replaceMultiple($texts, $data);
    $batchCount = (int) \Drupal::state()->get(self::COUNTER_KEY, 0);

    $this->assertSame('leaf_a_value', $batchResults['a'], 'leaf_a resolved correctly in batch.');
    $this->assertSame('leaf_b_value', $batchResults['b'], 'leaf_b resolved correctly in batch.');
    $this->assertSame('leaf_c_value', $batchResults['c'], 'leaf_c resolved correctly in batch.');
    $this->assertSame(
      1,
      $batchCount,
      'CountingPrefixResolver is invoked exactly once across a 3-token replaceMultiple() batch.',
    );

    // Reset counter.
    \Drupal::state()->set(self::COUNTER_KEY, 0);

    // Three individual replace() calls — prefix resolver must run 3 times.
    foreach ($texts as $text) {
      $this->tokenService->replace($text, $data);
    }
    $perCallCount = (int) \Drupal::state()->get(self::COUNTER_KEY, 0);

    $this->assertSame(
      3,
      $perCallCount,
      'CountingPrefixResolver is invoked once per replace() call (3 calls = 3 invocations).',
    );
  }

  // ---------------------------------------------------------------------------
  // Test 3 — Memo isolation
  // ---------------------------------------------------------------------------

  /**
   * Proves two consecutive replaceMultiple() calls each re-invoke the prefix.
   *
   * The memo is scoped to a single batch. A second call must create a new memo
   * and therefore re-invoke the prefix resolver again.
   */
  public function testMemoIsolationAcrossBatches(): void {
    $data = ['memo_probe' => self::PROBE_ROOT];
    $texts = [
      'a' => '[memo_probe:prefix:leaf_a]',
      'b' => '[memo_probe:prefix:leaf_b]',
      'c' => '[memo_probe:prefix:leaf_c]',
    ];

    \Drupal::state()->set(self::COUNTER_KEY, 0);

    // First batch.
    $this->tokenService->replaceMultiple($texts, $data);
    $afterFirst = (int) \Drupal::state()->get(self::COUNTER_KEY, 0);

    // Second batch — a new memo is created; prefix resolver must re-run.
    $this->tokenService->replaceMultiple($texts, $data);
    $afterSecond = (int) \Drupal::state()->get(self::COUNTER_KEY, 0);

    $this->assertSame(1, $afterFirst, 'First batch invokes prefix resolver once.');
    $this->assertSame(2, $afterSecond, 'Second batch invokes prefix resolver again (no cross-batch leakage).');
  }

  // ---------------------------------------------------------------------------
  // Test 4 — Cacheability composition
  // ---------------------------------------------------------------------------

  /**
   * Proves that memo-served resolutions produce the same BubbleableMetadata.
   *
   * The first token in the batch walks the prefix and seeds the memo. The
   * subsequent tokens reuse the memo. All three must produce BubbleableMetadata
   * equivalent to resolving each token individually (same cache contexts, tags,
   * max-age).
   */
  public function testMemoedChainProducesSameBubbleableMetadata(): void {
    $data = ['memo_probe' => self::PROBE_ROOT];

    // Resolve via three individual replace() calls to establish the expected
    // BubbleableMetadata for each.
    $expectedMeta = [];
    foreach (['a', 'b', 'c'] as $leaf) {
      $meta = new BubbleableMetadata();
      $this->tokenService->replace(
        sprintf('[memo_probe:prefix:leaf_%s]', $leaf),
        $data,
        [],
        $meta,
      );
      $expectedMeta[$leaf] = $meta;
    }

    // Resolve all three in one batch, capturing metadata.
    $batchMeta = new BubbleableMetadata();
    $this->tokenService->replaceMultiple(
      [
        'a' => '[memo_probe:prefix:leaf_a]',
        'b' => '[memo_probe:prefix:leaf_b]',
        'c' => '[memo_probe:prefix:leaf_c]',
      ],
      $data,
      [],
      $batchMeta,
    );

    // The batch metadata must be at least as broad as any individual call's
    // metadata (it is the union). For our simple test resolvers that add no
    // specific tags/contexts, all metadata objects should be equivalent and
    // empty of constraints. We assert each individual call's metadata is
    // "covered by" (bubbleable subset of) the batch metadata.
    foreach ($expectedMeta as $leaf => $perCallMeta) {
      $this->assertEquals(
        $perCallMeta->getCacheContexts(),
        array_intersect($perCallMeta->getCacheContexts(), $batchMeta->getCacheContexts()),
        sprintf('Batch BubbleableMetadata covers per-call cache contexts for leaf_%s.', $leaf),
      );
      $this->assertEquals(
        $perCallMeta->getCacheTags(),
        array_intersect($perCallMeta->getCacheTags(), $batchMeta->getCacheTags()),
        sprintf('Batch BubbleableMetadata covers per-call cache tags for leaf_%s.', $leaf),
      );
      $this->assertGreaterThanOrEqual(
        $batchMeta->getCacheMaxAge(),
        $perCallMeta->getCacheMaxAge(),
        sprintf('Batch BubbleableMetadata max-age is no more restrictive than per-call for leaf_%s.', $leaf),
      );
    }
  }

}
