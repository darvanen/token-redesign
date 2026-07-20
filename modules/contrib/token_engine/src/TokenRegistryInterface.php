<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Provides token definitions sliced by input type, loaded lazily.
 *
 * The full token set is never loaded at once. During chain traversal only the
 * slice for the current input type is fetched and cached. This mirrors the
 * views-data pattern and directly addresses the monolithic token-info cache
 * problem in the legacy system.
 *
 * Token identity is always (input_type, name) – never name alone. Callers
 * must always pass both components.
 */
interface TokenRegistryInterface {

  /**
   * Returns all token definitions registered for the given input type.
   *
   * Results are cached per-type per-language and loaded on first access.
   * Only slices required during the current chain traversal are ever loaded.
   *
   * @param string $inputType
   *   The input type (e.g. 'entity:node', 'timestamp', ''  for root tokens).
   *
   * @return \Drupal\token_engine\TokenDefinition[]
   *   Token definitions keyed by token name.
   */
  public function getTokensForInputType(string $inputType): array;

  /**
   * Returns the definition for a specific (input_type, name) pair.
   *
   * Includes legacy hook_token_info() definitions, so this is the method for
   * token-info and browser use cases. Returns NULL if no definition exists.
   * Never pass name alone; always include the input_type.
   */
  public function getToken(string $inputType, string $name): ?TokenDefinition;

  /**
   * Returns a resolvable (new-system) definition for an (input_type, name) pair.
   *
   * Unlike getToken(), this consults only the discovery sources (attributed
   * resolvers and typed-data field discovery) and the discovery-alter event; it
   * never builds the legacy hook_token_info() set. This is the path the
   * resolution engine uses for routing and chain traversal during replacement,
   * so a token replacement never triggers a full hook_token_info() build.
   * Tokens absent here fall through to the legacy hook pipeline.
   *
   * The lookup is by assignability, not exact equality: when $inputType has no
   * definition of $name, each ancestor of $inputType (produced by
   * progressively stripping a trailing ':<segment>', e.g. 'entity:node' is an
   * ancestor of 'entity:node:article') is checked in order, and the first hit
   * wins. This lets a token declared once against a general type (e.g.
   * 'entity') serve every more specific type ('entity:node', 'entity:user',
   * ...), while a same-named declaration against the specific type still takes
   * precedence. This does not apply to getToken(), which is unaffected.
   *
   * Returns NULL when no resolvable definition is registered for this identity
   * or any of its ancestors.
   */
  public function getResolvableToken(string $inputType, string $name): ?TokenDefinition;

  /**
   * Invalidates the registry cache for all types and languages.
   */
  public function invalidate(): void;

}
