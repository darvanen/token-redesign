<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\TypedData\ListInterface;

/**
 * Built-in resolver that extracts a delta-indexed item from a list value.
 *
 * This resolver handles the numeric segment in token chains such as:
 *   [node:field_tags:0:name]
 *
 * The `0` segment is not a typed-data property – it is an index operation on
 * a multi-value (list) output. Rather than requiring every list-producing token
 * to handle numeric segments individually, this resolver provides a single
 * built-in implementation that can be registered for any `list<T>` input type.
 *
 * Usage: register a TokenDefinition whose resolverClass points to this class,
 * with an integer-string name (e.g. '0', '1', '2') and input_type of
 * `list<some-type>`. The delta is determined by the token name itself (cast to
 * int), which can be overridden by passing `$arguments['delta']`.
 *
 * Out-of-range access returns TokenResult::fromValue('') rather than throwing.
 *
 * Input types accepted:
 *  - PHP array with numeric keys (the common case for field item lists).
 *  - \Drupal\Core\TypedData\ListInterface (the typed-data list type).
 *
 * The returned value is the item at the requested index. For TypedData lists
 * the item is the typed-data object itself; the caller's next segment can
 * traverse into it using a PathToken or another resolver.
 */
final class ListDeltaResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   *
   * @param mixed $input
   *   A PHP array with numeric keys or a TypedData ListInterface.
   * @param array<string, mixed> $arguments
   *   May contain 'delta' (int|string) to override the index. When absent,
   *   the resolver relies on the token name to carry the delta; callers that
   *   cannot pass the name here should supply 'delta' explicitly.
   * @param \Drupal\token_engine\TokenResolutionContext $context
   *   The resolution context. Not modified by this resolver.
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!is_array($input) && !($input instanceof ListInterface)) {
      return TokenResult::fromValue('');
    }

    // The delta comes from $arguments['delta'] when supplied, otherwise from the
    // token name itself (the numeric segment, e.g. the '0' in [field:0:value]),
    // which the engine passes as $arguments['name'].
    $delta = NULL;
    if (isset($arguments['delta'])) {
      $delta = (int) $arguments['delta'];
    }
    elseif (isset($arguments['name']) && is_numeric($arguments['name'])) {
      $delta = (int) $arguments['name'];
    }

    if ($delta === NULL) {
      // No delta supplied; return empty rather than guessing.
      return TokenResult::fromValue('');
    }

    if ($delta < 0) {
      return TokenResult::fromValue('');
    }

    if ($input instanceof ListInterface) {
      $item = $input->get($delta);
      if ($item === NULL) {
        return TokenResult::fromValue('');
      }
      return TokenResult::fromValue($item);
    }

    // Plain array case.
    if (!array_key_exists($delta, $input)) {
      return TokenResult::fromValue('');
    }

    return TokenResult::fromValue($input[$delta]);
  }

}
