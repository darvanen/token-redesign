<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\AttributeClassDiscovery;
use Drupal\token_engine\Attribute\Token;

/**
 * Plugin manager for attributed token resolvers.
 *
 * Token resolvers are discovered statically from the `#[Token]` attribute on
 * classes in each module's `Plugin\Token` namespace. Definitions are built from
 * the attributes alone (no instantiation) and cached, and a resolver instance
 * is created lazily only when one of its tokens is actually resolved. Resolvers
 * that need services implement
 * \Drupal\Core\Plugin\ContainerFactoryPluginInterface.
 *
 * The plugin ID is the token identity, "{input_type}:{name}".
 *
 * Modules declare resolver plugins via the #[Token] attribute; they do not call
 * this manager directly. It is internal plumbing of the token subsystem.
 *
 * Identity conflicts: two `#[Token]` classes in different modules may declare
 * the same identity (input_type, name), i.e. the same plugin ID. Plain
 * attribute-based discovery would let whichever class happens to be scanned
 * last silently clobber the other, with no record that the loser ever existed.
 * This manager instead groups every discovered class by identity before
 * collapsing them (see findDefinitions() and
 * discoverRawDefinitionsByIdentity()), so it can see every competing class.
 * The winner is picked deterministically — the higher-weight module wins,
 * ties broken by module name — the losing class is dropped and never reaches
 * consumers, a warning is logged on the 'token' channel, and the conflict is
 * recorded for getIdentityConflicts().
 *
 * @see \Drupal\token_engine\Attribute\Token
 * @see \Drupal\token_engine\TokenResolverInterface
 *
 * @internal
 */
final class TokenResolverManager extends DefaultPluginManager {

  /**
   * Identity conflicts detected during discovery, keyed by plugin ID.
   *
   * Each value is the list of resolver class names that declared the same
   * identity, winner first. Only identities with more than one declaring
   * class appear here. NULL means discovery has not run in this request yet
   * (see getIdentityConflicts()).
   *
   * Populated by findDefinitions() and persisted inside the same cache entry
   * as the definitions (see getCachedDefinitions()/setCachedDefinitions()), so
   * it survives the plugin definition cache round-trip instead of requiring a
   * second cache entry to stay in sync with.
   *
   * @var array<string, list<class-string>>|null
   */
  private ?array $identityConflicts = NULL;

  public function __construct(\Traversable $namespaces, CacheBackendInterface $cache_backend, ModuleHandlerInterface $module_handler) {
    parent::__construct(
      'Plugin/Token',
      $namespaces,
      $module_handler,
      TokenResolverInterface::class,
      Token::class,
    );
    $this->setCacheBackend($cache_backend, 'token_resolver_plugins');
  }

  /**
   * Returns identity conflicts detected among discovered `#[Token]` classes.
   *
   * Callers that only need to know whether conflicts exist (e.g. a future
   * status report) can use this without caring whether discovery ran in this
   * request or the definitions came straight from cache: calling this always
   * ensures the definitions (and therefore the conflict data cached alongside
   * them) are loaded first.
   *
   * @return array<string, list<class-string>>
   *   Competing resolver class names keyed by the identity (plugin ID) they
   *   all declared, winner first. Empty when no conflicts were found.
   */
  public function getIdentityConflicts(): array {
    // Loading the definitions guarantees $this->identityConflicts is
    // populated, either by findDefinitions() running just now, or restored
    // from the cache entry a previous request wrote alongside the
    // definitions.
    $this->getDefinitions();
    return $this->identityConflicts ?? [];
  }

  /**
   * {@inheritdoc}
   *
   * Overridden so that identity (input_type, name) conflicts among
   * `#[Token]` classes can be detected before they are collapsed to one
   * definition per plugin ID. The inherited implementation calls
   * $this->getDiscovery()->getDefinitions(), which returns a plain
   * associative array keyed by plugin ID — exactly what makes conflicts
   * invisible, since assembling that array already means the loser was
   * silently overwritten by whichever class the discovery scan happened to
   * visit last, with no trace of the loser remaining. Instead, this discovers
   * every class grouped by identity (see discoverRawDefinitionsByIdentity())
   * so every competing class is seen, then resolves each conflict
   * deterministically before continuing with the standard
   * processDefinition() / alterDefinitions() / provider-existence pipeline.
   */
  protected function findDefinitions() {
    $candidatesByIdentity = $this->discoverRawDefinitionsByIdentity();
    $rank = $this->modulePriorityRank();

    $definitions = [];
    $conflicts = [];
    foreach ($candidatesByIdentity as $plugin_id => $candidates) {
      // The module list is already ordered by ascending weight, then
      // ascending module name (see module_config_sort()) — the same order
      // hook_alter() implementations run in. Sorting candidates into that
      // same order and taking the last one means the winner is whichever
      // module would run *last* in a hook_alter() chain, i.e. the module
      // that would have the final word overwriting the others. That is the
      // deterministic rule this method implements: higher module weight
      // wins; a tie is broken by the alphabetically last module name.
      usort($candidates, fn (array $a, array $b): int =>
        ($rank[$a['provider']] ?? -1) <=> ($rank[$b['provider']] ?? -1)
      );
      $winner = end($candidates);
      $definitions[$plugin_id] = $winner['definition'];

      if (count($candidates) > 1) {
        $losers = array_filter($candidates, fn (array $candidate): bool => $candidate['class'] !== $winner['class']);
        $conflicts[$plugin_id] = array_merge([$winner['class']], array_column($losers, 'class'));
        foreach ($losers as $loser) {
          \Drupal::logger('token')->warning(
            'Token identity %id is declared by more than one #[Token] class: %winner (module %winner_provider) wins over %loser (module %loser_provider); %loser is dropped from the discovered definitions.',
            [
              '%id' => $plugin_id,
              '%winner' => $winner['class'],
              '%winner_provider' => $winner['provider'],
              '%loser' => $loser['class'],
              '%loser_provider' => $loser['provider'],
            ],
          );
        }
      }
    }

    foreach ($definitions as $plugin_id => &$definition) {
      $this->processDefinition($definition, $plugin_id);
    }
    $this->alterDefinitions($definitions);
    // If this plugin was provided by a module that does not exist, remove the
    // plugin definition.
    foreach ($definitions as $plugin_id => $plugin_definition) {
      $provider = $this->extractProviderFromDefinition($plugin_definition);
      if ($provider && !in_array($provider, ['core', 'component']) && !$this->providerExists($provider)) {
        unset($definitions[$plugin_id]);
      }
    }

    $this->identityConflicts = $conflicts;
    return $definitions;
  }

  /**
   * Discovers raw `#[Token]` definitions, grouped by identity.
   *
   * The standard \Drupal\Core\Plugin\Discovery\AttributeClassDiscovery::
   * getDefinitions() assembles a plain associative array keyed by plugin ID
   * while it walks every namespace, so a later class with the same ID
   * silently overwrites an earlier one — there is no signal a collision
   * happened, and no way to recover the loser afterwards. Restricting that
   * class to a single namespace per call to keep the collision visible is not
   * an option either: its dependency check
   * (AttributeClassDiscovery::hasMissingDependencies()) validates a class's
   * interfaces/parent classes against the two-level namespaces of *all*
   * namespaces it was constructed with, computed once in its constructor. A
   * `#[Token]` resolver almost always implements
   * \Drupal\token_engine\TokenResolverInterface or extends
   * \Drupal\token_engine\PathToken, i.e. depends on `Drupal\Core`; scoping the
   * constructor to one module's namespace would make that dependency look
   * "missing" and silently drop the class, which is a correctness regression
   * far worse than the one being fixed.
   *
   * So this keeps the discovery instance constructed with the full,
   * unmodified namespace list — exactly as
   * \Drupal\Core\Plugin\DefaultPluginManager::getDiscovery() does — which
   * keeps dependency-checking correct, but walks the filesystem itself
   * (an anonymous subclass overriding only the assignment) so every matching
   * class is appended to a list per plugin ID instead of overwriting it. The
   * walk reuses the parent class's own protected parseClass() and
   * hasMissingDependencies() so parsing and dependency-safety are identical
   * to the inherited behaviour; only the file-cache fast path is skipped,
   * since this only runs on a plugin-definition cache miss; Token resolver
   * plugins are few, and instantiating a fresh discovery here does not
   * disturb the shared \Drupal\Component\FileCache pool other discovery
   * consumers rely on. `#[Token]` does not support derivatives (no `deriver`
   * property anywhere in its attribute class), so no derivative decorator is
   * needed here, unlike \Drupal\Core\Plugin\DefaultPluginManager::
   * getDiscovery().
   *
   * @return array<string, list<array{provider: string, class: class-string, definition: array}>>
   *   Candidate definitions keyed by identity (plugin ID); each value is the
   *   list of every class competing for that identity. Most identities will
   *   have exactly one candidate.
   */
  private function discoverRawDefinitionsByIdentity(): array {
    $discovery = new class($this->subdir, $this->namespaces, $this->pluginDefinitionAttributeName) extends AttributeClassDiscovery {

      /**
       * Discovers definitions like the parent class, but grouped by ID.
       *
       * A near-verbatim copy of
       * \Drupal\Component\Plugin\Discovery\AttributeClassDiscovery::
       * getDefinitions(), which Drupal\Core's AttributeClassDiscovery does
       * not override; the only change is appending each parsed definition to
       * a list keyed by ID instead of overwriting the previous one, and
       * skipping the file-cache fast path (see the caller's docblock for
       * why). All parsing and dependency-safety comes from the inherited
       * parseClass() and hasMissingDependencies().
       *
       * @return array<string, list<array>>
       *   Parsed `#[Token]` attribute content, grouped by plugin ID.
       */
      public function getDefinitionsGroupedById(): array {
        $definitions = [];
        foreach ($this->getPluginNamespaces() as $namespace => $dirs) {
          foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
              continue;
            }
            $iterator = new \RecursiveIteratorIterator(
              new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileinfo) {
              assert($fileinfo instanceof \SplFileInfo);
              if ($fileinfo->getExtension() !== 'php') {
                continue;
              }
              $sub_path = $iterator->getSubIterator()->getSubPath();
              $sub_path = $sub_path ? str_replace(DIRECTORY_SEPARATOR, '\\', $sub_path) . '\\' : '';
              $class = $namespace . '\\' . $sub_path . $fileinfo->getBasename('.php');
              try {
                if (!class_exists($class)) {
                  continue;
                }
              }
              catch (\Error) {
                continue;
              }
              ['id' => $id, 'content' => $content, 'dependencies' => $dependencies] = $this->parseClass($class, $fileinfo);
              if ($id !== NULL && !$this->hasMissingDependencies($dependencies ?? [])) {
                $definitions[$id][] = $content;
              }
            }
          }
        }
        return $definitions;
      }

    };

    $candidatesByIdentity = [];
    foreach ($discovery->getDefinitionsGroupedById() as $plugin_id => $definitions) {
      foreach ($definitions as $definition) {
        $candidatesByIdentity[$plugin_id][] = [
          'provider' => $this->extractProviderFromDefinition($definition) ?? '',
          'class' => $definition['class'] ?? '',
          'definition' => $definition,
        ];
      }
    }
    return $candidatesByIdentity;
  }

  /**
   * Ranks installed modules by their position in the module handler's list.
   *
   * The module handler's module list is kept sorted by ascending weight, then
   * ascending name (see module_config_sort()), and re-sorts live when a
   * module's weight changes (e.g. via module_set_weight()). Using this order,
   * rather than the (fixed at container-compile time) namespace list this
   * manager already holds, means the priority ranking always reflects the
   * current module weights.
   *
   * @return array<string, int>
   *   Module machine name to its position in the module list; a higher
   *   number means a higher priority (it "runs later").
   */
  private function modulePriorityRank(): array {
    return array_flip(array_keys($this->moduleHandler->getModuleList()));
  }

  /**
   * {@inheritdoc}
   */
  protected function getCachedDefinitions() {
    if (!isset($this->definitions) && ($cache = $this->cacheGet($this->cacheKey))) {
      $this->definitions = $cache->data['definitions'];
      $this->identityConflicts = $cache->data['conflicts'];
    }
    return $this->definitions;
  }

  /**
   * {@inheritdoc}
   *
   * Overridden to persist the identity conflicts computed by findDefinitions()
   * in the same cache entry as the definitions, rather than in a parallel
   * cache entry that could fall out of sync with it (e.g. one being cleared
   * without the other). getCachedDefinitions() unpacks this same structure on
   * a cache hit, so getIdentityConflicts() is accurate whether or not
   * findDefinitions() actually ran in the current request.
   */
  protected function setCachedDefinitions($definitions) {
    $this->cacheSet($this->cacheKey, [
      'definitions' => $definitions,
      'conflicts' => $this->identityConflicts ?? [],
    ], Cache::PERMANENT, $this->cacheTags);
    $this->definitions = $definitions;
  }

  /**
   * {@inheritdoc}
   */
  public function clearCachedDefinitions() {
    parent::clearCachedDefinitions();
    $this->identityConflicts = NULL;
  }

}
