<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Render\BubbleableMetadata;

/**
 * Contract for the token resolution engine.
 *
 * The engine orchestrates chain traversal: it consults the registry for
 * attributed-resolver registrations, invokes those resolvers and walks the
 * chain structurally, falls back to the legacy hook pipeline for tokens not yet
 * migrated, and composes cacheability and access results across all segments.
 */
interface TokenResolutionEngineInterface {

  /**
   * Generates replacement values for a list of tokens.
   *
   * This signature is intentionally compatible with Token::generate() so that
   * Token.php can delegate here with no change to its own public API.
   *
   * @param string $type
   *   The token type (e.g. 'node', 'user').
   * @param array $tokens
   *   Tokens to replace, keyed by token name with raw token string as value.
   * @param array $data
   *   Keyed data objects (e.g. ['node' => $node]).
   * @param array $options
   *   Options such as 'langcode' and 'clear'.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata
   *   Bubbleable metadata accumulator; mutated in place.
   *
   * @return array
   *   Replacement values keyed by the raw token string.
   */
  public function generate(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array;

  /**
   * Resolves a chain of token segments to a final TokenResult.
   *
   * Each segment's output type must match the next segment's input type.
   * Cacheability and access from all segments are composed into the result.
   * The resolution context carries data between segments; resolvers may
   * attach computed values via $context->set() for downstream segments.
   *
   * @param string $rootType
   *   The input type for the first segment (e.g. 'entity:node').
   * @param string[] $segments
   *   The chain segments in order (e.g. ['field_author', 'entity', 'mail']).
   * @param mixed $rootInput
   *   The initial input value for the first resolver.
   * @param \Drupal\token_engine\TokenResolutionContext $context
   *   The mutable resolution context carrying actor, data, and options.
   *
   * @return \Drupal\token_engine\TokenResult|null
   *   The resolved result, or NULL when a segment is not in the registry and
   *   the chain cannot be completed structurally (caller should fall back to
   *   legacy).
   */
  public function resolveChain(string $rootType, array $segments, mixed $rootInput, TokenResolutionContext $context): ?TokenResult;

}
