<?php

namespace Drupal\token;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Replace core's token service with our own.
 *
 * This sets the 'token' service class to \Drupal\token\Token, which extends
 * \Drupal\token_engine\Token (see Token.php). Module service providers run in
 * a fixed order that puts this module's provider before token_engine's, so
 * this alter happens first: it sets the class while it still takes the core
 * constructor signature.
 *
 * token_engine's own service provider runs after this one and inspects the
 * resulting class. Because \Drupal\token\Token already extends
 * \Drupal\token_engine\Token rather than core's \Drupal\Core\Utility\Token,
 * token_engine's provider does not need to replace the class again; it just
 * appends the extra constructor argument that wires in the resolution
 * engine. The two providers cooperate rather than race: whichever one sets
 * the class first, the other one recognises the resulting hierarchy and only
 * adds the argument it owns.
 */
class TokenServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    $definition = $container->getDefinition('token');
    $definition->setClass('\Drupal\token\Token');
  }
}
