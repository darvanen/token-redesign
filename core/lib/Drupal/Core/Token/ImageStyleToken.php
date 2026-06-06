<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Token resolver that applies a named image style to an image URI.
 *
 * This is the canonical example of a type-level operation: image style tokens
 * are registered against the 'image' output type rather than against any
 * individual field or entity. One resolver class handles ALL configured image
 * styles. The style to apply is identified either via $arguments['style'] or,
 * when this resolver is registered once per style, via the token name itself.
 *
 * Registration pattern (via TokenDiscoveryAlterEvent subscriber):
 * @code
 * foreach ($imageStyleStorage->loadMultiple() as $style) {
 *   $event->addDefinition(new TokenDefinition(
 *     name: $style->id(),
 *     inputType: 'image',
 *     outputType: 'string',
 *     label: $style->label(),
 *     resolverClass: ImageStyleToken::class,
 *   ));
 * }
 * @endcode
 *
 * When invoked, the engine passes the style name as $arguments['style']. If
 * $arguments['style'] is absent the token name (from the definition) is used as
 * the style ID, allowing a single resolver class to serve any number of styles
 * without per-style subclasses.
 *
 * Input type: 'image' (a URI string or any value castable to a URI string).
 * Output type: 'string' (the absolute styled-image URL).
 *
 * If the image style entity does not exist or the input URI is empty, an empty
 * string is returned so token replacement degrades gracefully.
 */
final class ImageStyleToken implements TokenResolverInterface {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager used to load image style config entities.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   *
   * Resolves an image style token against an image URI.
   *
   * The style to apply is read from $arguments['style']. When that key is
   * absent the method returns an empty string; callers that register one token
   * per style should pass the style machine name as $arguments['style'].
   *
   * @param mixed $input
   *   The image URI string. Non-string values are cast via (string); empty
   *   strings result in an empty TokenResult.
   * @param array<string, mixed> $arguments
   *   Must contain 'style' with the image style machine name.
   * @param \Drupal\Core\Token\TokenResolutionContext $context
   *   The resolution context (unused by this resolver).
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $uri = is_string($input) ? $input : (string) $input;
    if ($uri === '') {
      return TokenResult::fromValue('');
    }

    // The style is taken from $arguments['style'] when supplied, otherwise from
    // the token name itself (one definition per configured style, all sharing
    // this resolver class), which the engine passes as $arguments['name'].
    $styleId = $arguments['style'] ?? $arguments['name'] ?? NULL;
    if ($styleId === NULL || $styleId === '') {
      return TokenResult::fromValue('');
    }

    /** @var \Drupal\image\ImageStyleInterface|null $style */
    $style = $this->entityTypeManager
      ->getStorage('image_style')
      ->load($styleId);

    if ($style === NULL) {
      return TokenResult::fromValue('');
    }

    $url = $style->buildUrl($uri);

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($style);

    return new TokenResult(
      value: $url,
      cacheability: $cacheability,
      access: AccessResult::allowed(),
      outputType: 'string',
    );
  }

}
