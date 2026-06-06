<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Token\Discovery\AttributedTokenDiscovery;

/**
 * Orchestrates token chain resolution.
 *
 * Chains are traversed structurally: each segment's declared output type
 * becomes the next segment's input type. Cacheability and access compose
 * automatically as the chain is walked. The terminal value is then handed to
 * the renderer for format-aware serialization.
 *
 * Routing is per-token. A token whose first segment is a registered, resolvable
 * attributed token is resolved structurally; everything else (and any chain
 * that cannot be completed structurally) falls through to the legacy hook
 * pipeline, so old hook implementations keep working alongside new resolvers.
 *
 * Access is enforced, not merely composed: when a chain resolves to an access
 * result that is not allowed, the value is dropped (replaced with an empty
 * string) and never falls through to legacy, which is what closes the
 * documented token exfiltration vulnerabilities. Access is checked against the
 * actor carried in the resolution context, never against the current user
 * implicitly.
 *
 * @internal
 */
final class TokenResolutionEngine implements TokenResolutionEngineInterface {

  /**
   * Maximum chain depth.
   *
   * Prevents infinite recursion in misconfigured or adversarial chains. This
   * could be made configurable per-site (e.g. via system.token config) rather
   * than a constant.
   *
   * @var int
   */
  private const MAX_CHAIN_DEPTH = 10;

  /**
   * @param \Drupal\Core\Token\LegacyTokenBridge $legacyBridge
   *   The legacy hook pipeline bridge.
   * @param \Drupal\Core\Token\TokenRegistryInterface $registry
   *   The attributed-resolver registry.
   * @param \Drupal\Core\Token\Discovery\AttributedTokenDiscovery $resolverLocator
   *   Used to retrieve live resolver instances by class name.
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user, used as the default actor when none is supplied.
   * @param \Drupal\Core\Token\TokenRendererInterface $renderer
   *   Serializes the terminal resolved value for the output context.
   * @param \Drupal\Core\Token\ListDeltaResolver $listDeltaResolver
   *   The built-in resolver invoked for numeric (delta) segments on list types.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Supplies the default content language when no langcode is in the options.
   */
  public function __construct(
    private readonly LegacyTokenBridge $legacyBridge,
    private readonly TokenRegistryInterface $registry,
    private readonly AttributedTokenDiscovery $resolverLocator,
    private readonly AccountInterface $currentUser,
    private readonly TokenRendererInterface $renderer,
    private readonly ListDeltaResolver $listDeltaResolver,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generate(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    $rootInput = $data[$type] ?? NULL;

    // Entities are also reachable under their typed-data input type
    // ('entity:node'), which is where auto-discovered field tokens and
    // entity-reference chains live. The legacy type name ('node') is always
    // consulted first, so curated legacy tokens are never shadowed; the typed
    // entity slice is only used for first-segments legacy does not define.
    $entityType = $rootInput instanceof EntityInterface
      ? 'entity:' . $rootInput->getEntityTypeId()
      : NULL;

    $actor = $this->resolveActor($options);
    $outputContext = $this->resolveOutputContext($options);
    $langcode = $this->resolveLangcode($options);

    $replacements = [];
    $legacyTokens = [];

    foreach ($tokens as $name => $raw) {
      $segments = explode(':', $name);
      $firstSegment = $segments[0];

      // Determine the input type the chain starts from, consulting only
      // resolvable (new-system) definitions so routing never builds legacy
      // hook_token_info(). Tokens that are not resolvable here fall through to
      // the legacy hook pipeline.
      $startType = NULL;
      if ($this->isResolvable($this->registry->getResolvableToken($type, $firstSegment))) {
        // An attributed resolver registered directly under the legacy type
        // name (an explicit migration), or a non-entity root such as the
        // fake_comment test fixture.
        $startType = $type;
      }
      elseif ($entityType !== NULL && count($segments) >= 2
        && $this->isResolvable($this->registry->getResolvableToken($entityType, $firstSegment))) {
        // A traversal chain rooted on an entity: auto-discovered field tokens
        // and entity-reference chains live under the typed entity input type.
        // Restricting this to multi-segment chains ensures single-segment
        // curated legacy tokens (e.g. [node:created]) are never shadowed by
        // auto-discovered field tokens of the same name.
        $startType = $entityType;
      }

      if ($startType === NULL) {
        $legacyTokens[$name] = $raw;
        continue;
      }

      $context = new TokenResolutionContext($data, $actor, $outputContext);
      $result = $this->resolveChain($startType, $segments, $rootInput, $context);

      if ($result === NULL) {
        // The chain could not be completed structurally; fall through.
        $legacyTokens[$name] = $raw;
        continue;
      }

      // Compose the chain's cacheability and access into the caller's metadata.
      // Access carries its own cache contexts (e.g. 'user'), so it bubbles
      // whether or not access is granted.
      $bubbleable_metadata->addCacheableDependency($result->cacheability);
      $bubbleable_metadata->addCacheableDependency($result->access);

      if (!$result->access->isAllowed()) {
        // Access denied for the resolved viewer: drop the value rather than
        // leaking it, and do not fall through to legacy.
        $replacements[$raw] = '';
        continue;
      }

      $rendered = $this->renderer->render(
        $result->value,
        $result->outputType ?? 'string',
        $outputContext,
        $langcode,
      );
      // In the HTML context the renderer has already escaped the value, so mark
      // it safe to prevent the replace pipeline from escaping it twice. Other
      // contexts return a plain string that the pipeline leaves intact.
      $replacements[$raw] = $outputContext === OutputContext::Html
        ? Markup::create($rendered)
        : $rendered;
    }

    if (!empty($legacyTokens)) {
      $legacyReplacements = $this->legacyBridge->generate($type, $legacyTokens, $data, $options, $bubbleable_metadata);
      $replacements += $legacyReplacements;
    }

    return $replacements;
  }

  /**
   * {@inheritdoc}
   */
  public function resolveChain(string $rootType, array $segments, mixed $rootInput, TokenResolutionContext $context): ?TokenResult {
    if (count($segments) > self::MAX_CHAIN_DEPTH) {
      return NULL;
    }

    $currentType = $rootType;
    $currentInput = $rootInput;
    $accumulated = TokenResult::fromValue($rootInput);

    // Enforce view access on the root entity against the viewer. The caller
    // provided the object, but token output is still gated on the viewer being
    // permitted to view it, which closes root-level exfiltration. Access on
    // entities traversed-to later in the chain is enforced by the 'entity'
    // deref, and field-level access by the field resolvers.
    if ($rootInput instanceof EntityInterface) {
      $accumulated = $accumulated->withAccess($rootInput->access('view', $context->actor->viewer, TRUE));
    }

    $count = count($segments);
    for ($i = 0; $i < $count; $i++) {
      $segment = $segments[$i];
      $definition = $this->registry->getResolvableToken($currentType, $segment);

      if ($this->isResolvable($definition)) {
        $resolver = $this->resolverLocator->getResolver($definition->resolverClass);
        if ($resolver === NULL) {
          return NULL;
        }

        $arguments = ['name' => $definition->name, 'path' => $definition->path];

        // A token that declares an argument name consumes the remainder of the
        // chain as that single argument and terminates traversal. This is the
        // [node:created:custom:Y-m-d] case: 'custom' takes 'Y-m-d' as 'format'.
        if ($definition->argumentName !== NULL) {
          $arguments[$definition->argumentName] = implode(':', array_slice($segments, $i + 1));
          $result = $resolver->resolve($currentInput, $arguments, $context);
          $accumulated = $accumulated->merge($result);
          $currentType = $definition->outputType;
          break;
        }

        $result = $resolver->resolve($currentInput, $arguments, $context);
        $accumulated = $accumulated->merge($result);
        $currentInput = $result->value;
        $currentType = $definition->outputType;
        continue;
      }

      // A numeric segment on a list output type is the built-in delta index
      // operation: it selects the item at that delta, producing the item type.
      // This keeps "every segment is a token" honest by treating the index as a
      // system-provided token defined against list input types.
      $itemType = $this->listItemType($currentType);
      if ($itemType !== NULL && is_numeric($segment)) {
        $result = $this->listDeltaResolver->resolve($currentInput, ['delta' => (int) $segment, 'name' => $segment], $context);
        $accumulated = $accumulated->merge($result);
        $currentInput = $result->value;
        $currentType = $itemType;
        continue;
      }

      // This segment is not registered and is not a recognised index: the chain
      // cannot be completed structurally. Signal a fall back to legacy.
      return NULL;
    }

    return $accumulated->withOutputType($currentType);
  }

  /**
   * Returns the item type for a list output type, or NULL when not a list.
   *
   * Recognises 'list<T>' (item type T) and a bare 'list' (item type 'string').
   *
   * @param string $outputType
   *   The output type to inspect.
   *
   * @return string|null
   *   The item type, or NULL when $outputType is not a list type.
   */
  private function listItemType(string $outputType): ?string {
    if ($outputType === 'list') {
      return 'string';
    }
    if (preg_match('/^list<(.+)>$/', $outputType, $matches) === 1) {
      return $matches[1];
    }
    return NULL;
  }

  /**
   * Resolves the actor context from the options array.
   *
   * Supported keys:
   *  - 'token_actor': an explicit ActorContext (used verbatim).
   *  - 'viewer': an AccountInterface to access-check against.
   * When neither is supplied the current user is used as the viewer.
   *
   * @param array<string, mixed> $options
   *   The replacement options.
   *
   * @return \Drupal\Core\Token\ActorContext
   *   The resolved actor context.
   */
  private function resolveActor(array $options): ActorContext {
    if (($options['token_actor'] ?? NULL) instanceof ActorContext) {
      return $options['token_actor'];
    }
    $viewer = ($options['viewer'] ?? NULL) instanceof AccountInterface ? $options['viewer'] : $this->currentUser;
    return new ActorContext($viewer);
  }

  /**
   * Resolves the output context from the options array, defaulting to HTML.
   *
   * @param array<string, mixed> $options
   *   The replacement options.
   *
   * @return \Drupal\Core\Token\OutputContext
   *   The resolved output context.
   */
  private function resolveOutputContext(array $options): OutputContext {
    if (($options['output_context'] ?? NULL) instanceof OutputContext) {
      return $options['output_context'];
    }
    return OutputContext::Html;
  }

  /**
   * Resolves the language tokens are being rendered for.
   *
   * Honours the long-standing 'langcode' replacement option, which callers such
   * as pathauto already set per translation when generating that translation's
   * alias. When absent, the current content language is used (which itself
   * falls back to the site default), so locale-sensitive rendering (date
   * formatting, slug transliteration) matches the language being resolved.
   *
   * @param array<string, mixed> $options
   *   The replacement options.
   *
   * @return string
   *   The resolved language code.
   */
  private function resolveLangcode(array $options): string {
    if (is_string($options['langcode'] ?? NULL) && $options['langcode'] !== '') {
      return $options['langcode'];
    }
    return $this->languageManager->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
  }

  /**
   * Returns TRUE when the definition exists and has a usable resolver class.
   *
   * @param \Drupal\Core\Token\TokenDefinition|null $definition
   *   The token definition to check, or NULL.
   *
   * @return bool
   *   TRUE when the definition is backed by a usable resolver class.
   */
  private function isResolvable(?TokenDefinition $definition): bool {
    return $definition !== NULL
      && $definition->resolverClass !== NULL
      && class_exists($definition->resolverClass)
      && is_a($definition->resolverClass, TokenResolverInterface::class, TRUE);
  }

}
