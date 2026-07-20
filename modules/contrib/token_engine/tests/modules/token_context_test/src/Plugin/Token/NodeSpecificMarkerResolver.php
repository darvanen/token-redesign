<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

/**
 * A same-named token declared at the concrete 'entity:node' type.
 *
 * This exists to prove most-specific-wins: for a chain that reaches
 * 'entity:node', this definition must be returned in preference to
 * GenericEntityMarkerResolver's ancestor-level 'entity' definition of the same
 * name, because the concrete slice is checked before any ancestor walk. Chains
 * that reach a different concrete entity type (e.g. entity:user) are
 * unaffected and still fall through to the ancestor-level definition.
 *
 * @see \Drupal\token_context_test\Plugin\Token\GenericEntityMarkerResolver
 */
#[Token(
  name: 'generic_marker',
  input_type: 'entity:node',
  output_type: 'string',
  label: new TranslatableMarkup('Node-specific marker'),
)]
final class NodeSpecificMarkerResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!($input instanceof EntityInterface)) {
      return TokenResult::fromValue('');
    }
    return TokenResult::fromValue('NODE_SPECIFIC:' . $input->label());
  }

}
