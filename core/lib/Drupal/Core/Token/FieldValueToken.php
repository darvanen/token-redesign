<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldItemInterface;

/**
 * Generic resolver for a scalar/text field on an entity.
 *
 * One resolver class serves every non-reference field discovered by
 * TypedDataFieldDiscovery. The field to read is identified by the token name,
 * passed by the engine as $arguments['name'] (token identity is still
 * (input_type, name); the resolver class is shared, the identity is not).
 *
 * Access is checked against the VIEWER actor, not the current user. Both the
 * entity 'view' access AND the field-level 'view' access are required and
 * composed together: checking only entity access is the classic token
 * exfiltration bug, where a viewer who may see the entity but is denied a
 * particular field (e.g. user.mail, or a field restricted by field_permissions
 * or hook_entity_field_access) would still have its value leaked. The engine
 * drops the value when the composed access is not allowed.
 *
 * In the unenforced tier (no viewer; see ActorContext::isEnforced()) the field
 * access check is skipped and access is unconditionally allowed, reproducing
 * legacy resolution, which never checked field access at all.
 *
 * @internal
 */
final class FieldValueToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $fieldName = $arguments['name'] ?? NULL;
    if (!($input instanceof FieldableEntityInterface) || $fieldName === NULL || !$input->hasField($fieldName)) {
      return TokenResult::fromValue('');
    }

    $items = $input->get($fieldName);

    // Enforce field-level view access for the viewer. This is the check that
    // entity access does not cover and that token systems classically omit,
    // leaking restricted fields (e.g. user.mail, or fields gated by
    // field_permissions / hook_entity_field_access). View access on the entity
    // this field belongs to is established upstream: by the 'entity' deref for
    // traversed entities, and by the caller for the root object.
    $access = $context->actor->isEnforced()
      ? $items->access('view', $context->actor->viewer, TRUE)
      : AccessResult::allowed();

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheableDependency($input);
    $cacheability->addCacheableDependency($access);

    // For a multi-value field, return the whole delta-ordered list of values so
    // a numeric delta segment can index into it (e.g. [node:field_tags:1]). For
    // a single-value field, return the one value directly.
    if ($items->getFieldDefinition()->getFieldStorageDefinition()->isMultiple()) {
      $values = [];
      foreach ($items as $item) {
        $values[] = $this->itemValue($item);
      }
      return new TokenResult(
        value: $values,
        cacheability: $cacheability,
        access: $access,
        outputType: 'list<string>',
      );
    }

    $value = $items->isEmpty() ? '' : $this->itemValue($items->first());

    return new TokenResult(
      value: $value ?? '',
      cacheability: $cacheability,
      access: $access,
      outputType: 'string',
    );
  }

  /**
   * Extracts the main-property value from a field item.
   */
  private function itemValue(?FieldItemInterface $item): mixed {
    if ($item === NULL) {
      return '';
    }
    $mainProperty = $item->mainPropertyName();
    return $mainProperty !== NULL ? $item->{$mainProperty} : $item->getString();
  }

}
