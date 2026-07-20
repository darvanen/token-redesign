<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Per-batch memoization of resolved chain prefixes.
 *
 * When multiple token strings share a chain prefix (e.g. [og:image],
 * [og:image:width], [og:image:height] all share the walk from the root to
 * "image"), the engine can look up the partial result of the shared walk
 * instead of re-invoking the same resolvers for every token.
 *
 * One instance is created per resolution batch (one replaceMultiple() call, or
 * one generate() call when not using the batch path). The scope is intentionally
 * aligned with the lifetime of the TokenResolutionContext that runs under the
 * same batch: viewer, options, and langcode are fixed within a batch, which is
 * the precondition that makes reuse safe.
 *
 * Memo keys encode:
 *   {rootType}|{rootInputIdentity}|{segment0}:{segment1}:…
 *
 * where rootInputIdentity is spl_object_id() for objects and
 * serialize() for scalars. This ensures memoized state from one root object
 * is never reused for a different root object of the same type within the
 * same batch.
 *
 * Only successful structural steps are memoized. A NULL result (chain fell
 * through to legacy) is never stored here — the engine's normal NULL return
 * signals the fall-through path, and that is already cheap.
 *
 * @internal
 */
final class ChainPrefixMemo {

  /**
   * Memoized prefix states, keyed by memo key.
   *
   * Each entry is a MemoEntry recording the accumulated TokenResult, the
   * current input value, and the current type at the end of the prefix walk.
   *
   * @var array<string, MemoEntry>
   */
  private array $entries = [];

  /**
   * Stores the accumulated walk state for the given prefix.
   *
   * @param string $rootType
   *   The root type the chain started from (e.g. 'entity:node').
   * @param mixed $rootInput
   *   The root input value, used to compute the identity key.
   * @param string[] $prefixSegments
   *   The prefix segments that have been successfully resolved (up to and
   *   including the segment just resolved).
   * @param \Drupal\token_engine\TokenResult $accumulated
   *   The accumulated TokenResult after resolving all prefix segments.
   * @param mixed $currentInput
   *   The input value produced at the end of the prefix walk.
   * @param string $currentType
   *   The output type at the end of the prefix walk.
   */
  public function store(
    string $rootType,
    mixed $rootInput,
    array $prefixSegments,
    TokenResult $accumulated,
    mixed $currentInput,
    string $currentType,
  ): void {
    $key = $this->makeKey($rootType, $rootInput, $prefixSegments);
    $this->entries[$key] = new MemoEntry($accumulated, $currentInput, $currentType);
  }

  /**
   * Looks up the longest memoized prefix for the given chain.
   *
   * Scans from the longest prefix down to the single-segment prefix, returning
   * the first (longest) match found. Returns NULL when nothing is memoized for
   * this chain.
   *
   * @param string $rootType
   *   The root type the chain starts from.
   * @param mixed $rootInput
   *   The root input value.
   * @param string[] $segments
   *   The full chain segments.
   *
   * @return array{entry: \Drupal\token_engine\MemoEntry, depth: int}|null
   *   An associative array with the matched MemoEntry and the number of
   *   segments it covers, or NULL when nothing is memoized.
   */
  public function lookup(string $rootType, mixed $rootInput, array $segments): ?array {
    // Try from longest possible prefix down to length 1.
    $count = count($segments);
    for ($len = $count - 1; $len >= 1; $len--) {
      $prefix = array_slice($segments, 0, $len);
      $key = $this->makeKey($rootType, $rootInput, $prefix);
      if (isset($this->entries[$key])) {
        return ['entry' => $this->entries[$key], 'depth' => $len];
      }
    }
    return NULL;
  }

  /**
   * Builds the memo key for a root type, input identity, and prefix segments.
   *
   * @param string $rootType
   *   The root type.
   * @param mixed $rootInput
   *   The root input value.
   * @param string[] $prefixSegments
   *   The prefix segments.
   *
   * @return string
   *   The memo key.
   */
  private function makeKey(string $rootType, mixed $rootInput, array $prefixSegments): string {
    $identity = is_object($rootInput)
      ? (string) spl_object_id($rootInput)
      : serialize($rootInput);
    return $rootType . '|' . $identity . '|' . implode(':', $prefixSegments);
  }

}
