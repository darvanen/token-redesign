<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Immutable metadata describing a single token.
 *
 * A token is uniquely identified by the pair (input_type, name). Never key
 * a registry, cache, or lookup by name alone – that is a bug against the model.
 * Use getIdentityKey() to derive a canonical compound key.
 */
final class TokenDefinition {

  /**
   * @param string $name
   *   The token name (a single colon-delimited segment, e.g. 'title').
   * @param string $inputType
   *   The input type accepted by this token (e.g. 'entity:node'). Empty string
   *   for root tokens that require no input.
   * @param string $outputType
   *   The output type produced (e.g. 'string', 'entity:user', 'timestamp').
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   Human-readable label shown in the token browser.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $description
   *   Human-readable description shown in the token browser.
   * @param string|null $resolverClass
   *   FQN of the TokenResolverInterface implementation, if using an attributed
   *   resolver. NULL for legacy-bridge tokens.
   * @param string|null $path
   *   Typed-data property path for path-based resolvers (PathToken base class).
   * @param string|null $module
   *   Module that provides this token (informational; used by token browser).
   * @param string|null $argumentName
   *   When set, this token consumes the remainder of the chain as a single
   *   argument under this key and terminates traversal. See the #[Token]
   *   attribute's $argument_name for the full explanation.
   * @param string|null $placePermission
   *   Permission an author must hold to place this token, or NULL for no
   *   placement restriction. See the #[Token] attribute's $place_permission.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $inputType,
    public readonly string $outputType,
    public readonly TranslatableMarkup|string|null $label = NULL,
    public readonly TranslatableMarkup|string|null $description = NULL,
    public readonly ?string $resolverClass = NULL,
    public readonly ?string $path = NULL,
    public readonly ?string $module = NULL,
    public readonly ?string $argumentName = NULL,
    public readonly ?string $placePermission = NULL,
  ) {}

  /**
   * Returns the canonical identity key: "{input_type}:{name}".
   *
   * This is the token's full identity and matches its attributed-plugin ID.
   * Per-input-type registry slices key entries by name alone because the input
   * type is implicit in the slice; anywhere a definition is held outside such a
   * slice, identify it by this key, never by name alone.
   */
  public function getIdentityKey(): string {
    return $this->inputType . ':' . $this->name;
  }

}
