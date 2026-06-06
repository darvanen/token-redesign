<?php

declare(strict_types=1);

namespace Drupal\webform\Plugin\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Token\Attribute\Token;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResolverInterface;
use Drupal\Core\Token\TokenResult;
use Drupal\webform\WebformInterface;

/**
 * Attributed resolver for the [webform:id] token.
 *
 * This is the migration of webform's hook_tokens() 'id' implementation to the
 * new attributed-resolver mechanism. The legacy hook implementation in
 * \Drupal\webform\Hook\WebformTokensHooks::tokens() is retained so the token
 * keeps working on Drupal versions that predate the resolution engine; on
 * engines that support attributed resolvers this resolver is discovered as a
 * Plugin\Token plugin for the (webform, id) identity and takes precedence,
 * producing identical output.
 *
 * @see \Drupal\webform\Hook\WebformTokensHooks::tokens()
 */
#[Token(
  name: 'id',
  input_type: 'webform',
  output_type: 'string',
  label: new TranslatableMarkup('Webform ID'),
  description: new TranslatableMarkup('The ID of the webform.'),
)]
final class WebformIdToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!$input instanceof WebformInterface) {
      return TokenResult::fromValue('');
    }

    return new TokenResult(
      value: (string) $input->id(),
      cacheability: (new CacheableMetadata())->addCacheableDependency($input),
      access: AccessResult::allowed(),
      outputType: 'string',
    );
  }

}
