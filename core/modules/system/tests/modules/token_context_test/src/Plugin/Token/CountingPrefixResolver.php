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
 * A prefix resolver that counts how many times resolve() is called.
 *
 * Used to prove memo effectiveness: when multiple tokens share the prefix
 * [memo_probe:prefix:…], resolving them in a single replaceMultiple() batch
 * should invoke this resolver exactly once regardless of how many tokens share
 * the prefix, while N individual replace() calls invoke it N times.
 *
 * The invocation count is stored in state under
 * 'token_context_test.counting_prefix_invocations'.
 *
 * Token chain: [memo_probe:prefix:leaf]
 *   - 'prefix' segment: CountingPrefixResolver (input_type 'memo_probe',
 *     output_type 'memo_probe_prefixed')
 *   - 'leaf' segment: PrefixLeafResolver (input_type 'memo_probe_prefixed',
 *     output_type 'string')
 */
#[Token(
  name: 'prefix',
  input_type: 'memo_probe',
  output_type: 'memo_probe_prefixed',
  label: new TranslatableMarkup('Counting prefix'),
)]
final class CountingPrefixResolver implements TokenResolverInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get('state'));
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    $key = 'token_context_test.counting_prefix_invocations';
    $this->state->set($key, (int) $this->state->get($key, 0) + 1);
    return TokenResult::fromValue('prefix_value');
  }

}
