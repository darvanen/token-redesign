<?php

declare(strict_types=1);

namespace Drupal\token_engine\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * Blocks an author from placing tokens they are not entitled to place.
 *
 * Attach this to a text value (typically a formatted-text or string field item)
 * to gate placement at save time. The validator scans the value for tokens and,
 * for each, walks the chain against the token definitions; a token is blocked
 * when a step exposes data the author cannot access (a restricted field, or an
 * attributed token's declared place permission), or when the chain cannot be
 * statically verified and hardening is on.
 *
 * @see \Drupal\token_engine\Attribute\Token::$place_permission
 * @see \Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 */
#[Constraint(
  id: 'TokenPlacement',
  label: new TranslatableMarkup('Token placement', [], ['context' => 'Validation']),
)]
class TokenPlacementConstraint extends SymfonyConstraint {

  /**
   * Violation when the token exposes data the author cannot access.
   *
   * @var string
   */
  public string $message = 'You are not allowed to use the token %token here; it can expose data you do not have access to.';

  /**
   * Violation when the token requires a specific permission to place.
   *
   * @var string
   */
  public string $permissionMessage = 'You are not allowed to use the token %token here; it requires the %permission permission. An account with that permission can save this content.';

  /**
   * Violation when the chain cannot be verified and hardening is on.
   *
   * @var string
   */
  public string $unverifiableMessage = 'The token %token could not be verified and may expose restricted data. An account with the "place unverifiable tokens" permission can save this content.';

}
