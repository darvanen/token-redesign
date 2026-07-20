<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Component\Render\HtmlEscapedText;
use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RendererInterface;
use Drupal\Core\Utility\Token as CoreToken;

/**
 * Engine-routed drop-in for core's token service.
 *
 * generate() dispatches through the resolution engine, which resolves
 * attributed/structural tokens itself and delegates everything else to the
 * legacy hook pipeline via the bridge, so hook_token_info() /
 * hook_tokens() implementations keep working unchanged. With no engine
 * injected (direct instantiation with core's five arguments) every call
 * falls through to the parent's pure legacy behaviour.
 *
 * The following replacement options are honoured by the engine for tokens
 * served by attributed resolvers; legacy hook_tokens() implementations ignore
 * them:
 * - viewer: an AccountInterface token access is checked against. Set this
 *   when the output is consumed by someone other than the current request
 *   user, e.g. the recipient of a queued email.
 * - token_actor: an ActorContext supplying the viewer; takes precedence over
 *   'viewer'.
 * - output_context: an OutputContext case selecting how resolved values are
 *   serialized. Defaults to HTML for replace() and plain text for
 *   replacePlain().
 */
class Token extends CoreToken {

  /**
   * The token resolution engine, or NULL for pure legacy behaviour.
   */
  protected ?TokenResolutionEngineInterface $resolutionEngine;

  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache, LanguageManagerInterface $language_manager, CacheTagsInvalidatorInterface $cache_tags_invalidator, RendererInterface $renderer, ?TokenResolutionEngineInterface $resolution_engine = NULL) {
    parent::__construct($module_handler, $cache, $language_manager, $cache_tags_invalidator, $renderer);
    $this->resolutionEngine = $resolution_engine;
  }

  /**
   * {@inheritdoc}
   */
  public function replace($markup, array $data = [], array $options = [], ?BubbleableMetadata $bubbleable_metadata = NULL) {
    if (!isset($options['output_context'])) {
      $options['output_context'] = OutputContext::Html;
    }
    return parent::replace($markup, $data, $options, $bubbleable_metadata);
  }

  /**
   * {@inheritdoc}
   */
  public function replacePlain(string $plain, array $data = [], array $options = [], ?BubbleableMetadata $bubbleable_metadata = NULL): string {
    if (!isset($options['output_context'])) {
      $options['output_context'] = OutputContext::PlainText;
    }
    return parent::replacePlain($plain, $data, $options, $bubbleable_metadata);
  }

  /**
   * {@inheritdoc}
   */
  public function generate($type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    if ($this->resolutionEngine !== NULL) {
      return $this->resolutionEngine->generate($type, $tokens, $data, $options, $bubbleable_metadata);
    }
    return parent::generate($type, $tokens, $data, $options, $bubbleable_metadata);
  }

  /**
   * Replaces tokens in multiple strings in a single batch.
   *
   * Equivalent to calling replace() on every element of $texts individually,
   * but uses a shared chain-prefix memo across all strings so structural
   * prefix walks are performed only once per batch. This is the recommended
   * API when replacing many short strings that share chain prefixes (e.g.
   * Metatag replacing 20-30 per-tag strings against the same node).
   *
   * Semantics are identical to replace(): HTML output, same escaping rules,
   * same 'clear', 'callback', 'langcode', 'viewer', and 'output_context'
   * option handling.
   *
   * @param string[] $texts
   *   Array of HTML strings keyed by arbitrary caller-defined keys.
   * @param array $data
   *   (optional) An array of keyed objects. See replace().
   * @param array $options
   *   (optional) A keyed array of options. See replace().
   * @param \Drupal\Core\Render\BubbleableMetadata|null $bubbleable_metadata
   *   (optional) Target for adding metadata. See replace().
   *
   * @return string[]
   *   Replaced strings, with the same keys as $texts.
   */
  public function replaceMultiple(array $texts, array $data = [], array $options = [], ?BubbleableMetadata $bubbleable_metadata = NULL): array {
    if (empty($texts)) {
      return [];
    }

    // Scan all texts and union their tokens, grouped by type. Deduplication is
    // inherent: scan() returns unique token-name-to-raw-token maps per type,
    // and merging multiple scans with += keeps the first occurrence per key.
    $allTokensByType = [];
    foreach ($texts as $text) {
      foreach ($this->scan((string) $text) as $type => $tokens) {
        if (!isset($allTokensByType[$type])) {
          $allTokensByType[$type] = [];
        }
        $allTokensByType[$type] += $tokens;
      }
    }

    if (empty($allTokensByType)) {
      return $texts;
    }

    $bubbleable_metadata_is_passed_in = (bool) $bubbleable_metadata;
    $bubbleable_metadata = $bubbleable_metadata ?: new BubbleableMetadata();

    if (!isset($options['output_context'])) {
      $options['output_context'] = OutputContext::Html;
    }

    // One shared memo for the whole batch so prefix walks are amortised.
    $memo = ($this->resolutionEngine instanceof TokenResolutionEngine)
      ? new ChainPrefixMemo()
      : NULL;

    $replacements = [];
    foreach ($allTokensByType as $type => $tokens) {
      if ($memo !== NULL) {
        $replacements += $this->resolutionEngine->generateWithMemo($type, $tokens, $data, $options, $bubbleable_metadata, $memo);
      }
      else {
        $replacements += $this->generate($type, $tokens, $data, $options, $bubbleable_metadata);
      }
      if (!empty($options['clear'])) {
        $replacements += array_fill_keys($tokens, '');
      }
    }

    // Apply the same escaping as the parent's doReplace().
    foreach ($replacements as $token => $value) {
      $replacements[$token] = $value instanceof MarkupInterface
        ? $value
        : new HtmlEscapedText($value);
    }

    if (!empty($options['callback'])) {
      $function = $options['callback'];
      $function($replacements, $data, $options, $bubbleable_metadata);
    }

    if (!$bubbleable_metadata_is_passed_in && $this->renderer->hasRenderContext()) {
      $build = [];
      $bubbleable_metadata->applyTo($build);
      $this->renderer->render($build);
    }

    $result = [];
    $tokenKeys = array_keys($replacements);
    $tokenValues = array_values($replacements);
    foreach ($texts as $key => $text) {
      $result[$key] = str_replace($tokenKeys, $tokenValues, (string) $text);
    }
    return $result;
  }

}
