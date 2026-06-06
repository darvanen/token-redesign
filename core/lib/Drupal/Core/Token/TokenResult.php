<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Immutable value object carrying the result of resolving one token segment.
 *
 * Resolvers return a TokenResult rather than a bare value so that cacheability
 * metadata and access results compose automatically as the chain is traversed.
 * Every provider only declares its own contribution; the engine does the rest.
 */
final class TokenResult {

  /**
   * @param mixed $value
   *   The resolved value, typed according to the token's output_type.
   * @param \Drupal\Core\Cache\CacheableMetadata $cacheability
   *   Cacheability metadata contributed by this segment.
   * @param \Drupal\Core\Access\AccessResultInterface $access
   *   Access result for this segment.
   * @param string|null $outputType
   *   The output type of $value (e.g. 'string', 'entity:user', 'timestamp').
   *   Carried through the chain so the renderer can serialize the terminal
   *   value with the correct per-type default. NULL when unknown.
   */
  public function __construct(
    public readonly mixed $value,
    public readonly CacheableMetadata $cacheability,
    public readonly AccessResultInterface $access,
    public readonly ?string $outputType = NULL,
  ) {}

  /**
   * Creates a simple result with no cacheability constraints and full access.
   */
  public static function fromValue(mixed $value): static {
    return new static($value, new CacheableMetadata(), AccessResult::allowed());
  }

  /**
   * Returns a new result tagged with the given output type.
   *
   * The engine sets this on the accumulated result once chain traversal
   * completes, so the renderer knows how to serialize the terminal value.
   */
  public function withOutputType(?string $outputType): static {
    return new static($this->value, $this->cacheability, $this->access, $outputType);
  }

  /**
   * Returns a new result with access narrowed by an AND intersection.
   */
  public function withAccess(AccessResultInterface $more): static {
    return new static(
      $this->value,
      $this->cacheability,
      $this->access->andIf($more),
      $this->outputType,
    );
  }

  /**
   * Returns a new result composing this metadata with a downstream segment.
   *
   * This is the engine's composition operation during chain traversal: each
   * segment's cacheability is merged into the accumulator, access is ANDed,
   * and the downstream value becomes the new value.
   */
  public function merge(TokenResult $downstream): static {
    $cacheability = clone $this->cacheability;
    $cacheability->addCacheableDependency($downstream->cacheability);
    return new static(
      $downstream->value,
      $cacheability,
      $this->access->andIf($downstream->access),
      $downstream->outputType,
    );
  }

}
