<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

/**
 * Mutable context that accumulates data as a token chain is traversed.
 *
 * Each resolver in a chain receives this context, may read from it, and must
 * attach whatever data the next segment needs before returning. Resolvers hand
 * downstream resolvers data through this object, including computed or composed
 * values that are not typed-data properties (e.g. comment:entity, which is
 * assembled from two separate fields with no DER in core).
 *
 * The data and options accessors are the attributed-resolver equivalent of the
 * $data and $options arrays a legacy hook_tokens() implementation receives, so
 * a resolver can read 'langcode', 'clear', or caller-supplied options. Core's
 * own resolvers do not need them yet; they are the contrib-facing surface.
 */
final class TokenResolutionContext {

  /**
   * Resolved and input data keyed by type or arbitrary string.
   *
   * @var array<string, mixed>
   */
  private array $data;

  /**
   * @param array $data
   *   Initial data keyed by type or arbitrary string (e.g. 'node' => $node).
   * @param \Drupal\Core\Token\ActorContext $actor
   *   The viewer the output is access-checked against.
   * @param \Drupal\Core\Token\OutputContext $outputContext
   *   The intended rendering context.
   * @param array<string, mixed> $options
   *   The replacement options, as passed to Token::replace() (e.g. 'langcode').
   * @param string|null $langcode
   *   The language code this resolution runs for: the 'langcode' option when
   *   the caller supplied one, otherwise the current content language as
   *   resolved by the engine. Entity values are translated to this language as
   *   the chain is walked, so resolvers receive the translation matching the
   *   language being resolved rather than whatever translation the caller
   *   happened to pass in. NULL only when a context is constructed outside the
   *   engine without a language; consumers should treat NULL as "current
   *   content language".
   * @param bool $langcodeVariesByRequest
   *   TRUE when $langcode was resolved from the current content language (the
   *   caller supplied no 'langcode' option and the root input carried no
   *   language of its own), meaning any translation selected with it varies by
   *   the request's language and must declare the content language cache
   *   context. FALSE when the langcode is pinned by the caller or by the root
   *   entity.
   */
  public function __construct(
    array $data,
    public readonly ActorContext $actor,
    public readonly OutputContext $outputContext = OutputContext::Html,
    private readonly array $options = [],
    public readonly ?string $langcode = NULL,
    public readonly bool $langcodeVariesByRequest = FALSE,
  ) {
    $this->data = $data;
  }

  /**
   * Returns all data in this context.
   *
   * @return array<string, mixed>
   *   All data in this context, keyed by type or arbitrary string.
   */
  public function getData(): array {
    return $this->data;
  }

  /**
   * Returns a single data entry or NULL if not set.
   */
  public function get(string $key): mixed {
    return $this->data[$key] ?? NULL;
  }

  /**
   * Attaches a value under a key so downstream resolvers can access it.
   *
   * This is the primary mechanism for a resolver to carry computed data
   * forward. For example, the comment 'entity' segment assembles its value
   * from two fields and writes the resulting entity here so the next segment
   * can traverse into it.
   */
  public function set(string $key, mixed $value): void {
    $this->data[$key] = $value;
  }

  /**
   * Returns all options.
   *
   * @return array<string, mixed>
   *   All options passed to the resolution call.
   */
  public function getOptions(): array {
    return $this->options;
  }

  /**
   * Returns a single option value, or $default if not set.
   */
  public function getOption(string $key, mixed $default = NULL): mixed {
    return $this->options[$key] ?? $default;
  }

}
