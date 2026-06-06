<?php

declare(strict_types=1);

namespace Drupal\token_context_test\Plugin\Token;

use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * A probe resolver that records each time it is instantiated.
 *
 * Used to prove the laziness guarantee: discovering token definitions must not
 * instantiate the resolver, and resolving its token must instantiate it exactly
 * once. The instantiation count is recorded in state under
 * 'token_context_test.lazy_probe_instantiations'.
 */
#[Token(
  name: 'value',
  input_type: 'lazy_probe',
  output_type: 'string',
  label: new TranslatableMarkup('Lazy probe value'),
)]
final class LazyProbeResolver implements TokenResolverInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $state = $container->get('state');
    $key = 'token_context_test.lazy_probe_instantiations';
    $state->set($key, (int) $state->get($key, 0) + 1);
    return new static($state);
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    return TokenResult::fromValue('probed');
  }

}
