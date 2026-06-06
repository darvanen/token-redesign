<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * Produces a multi-value list output for exercising the delta index operation.
 *
 * The chain [fake_list:items:0] resolves 'items' to a list<string> and then the
 * numeric '0' segment selects the delta via the engine's built-in list index
 * operation (ListDeltaResolver), with no per-token delta handling.
 */
#[Token(
  name: 'items',
  input_type: 'fake_list',
  output_type: 'list<string>',
  label: new TranslatableMarkup('List items'),
)]
final class FakeListResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue(['alpha', 'beta', 'gamma']);
  }

}
