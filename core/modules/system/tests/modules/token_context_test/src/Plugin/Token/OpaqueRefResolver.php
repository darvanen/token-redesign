<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * A token whose output type has no further registered tokens.
 *
 * Used to prove the "unverifiable chain" path: a chain that continues past this
 * token (e.g. [placement_probe:opaque:more]) lands on the opaque output type,
 * which is neither a scalar nor a type the registry can traverse, so the
 * placement validator cannot determine whether the chain reaches sensitive
 * data. This models a polymorphic reference whose target type is not statically
 * known.
 */
#[Token(
  name: 'opaque',
  input_type: 'placement_probe',
  output_type: 'placement_opaque',
  label: new TranslatableMarkup('Opaque reference'),
)]
final class OpaqueRefResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('opaque');
  }

}
