<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResult;
use Drupal\Core\Token\TokenResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Resolver for the 'entity' segment of a fake_comment token.
 *
 * This resolver demonstrates the comment:entity hard case: the entity is not a
 * single typed-data field but a computed value assembled from two separate
 * fields (entity_type and entity_id). The resolver:
 *  1. Reads both fields from the input (an associative array).
 *  2. Loads the referenced entity via EntityTypeManager.
 *  3. Stores the loaded entity in the context under 'fake_comment_entity' so
 *     that the next segment in the chain (FakeCommentEntityLabelResolver) can
 *     read it without re-loading.
 *
 * The input is an associative array with:
 *  - 'entity_type' (string): the entity type ID of the commented entity.
 *  - 'entity_id'   (int|string): the entity ID of the commented entity.
 */
#[Token(
  name: 'entity',
  input_type: 'fake_comment',
  output_type: 'fake_comment_entity',
  label: new TranslatableMarkup('Commented entity'),
)]
final class FakeCommentEntityResolver implements TokenResolverInterface, ContainerFactoryPluginInterface {

  /**
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the referenced entity.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   *
   * Composes entity_type + entity_id into a loaded entity, stores it in the
   * context for downstream resolvers, and returns the entity as the output
   * value so the chain can continue into entity-specific sub-tokens.
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!is_array($input)) {
      return TokenResult::fromValue(NULL);
    }

    $entityType = $input['entity_type'] ?? NULL;
    $entityId = $input['entity_id'] ?? NULL;

    if ($entityType === NULL || $entityId === NULL) {
      return TokenResult::fromValue(NULL);
    }

    try {
      $entity = $this->entityTypeManager
        ->getStorage($entityType)
        ->load($entityId);
    }
    catch (\Exception) {
      return TokenResult::fromValue(NULL);
    }

    if ($entity === NULL) {
      return TokenResult::fromValue(NULL);
    }

    // Store the loaded entity in the context so the next segment can read it
    // without needing to re-load or receive it through the chain value alone.
    // This is the core mechanism that handles computed/composed data which is
    // not a single typed-data property path.
    $context->set('fake_comment_entity', $entity);

    return TokenResult::fromValue($entity);
  }

}
