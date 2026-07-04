<?php

declare(strict_types=1);

namespace Drupal\eca\Plugin\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;

/**
 * Attributed resolver for the generic [entity:entity_type] token identity.
 *
 * This complements, and does not replace, NodeEntityTypeToken. It is declared
 * at the bare 'entity' input type, so TokenRegistry::getResolvableToken()'s
 * ancestor walk reaches it mid-chain from any concrete entity type
 * (entity:user, entity:node, and so on) that has no more specific definition
 * of its own, for example [node:uid:entity:entity_type] resolving at
 * entity:user.
 *
 * It cannot claim the root position for entity types other than node.
 * TokenResolutionEngine never tries the entity:<type> branch for
 * a single-segment token; that guard exists so an auto-discovered field token
 * cannot shadow a curated legacy token at the root. A root token like 'node'
 * or 'user' is also colon-free, so the ancestor walk (which strips a trailing
 * ':<segment>') has nothing to strip and cannot bridge it to 'entity' either.
 * So [user:entity_type] stays served by ECA's legacy hook_tokens()
 * implementation (\Drupal\eca\Hook\TokenHooks::tokens), unchanged, and
 * [node:entity_type] keeps resolving via the node-scoped NodeEntityTypeToken,
 * also unchanged. This is a routing-model limitation of the POC engine, not
 * something this resolver works around.
 *
 * @see \Drupal\eca\Plugin\Token\NodeEntityTypeToken
 */
#[Token(
  name: 'entity_type',
  input_type: 'entity',
  output_type: 'string',
  label: new TranslatableMarkup('Entity type'),
  description: new TranslatableMarkup('The type ID of the entity.'),
)]
final class EntityTypeToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!$input instanceof EntityInterface) {
      return TokenResult::fromValue('');
    }

    return new TokenResult(
      value: $input->getEntityTypeId(),
      cacheability: new CacheableMetadata(),
      access: AccessResult::allowed(),
      outputType: 'string',
    );
  }

}
