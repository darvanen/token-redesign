<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

/**
 * A declared token that gates its own placement on an author permission.
 *
 * Used to prove the placement constraint for the declared-token path: the token
 * always resolves to a value, but an author may only place it when they hold
 * 'place secret tokens'. This is the explicit-declaration analogue of a field
 * token whose placement is gated by field access.
 */
#[Token(
  name: 'secret',
  input_type: 'placement_probe',
  output_type: 'string',
  label: new TranslatableMarkup('Placement-gated secret'),
  place_permission: 'place secret tokens',
)]
final class PlacementGatedSecretResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('s3cr3t');
  }

}
