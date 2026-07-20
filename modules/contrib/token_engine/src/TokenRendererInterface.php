<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Converts a typed resolved token value into a string for an output context.
 *
 * Rendering is separate from resolution and is format-aware serialization. A
 * resolved value of a given output_type is serialized differently depending on
 * the OutputContext (HTML, plain text, URL slug, email subject).
 *
 * Every output type defines a default leaf serialization so that any chain can
 * terminate at any segment and still render sensibly. For example,
 * [comment:entity] rendered on its own displays the entity's label (leaf
 * serialization of entity:comment), while [comment:entity:subject] traverses
 * through it.
 */
interface TokenRendererInterface {

  /**
   * Serializes a resolved value into a string for the given output context.
   *
   * @param mixed $value
   *   The resolved value. Its PHP type corresponds to the token's declared
   *   output_type (e.g. a string, an EntityInterface, an int timestamp).
   * @param string $outputType
   *   The declared output type of the resolved value (e.g. 'string',
   *   'entity:node', 'timestamp').
   * @param \Drupal\token_engine\OutputContext $context
   *   The target output context (HTML, plain text, URL slug, email subject).
   * @param string|null $langcode
   *   (optional) The language the value is being rendered for. Used where
   *   serialization is locale-sensitive: date formatting and URL-slug
   *   transliteration. Other paths ignore it because their value's language was
   *   already chosen upstream when the translation was selected. NULL lets each
   *   path fall back to its own default.
   *
   * @return string
   *   The serialized string representation suitable for the output context.
   */
  public function render(mixed $value, string $outputType, OutputContext $context, ?string $langcode = NULL): string;

}
