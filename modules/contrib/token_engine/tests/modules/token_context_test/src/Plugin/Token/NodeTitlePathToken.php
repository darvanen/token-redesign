<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\PathToken;

/**
 * A declarative path token with no method body, to exercise PathToken.
 *
 * The entire token is the attribute: it reads the node title via the typed-data
 * path 'title.value'. There is no resolve() implementation; PathToken walks the
 * declared path, and the resolver instantiates with no factory because it has
 * no constructor dependencies.
 */
#[Token(
  name: 'title_via_path',
  input_type: 'entity:node',
  output_type: 'string',
  path: 'title.value',
  label: new TranslatableMarkup('Node title (via PathToken)'),
)]
final class NodeTitlePathToken extends PathToken {}
