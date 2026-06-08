<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

/**
 * Maps between entity type ids and token type names.
 *
 * Most token types share their entity type id ('node', 'user'), but a few do
 * not: the taxonomy term entity type is 'taxonomy_term' while its token type is
 * 'term'. Resolution sidesteps this because it derives the input type from a
 * live entity object, but anything reasoning about types without an object (the
 * token browser deciding which tokens are relevant to an available context)
 * needs the mapping explicitly. This is the core counterpart of the contrib
 * token module's token.entity_mapper.
 *
 * @internal
 */
final class TokenEntityTypeMapper {

  /**
   * Token types that differ from their entity type id, keyed by entity type id.
   *
   * @var array<string, string>
   */
  private const ALIASES = [
    'taxonomy_term' => 'term',
    'taxonomy_vocabulary' => 'vocabulary',
  ];

  /**
   * Returns the token type for an entity type id.
   */
  public function getTokenType(string $entityTypeId): string {
    return self::ALIASES[$entityTypeId] ?? $entityTypeId;
  }

  /**
   * Returns the entity type id for a token type, or the type itself if none.
   */
  public function getEntityTypeId(string $tokenType): string {
    return array_flip(self::ALIASES)[$tokenType] ?? $tokenType;
  }

}
