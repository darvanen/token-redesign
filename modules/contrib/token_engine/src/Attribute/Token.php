<?php

declare(strict_types=1);

namespace Drupal\token_engine\Attribute;

use Drupal\Component\Plugin\Attribute\AttributeBase;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Declares a token resolver plugin.
 *
 * Decorate any class that implements
 * \Drupal\token_engine\TokenResolverInterface (or extends
 * \Drupal\token_engine\PathToken) with this attribute and place it in your
 * module's `Plugin\Token` namespace. The token resolver plugin manager
 * discovers it statically (no service registration required) and instantiates
 * it lazily, only when one of its tokens is actually resolved. Resolvers that
 * need services should implement
 * \Drupal\Core\Plugin\ContainerFactoryPluginInterface.
 *
 * The plugin ID is the token's identity: "{input_type}:{name}".
 *
 * Example usage:
 * @code
 * #[Token(
 *   name: 'title',
 *   input_type: 'entity:node',
 *   output_type: 'string',
 *   label: new TranslatableMarkup('Title'),
 * )]
 * final class NodeTitleToken implements TokenResolverInterface {
 *   public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
 *     return TokenResult::fromValue($input->getTitle());
 *   }
 * }
 * @endcode
 *
 * Path-based example (pure typed-data, no custom resolve() needed):
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
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class Token extends AttributeBase {

  /**
   * Constructs a Token attribute.
   *
   * @param string $name
   *   The token name — a single colon-delimited segment, e.g. 'title'. Must
   *   not contain square brackets.
   * @param string $input_type
   *   The input type accepted by this resolver, e.g. 'entity:node'. Use an
   *   empty string for root tokens that require no input object.
   * @param string $output_type
   *   The output type produced, e.g. 'string', 'entity:user', 'timestamp'.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $label
   *   Human-readable label shown in the token browser. NULL means unlabelled.
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup|string|null $description
   *   Human-readable description shown in the token browser. NULL means none.
   * @param string|null $path
   *   Typed-data property path used by PathToken subclasses, e.g. 'mail.value'.
   *   NULL for resolvers that provide their own resolve() implementation.
   * @param string|null $argument_name
   *   When set, this token consumes the remainder of the chain (every segment
   *   after its own name, re-joined with ':') as a single argument passed to
   *   resolve() under this key, and terminates traversal. This expresses tokens
   *   like `[node:created:custom:Y-m-d]`, where `custom` takes the trailing
   *   `Y-m-d` as a 'format' argument rather than treating it as a chain step.
   * @param string|null $place_permission
   *   When set, an author may only place this token (in content gated by the
   *   placement constraint) if they hold this permission. NULL means the token
   *   carries no placement restriction of its own. This is the placement-time
   *   (authoring) gate, checked against the author at save; it is independent of
   *   the resolution-time access check against the viewer.
   */
  public function __construct(
    public readonly string $name,
    public readonly string $input_type,
    public readonly string $output_type,
    public readonly TranslatableMarkup|string|null $label = NULL,
    public readonly TranslatableMarkup|string|null $description = NULL,
    public readonly ?string $path = NULL,
    public readonly ?string $argument_name = NULL,
    public readonly ?string $place_permission = NULL,
  ) {
    // The plugin ID is the token identity (input_type, name).
    parent::__construct($input_type . ':' . $name);
  }

}
