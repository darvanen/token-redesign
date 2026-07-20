<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;

/**
 * Generic resolver for an entity-reference field.
 *
 * Produces the referenced entity as an 'entity_reference:<target>' value. The
 * chain continues through a paired 'entity' deref token (EntityDerefToken),
 * mirroring the [node:field_author:entity:...] pattern from the design brief:
 * the field segment yields a reference, the 'entity' segment yields the
 * referenced entity, after which further field tokens traverse into it.
 *
 * The field to read is identified by the token name passed as
 * $arguments['name']. Delta 0 is used; deeper deltas are reached by the numeric
 * index operation the engine applies to list output types.
 *
 * In the unenforced tier (no viewer; see ActorContext::isEnforced()) the field
 * access check is skipped and access is unconditionally allowed, reproducing
 * legacy resolution, which never checked field access at all.
 *
 * @internal
 */
final class EntityReferenceFieldToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $fieldName = $arguments['name'] ?? NULL;
    if (!($input instanceof FieldableEntityInterface) || $fieldName === NULL || !$input->hasField($fieldName)) {
      return TokenResult::fromValue(NULL);
    }

    $items = $input->get($fieldName);

    // Enforce field-level view access on the reference field itself, so a viewer
    // denied the field cannot traverse through it. View access on the referenced
    // target entity is enforced separately by the downstream 'entity' deref, and
    // view access on the root entity is the caller's responsibility.
    $access = $context->actor->isEnforced()
      ? $items->access('view', $context->actor->viewer, TRUE)
      : AccessResult::allowed();

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($input);
    $cacheability->addCacheableDependency($access);

    $target = $items->getFieldDefinition()->getSetting('target_type');

    // For a multi-value field, return the whole delta-ordered list of
    // referenced entities so a numeric delta segment can index into it. For a
    // single-value field, return the single referenced entity directly.
    if ($items->getFieldDefinition()->getFieldStorageDefinition()->isMultiple()) {
      return new TokenResult(
        value: $items->referencedEntities(),
        cacheability: $cacheability,
        access: $access,
        outputType: 'list<entity_reference:' . $target . '>',
      );
    }

    $referenced = $items->isEmpty() ? NULL : $items->first()?->entity;

    return new TokenResult(
      value: $referenced,
      cacheability: $cacheability,
      access: $access,
      outputType: $items->isEmpty() ? NULL : 'entity_reference:' . $target,
    );
  }

}
