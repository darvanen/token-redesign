<?php

declare(strict_types=1);

namespace Drupal\pathauto\Plugin\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;
use Drupal\pathauto\AliasCleanerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attributed resolver for the [array:join-path] token.
 *
 * This is the migration of pathauto's hook_tokens() 'join-path' implementation
 * to the new attributed-resolver mechanism. The legacy hook implementation in
 * \Drupal\pathauto\Hook\PathautoTokensHooks is retained so the token keeps
 * working on Drupal versions that predate the resolution engine; on engines
 * that support attributed resolvers this resolver is discovered as a
 * Plugin\Token plugin for the (array, join-path) identity and takes precedence,
 * producing identical output.
 */
#[Token(
  name: 'join-path',
  input_type: 'array',
  output_type: 'string',
  label: new TranslatableMarkup('Joined path'),
  description: new TranslatableMarkup('The array values each cleaned by Pathauto and then joined with the slash into a string that resembles an URL.'),
)]
final class ArrayJoinPathToken implements TokenResolverInterface, ContainerFactoryPluginInterface {

  public function __construct(
    private readonly AliasCleanerInterface $aliasCleaner,
    private readonly RendererInterface $renderer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $container->get('pathauto.alias_cleaner'),
      $container->get('renderer'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!is_array($input)) {
      return TokenResult::fromValue('');
    }

    $options = $context->getOptions();
    $values = [];
    foreach (token_element_children($input) as $key) {
      $value = is_array($input[$key]) ? $this->renderer->render($input[$key]) : (string) $input[$key];
      $values[] = $this->aliasCleaner->cleanString($value, $options);
    }

    return new TokenResult(
      value: implode('/', $values),
      cacheability: new CacheableMetadata(),
      access: AccessResult::allowed(),
      outputType: 'string',
    );
  }

}
