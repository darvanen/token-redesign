<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * Emits a fixed value carrying a German umlaut for locale-rendering tests.
 *
 * The value ("Zürich") romanises differently per language, so resolving this
 * token to a URL slug with different langcodes proves the 'langcode' option is
 * threaded through the engine to the renderer's transliteration step.
 */
#[Token(
  name: 'city',
  input_type: 'slug_probe',
  output_type: 'string',
  label: new TranslatableMarkup('Slug probe city'),
)]
final class SlugProbeCityResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('Zürich');
  }

}
