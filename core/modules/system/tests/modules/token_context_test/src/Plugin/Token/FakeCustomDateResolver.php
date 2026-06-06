<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * A trailing-argument token: formats a timestamp with a custom format string.
 *
 * This proves the [type:...:custom:Y-m-d] pattern from the design brief, where
 * 'custom' consumes the remainder of the chain ('Y-m-d') as a single 'format'
 * argument rather than treating it as a further chain segment. The argument is
 * declared via the #[Token] attribute's argument_name; the engine joins the
 * remaining segments and passes them under that key, then terminates traversal.
 */
#[Token(
  name: 'custom',
  input_type: 'timestamp',
  output_type: 'string',
  label: new TranslatableMarkup('Custom date format'),
  argument_name: 'format',
)]
final class FakeCustomDateResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $format = $arguments['format'] ?? 'Y-m-d';
    $timestamp = is_numeric($input) ? (int) $input : 0;
    // Use gmdate so the test is deterministic regardless of the runtime timezone.
    return TokenResult::fromValue(gmdate($format, $timestamp));
  }

}
