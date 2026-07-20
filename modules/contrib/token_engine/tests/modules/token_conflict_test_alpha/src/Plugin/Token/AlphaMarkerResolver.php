<?php

declare(strict_types=1);

namespace Drupal\token_conflict_test_alpha\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

/**
 * Deliberately claims the same identity as BetaMarkerResolver.
 *
 * Used by \Drupal\Tests\token_engine\Kernel\TokenIdentityConflictTest to prove
 * \Drupal\token_engine\TokenResolverManager resolves identity (input_type,
 * name) conflicts between `#[Token]` classes deterministically, rather than
 * letting discovery order silently decide the winner.
 *
 * @see \Drupal\token_conflict_test_beta\Plugin\Token\BetaMarkerResolver
 */
#[Token(
  name: 'marker',
  input_type: 'token_conflict_probe',
  output_type: 'string',
  label: new TranslatableMarkup('Conflict probe marker (alpha)'),
)]
final class AlphaMarkerResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('from-alpha');
  }

}
