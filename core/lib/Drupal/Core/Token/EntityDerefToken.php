<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityInterface;

/**
 * The 'entity' deref token: turns an entity reference into the entity.
 *
 * This is the structural counterpart to EntityReferenceFieldToken. The upstream
 * field token loads and forwards the referenced entity; this token retypes it
 * from 'entity_reference:<target>' to 'entity:<target>' and is where the
 * VIEWER's view access on the referenced entity is enforced. The engine drops
 * the chain's value if that access is not allowed, which is what closes the
 * documented token exfiltration vulnerabilities.
 *
 * @internal
 */
final class EntityDerefToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!($input instanceof EntityInterface)) {
      return TokenResult::fromValue(NULL);
    }

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($input);

    $access = $input->access('view', $context->actor->viewer, TRUE);
    $cacheability->addCacheableDependency($access);

    return new TokenResult(
      value: $input,
      cacheability: $cacheability,
      access: $access,
      outputType: 'entity:' . $input->getEntityTypeId(),
    );
  }

}
