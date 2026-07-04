<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * A generic entity-input token, declared at the bare 'entity' type.
 *
 * This token is reachable at the end of ANY entity chain (entity:node,
 * entity:user, and so on) once TokenRegistry's ancestor walk is in place,
 * because 'entity' is the ancestor of every concrete 'entity:<type>' input
 * type. It exists to prove that reach is type-level, not hard-coded to any
 * single entity type: no chain-specific logic here, just a resolver declared
 * once against the ancestor type.
 *
 * The place_permission also makes this fixture double as the placement-parity
 * case: an author needs 'place secret tokens' (the permission
 * PlacementGatedSecretResolver already declares; reused here rather than
 * declaring a new one, which would require a permissions.yml change outside
 * this task's file scope) to place a chain that only reaches this definition
 * via the ancestor match, proving the placement validator's walk() inherits
 * the same assignability as the resolution engine because both call
 * TokenRegistryInterface::getResolvableToken().
 *
 * @see \Drupal\token_context_test\Plugin\Token\NodeSpecificMarkerResolver
 * @see \Drupal\token_context_test\Plugin\Token\PlacementGatedSecretResolver
 */
#[Token(
  name: 'generic_marker',
  input_type: 'entity',
  output_type: 'string',
  label: new TranslatableMarkup('Generic entity marker'),
  place_permission: 'place secret tokens',
)]
final class GenericEntityMarkerResolver implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!($input instanceof EntityInterface)) {
      return TokenResult::fromValue('');
    }
    return TokenResult::fromValue('GENERIC:' . $input->label());
  }

}
