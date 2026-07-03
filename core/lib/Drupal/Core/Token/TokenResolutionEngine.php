<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Token\Discovery\AttributedTokenDiscovery;
use Drupal\Core\Token\Event\TokenResultAlterEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

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
   * Whether the no-viewer deprecation has fired for this engine instance.
   *
   * The engine is a request-scoped service, so instance-level once-guarding
   * amortizes the trigger_error() cost across a request no matter how many
   * viewer-less replacements it performs (contrib token's hook
   * implementations recurse into generate() on every legacy field token, so
   * per-call firing measurably regressed the legacy path — see the token
   * replacement benchmark's scenario 4a). Kernel tests build a fresh
   * container (and thus a fresh engine) per test method, so tests asserting
   * the deprecation still observe it without any reset hook.
   */
  private bool $noViewerDeprecationTriggered = FALSE;

  /**
   * @param \Drupal\Core\Token\LegacyTokenBridge $legacyBridge
   *   The legacy hook pipeline bridge.
   * @param \Drupal\Core\Token\TokenRegistryInterface $registry
   *   The attributed-resolver registry.
   * @param \Drupal\Core\Token\Discovery\AttributedTokenDiscovery $resolverLocator
   *   Used to retrieve live resolver instances by class name.
   * @param \Drupal\Core\Token\TokenRendererInterface $renderer
   *   Serializes the terminal resolved value for the output context.
   * @param \Drupal\Core\Token\ListDeltaResolver $listDeltaResolver
   *   The built-in resolver invoked for numeric (delta) segments on list types.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   Supplies the default content language when no langcode is in the options.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $eventDispatcher
   *   Dispatches TokenResultAlterEvent once per engine-resolved token.
   */
  public function __construct(
    private readonly LegacyTokenBridge $legacyBridge,
    private readonly TokenRegistryInterface $registry,
    private readonly AttributedTokenDiscovery $resolverLocator,
    private readonly TokenRendererInterface $renderer,
    private readonly ListDeltaResolver $listDeltaResolver,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EventDispatcherInterface $eventDispatcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function generate(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata): array {
    return $this->generateWithMemo($type, $tokens, $data, $options, $bubbleable_metadata, NULL);
  }

  /**
   * Generates replacements with an optional shared chain-prefix memo.
   *
   * When $memo is non-NULL the engine will look up and store partial chain
   * results in it so that shared prefix walks are only performed once per
   * batch. Called by generate() (which passes NULL) and by Token::replaceMultiple()
   * (which passes one memo for the whole batch).
   *
   * @param string $type
   *   The token type.
   * @param array $tokens
   *   Tokens to replace.
   * @param array $data
   *   Keyed data objects.
   * @param array $options
   *   Options such as 'langcode' and 'clear'.
   * @param \Drupal\Core\Render\BubbleableMetadata $bubbleable_metadata
   *   Accumulator; mutated in place.
   * @param \Drupal\Core\Token\ChainPrefixMemo|null $memo
   *   Optional shared prefix memo for this batch. NULL disables memoization.
   *
   * @return array
   *   Replacement values keyed by the raw token string.
   *
   * @internal
   */
  public function generateWithMemo(string $type, array $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata, ?ChainPrefixMemo $memo): array {
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

    // Root-token engine activation: when the caller supplied no $data entry for
    // this type, a root definition (input type '', e.g. ":current-user") may
    // still be able to produce the root value itself, binding the viewer (e.g.
    // loading the current-user's user entity from the actor rather than from
    // $data). This only has meaning when there is a viewer to bind: in the
    // unenforced tier $rootResult stays NULL and every token of this type falls
    // through to the legacy pipeline below exactly as it did before this
    // activation existed, which is byte-identical legacy output.
    $rootDefinition = NULL;
    $rootResult = NULL;
    if ($rootInput === NULL && $actor->isEnforced()) {
      $candidate = $this->registry->getResolvableToken('', $type);
      if ($this->isResolvable($candidate)) {
        $rootResolver = $this->resolverLocator->getResolver($candidate->resolverClass);
        if ($rootResolver !== NULL) {
          $rootDefinition = $candidate;
          $rootContext = new TokenResolutionContext($data, $actor, $outputContext);
          $rootResult = $rootResolver->resolve(NULL, ['name' => $candidate->name, 'path' => $candidate->path], $rootContext);
        }
      }
    }

    foreach ($tokens as $name => $raw) {
      $segments = explode(':', $name);
      $firstSegment = $segments[0];

      // Determine the input type the chain starts from, consulting only
      // resolvable (new-system) definitions so routing never builds legacy
      // hook_token_info(). Tokens that are not resolvable here fall through to
      // the legacy hook pipeline.
      $startType = NULL;
      $chainInput = $rootInput;
      $fromRootActivation = FALSE;
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
      elseif ($rootResult !== NULL) {
        // Walk the remaining segments from the root definition's own output
        // type, using the value the root resolver produced (not $rootInput,
        // which is NULL here) as the chain's starting input.
        $startType = $rootDefinition->outputType;
        $chainInput = $rootResult->value;
        $fromRootActivation = TRUE;
      }

      if ($startType === NULL) {
        $legacyTokens[$name] = $raw;
        continue;
      }

      $context = new TokenResolutionContext($data, $actor, $outputContext);
      $result = $this->resolveChain($startType, $segments, $chainInput, $context, $memo);

      if ($result === NULL) {
        // The chain could not be completed structurally; fall through.
        $legacyTokens[$name] = $raw;
        continue;
      }

      if ($fromRootActivation) {
        // Compose the root resolver's own cacheability and access (e.g. the
        // 'user' cache context and the loaded entity's view access) with the
        // chain walked from its output type, the same merge() used between any
        // two segments in resolveChain().
        $result = $rootResult->merge($result);
      }

      // Dispatch the alter event now: the composed result is final (root
      // activation's own contribution merged in, where applicable), but
      // caller-metadata composition, the access gate, and rendering have not
      // run yet, so a subscriber's access and cacheability are enforced and
      // bubbled exactly like a resolver's own contribution would be. This
      // fires once per engine-resolved token, including tokens resumed from a
      // memoized chain prefix — the memo only skips recomputation of segments
      // already walked inside resolveChain(); every token in this loop still
      // reaches this dispatch exactly once, whether its $result came from a
      // fresh walk or a memo hit. Tokens that fell through to $legacyTokens
      // above never reach here; they keep flowing through
      // hook_tokens_alter() via the legacy bridge below.
      $event = new TokenResultAlterEvent($raw, $type, $name, $context, $result);
      $this->eventDispatcher->dispatch($event, TokenResultAlterEvent::RESULT_ALTER);
      $result = $event->getResult();

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
  public function resolveChain(string $rootType, array $segments, mixed $rootInput, TokenResolutionContext $context, ?ChainPrefixMemo $memo = NULL): ?TokenResult {
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
    // In the unenforced tier (no viewer) this check is skipped and access is
    // unconditionally allowed, reproducing legacy resolution, which never
    // checked access at all.
    if ($rootInput instanceof EntityInterface) {
      $accumulated = $accumulated->withAccess(
        $context->actor->isEnforced()
          ? $rootInput->access('view', $context->actor->viewer, TRUE)
          : AccessResult::allowed()
      );
    }

    $startIndex = 0;
    // Track only the contribution of the prefix segments (cacheability + access
    // from the resolvers themselves), separate from $accumulated which includes
    // the root entity access check above. This is what gets stored in and
    // retrieved from the memo so root entity access is never double-applied.
    $prefixContribution = TokenResult::fromValue(NULL);

    // Resume from the longest memoized prefix, if any.
    if ($memo !== NULL) {
      $hit = $memo->lookup($rootType, $rootInput, $segments);
      if ($hit !== NULL) {
        $entry = $hit['entry'];
        // entry->accumulated holds the prefix contribution only (no root access).
        // Merge it into $accumulated (which already has root entity access) so
        // the root access is not double-applied.
        $accumulated = $accumulated->merge($entry->accumulated);
        $prefixContribution = $entry->accumulated;
        $currentInput = $entry->currentInput;
        $currentType = $entry->currentType;
        $startIndex = $hit['depth'];
      }
    }

    $count = count($segments);
    for ($i = $startIndex; $i < $count; $i++) {
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
        $prefixContribution = $prefixContribution->merge($result);
        $currentInput = $result->value;
        $currentType = $definition->outputType;
        if ($memo !== NULL) {
          $memo->store($rootType, $rootInput, array_slice($segments, 0, $i + 1), $prefixContribution, $currentInput, $currentType);
        }
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
        $prefixContribution = $prefixContribution->merge($result);
        $currentInput = $result->value;
        $currentType = $itemType;
        if ($memo !== NULL) {
          $memo->store($rootType, $rootInput, array_slice($segments, 0, $i + 1), $prefixContribution, $currentInput, $currentType);
        }
        continue;
      }

      // Rule B — implicit delta-0 coercion: when the current type is a list and
      // the segment is non-numeric, resolve delta 0 implicitly and re-evaluate
      // the same segment against the item type. At most one coercion attempt per
      // segment: if the re-evaluated segment still matches nothing, fall through
      // to legacy as normal. This makes bare spellings like
      // [node:field_refs:entity:name] equivalent to
      // [node:field_refs:0:entity:name].
      if ($itemType !== NULL) {
        $deltaResult = $this->listDeltaResolver->resolve($currentInput, ['delta' => 0, 'name' => '0'], $context);
        $coercedInput = $deltaResult->value;
        $coercedType = $itemType;
        $reEvaluatedDefinition = $this->registry->getResolvableToken($coercedType, $segment);
        if (!$this->isResolvable($reEvaluatedDefinition)) {
          // The segment still matches nothing on the item type; fall back.
          return NULL;
        }
        // Coercion succeeded: merge the implicit delta result and re-evaluate.
        $accumulated = $accumulated->merge($deltaResult);
        $prefixContribution = $prefixContribution->merge($deltaResult);
        $currentInput = $coercedInput;
        $currentType = $coercedType;
        // Memo is NOT stored here because $i is about to be decremented — the
        // coerced implicit-delta step is not yet a completed segment from the
        // caller's perspective. The next iteration (same $i, now against item
        // type) will store after the attributed resolver runs.
        // Do not advance $i — the loop will re-visit this segment against the
        // item type on the next iteration. The registered-token branch above
        // will now match.
        $i--;
        continue;
      }

      // Rule C — identity zero on a non-list type: when the current type is not
      // a list and the segment is the literal "0", consume the segment without
      // changing the value or type. This makes ":0:" spellings survive a field
      // being reconfigured from multi-value back to single-value, because the
      // single value is already "the zeroth item". Any other numeric segment on
      // a non-list type has no defined meaning; fall through to legacy.
      if ($segment === '0') {
        // Value and type are unchanged; just advance past the segment.
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
   *  - 'viewer': an AccountInterface to access-check against (enforced tier).
   * When neither is supplied, resolution runs in the unenforced tier (a NULL
   * viewer): legacy-equivalent output with no access enforcement anywhere in
   * the chain, for BC with callers that predate the actor model. This is
   * deprecated; callers should pass 'viewer' to get access-checked
   * replacement.
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
    if (($options['viewer'] ?? NULL) instanceof AccountInterface) {
      return new ActorContext($options['viewer']);
    }
    if (!$this->noViewerDeprecationTriggered) {
      $this->noViewerDeprecationTriggered = TRUE;
      @trigger_error("Calling Drupal\Core\Utility\Token::replace() without a 'viewer' option is deprecated in drupal:12.0.0 and unenforced token resolution is removed from drupal:13.0.0. Pass options['viewer'] to enable access-checked replacement. See https://www.drupal.org/node/3593502", E_USER_DEPRECATED);
    }
    return new ActorContext(NULL);
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
