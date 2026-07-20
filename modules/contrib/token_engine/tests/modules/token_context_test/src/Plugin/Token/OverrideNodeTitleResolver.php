<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

/**
 * Deliberately declares (entity:node, title) to collide with field discovery.
 *
 * The node 'title' field is also auto-discovered by typed-data discovery. This
 * attributed resolver exists to prove that an explicit declaration wins over
 * auto-discovery for the same (input_type, name) identity, rather than being
 * silently overridden by it.
 */
#[Token(
  name: 'title',
  input_type: 'entity:node',
  output_type: 'string',
  label: new TranslatableMarkup('Title (overridden)'),
)]
final class OverrideNodeTitleResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('overridden-title');
  }

}
