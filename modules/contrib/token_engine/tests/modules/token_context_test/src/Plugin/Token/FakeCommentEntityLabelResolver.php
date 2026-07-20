<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResult;
use Drupal\token_engine\TokenResolverInterface;

/**
 * Resolver for the 'label' segment downstream of a fake_comment:entity token.
 *
 * This resolver demonstrates that a segment can read data from the context that
 * was placed there by a preceding segment (FakeCommentEntityResolver). Rather
 * than requiring the engine to pass the entity as the $input value, this
 * resolver reads the entity from the context under 'fake_comment_entity'.
 *
 * This proves the full comment:entity chain:
 *  [fake_comment:entity:label]
 *    -> FakeCommentEntityResolver stores entity in context
 *    -> FakeCommentEntityLabelResolver reads entity from context, returns label
 */
#[Token(
  name: 'label',
  input_type: 'fake_comment_entity',
  output_type: 'string',
  label: new TranslatableMarkup('Entity label'),
)]
final class FakeCommentEntityLabelResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   *
   * Reads the entity stored in the context by FakeCommentEntityResolver and
   * returns its label. Falls back to the $input value if it is already an
   * EntityInterface (allowing direct use without the context shortcut).
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    // Prefer the entity stored in context (set by FakeCommentEntityResolver).
    $entity = $context->get('fake_comment_entity');

    // Fall back to $input if it is already the entity.
    if (!($entity instanceof EntityInterface) && $input instanceof EntityInterface) {
      $entity = $input;
    }

    if (!($entity instanceof EntityInterface)) {
      return TokenResult::fromValue('');
    }

    return TokenResult::fromValue($entity->label() ?? '');
  }

}
