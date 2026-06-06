<?php

declare(strict_types=1);

namespace Drupal\Core\Token\Discovery;

use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Token\EntityDerefToken;
use Drupal\Core\Token\EntityReferenceFieldToken;
use Drupal\Core\Token\FieldValueToken;
use Drupal\Core\Token\TokenDefinition;

/**
 * Derives token definitions from typed-data field definitions at runtime.
 *
 * Fields are NOT declared as attributed resolvers; they are discovered here
 * from the entity field definitions. This is why a node with 50 reference
 * fields needs zero declarations: every field token comes from this source.
 *
 * The flat model is preserved structurally:
 *  - A scalar/text field on entity:node becomes (entity:node, field_name) with
 *    output type 'string', backed by FieldValueToken.
 *  - An entity-reference field becomes (entity:node, field_name) with output
 *    type 'entity_reference:<target>', backed by EntityReferenceFieldToken, and
 *    a paired (entity_reference:<target>, 'entity') deref token backed by
 *    EntityDerefToken that produces 'entity:<target>'. This is exactly the
 *    [node:field_author:entity:...] pattern from the design brief: the field
 *    produces a reference, and the 'entity' segment produces the referenced
 *    entity, after which the next field token traverses into it.
 *
 * Discovery uses the entity type's field storage definitions, so it covers both
 * base fields and configurable (bundle) fields. Because storage definitions are
 * not bundle-specific, a field attached to only some bundles is exposed for the
 * whole entity type and resolves to an empty value on bundles that lack it.
 *
 * Making discovery bundle-precise (e.g. an 'entity:node:article' input type,
 * with per-bundle field instances and labels) is an open architectural question,
 * not a drop-in change: it would reshape the input-type model, token identity,
 * routing, and the per-type cache keys. It needs design discussion before being
 * pursued.
 *
 * Discovery is per-input-type so the registry never loads the full field set
 * up front; only the slice for the type currently being traversed is built.
 *
 * @internal
 *   Use TokenDiscoveryInterface; do not depend on this implementation directly.
 */
final class TypedDataFieldDiscovery implements TokenDiscoveryInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function discoverForInputType(string $inputType): array {
    // The 'entity' deref token lives against entity_reference:<target> types.
    if (str_starts_with($inputType, 'entity_reference:')) {
      $target = substr($inputType, strlen('entity_reference:'));
      return [
        'entity' => new TokenDefinition(
          name: 'entity',
          inputType: $inputType,
          outputType: 'entity:' . $target,
          label: 'Referenced entity',
          resolverClass: EntityDerefToken::class,
          module: 'system',
        ),
      ];
    }

    // Field tokens live against entity:<entity_type_id> types.
    if (!str_starts_with($inputType, 'entity:')) {
      return [];
    }

    $entityTypeId = substr($inputType, strlen('entity:'));
    if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return [];
    }
    $entityType = $this->entityTypeManager->getDefinition($entityTypeId);
    if (!$entityType->entityClassImplements(FieldableEntityInterface::class)) {
      return [];
    }

    $definitions = [];
    foreach ($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId) as $fieldName => $fieldDefinition) {
      $targetType = $fieldDefinition->getSetting('target_type');
      $isReference = $targetType !== NULL
        && str_contains($fieldDefinition->getType(), 'entity_reference');

      // A multi-value field outputs a list, so a numeric delta segment can
      // index into it (e.g. [node:field_refs:1:entity:title]); a single-value
      // field outputs the item type directly, so no delta is needed
      // (e.g. [node:uid:entity:name]).
      $multiple = $fieldDefinition->isMultiple();

      if ($isReference) {
        $itemType = 'entity_reference:' . $targetType;
        $definitions[$fieldName] = new TokenDefinition(
          name: $fieldName,
          inputType: $inputType,
          outputType: $multiple ? 'list<' . $itemType . '>' : $itemType,
          label: $fieldDefinition->getLabel(),
          resolverClass: EntityReferenceFieldToken::class,
          module: 'system',
        );
      }
      else {
        $definitions[$fieldName] = new TokenDefinition(
          name: $fieldName,
          inputType: $inputType,
          outputType: $multiple ? 'list<string>' : 'string',
          label: $fieldDefinition->getLabel(),
          resolverClass: FieldValueToken::class,
          module: 'system',
        );
      }
    }

    return $definitions;
  }

}
