<?php

declare(strict_types=1);

namespace Drupal\eca\Plugin\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;

/**
 * Attributed resolver for the [node:entity_type] token.
 *
 * This migrates the 'entity_type' case of ECA's hook_tokens() implementation
 * (\Drupal\eca\Hook\TokenHooks::tokens) to the new attributed-resolver
 * mechanism. ECA adds an 'entity_type' token to every content entity type via
 * hook_token_info_alter(); the legacy hook resolves it unconditionally to
 * $data[$type]->getEntityTypeId().
 *
 * The legacy hook handles all content entity types in a single generic loop.
 * Because a token resolver is identified by a single concrete (input_type,
 * name) pair, this resolver migrates only the 'node' entity type, which is the
 * deterministic case exercised by the kernel tests. The other entity types
 * continue to be served by the legacy hook (which is retained), and the same
 * resolver shape can be replicated per entity type if desired. On engines that
 * support attributed resolvers this resolver is discovered as a Plugin\Token
 * plugin for the (node, entity_type) identity and takes precedence, producing
 * output that is identical to the legacy hook (the literal entity type id
 * 'node').
 */
#[Token(
  name: 'entity_type',
  input_type: 'node',
  output_type: 'string',
  label: new TranslatableMarkup('Entity type'),
  description: new TranslatableMarkup('The type ID of the entity.'),
)]
final class NodeEntityTypeToken implements TokenResolverInterface {

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!$input instanceof ContentEntityInterface) {
      return TokenResult::fromValue('');
    }

    return new TokenResult(
      value: $input->getEntityTypeId(),
      cacheability: new CacheableMetadata(),
      access: AccessResult::allowed(),
      outputType: 'string',
    );
  }

}
