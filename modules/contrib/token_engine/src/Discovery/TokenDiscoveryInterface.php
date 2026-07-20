<?php

declare(strict_types=1);

namespace Drupal\token_engine\Discovery;

/**
 * Contract for a single token discovery source.
 *
 * Multiple implementations may be registered and composed by the registry.
 * Each source is responsible for a specific discovery strategy — tagged
 * services, typed-data field introspection, config entity definitions, etc.
 * The registry merges results from all sources and dispatches
 * \Drupal\token_engine\Event\TokenDiscoveryAlterEvent before caching.
 *
 * Definitions are keyed by the canonical identity key returned by
 * \Drupal\token_engine\TokenDefinition::getIdentityKey(), i.e. "{input_type}:{name}".
 * Never key by name alone.
 */
interface TokenDiscoveryInterface {

  /**
   * Discovers the token definitions this source provides for one input type.
   *
   * This is the lazy entry point used during chain traversal: the registry asks
   * each source only for the input type currently being resolved, so the full
   * token set is never built up front.
   *
   * @param string $inputType
   *   The input type to discover tokens for (e.g. 'entity:node').
   *
   * @return array<string, \Drupal\token_engine\TokenDefinition>
   *   Token definitions for this input type, keyed by token name. Empty when
   *   this source provides nothing for the given input type.
   */
  public function discoverForInputType(string $inputType): array;

}
