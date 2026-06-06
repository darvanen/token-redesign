<?php

declare(strict_types=1);

namespace Drupal\Core\Token\Event;

use Drupal\Core\Token\TokenDefinition;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event fired after token discovery and before definitions are cached.
 *
 * Subscribe to this event to add, remove, or modify token definitions
 * contributed by any discovery source. This is the canonical extension point
 * replacing hook_token_info_alter() and its contrib equivalents such as
 * the contrib token module's fieldTokenInfoAlter() (which iterates every
 * content entity type's field definitions and attaches per-field, sub-property,
 * delta, image-style and date-format token definitions).
 *
 * Definitions are organised in a two-level array:
 * - Outer key: input type (e.g. 'entity:node').
 * - Inner key: token name (e.g. 'title').
 *
 * Example subscriber:
 * @code
 * public function onTokenDiscoveryAlter(TokenDiscoveryAlterEvent $event): void {
 *   // Add a synthetic token contributed by a field module.
 *   $event->addDefinition(new TokenDefinition(
 *     name: 'computed_full_name',
 *     inputType: 'entity:user',
 *     outputType: 'string',
 *     label: new TranslatableMarkup('Full name'),
 *     resolverClass: ComputedFullNameToken::class,
 *   ));
 *
 *   // Remove a token that should not be exposed in this profile.
 *   $event->removeDefinition('entity:node', 'promote');
 * }
 * @endcode
 */
final class TokenDiscoveryAlterEvent extends Event {

  /**
   * The event name dispatched by the token registry after discovery.
   */
  const DISCOVERY_ALTER = 'token.discovery.alter';

  /**
   * All discovered definitions, organised by input type then by token name.
   *
   * @var array<string, array<string, \Drupal\Core\Token\TokenDefinition>>
   */
  private array $definitions;

  /**
   * @param array<string, array<string, \Drupal\Core\Token\TokenDefinition>> $definitions
   *   Initial definitions keyed by input type, then by token name.
   */
  public function __construct(array $definitions) {
    $this->definitions = $definitions;
  }

  /**
   * Returns all definitions, keyed by input type then by token name.
   *
   * @return array<string, array<string, \Drupal\Core\Token\TokenDefinition>>
   *   Definitions keyed by input type, then by token name.
   */
  public function getDefinitions(): array {
    return $this->definitions;
  }

  /**
   * Returns the definitions for a specific input type, keyed by token name.
   *
   * @param string $inputType
   *   The input type, e.g. 'entity:node'.
   *
   * @return array<string, \Drupal\Core\Token\TokenDefinition>
   *   Definitions keyed by token name, or an empty array if none exist.
   */
  public function getDefinitionsForInputType(string $inputType): array {
    return $this->definitions[$inputType] ?? [];
  }

  /**
   * Adds or replaces a token definition.
   *
   * If a definition with the same (input_type, name) pair already exists it
   * is overwritten. This allows subscribers to replace the resolver class or
   * relabel a token contributed by another module.
   *
   * @param \Drupal\Core\Token\TokenDefinition $definition
   *   The definition to add.
   */
  public function addDefinition(TokenDefinition $definition): void {
    $this->definitions[$definition->inputType][$definition->name] = $definition;
  }

  /**
   * Removes a token definition identified by its input type and name.
   *
   * Silently does nothing if no matching definition exists.
   *
   * @param string $inputType
   *   The input type of the token to remove, e.g. 'entity:node'.
   * @param string $name
   *   The token name to remove, e.g. 'title'.
   */
  public function removeDefinition(string $inputType, string $name): void {
    unset($this->definitions[$inputType][$name]);

    // Remove the input type key when it becomes empty to avoid phantom entries.
    if (isset($this->definitions[$inputType]) && $this->definitions[$inputType] === []) {
      unset($this->definitions[$inputType]);
    }
  }

  /**
   * Returns TRUE when a definition exists for the given (inputType, name) pair.
   *
   * @param string $inputType
   *   The input type to check.
   * @param string $name
   *   The token name to check.
   *
   * @return bool
   *   Whether the definition is currently registered.
   */
  public function hasDefinition(string $inputType, string $name): bool {
    return isset($this->definitions[$inputType][$name]);
  }

}
