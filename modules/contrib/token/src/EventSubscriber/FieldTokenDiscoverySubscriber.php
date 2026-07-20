<?php

declare(strict_types=1);

namespace Drupal\token\EventSubscriber;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Component\Utility\Html;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\TypedData\DataReferenceDefinitionInterface;
use Drupal\Core\TypedData\PrimitiveInterface;
use Drupal\field\FieldStorageConfigInterface;
use Drupal\token\TokenEntityMapperInterface;
use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\token_engine\TokenDefinition;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Subscribes to TokenDiscoveryAlterEvent to add field-derived token definitions.
 *
 * This is the new-system equivalent of
 * \Drupal\token\Hook\TokenTokenInfoHooks::fieldTokenInfoAlter().
 *
 * That method used hook_token_info_alter() to attach field tokens to their
 * respective entity token types. This subscriber does the same thing via the
 * TokenDiscoveryAlterEvent, which fires after attributed-resolver discovery
 * and before per-type caching. The event API can express everything the hook
 * did: field token types, list types for multivalue fields, delta tokens,
 * property sub-tokens, image-style tokens, and date-format tokens.
 *
 * ## Migration status
 *
 * Both the hook implementation and this subscriber register the same tokens.
 * Once the hook is removed (on a normal deprecation schedule), this subscriber
 * becomes the sole source of field token definitions. Until then, the hook
 * implementation in TokenTokenInfoHooks::fieldTokenInfoAlter() is preserved
 * for backward compatibility with sites that may be caching the legacy token
 * info array directly.
 *
 * The hook and this subscriber produce structurally equivalent definitions.
 * They are NOT additive: each guard against registering the same token twice
 * (the hook skips tokens already in $info, the subscriber skips tokens already
 * in the event). Running both together is safe; whichever runs first wins for
 * any given (input_type, name) pair.
 *
 * @see \Drupal\token\Hook\TokenTokenInfoHooks::fieldTokenInfoAlter()
 */
final class FieldTokenDiscoverySubscriber implements EventSubscriberInterface {

  use StringTranslationTrait;

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entityFieldManager
   *   The entity field manager.
   * @param \Drupal\token\TokenEntityMapperInterface $tokenEntityMapper
   *   Maps entity type IDs to token type names and vice versa.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly TokenEntityMapperInterface $tokenEntityMapper,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER => ['onDiscoveryAlter', 0],
    ];
  }

  /**
   * Adds field-derived token definitions for all content entity types.
   *
   * This method mirrors fieldTokenInfoAlter() using the new event API:
   *  - Field tokens → $event->addDefinition()
   *  - Field type tokens, list<> types, delta tokens → $event->addDefinition()
   *  - Property sub-tokens → $event->addDefinition()
   *  - Image-style tokens → $event->addDefinition()
   *  - Date-format tokens → $event->addDefinition()
   */
  public function onDiscoveryAlter(TokenDiscoveryAlterEvent $event): void {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }

      $token_type = $this->tokenEntityMapper->getTokenTypeForEntityType($entity_type_id);
      if (empty($token_type)) {
        // No token type mapping registered for this entity. Mirrors the
        // `empty($token_type)` guard in fieldTokenInfoAlter().
        continue;
      }

      $this->addFieldTokensForEntityType(
        $event,
        $entity_type_id,
        $token_type,
      );
    }
  }

  /**
   * Adds field-level token definitions for a single content entity type.
   *
   * @param \Drupal\token_engine\Event\TokenDiscoveryAlterEvent $event
   *   The alter event.
   * @param string $entityTypeId
   *   The entity type ID (e.g. 'node').
   * @param string $tokenType
   *   The token type for this entity (e.g. 'node').
   */
  private function addFieldTokensForEntityType(
    TokenDiscoveryAlterEvent $event,
    string $entityTypeId,
    string $tokenType,
  ): void {
    foreach ($this->entityFieldManager->getFieldStorageDefinitions($entityTypeId) as $fieldName => $field) {
      // Only FieldStorageConfigInterface fields or token-defined fields.
      if (!($field instanceof FieldStorageConfigInterface)) {
        continue;
      }

      // Skip fields that are already registered.
      if ($event->hasDefinition($tokenType, $fieldName)) {
        continue;
      }

      // Skip the comment:body field (core registers it as [comment:body]).
      if ($tokenType === 'comment' && $fieldName === 'comment_body') {
        continue;
      }

      // Skip fields with no typed-data properties.
      if (!$field->getPropertyDefinitions()) {
        continue;
      }

      $this->addFieldDefinitions($event, $tokenType, $fieldName, $field);
    }
  }

  /**
   * Adds all token definitions for a single field storage definition.
   *
   * Replicates the per-field logic in fieldTokenInfoAlter():
   *  - Field token on the entity type (with its nested sub-type).
   *  - list<> type + delta tokens for multivalue fields.
   *  - Property sub-tokens (primitives and entity references).
   *  - Image-style tokens for image fields.
   *  - Date-format tokens for date fields.
   */
  private function addFieldDefinitions(
    TokenDiscoveryAlterEvent $event,
    string $tokenType,
    string $fieldName,
    FieldStorageDefinitionInterface $field,
  ): void {
    $fieldTokenName = $tokenType . '-' . $fieldName;
    $cardinality = $field->getCardinality();
    $cardinality = ($cardinality === FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED || $cardinality > 3)
      ? 3
      : $cardinality;

    $outputType = ($cardinality > 1)
      ? "list<{$fieldTokenName}>"
      : $fieldTokenName;

    // Main field token on the entity type.
    $event->addDefinition(new TokenDefinition(
      name: $fieldName,
      inputType: $tokenType,
      outputType: $outputType,
      label: Html::escape($fieldName),
      module: 'token',
    ));

    // Field sub-type token (single item).
    $event->addDefinition(new TokenDefinition(
      name: $fieldTokenName,
      inputType: '',
      outputType: 'string',
      label: Html::escape($fieldName),
      module: 'token',
    ));

    // List wrapper type + delta tokens for multivalue fields.
    if ($cardinality > 1) {
      $listTypeName = "list<{$fieldTokenName}>";

      $event->addDefinition(new TokenDefinition(
        name: $listTypeName,
        inputType: '',
        outputType: 'string',
        module: 'token',
      ));

      for ($delta = 0; $delta < $cardinality; $delta++) {
        $event->addDefinition(new TokenDefinition(
          name: (string) $delta,
          inputType: $listTypeName,
          outputType: $fieldTokenName,
          label: $this->t('@type item @delta', ['@type' => $fieldName, '@delta' => $delta]),
          module: 'token',
        ));
      }
    }

    // Property sub-tokens.
    foreach ($field->getPropertyDefinitions() as $property => $propertyDefinition) {
      if (is_subclass_of($propertyDefinition->getClass(), PrimitiveInterface::class)) {
        $event->addDefinition(new TokenDefinition(
          name: (string) $property,
          inputType: $fieldTokenName,
          outputType: 'string',
          label: $propertyDefinition->getLabel(),
          description: $propertyDefinition->getDescription(),
          module: 'token',
        ));
      }
      elseif ($propertyDefinition instanceof DataReferenceDefinitionInterface) {
        $target = $propertyDefinition->getTargetDefinition();
        if ($target->getDataType() === 'entity') {
          $referencedEntityType = $target instanceof DataDefinitionInterface
            ? (method_exists($target, 'getEntityTypeId') ? $target->getEntityTypeId() : NULL)
            : NULL;
          if ($referencedEntityType) {
            $refTokenType = $this->tokenEntityMapper->getTokenTypeForEntityType($referencedEntityType);
            if ($refTokenType) {
              $event->addDefinition(new TokenDefinition(
                name: (string) $property,
                inputType: $fieldTokenName,
                outputType: $refTokenType,
                label: $propertyDefinition->getLabel(),
                description: $propertyDefinition->getDescription(),
                module: 'token',
              ));
            }
          }
        }
      }
    }

    // Image-style tokens: one token per configured style against this field's
    // type. This is the type-level operation pattern: the resolver is registered
    // once per style, not once per field.
    if ($field->getType() === 'image' && $this->entityTypeManager->hasDefinition('image_style')) {
      $imageStyles = $this->entityTypeManager->getStorage('image_style')->loadMultiple();
      foreach ($imageStyles as $style => $imageStyle) {
        $description = $imageStyle->label();
        $event->addDefinition(new TokenDefinition(
          name: (string) $style,
          inputType: $fieldTokenName,
          outputType: 'image_with_image_style',
          label: $description,
          description: $this->t('Represents the image in the given image style.'),
        ));
      }
    }

    // Date-format tokens for date fields.
    $dateFields = ['datetime', 'timestamp', 'created', 'changed'];
    if (in_array($field->getType(), $dateFields, TRUE)) {
      $event->addDefinition(new TokenDefinition(
        name: 'date',
        inputType: $fieldTokenName,
        outputType: 'date',
        label: $this->t('@name format', ['@name' => $fieldName]),
        module: 'token',
      ));
    }
  }

}
