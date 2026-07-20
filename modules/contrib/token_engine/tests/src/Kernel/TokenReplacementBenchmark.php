<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\token_engine\ActorContext;
use Drupal\token_engine\LegacyTokenBridge;
use Drupal\token_engine\TokenResolutionEngineInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Reproducible token-replacement benchmark harness.
 *
 * Measures wall-clock time (hrtime) for nine scenarios against the production
 * token stack. Each test method passes with normal PHPUnit assertions; timing
 * results are written to a deterministic file path and also included in the
 * last test's assertion message for visibility.
 *
 * OUTPUT MECHANISM
 * ────────────────
 * PHPUnit 12's RunTestsInSeparateProcesses mode captures BOTH stdout and stderr
 * from child processes: stdout carries serialised PHPUnit results (echoing would
 * corrupt it), and any non-empty stderr causes the parent runner to report an
 * ERROR via ChildProcessResultProcessor. Therefore this harness writes to a
 * file instead of STDERR and documents the path in the test output.
 *
 * Results are appended to (and fully written by the final test to):
 *
 *   /tmp/token_benchmark_results.txt
 *
 * After running, inspect the table with:
 *
 *   ddev exec cat /tmp/token_benchmark_results.txt
 *
 * LEGACY-VS-ENGINE SEPARATION
 * ────────────────────────────
 * The resolution engine routes each token in two ways:
 *
 * 1. Attributed/engine path – field-chain tokens whose first segment is a
 *    TypedData-discovered definition in the registry (e.g.
 *    [node:field_refs:0:entity:name], [node:uid:entity:name]).  The engine
 *    traverses these structurally and never calls hook_tokens().
 *
 * 2. Legacy-bridge path – tokens whose type:name is not in the attributed
 *    registry (e.g. [node:title], [node:author:display-name], [site:name]).
 *    The engine delegates these to LegacyTokenBridge::generate(), which
 *    invokes hook_tokens() / hook_tokens_alter().
 *
 * The most honest direct comparison available without modifying core is:
 *
 *   a) LegacyTokenBridge::generate() called directly with legacy tokens → pure
 *      hook pipeline, no engine overhead.
 *   b) TokenResolutionEngine::generate() called directly with field-chain
 *      tokens that the engine handles without any hook fall-through → pure
 *      engine path.
 *
 * Caveat: the two sets resolve different tokens (by design; legacy tokens have
 * no attributed resolver, attributed tokens have no hook handler), so the
 * comparison measures per-token dispatch overhead, not identical work.
 * Token::replace() (the normal entry point) routes through the engine which
 * then calls the bridge for any unresolvable token; Scenarios 2 and 3 use
 * this full path to reflect real-world behaviour.
 *
 * MODULE SET
 * ──────────
 * Core: node, user, field, text, filter, system (via parent), path, path_alias.
 * Contrib: token (modules/contrib/token) — enabled when present. The contrib
 * module adds hook_tokens() implementations for many entity types; its
 * FieldTokenDiscoverySubscriber also participates in TokenDiscoveryAlterEvent,
 * exercising the registry's subscriber path.
 *
 * RUNNING
 * ───────
 * ddev exec vendor/bin/phpunit \
 *   -c core/phpunit.xml \
 *   core/modules/system/tests/src/Kernel/Token/TokenReplacementBenchmark.php
 *
 * ddev exec cat /tmp/token_benchmark_results.txt
 *
 * Set TOKEN_BENCHMARK_ITERATIONS to override the default of 100:
 *
 *   TOKEN_BENCHMARK_ITERATIONS=500 ddev exec vendor/bin/phpunit ...
 */
#[Group('TokenBenchmark')]
#[RunTestsInSeparateProcesses]
class TokenReplacementBenchmark extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * Absolute path of the consolidated results file for the full benchmark run.
   *
   * Each scenario writes its rows to a per-scenario shard file (named by test
   * method), then re-assembles all shards into this file (idempotent — safe
   * regardless of which scenario's process happens to run last). Run
   * `ddev exec cat /tmp/token_benchmark_results.txt` after the benchmark to
   * inspect the complete table.
   *
   * Scenarios in order: 1 (cold start), 2 (warm single string), 2u (warm
   * single string, unenforced), 3 (metatag per-string baseline), 3u (metatag
   * per-string, unenforced), 3b (metatag batch via replaceMultiple), 4a/4b
   * (legacy bridge vs engine direct dispatch), 4c (engine direct,
   * unenforced — isolates structural chain-walk cost from access-enforcement
   * cost), 4d (engine direct, enforced, with a no-op TokenResultAlterEvent
   * listener registered — isolates listener-present dispatch cost).
   */
  private const RESULTS_FILE = '/tmp/token_benchmark_results.txt';

  /**
   * Directory for per-scenario shard files.
   *
   * Using shard files (one per test method, each running in its own child
   * process) avoids the race conditions that arise when separate child
   * processes try to append to a single file while a prior test's sentinel
   * file may or may not exist.
   */
  private const SHARD_DIR = '/tmp/token_benchmark_shards';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'field',
    'text',
    'filter',
    'path',
    'path_alias',
  ];

  /**
   * Whether the contrib token module was successfully enabled.
   */
  private bool $contribTokenEnabled = FALSE;

  /**
   * The resolution engine service.
   */
  private TokenResolutionEngineInterface $engine;

  /**
   * The legacy bridge service (registered as token_engine.legacy_bridge).
   */
  private LegacyTokenBridge $bridge;

  /**
   * The node used across scenarios.
   */
  private Node $node;

  /**
   * Admin user for access checks in engine-path tokens.
   */
  private \Drupal\user\UserInterface $admin;

  /**
   * Two users referenced by the multi-value field.
   *
   * @var \Drupal\user\UserInterface[]
   */
  private array $refUsers = [];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Try to enable the contrib token module when it is present. Drupal::root()
    // returns the application root (e.g. /var/www/html in DDEV), so the contrib
    // module directory is at modules/contrib/token relative to that root.
    $contribTokenPath = \Drupal::root() . '/modules/contrib/token/token.info.yml';
    if (file_exists($contribTokenPath)) {
      try {
        $this->installEntitySchema('path_alias');
        \Drupal::service('router.builder')->rebuild();
        $this->enableModules(['token']);
        $this->contribTokenEnabled = TRUE;
      }
      catch (\Throwable) {
        $this->contribTokenEnabled = FALSE;
      }
    }
    else {
      $this->installEntitySchema('path_alias');
    }

    // Scenario 4d isolates the marginal cost of ONE registered
    // TokenResultAlterEvent listener over Scenario 4b/4c's zero-listener
    // dispatch. Enabling token_context_test here — gated to that one test
    // method, and before any entities are created so the engine's event
    // dispatcher (fetched later in this method) is wired to it from the
    // start — keeps every other scenario's listener landscape at zero
    // registered subscribers, unchanged from B1, so their numbers stay
    // comparable.
    if ($this->name() === 'testScenario4dEngineDirectWithListener') {
      $this->enableModules(['token_context_test']);
    }

    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->createContentType(['type' => 'article']);

    // Multi-value entity-reference field (node → user, unlimited cardinality).
    FieldStorageConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_refs',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'References',
    ])->save();

    // Scalar string field for additional chain material.
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Subtitle',
    ])->save();

    $this->config('system.site')->set('name', 'TokenBenchmarkSite')->save();

    $this->refUsers[] = $this->createUser([], 'ref_user_alpha');
    $this->refUsers[] = $this->createUser([], 'ref_user_beta');
    $this->admin = $this->createUser([], NULL, TRUE);
    $author = $this->createUser([], 'bench_author');

    $this->node = Node::create([
      'type' => 'article',
      'title' => 'Benchmark Node Title',
      'uid' => $author->id(),
      'field_refs' => array_map(fn($u) => ['target_id' => $u->id()], $this->refUsers),
      'field_subtitle' => 'Benchmark subtitle',
      'status' => 1,
    ]);
    $this->node->save();

    $this->engine = $this->container->get('token_engine.resolution_engine');
    $this->bridge = $this->container->get('token_engine.legacy_bridge');

    // Ensure the shard directory exists and clear this test's own shard file
    // so that a re-run does not accumulate rows from the previous run.
    // Each test method runs in its own child process (RunTestsInSeparateProcesses),
    // so clearing only the own shard is race-condition-free.
    if (!is_dir(self::SHARD_DIR)) {
      mkdir(self::SHARD_DIR, 0777, TRUE);
    }
    file_put_contents($this->shardFile(), '');
  }

  // -------------------------------------------------------------------------
  // Scenarios
  // -------------------------------------------------------------------------

  /**
   * Scenario 1 – cold start.
   *
   * Times the very first Token::replace() call in the process, which includes
   * the registry slice build and hook_token_info() aggregation. Measured once;
   * no iteration loop (latency, not throughput).
   */
  public function testScenario1ColdStart(): void {
    $data = ['node' => $this->node];
    $text = '[node:title] | [site:name] | [node:author:display-name]';

    $start = hrtime(TRUE);
    $result = $this->tokenService->replace($text, $data, ['viewer' => $this->admin]);
    $elapsed_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertNotEmpty($result, 'Cold replace must produce a non-empty result.');

    $this->appendRow('Scenario 1 – cold start (single call)', 1, $elapsed_us);
  }

  /**
   * Scenario 2 – warm single-string replacement.
   *
   * One string containing six mixed tokens across multiple token types,
   * repeated N times against a warm registry and populated node. Exercises
   * the full Token::replace() → engine → bridge path.
   */
  public function testScenario2WarmSingleString(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];

    // Six tokens: two legacy node tokens, one site token, one legacy chained
    // user token, and two engine-path field-chain tokens.
    $text = '[node:title] | [node:nid] | [site:name]'
      . ' | [node:author:display-name]'
      . ' | [node:uid:entity:name]'
      . ' | [node:field_refs:0:entity:name]';

    // Warm-up pass to populate all registry slices before timing.
    $this->tokenService->replace($text, $data, ['viewer' => $this->admin]);

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $result = $this->tokenService->replace($text, $data, ['viewer' => $this->admin]);
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertNotEmpty($result ?? '', 'Warm single-string replace must produce a non-empty result.');

    $this->appendRow('Scenario 2 – warm single string (6 tokens)', $iterations, $total_us);
  }

  /**
   * Scenario 3 – metatag-shaped workload (baseline).
   *
   * Simulates the workload Metatag and similar modules produce: many short
   * strings, typically one token each, with heavy shared chain prefixes. Each
   * string is replaced individually (not as a batch). This establishes the
   * baseline a future batch-resolution API must beat.
   *
   * The 25 strings are arranged in families that share chain prefixes:
   *   – node root (legacy): [node:title], [node:nid], [node:uuid], …
   *   – site root (legacy): [site:name], [site:slogan], …
   *   – author chain (legacy): [node:author:display-name], [node:author:name]
   *   – uid entity chain (engine): [node:uid:entity:name], …
   *   – field_refs chain (engine): [node:field_refs:0:entity:name], …
   */
  public function testScenario3MetatagShaped(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];
    $admin = $this->admin;

    $strings = [
      // Legacy node root family.
      '[node:title]',
      '[node:nid]',
      '[node:uuid]',
      '[node:type]',
      '[node:langcode]',
      // Legacy site root family.
      '[site:name]',
      '[site:slogan]',
      '[site:mail]',
      // Legacy author chain family.
      '[node:author:display-name]',
      '[node:author:name]',
      '[node:author:uid]',
      '[node:author:mail]',
      // Engine-path uid:entity chain family (attributed resolver).
      '[node:uid:entity:name]',
      '[node:uid:entity:uuid]',
      '[node:uid:entity:langcode]',
      // Engine-path field_refs chain – delta 0 (attributed resolver).
      '[node:field_refs:0:entity:name]',
      '[node:field_refs:0:entity:uuid]',
      '[node:field_refs:0:entity:langcode]',
      // Engine-path field_refs chain – delta 1.
      '[node:field_refs:1:entity:name]',
      '[node:field_refs:1:entity:uuid]',
      '[node:field_refs:1:entity:langcode]',
      // Mixed strings with one token each (metatag-shaped).
      'Page: [node:title] — [site:name]',
      'Author: [node:author:display-name]',
      'Ref0: [node:field_refs:0:entity:name]',
      'Ref1: [node:field_refs:1:entity:name]',
    ];

    // Warm-up iteration to populate all registry slices before timing.
    foreach ($strings as $text) {
      $this->tokenService->replace($text, $data, ['viewer' => $admin]);
    }

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      foreach ($strings as $text) {
        $result = $this->tokenService->replace($text, $data, ['viewer' => $admin]);
      }
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;
    $total_calls = $iterations * count($strings);

    $this->assertNotEmpty($result ?? '', 'Metatag-shaped replace must produce a non-empty result.');

    $this->appendRow(
      sprintf('Scenario 3 – metatag-shaped (%d strings × %d iters)', count($strings), $iterations),
      $total_calls,
      $total_us,
    );
  }

  /**
   * Scenario 3b – metatag-shaped workload via replaceMultiple() (batch).
   *
   * Uses the same 25 strings and the same node as Scenario 3 but calls
   * replaceMultiple() once per iteration instead of 25 individual replace()
   * calls. The shared chain-prefix memo amortises the structural walks for
   * tokens that share engine-path prefixes (uid:entity, field_refs:0:entity,
   * field_refs:1:entity).
   *
   * Compare the µs/call figure from this scenario against Scenario 3 to see
   * the per-iteration speedup. The "call" unit here is one replaceMultiple()
   * invocation covering all 25 strings.
   */
  public function testScenario3bMetatagShapedBatch(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];
    $admin = $this->admin;

    $strings = [
      // Legacy node root family.
      '[node:title]',
      '[node:nid]',
      '[node:uuid]',
      '[node:type]',
      '[node:langcode]',
      // Legacy site root family.
      '[site:name]',
      '[site:slogan]',
      '[site:mail]',
      // Legacy author chain family.
      '[node:author:display-name]',
      '[node:author:name]',
      '[node:author:uid]',
      '[node:author:mail]',
      // Engine-path uid:entity chain family (attributed resolver).
      '[node:uid:entity:name]',
      '[node:uid:entity:uuid]',
      '[node:uid:entity:langcode]',
      // Engine-path field_refs chain – delta 0 (attributed resolver).
      '[node:field_refs:0:entity:name]',
      '[node:field_refs:0:entity:uuid]',
      '[node:field_refs:0:entity:langcode]',
      // Engine-path field_refs chain – delta 1.
      '[node:field_refs:1:entity:name]',
      '[node:field_refs:1:entity:uuid]',
      '[node:field_refs:1:entity:langcode]',
      // Mixed strings with one token each (metatag-shaped).
      'Page: [node:title] — [site:name]',
      'Author: [node:author:display-name]',
      'Ref0: [node:field_refs:0:entity:name]',
      'Ref1: [node:field_refs:1:entity:name]',
    ];
    $options = ['viewer' => $admin];

    // Warm-up iteration to populate all registry slices before timing.
    $this->tokenService->replaceMultiple($strings, $data, $options);

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $result = $this->tokenService->replaceMultiple($strings, $data, $options);
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertNotEmpty($result ?? [], 'Metatag-shaped batch replace must produce non-empty results.');

    $this->appendRow(
      sprintf('Scenario 3b – metatag batch replaceMultiple() (%d strings × %d iters)', count($strings), $iterations),
      $iterations,
      $total_us,
    );
  }

  /**
   * Scenario 4 – legacy bridge vs engine direct call (dispatch overhead).
   *
   * Isolates the per-token dispatch overhead of each path by calling each
   * service directly rather than via Token::replace():
   *
   *   a) LegacyTokenBridge::generate() with purely legacy tokens.
   *   b) TokenResolutionEngine::generate() with field-chain tokens that the
   *      engine handles without any hook fall-through.
   *
   * These measure different work (legacy tokens have no attributed resolver;
   * engine tokens have no hook handler), so this is a dispatch-overhead
   * comparison, not an identical-work A/B. See the class docblock for the
   * full rationale.
   *
   * This is the last scenario; it also writes the full accumulated table to
   * the results file and includes the file path in its assertion so the
   * location is visible in PHPUnit's output.
   */
  public function testScenario4LegacyVsEngineDispatch(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];
    $options = ['langcode' => 'en'];

    // Purely legacy tokens (no attributed resolver in registry for these).
    $legacy_tokens = [
      'title'  => '[node:title]',
      'nid'    => '[node:nid]',
      'type'   => '[node:type]',
      'uuid'   => '[node:uuid]',
    ];

    // Attributed engine tokens: multi-segment field chains rooted on the
    // 'entity:node' typed-data input type, resolved structurally without any
    // hook call.
    $engine_tokens = [
      'uid:entity:name'          => '[node:uid:entity:name]',
      'field_refs:0:entity:name' => '[node:field_refs:0:entity:name]',
      'field_refs:1:entity:name' => '[node:field_refs:1:entity:name]',
      'uid:entity:uuid'          => '[node:uid:entity:uuid]',
    ];

    // Warm-up to ensure registry slices are populated before timing.
    $meta = new BubbleableMetadata();
    $this->bridge->generate('node', $legacy_tokens, $data, $options, $meta);
    $engine_options = $options + ['viewer' => $this->admin];
    $this->engine->generate('node', $engine_tokens, $data, $engine_options, $meta);

    // --- Legacy bridge direct ---
    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $meta = new BubbleableMetadata();
      $this->bridge->generate('node', $legacy_tokens, $data, $options, $meta);
    }
    $bridge_us = (hrtime(TRUE) - $start) / 1_000;

    // --- Engine direct ---
    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $meta = new BubbleableMetadata();
      $this->engine->generate('node', $engine_tokens, $data, $engine_options, $meta);
    }
    $engine_us = (hrtime(TRUE) - $start) / 1_000;

    // Sanity assertions.
    $meta = new BubbleableMetadata();
    $bridge_result = $this->bridge->generate('node', $legacy_tokens, $data, $options, $meta);
    $this->assertArrayHasKey('[node:title]', $bridge_result, 'Legacy bridge must resolve [node:title].');

    $meta = new BubbleableMetadata();
    $engine_result = $this->engine->generate('node', $engine_tokens, $data, $engine_options, $meta);
    $this->assertArrayHasKey('[node:uid:entity:name]', $engine_result, 'Engine must resolve attributed field-chain token.');

    $this->appendRow(
      sprintf('Scenario 4a – legacy bridge direct (%d tokens)', count($legacy_tokens)),
      $iterations,
      $bridge_us,
    );
    $this->appendRow(
      sprintf('Scenario 4b – engine direct (%d tokens)', count($engine_tokens)),
      $iterations,
      $engine_us,
    );

    // Append ratio footnote.
    $bridge_per_op = $bridge_us / $iterations;
    $engine_per_op = $engine_us / $iterations;
    if ($engine_per_op > 0) {
      $ratio = $bridge_per_op / $engine_per_op;
      $this->appendLine(sprintf(
        '  legacy/engine ratio: %.2fx  (legacy=%.1fµs/op, engine=%.1fµs/op)',
        $ratio,
        $bridge_per_op,
        $engine_per_op,
      ));
    }
    $this->appendLine(str_repeat('─', 78));

    // Assemble all scenario shards into the final results file. The path is
    // included in the assertion message so PHPUnit's output shows where the
    // full table lives after the run.
    $table = $this->assembleTable();
    $this->assertNotEmpty(
      $table,
      'Benchmark results written to: ' . self::RESULTS_FILE
        . PHP_EOL . 'Inspect with: ddev exec cat ' . self::RESULTS_FILE,
    );
  }

  /**
   * Scenario 4c – engine direct, unenforced tier (access checks skipped).
   *
   * Same four field-chain tokens as Scenario 4b, called with 'token_actor'
   * set to an ActorContext carrying a NULL viewer instead of 'viewer'. Per
   * TokenResolutionEngine::resolveActor(), an explicit 'token_actor' is
   * returned verbatim — the deprecation trigger_error only fires when
   * neither 'token_actor' nor 'viewer' is supplied — so this measures the
   * unenforced tier's structural chain-walk cost only, uncontaminated by
   * deprecation-handling overhead.
   *
   * Scenario 4b minus Scenario 4c approximates the per-op cost of access
   * enforcement (root-entity view check, entity-deref check, field-level
   * view checks) for these four chains.
   */
  public function testScenario4cEngineDirectUnenforced(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];

    // Same four attributed engine tokens as Scenario 4b.
    $engine_tokens = [
      'uid:entity:name'          => '[node:uid:entity:name]',
      'field_refs:0:entity:name' => '[node:field_refs:0:entity:name]',
      'field_refs:1:entity:name' => '[node:field_refs:1:entity:name]',
      'uid:entity:uuid'          => '[node:uid:entity:uuid]',
    ];
    $options = ['langcode' => 'en', 'token_actor' => new ActorContext(NULL)];

    // Warm-up to ensure registry slices are populated before timing.
    $meta = new BubbleableMetadata();
    $this->engine->generate('node', $engine_tokens, $data, $options, $meta);

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $meta = new BubbleableMetadata();
      $result = $this->engine->generate('node', $engine_tokens, $data, $options, $meta);
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertArrayHasKey('[node:uid:entity:name]', $result, 'Unenforced engine direct must resolve the field-chain token.');

    $this->appendRow(
      sprintf('Scenario 4c – engine direct unenforced (%d tokens)', count($engine_tokens)),
      $iterations,
      $total_us,
    );

    $this->assembleTable();
  }

  /**
   * Scenario 4d – engine direct, enforced, with a no-op listener registered.
   *
   * Identical to Scenario 4b except the token_context_test module's
   * TokenResultAlterEventSubscriber is registered on the event dispatcher
   * (enabled in setUp(), gated to this scenario only so every other
   * scenario keeps a zero-listener landscape). The subscriber is left
   * unconfigured — its default state only records the dispatch in $calls
   * and never alters the result — so this isolates the marginal cost of ONE
   * registered listener over Scenario 4b's zero-listener dispatch, i.e. the
   * per-token TokenResultAlterEvent overhead beyond bare
   * dispatch-with-no-listeners.
   */
  public function testScenario4dEngineDirectWithListener(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];

    $engine_tokens = [
      'uid:entity:name'          => '[node:uid:entity:name]',
      'field_refs:0:entity:name' => '[node:field_refs:0:entity:name]',
      'field_refs:1:entity:name' => '[node:field_refs:1:entity:name]',
      'uid:entity:uuid'          => '[node:uid:entity:uuid]',
    ];
    $options = ['langcode' => 'en', 'viewer' => $this->admin];

    // Warm-up to ensure registry slices are populated before timing.
    $meta = new BubbleableMetadata();
    $this->engine->generate('node', $engine_tokens, $data, $options, $meta);

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $meta = new BubbleableMetadata();
      $result = $this->engine->generate('node', $engine_tokens, $data, $options, $meta);
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertArrayHasKey('[node:uid:entity:name]', $result, 'Engine direct with listener must resolve the field-chain token.');

    $this->appendRow(
      sprintf('Scenario 4d – engine direct + no-op listener (%d tokens)', count($engine_tokens)),
      $iterations,
      $total_us,
    );

    $this->assembleTable();
  }

  /**
   * Scenario 2u – warm single-string replacement, unenforced tier.
   *
   * Identical to Scenario 2 (same string, same six mixed tokens) except
   * 'token_actor' is set to an ActorContext with a NULL viewer instead of
   * 'viewer' being set to an admin account. As with Scenario 4c, an explicit
   * 'token_actor' avoids the deprecation notice regardless of the viewer it
   * carries. Compare against Scenario 2 to see the end-to-end cost
   * enforcement adds to a realistic Token::replace() call mixing legacy and
   * engine tokens.
   */
  public function testScenario2uWarmSingleStringUnenforced(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];
    $options = ['token_actor' => new ActorContext(NULL)];

    $text = '[node:title] | [node:nid] | [site:name]'
      . ' | [node:author:display-name]'
      . ' | [node:uid:entity:name]'
      . ' | [node:field_refs:0:entity:name]';

    // Warm-up pass to populate all registry slices before timing.
    $this->tokenService->replace($text, $data, $options);

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      $result = $this->tokenService->replace($text, $data, $options);
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;

    $this->assertNotEmpty($result ?? '', 'Unenforced warm single-string replace must produce a non-empty result.');

    $this->appendRow('Scenario 2u – warm single string, unenforced', $iterations, $total_us);

    $this->assembleTable();
  }

  /**
   * Scenario 3u – metatag-shaped workload, unenforced tier.
   *
   * Identical to Scenario 3 (same 25 strings, same node) except replacement
   * uses 'token_actor' with a NULL-viewer ActorContext instead of 'viewer'.
   * Compare against Scenario 3 to see the end-to-end enforcement cost on a
   * realistic per-string metatag-style workload.
   */
  public function testScenario3uMetatagShapedUnenforced(): void {
    $iterations = $this->iterations();
    $data = ['node' => $this->node];
    $options = ['token_actor' => new ActorContext(NULL)];

    $strings = [
      // Legacy node root family.
      '[node:title]',
      '[node:nid]',
      '[node:uuid]',
      '[node:type]',
      '[node:langcode]',
      // Legacy site root family.
      '[site:name]',
      '[site:slogan]',
      '[site:mail]',
      // Legacy author chain family.
      '[node:author:display-name]',
      '[node:author:name]',
      '[node:author:uid]',
      '[node:author:mail]',
      // Engine-path uid:entity chain family (attributed resolver).
      '[node:uid:entity:name]',
      '[node:uid:entity:uuid]',
      '[node:uid:entity:langcode]',
      // Engine-path field_refs chain – delta 0 (attributed resolver).
      '[node:field_refs:0:entity:name]',
      '[node:field_refs:0:entity:uuid]',
      '[node:field_refs:0:entity:langcode]',
      // Engine-path field_refs chain – delta 1.
      '[node:field_refs:1:entity:name]',
      '[node:field_refs:1:entity:uuid]',
      '[node:field_refs:1:entity:langcode]',
      // Mixed strings with one token each (metatag-shaped).
      'Page: [node:title] — [site:name]',
      'Author: [node:author:display-name]',
      'Ref0: [node:field_refs:0:entity:name]',
      'Ref1: [node:field_refs:1:entity:name]',
    ];

    // Warm-up iteration to populate all registry slices before timing.
    foreach ($strings as $text) {
      $this->tokenService->replace($text, $data, $options);
    }

    $start = hrtime(TRUE);
    for ($i = 0; $i < $iterations; $i++) {
      foreach ($strings as $text) {
        $result = $this->tokenService->replace($text, $data, $options);
      }
    }
    $total_us = (hrtime(TRUE) - $start) / 1_000;
    $total_calls = $iterations * count($strings);

    $this->assertNotEmpty($result ?? '', 'Unenforced metatag-shaped replace must produce a non-empty result.');

    $this->appendRow(
      sprintf('Scenario 3u – metatag-shaped unenforced (%d strings × %d iters)', count($strings), $iterations),
      $total_calls,
      $total_us,
    );

    $this->assembleTable();
  }

  // -------------------------------------------------------------------------
  // Helpers
  // -------------------------------------------------------------------------

  /**
   * Returns the number of iterations from the environment or the default.
   */
  private function iterations(): int {
    $env = getenv('TOKEN_BENCHMARK_ITERATIONS');
    return ($env !== FALSE && ctype_digit($env) && (int) $env > 0) ? (int) $env : 100;
  }

  /**
   * Returns the shard file path for the current test method.
   *
   * Each test method runs in its own child process (RunTestsInSeparateProcesses).
   * Writing to a per-method shard file avoids cross-process append races and
   * allows the final scenario to assemble a clean, ordered table.
   */
  private function shardFile(): string {
    return self::SHARD_DIR . '/' . $this->name() . '.txt';
  }

  /**
   * Builds the results table header block.
   */
  private function buildHeader(): string {
    $contribStatus = $this->contribTokenEnabled
      ? 'YES'
      : 'NO (not found at {root}/modules/contrib/token or failed to enable)';
    $sep = str_repeat('─', 78);
    return implode(PHP_EOL, [
      '',
      $sep,
      '  TOKEN REPLACEMENT BENCHMARK',
      sprintf('  Iterations: %d  |  Contrib token: %s', $this->iterations(), $contribStatus),
      $sep,
      sprintf("  %-50s  %8s  %10s", 'Scenario', 'calls', 'µs/call'),
      $sep,
      '',
    ]);
  }

  /**
   * Appends one result row to the current test's shard file.
   *
   * error_log() type 3 appends to a file without writing to STDERR and
   * therefore does not trigger PHPUnit's ChildProcessResultProcessor error path
   * (which treats any non-empty child process stderr as a test error).
   */
  private function appendRow(string $label, int $calls, float $total_us): void {
    $per_call = $calls > 0 ? $total_us / $calls : 0.0;
    $line = sprintf(
      "  %-50s  %8d  %10.1f\n",
      mb_substr($label, 0, 50),
      $calls,
      $per_call,
    );
    error_log($line, 3, $this->shardFile());
  }

  /**
   * Appends a raw line to the current test's shard file.
   */
  private function appendLine(string $line): void {
    error_log($line . PHP_EOL, 3, $this->shardFile());
  }

  /**
   * Assembles shard files from all scenarios into the consolidated results file.
   *
   * Returns the assembled table content as a string for assertion purposes.
   */
  private function assembleTable(): string {
    $scenarios = [
      'testScenario1ColdStart',
      'testScenario2WarmSingleString',
      'testScenario2uWarmSingleStringUnenforced',
      'testScenario3MetatagShaped',
      'testScenario3uMetatagShapedUnenforced',
      'testScenario3bMetatagShapedBatch',
      'testScenario4LegacyVsEngineDispatch',
      'testScenario4cEngineDirectUnenforced',
      'testScenario4dEngineDirectWithListener',
    ];

    $table = $this->buildHeader();
    foreach ($scenarios as $name) {
      $shard = self::SHARD_DIR . '/' . $name . '.txt';
      if (file_exists($shard)) {
        $table .= file_get_contents($shard);
      }
    }

    file_put_contents(self::RESULTS_FILE, $table);

    return $table;
  }

}

