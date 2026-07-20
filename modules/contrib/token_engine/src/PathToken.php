<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\TypedData\ComplexDataInterface;
use Drupal\Core\TypedData\ListInterface;
use Drupal\Core\TypedData\TypedDataInterface;

/**
 * Base resolver for tokens backed by a typed-data property path.
 *
 * Subclass this instead of implementing TokenResolverInterface directly when
 * the token value is simply a property read from the input via Drupal's
 * TypedData API. Declare the path on the #[Token] attribute and write no method
 * body at all:
 * @code
 * #[Token(
 *   name: 'mail',
 *   input_type: 'entity:user',
 *   output_type: 'string',
 *   path: 'mail.value',
 *   label: new TranslatableMarkup('Email'),
 * )]
 * final class UserMailToken extends PathToken {}
 * @endcode
 *
 * The engine supplies the declared path as $arguments['path'], so no reflection
 * or service injection is needed and the plugin instantiates with no factory.
 *
 * The path is walked one segment at a time. A multi-value field (a list) is
 * auto-dereferenced to its first item when a named property follows it, so
 * 'mail.value' reads the value of the first mail item; a numeric segment selects
 * a specific delta ('field.0.value'). Anything missing yields an empty string
 * rather than throwing.
 *
 * This base reads the value only; it does not perform field-level access
 * checks. The engine already gates entity-level access (the root entity and
 * each traversed entity), so PathToken suits declarative mappings where field
 * access matches entity access. For a field with stricter field-level access
 * (e.g. user.mail behind 'view user email addresses'), implement
 * TokenResolverInterface and check field access (see FieldValueToken).
 */
abstract class PathToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $path = $arguments['path'] ?? NULL;
    if ($path === NULL || $path === '') {
      return TokenResult::fromValue('');
    }

    // Obtain a typed-data handle for the input.
    if ($input instanceof TypedDataInterface) {
      $current = $input;
    }
    elseif ($input instanceof FieldableEntityInterface) {
      try {
        $current = $input->getTypedData();
      }
      catch (\Exception) {
        return TokenResult::fromValue('');
      }
    }
    else {
      return TokenResult::fromValue('');
    }

    foreach (explode('.', $path) as $segment) {
      // A multi-value field is a list; auto-dereference it to its first item
      // when a named (non-numeric) property is requested next.
      if ($current instanceof ListInterface && !is_numeric($segment)) {
        $current = $current->isEmpty() ? NULL : $current->first();
      }

      if ($current instanceof ListInterface) {
        $current = $current->get((int) $segment);
      }
      elseif ($current instanceof ComplexDataInterface) {
        try {
          $current = $current->get($segment);
        }
        catch (\InvalidArgumentException) {
          return TokenResult::fromValue('');
        }
      }
      else {
        return TokenResult::fromValue('');
      }

      if ($current === NULL) {
        return TokenResult::fromValue('');
      }
    }

    $value = $current instanceof TypedDataInterface ? $current->getValue() : '';
    return TokenResult::fromValue($value ?? '');
  }

}
