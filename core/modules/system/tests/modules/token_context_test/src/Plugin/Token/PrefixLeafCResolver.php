<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * Third leaf token off memo_probe_prefixed, used to prove memo effectiveness.
 *
 * Chain: [memo_probe:prefix:leaf_c]
 */
#[Token(
  name: 'leaf_c',
  input_type: 'memo_probe_prefixed',
  output_type: 'string',
  label: new TranslatableMarkup('Prefix leaf C'),
)]
final class PrefixLeafCResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('leaf_c_value');
  }

}
