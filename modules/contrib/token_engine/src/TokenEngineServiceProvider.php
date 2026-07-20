<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\Core\Utility\Token as CoreToken;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Routes core's token service through the resolution engine.
 *
 * The 'token' service keeps its ID and its public API; only the class and an
 * appended constructor argument change, so every existing caller of
 * \Drupal::token() transparently gains engine-routed resolution while legacy
 * hook implementations keep working through the bridge.
 *
 * Cooperation with other modules that also swap the class (the token module's
 * token_engine-aware branch re-parents \Drupal\token\Token onto this module's
 * subclass, and its provider runs before this one alphabetically):
 *  - current class is core's Token: swap to ours and append the engine.
 *  - current class already extends ours: keep it, append the engine only.
 *  - current class is something else entirely: leave the definition alone.
 *    The engine stays dormant rather than breaking an unknown override's
 *    constructor contract.
 */
class TokenEngineServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container): void {
    if (!$container->hasDefinition('token')) {
      return;
    }
    $definition = $container->getDefinition('token');
    $class = $definition->getClass() ?? CoreToken::class;

    if ($class === CoreToken::class) {
      $definition->setClass(Token::class);
    }
    elseif (!is_a($class, Token::class, TRUE)) {
      return;
    }

    $definition->addArgument(new Reference('token_engine.resolution_engine'));
  }

}
