<?php

declare(strict_types=1);

namespace Drupal\Core\Token\Plugin\Validation\Constraint;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Token\EntityReferenceFieldToken;
use Drupal\Core\Token\FieldValueToken;
use Drupal\Core\Token\TokenDefinition;
use Drupal\Core\Token\TokenRegistryInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\Utility\Token;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates that the author may place every token in a text value.
 *
 * Runs at entity save, where the current user is the author. Each token in the
 * value is walked against the token definitions (no entity is loaded), and a
 * step is blocked when it exposes data the author cannot access:
 *  - a field token whose field the author cannot view (checked against the
 *    author with no entity bound, so it gives a permission-coarse answer);
 *  - an attributed token declaring a place permission the author lacks.
 * A chain that cannot be walked to its end (e.g. a polymorphic reference whose
 * target type is not statically known) is "unverifiable" and is blocked only
 * when placement hardening is on and the author lacks the override permission.
 *
 * Gating is on presence, not on what changed: editing content that already
 * contains a restricted token challenges an author who is not entitled to it.
 * This is the authoring-side counterpart to the engine's resolution-time check
 * against the viewer, and it is the only layer that stops a token resolving to
 * data the viewer is allowed to see but the author was never entitled to place.
 */
class TokenPlacementConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Permission that lets an author place a chain we could not verify.
   */
  private const UNVERIFIABLE_PERMISSION = 'place unverifiable tokens';

  /**
   * Output types treated as terminal scalars (trailing segments are harmless).
   *
   * @var string[]
   */
  private const SCALAR_TYPES = [
    'string', 'int', 'integer', 'float', 'decimal', 'bool', 'boolean',
    'timestamp', 'datetime', 'uri', 'email', 'telephone', 'language',
  ];

  public function __construct(
    private readonly Token $token,
    private readonly TokenRegistryInterface $registry,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly AccountInterface $currentUser,
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('token'),
      $container->get('token.registry'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager'),
      $container->get('current_user'),
      $container->get('config.factory'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof TokenPlacementConstraint);

    $text = $this->extractText($value);
    if ($text === '') {
      return;
    }

    foreach ($this->token->scan($text) as $type => $tokens) {
      foreach ($tokens as $name => $raw) {
        $this->validateToken($type, (string) $name, $raw, $constraint);
      }
    }
  }

  /**
   * Adds a violation when the author may not place a single scanned token.
   */
  private function validateToken(string $type, string $name, string $raw, TokenPlacementConstraint $constraint): void {
    $assessment = $this->assess($type, $name);

    if ($assessment['status'] === 'allowed') {
      return;
    }

    if ($assessment['status'] === 'unverifiable') {
      if (!$this->hardened() || $this->currentUser->hasPermission(self::UNVERIFIABLE_PERMISSION)) {
        return;
      }
      $this->context->buildViolation($constraint->unverifiableMessage)
        ->setParameter('%token', $raw)
        ->addViolation();
      return;
    }

    // Denied.
    if ($assessment['permission'] !== NULL) {
      $this->context->buildViolation($constraint->permissionMessage)
        ->setParameter('%token', $raw)
        ->setParameter('%permission', $assessment['permission'])
        ->addViolation();
      return;
    }
    $this->context->buildViolation($constraint->message)
      ->setParameter('%token', $raw)
      ->addViolation();
  }

  /**
   * Assesses one token's whole chain.
   *
   * @return array{status: string, permission: string|null}
   *   status is 'allowed', 'denied', or 'unverifiable'.
   */
  private function assess(string $type, string $name): array {
    $walk = $this->walk($type, $name);
    foreach ($walk['definitions'] as $definition) {
      $gate = $this->gate($definition);
      if ($gate['denied']) {
        return ['status' => 'denied', 'permission' => $gate['permission']];
      }
    }
    if ($walk['unverifiable']) {
      return ['status' => 'unverifiable', 'permission' => NULL];
    }
    return ['status' => 'allowed', 'permission' => NULL];
  }

  /**
   * Walks a token's chain by type, collecting the definitions it passes.
   *
   * @return array{definitions: \Drupal\Core\Token\TokenDefinition[], unverifiable: bool}
   *   The definitions encountered, and whether the chain could not complete.
   */
  private function walk(string $type, string $name): array {
    $segments = explode(':', $name);
    $definitions = [];
    $currentType = NULL;
    $count = count($segments);

    for ($i = 0; $i < $count; $i++) {
      $definition = $this->lookup($currentType, $type, $i, $segments[$i]);
      if ($definition !== NULL) {
        $definitions[] = $definition;
        // An argument-consuming token swallows the rest of the chain.
        if ($definition->argumentName !== NULL) {
          return ['definitions' => $definitions, 'unverifiable' => FALSE];
        }
        $currentType = $definition->outputType;
        continue;
      }

      // Not a token: a numeric delta on a list advances to the item type.
      $advanced = $this->advanceNonToken($currentType, $segments[$i]);
      if ($advanced !== NULL) {
        $currentType = $advanced;
        continue;
      }

      // The first segment did not resolve to any known token, so this is a
      // legacy or unknown token, not a chain we entered: leave it ungated.
      if ($currentType === NULL) {
        return ['definitions' => $definitions, 'unverifiable' => FALSE];
      }

      // Trailing segments after a terminal scalar are harmless junk.
      if ($this->isScalar($currentType)) {
        return ['definitions' => $definitions, 'unverifiable' => FALSE];
      }

      // An unknown intermediate type we cannot traverse: unverifiable.
      return ['definitions' => $definitions, 'unverifiable' => TRUE];
    }

    return ['definitions' => $definitions, 'unverifiable' => FALSE];
  }

  /**
   * Looks up the token definition for one segment, advancing the chain.
   */
  private function lookup(?string $currentType, string $rootType, int $index, string $segment): ?TokenDefinition {
    if ($index > 0) {
      return $currentType === NULL ? NULL : $this->registry->getResolvableToken($currentType, $segment);
    }

    // First segment: try the token type name, then its typed-data entity input
    // type, mirroring how the engine reaches auto-discovered field tokens.
    $definition = $this->registry->getResolvableToken($rootType, $segment);
    if ($definition !== NULL) {
      return $definition;
    }
    if ($this->entityTypeManager->hasDefinition($rootType)) {
      return $this->registry->getResolvableToken('entity:' . $rootType, $segment);
    }
    return NULL;
  }

  /**
   * Advances past a non-token segment (a numeric delta on a list type).
   */
  private function advanceNonToken(?string $currentType, string $segment): ?string {
    if ($currentType === NULL || !is_numeric($segment)) {
      return NULL;
    }
    if ($currentType === 'list') {
      return 'string';
    }
    if (preg_match('/^list<(.+)>$/', $currentType, $matches) === 1) {
      return $matches[1];
    }
    return NULL;
  }

  /**
   * Decides whether a single token definition is placeable by the author.
   *
   * @return array{denied: bool, permission: string|null}
   *   Whether placement is denied, and the permission name when one is known.
   */
  private function gate(TokenDefinition $definition): array {
    if ($definition->placePermission !== NULL) {
      return [
        'denied' => !$this->currentUser->hasPermission($definition->placePermission),
        'permission' => $definition->placePermission,
      ];
    }

    $fieldDefinition = $this->fieldDefinitionFor($definition);
    if ($fieldDefinition === NULL) {
      return ['denied' => FALSE, 'permission' => NULL];
    }

    $entityTypeId = substr($definition->inputType, strlen('entity:'));
    $access = $this->entityTypeManager->getAccessControlHandler($entityTypeId)
      ->fieldAccess('view', $fieldDefinition, $this->currentUser, NULL, TRUE);
    return ['denied' => !$access->isAllowed(), 'permission' => NULL];
  }

  /**
   * Returns the base field definition behind a field token, or NULL.
   *
   * Only base fields are resolvable without a bundle at save time; configurable
   * fields on a chained-to entity type are not field-access checked here.
   */
  private function fieldDefinitionFor(TokenDefinition $definition): ?FieldDefinitionInterface {
    $fieldResolvers = [FieldValueToken::class, EntityReferenceFieldToken::class];
    if (!in_array($definition->resolverClass, $fieldResolvers, TRUE)) {
      return NULL;
    }
    if (!str_starts_with($definition->inputType, 'entity:')) {
      return NULL;
    }
    $entityTypeId = substr($definition->inputType, strlen('entity:'));
    if (!$this->entityTypeManager->hasDefinition($entityTypeId)) {
      return NULL;
    }
    $baseFields = $this->entityFieldManager->getBaseFieldDefinitions($entityTypeId);
    return $baseFields[$definition->name] ?? NULL;
  }

  /**
   * Returns TRUE when the output type is a terminal scalar.
   */
  private function isScalar(string $type): bool {
    return in_array($type, self::SCALAR_TYPES, TRUE);
  }

  /**
   * Returns TRUE when placement hardening is on (un-walkable chains blocked).
   */
  private function hardened(): bool {
    return $this->configFactory->get('system.token')->get('harden_placement') ?? TRUE;
  }

  /**
   * Extracts the text to scan from the validated value.
   */
  private function extractText(mixed $value): string {
    if ($value === NULL || is_string($value)) {
      return (string) $value;
    }
    if ($value instanceof FieldItemListInterface) {
      $parts = [];
      foreach ($value as $item) {
        $parts[] = (string) ($item->value ?? '');
      }
      return implode("\n", $parts);
    }
    if ($value instanceof TypedDataInterface) {
      return (string) $value->getValue();
    }
    return (string) $value;
  }

}
