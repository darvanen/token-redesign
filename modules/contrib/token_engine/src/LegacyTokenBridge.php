<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Cache\CacheableDependencyInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Render\AttachmentsInterface;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Invokes the legacy hook_tokens() / hook_tokens_alter() pipeline.
 *
 * This class contains the exact hook-invocation logic previously embedded in
 * Token::generate(). Extracting it here lets the resolution engine delegate to
 * the legacy hooks. The bridge is the defined BC fallback: when a token has no
 * attributed resolver in the registry, the engine calls the bridge.
 *
 * @internal
 *   This class is part of the token system refactor. It is not a public API
 *   and will be deprecated once all core tokens are migrated to attributed
 *   resolvers.
 */
final class LegacyTokenBridge {

  /**
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {}

  /**
   * Generates replacement values for a list of tokens via legacy hooks.
   *
   * This is a direct extraction of the hook-invocation logic from
   * Token::generate(). It maintains identical semantics: hook_tokens()
   * implementations receive the same parameters and the same
   * $bubbleable_metadata object is mutated in place by each hook.
   *
   * @param string $type
   *   The token type (e.g. 'node', 'user').
   * @param array $tokens
   *   Tokens to replace, keyed by token name with raw token string as value.
   * @param array $data
   *   Keyed data objects (e.g. ['node' => $node]).
   * @param array $options
   *   Options such as 'langcode' and 'clear'.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata
   *   Bubbleable metadata accumulator; mutated in place by hooks.
   *
   * @return array
   *   Replacement values keyed by the raw token string (e.g. '[node:title]').
   */
  public function generate(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    // Merge cacheability from the data objects themselves, just as the legacy
    // Token::generate() did before this was extracted.
    foreach ($data as $object) {
      if ($object instanceof CacheableDependencyInterface || $object instanceof AttachmentsInterface) {
        $bubbleable_metadata->addCacheableDependency($object);
      }
    }

    $replacements = $this->moduleHandler->invokeAll('tokens', [$type, $tokens, $data, $options, $bubbleable_metadata]);

    $context = [
      'type' => $type,
      'tokens' => $tokens,
      'data' => $data,
      'options' => $options,
    ];
    $this->moduleHandler->alter('tokens', $replacements, $context, $bubbleable_metadata);

    return $replacements;
  }

}
