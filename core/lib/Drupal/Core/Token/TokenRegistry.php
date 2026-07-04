<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Token\Discovery\TokenDiscoveryInterface;
use Drupal\Core\Token\Event\TokenDiscoveryAlterEvent;
use Drupal\Core\Utility\Token;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Per-input-type token registry with lazy, language-aware cache slices.
 *
 * A slice is built only when its input type is first requested during chain
 * traversal, and never before. The full token set is never assembled or cached
 * as one blob. Each slice is composed from, in order:
 *
 * 1. Legacy hook_token_info() output for the input type (loaded once per
 *    request via the cached hook system, then sliced by type).
 * 2. Every TokenDiscoveryInterface source (attributed resolvers, typed-data
 *    field discovery, and future config-entity discovery), each queried only
 *    for the requested input type. A source-provided definition for an
 *    (input_type, name) pair replaces any legacy definition for that pair, so
 *    once a token is migrated the new resolver takes precedence.
 * 3. A TokenDiscoveryAlterEvent dispatched for the input type. Subscribers
 *    receive the slice and may add, remove, or modify definitions. Because the
 *    event fires once per input type, a subscriber that contributes tokens to
 *    many types (the fieldTokenInfoAlter pattern) simply runs for each type as
 *    that type's slice is built.
 *
 * Token identity is always (input_type, name). Never key by name alone.
 *
 * @internal
 */
final class TokenRegistry implements TokenRegistryInterface {

  /**
   * In-process cache of TokenDefinition arrays keyed by persistent cache ID.
   *
   * @var array<string, array<string, \Drupal\Core\Token\TokenDefinition>>
   */
  private array $staticCache = [];

  /**
   * The full legacy hook_token_info() output, loaded at most once per request.
   *
   * @var array<string, array>|null
   */
  private ?array $legacyInfo = NULL;

  /**
   * Auto-discovery sources (lowest precedence; gap-filling).
   *
   * Typed-data field discovery and, in future, config-entity discovery. These
   * derive tokens automatically and must never override a token that was
   * defined deliberately elsewhere (a legacy declaration, an attributed
   * resolver, or the alter event).
   *
   * @var \Drupal\Core\Token\Discovery\TokenDiscoveryInterface[]
   */
  private readonly array $autoDiscoverySources;

  /**
   * Explicit discovery sources (override auto-discovery and legacy).
   *
   * Attributed `#[Token]` resolvers. A deliberate declaration takes precedence
   * over the auto-discovered field of the same (input_type, name).
   *
   * @var \Drupal\Core\Token\Discovery\TokenDiscoveryInterface[]
   */
  private readonly array $explicitDiscoverySources;

  /**
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $moduleHandler
   *   The module handler.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The token cache backend.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   The event dispatcher for firing TokenDiscoveryAlterEvent.
   * @param iterable<\Drupal\Core\Token\Discovery\TokenDiscoveryInterface> $autoDiscoverySources
   *   Auto-discovery sources (lowest precedence), collected via the
   *   `token.discovery.auto` tag.
   * @param iterable<\Drupal\Core\Token\Discovery\TokenDiscoveryInterface> $explicitDiscoverySources
   *   Explicit discovery sources (override auto-discovery), collected via the
   *   `token.discovery` tag.
   * @param \Drupal\Core\Cache\CacheTagsInvalidatorInterface $cacheTagsInvalidator
   *   The cache tags invalidator, used by invalidate() to clear persistent
   *   cache entries keyed by the token_info tag.
   */
  public function __construct(
    private readonly ModuleHandlerInterface $moduleHandler,
    private readonly CacheBackendInterface $cache,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EventDispatcherInterface $eventDispatcher,
    iterable $autoDiscoverySources,
    iterable $explicitDiscoverySources,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {
    $this->autoDiscoverySources = $this->filterSources($autoDiscoverySources);
    $this->explicitDiscoverySources = $this->filterSources($explicitDiscoverySources);
  }

  /**
   * Filters an iterable down to the valid discovery sources.
   *
   * @param iterable<object> $sources
   *   The candidate sources.
   *
   * @return \Drupal\Core\Token\Discovery\TokenDiscoveryInterface[]
   *   The discovery sources.
   */
  private function filterSources(iterable $sources): array {
    $filtered = [];
    foreach ($sources as $source) {
      if ($source instanceof TokenDiscoveryInterface) {
        $filtered[] = $source;
      }
    }
    return $filtered;
  }

  /**
   * {@inheritdoc}
   */
  public function getTokensForInputType(string $inputType): array {
    return $this->loadSlice($inputType, TRUE);
  }

  /**
   * {@inheritdoc}
   */
  public function getToken(string $inputType, string $name): ?TokenDefinition {
    return $this->getTokensForInputType($inputType)[$name] ?? NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getResolvableToken(string $inputType, string $name): ?TokenDefinition {
    $definition = $this->loadSlice($inputType, FALSE)[$name] ?? NULL;
    if ($definition !== NULL) {
      return $definition;
    }

    // Most-specific-wins: the concrete slice missed, so fall back to each
    // ancestor's slice in order, returning the first hit. This is the resolve
    // variant only (see loadSlice()'s $includeLegacy parameter): applying the
    // walk here does not change getToken()'s token-info/browser output.
    foreach ($this->getTypeAncestors($inputType) as $ancestorType) {
      $definition = $this->loadSlice($ancestorType, FALSE)[$name] ?? NULL;
      if ($definition !== NULL) {
        return $definition;
      }
    }

    return NULL;
  }

  /**
   * Returns the ancestors of an input type, most specific first.
   *
   * An ancestor is produced by progressively stripping the trailing
   * ':<segment>' from the type string, e.g. 'entity:node:article' yields
   * ['entity:node', 'entity']; 'entity_reference:user' yields
   * ['entity_reference']. A colon-free type (including the root type, '') has
   * no ancestors. Stripping always stops at the last segment: the result never
   * contains '' and never contains the original type. Parameterized types
   * (e.g. 'list<entity_reference:user>') have no ancestors either: stripping
   * at their inner colon would fabricate a nonsense type and pollute the slice
   * cache; their item type is handled by the engine's list rules, not by
   * assignability.
   *
   * This is the POC's assignability rule for input-type matching, mirroring
   * typed-data's derivative-ID convention (a plugin ID's ':'-delimited suffix
   * denotes a more specific variant of its prefix). The eventual integration
   * point, once this POC graduates, is
   * \Drupal\Core\Plugin\Context\ContextDefinition::isSatisfiedBy(), which
   * already expresses the equivalent assignability relation for typed data
   * definitions.
   *
   * @param string $type
   *   The input type to expand.
   *
   * @return string[]
   *   The ancestor types, most specific first.
   */
  private function getTypeAncestors(string $type): array {
    if (str_contains($type, '<')) {
      return [];
    }
    $ancestors = [];
    $remaining = $type;
    while (($position = strrpos($remaining, ':')) !== FALSE) {
      $remaining = substr($remaining, 0, $position);
      if ($remaining === '') {
        break;
      }
      $ancestors[] = $remaining;
    }
    return $ancestors;
  }

  /**
   * Loads (and caches) a definition slice for an input type.
   *
   * @param string $inputType
   *   The input type.
   * @param bool $includeLegacy
   *   When TRUE the slice includes legacy hook_token_info() definitions (for
   *   token-info/UI). When FALSE only discovery sources and the alter event are
   *   consulted, so no hook_token_info() build is triggered (the resolution
   *   path).
   *
   * @return array<string, \Drupal\Core\Token\TokenDefinition>
   *   Definitions keyed by token name.
   */
  private function loadSlice(string $inputType, bool $includeLegacy): array {
    $langcode = $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $variant = $includeLegacy ? 'info' : 'resolve';
    $cacheId = "token_registry:v1:{$variant}:{$langcode}:{$inputType}";

    if (isset($this->staticCache[$cacheId])) {
      return $this->staticCache[$cacheId];
    }

    if ($cached = $this->cache->get($cacheId)) {
      return $this->staticCache[$cacheId] = $cached->data;
    }

    $slice = $this->buildSlice($inputType, $includeLegacy);

    $this->cache->set($cacheId, $slice, CacheBackendInterface::CACHE_PERMANENT, [
      Token::TOKEN_INFO_CACHE_TAG,
    ]);

    return $this->staticCache[$cacheId] = $slice;
  }

  /**
   * {@inheritdoc}
   */
  public function invalidate(): void {
    $this->staticCache = [];
    $this->legacyInfo = NULL;
    $this->cacheTagsInvalidator->invalidateTags([Token::TOKEN_INFO_CACHE_TAG]);
  }

  /**
   * Builds the definition slice for a single input type.
   *
   * @param string $inputType
   *   The input type to build.
   * @param bool $includeLegacy
   *   Whether to seed the slice with legacy hook_token_info() definitions.
   *
   * @return array<string, \Drupal\Core\Token\TokenDefinition>
   *   Definitions keyed by token name.
   */
  private function buildSlice(string $inputType, bool $includeLegacy): array {
    // The slice is layered by precedence, lowest first; a later layer overrides
    // an earlier one for the same token name. Auto-discovery is the lowest layer
    // so it never silently overrides a token defined deliberately elsewhere.
    $slice = [];

    // Layer 1 (lowest): auto-discovery. Typed-data field discovery and, later,
    // config-entity discovery. These are derived, not declared.
    foreach ($this->autoDiscoverySources as $source) {
      foreach ($source->discoverForInputType($inputType) as $name => $definition) {
        $slice[$name] = $definition;
      }
    }

    // Layer 2: legacy hook_token_info() definitions (info variant only). Curated
    // legacy tokens override an auto-discovered field of the same name. The
    // resolution variant never builds legacy info, so token replacement does not
    // trigger a full hook_token_info() build.
    if ($includeLegacy) {
      foreach ($this->buildLegacyDefinitionsForType($inputType) as $name => $definition) {
        $slice[$name] = $definition;
      }
    }

    // Layer 3: explicit attributed declarations. A deliberate `#[Token]`
    // resolver overrides both auto-discovery and legacy for the same identity,
    // which is how a provider migrates or replaces a token on purpose.
    foreach ($this->explicitDiscoverySources as $source) {
      foreach ($source->discoverForInputType($inputType) as $name => $definition) {
        $slice[$name] = $definition;
      }
    }

    // Layer 4 (highest): the alter event has the final say. Subscribers may add,
    // remove, or modify definitions. The event carries only this type, so
    // additions to other types are ignored when reading the slice back.
    $event = new TokenDiscoveryAlterEvent([$inputType => $slice]);
    $this->eventDispatcher->dispatch($event, TokenDiscoveryAlterEvent::DISCOVERY_ALTER);

    return $event->getDefinitionsForInputType($inputType);
  }

  /**
   * Converts the legacy hook_token_info() output for one input type.
   *
   * @param string $inputType
   *   The input type (legacy type key, e.g. 'node', 'site').
   *
   * @return array<string, \Drupal\Core\Token\TokenDefinition>
   *   Definitions keyed by token name.
   */
  private function buildLegacyDefinitionsForType(string $inputType): array {
    $info = $this->getLegacyInfo();
    $typeModule = $info['types'][$inputType]['module'] ?? NULL;

    $definitions = [];
    foreach ($info['tokens'][$inputType] ?? [] as $name => $tokenInfo) {
      // Legacy hook_token_info() may supply MarkupInterface objects as label or
      // description. TokenDefinition only accepts TranslatableMarkup|string|null,
      // so cast any other MarkupInterface objects to plain string for BC.
      $label = $tokenInfo['name'] ?? NULL;
      if ($label instanceof MarkupInterface) {
        $label = (string) $label;
      }
      $description = $tokenInfo['description'] ?? NULL;
      if ($description instanceof MarkupInterface) {
        $description = (string) $description;
      }
      $definitions[$name] = new TokenDefinition(
        name: (string) $name,
        inputType: $inputType,
        outputType: $tokenInfo['type'] ?? 'string',
        label: $label,
        description: $description,
        module: $tokenInfo['module'] ?? $typeModule,
      );
    }

    return $definitions;
  }

  /**
   * Returns the full legacy hook_token_info() output, loading it once per request.
   *
   * @return array<string, array>
   *   The hook_token_info() output, keyed by 'types' and 'tokens'.
   */
  private function getLegacyInfo(): array {
    if ($this->legacyInfo === NULL) {
      $this->legacyInfo = $this->moduleHandler->invokeAll('token_info');
      $this->moduleHandler->alter('token_info', $this->legacyInfo);
    }
    return $this->legacyInfo;
  }

}
