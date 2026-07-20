<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

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
