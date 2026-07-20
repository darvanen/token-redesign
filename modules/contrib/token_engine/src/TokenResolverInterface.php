<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Contract for attributed token resolvers.
 *
 * Every class decorated with #[Token] must implement this interface unless it
 * extends PathToken, which provides a shared implementation for the pure-path
 * case. Resolvers should not write global replacement logic; they declare only
 * their own segment's contribution.
 */
interface TokenResolverInterface {

  /**
   * Resolves this token against an input value.
   *
   * @param mixed $input
   *   The typed value produced by the previous chain segment, corresponding to
   *   this token's declared input_type. NULL for root tokens (no input type).
   * @param array<string, mixed> $arguments
   *   Any arguments parsed from the token string (e.g. a date format string
   *   for a custom date token). Empty array when none are present.
   * @param \Drupal\token_engine\TokenResolutionContext $context
   *   The mutable resolution context. Use $context->set() to attach data that
   *   the next segment in the chain needs. Do not assume the current user;
   *   use $context->actor for access checking.
   *
   * @return \Drupal\token_engine\TokenResult
   *   A structured result carrying the output value, cacheability metadata,
   *   and an access result. The engine composes these automatically across
   *   all segments in the chain.
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult;

}
