<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

/**
 * Value object holding the accumulated state at the end of a memoized prefix.
 *
 * @internal
 */
final class MemoEntry {

  /**
   * @param \Drupal\Core\Token\TokenResult $accumulated
   *   The accumulated TokenResult (value, cacheability, access) at the end of
   *   the memoized prefix walk.
   * @param mixed $currentInput
   *   The resolved input value produced at the end of the prefix walk.
   * @param string $currentType
   *   The output type at the end of the prefix walk.
   */
  public function __construct(
    public readonly TokenResult $accumulated,
    public readonly mixed $currentInput,
    public readonly string $currentType,
  ) {}

}
