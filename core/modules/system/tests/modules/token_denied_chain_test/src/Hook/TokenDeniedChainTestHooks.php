<?php

declare(strict_types=1);

namespace Drupal\token_denied_chain_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations proving the denial-fallthrough vulnerability is closed.
 *
 * The entity_field_access() hook forbids 'view' on field_secret for anyone but
 * the account's owner. The tokens() hook resolves any '<field>:value' spelling
 * to the raw field value with no access check, the classic exfiltration
 * mechanism the legacy pipeline is built on. A denied engine chain that reaches
 * this hook would leak the value; the engine must instead resolve it to an
 * empty string and never route it here.
 */
class TokenDeniedChainTestHooks {

  /**
   * Implements hook_entity_field_access().
   *
   * Forbids viewing field_secret unless the viewer owns the account the field
   * belongs to. Every other field and operation gets no opinion.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    if ($operation !== 'view' || $field_definition->getName() !== 'field_secret' || $items === NULL) {
      return AccessResult::neutral();
    }
    $owner = $items->getEntity();
    if ($owner->id() !== NULL && (string) $owner->id() === (string) $account->id()) {
      return AccessResult::neutral();
    }
    return AccessResult::forbidden('token_denied_chain_test forbids field_secret to non-owners');
  }

  /**
   * Implements hook_tokens().
   *
   * Resolves any '<field>:value' user token to the raw field value with no
   * access check. This is the legacy mechanism a denied chain must never reach.
   */
  #[Hook('tokens')]
  public function tokens(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $replacements = [];
    if ($type !== 'user') {
      return $replacements;
    }
    $user = $data['user'] ?? NULL;
    if (!$user instanceof FieldableEntityInterface) {
      return $replacements;
    }
    foreach ($tokens as $name => $raw) {
      if (!str_ends_with($name, ':value')) {
        continue;
      }
      $field = substr($name, 0, -strlen(':value'));
      if ($user->hasField($field)) {
        $replacements[$raw] = (string) $user->get($field)->value;
      }
    }
    return $replacements;
  }

}
