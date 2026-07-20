<?php

namespace Drupal\eca\Token;

use Drupal\Core\Utility\Token;
use Drupal\token_engine\Token as EngineToken;

/**
 * The ECA token service, which is a decorator for the core token service.
 *
 * Extends token_engine's Token subclass rather than core's: the decorator is
 * wired with "parent: token", so it receives the same constructor arguments
 * as the decorated definition, including the resolution engine appended by
 * token_engine's service provider. Extending core's Token directly would
 * silently drop that argument and leave this outer instance without engine
 * dispatch, batch replacement, or output-context defaults.
 *
 * @see \Drupal\eca\Token\TokenServices
 */
class CoreToken extends EngineToken implements TokenInterface {

  use TokenDecoratorTrait;

  /**
   * The decorated token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected Token $token;

}
