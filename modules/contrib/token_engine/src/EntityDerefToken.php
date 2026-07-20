<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Access\AccessResult;
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
 * In the unenforced tier (no viewer; see ActorContext::isEnforced()) this check
 * is skipped and access is unconditionally allowed, reproducing legacy
 * resolution, which never checked access at all.
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

    $access = $context->actor->isEnforced()
      ? $input->access('view', $context->actor->viewer, TRUE)
      : AccessResult::allowed();
    $cacheability->addCacheableDependency($access);

    return new TokenResult(
      value: $input,
      cacheability: $cacheability,
      access: $access,
      outputType: 'entity:' . $input->getEntityTypeId(),
    );
  }

}
