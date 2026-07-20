<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManager;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Multilingual (80-language) token replacement benchmark.
 *
 * Unlike TokenReplacementBenchmark this file references NO engine classes and
 * exercises only the public Token::replace() API, so the identical file runs
 * against plain core (the branch's upstream base commit) and against the
 * token-overhaul HEAD. Comparing the two runs answers: what does the new token
 * stack cost or save on a site with 80 enabled languages?
 *
 * Scenarios:
 *  - S1 per-language first call: for each of the 80 languages the current
 *    content language is switched and one full workload replacement runs with
 *    that langcode. On HEAD this pays the per-language registry slice build;
 *    on plain core the hook pipeline has no per-language build cost. Reported
 *    as median / p90 / total across languages.
 *  - S2 warm throughput: repeated workload replacements per language after
 *    the first. Reported as µs per replace() call (median across languages).
 *  - S3 cache footprint: rows and bytes in cache_default keyed token* after
 *    all 80 languages have been exercised.
 *  - S4 field chains, translation done by each stack: the root object is
 *    passed UNTRANSLATED with an explicit langcode option, so whichever stack
 *    runs must select the translations itself (contrib token's hooks call
 *    getTranslationFromContext() per field token; the engine translates at
 *    the root and deref boundaries). The contrib token module is enabled when
 *    present precisely so plain core resolves the same chains to the same
 *    translated values: like-for-like work, like-for-like output. For the
 *    plain-core run, check out contrib token's own upstream base commit; the
 *    branch checkout references engine classes at container compile time.
 *    Uses replaceMultiple() where available.
 *
 * The workload tokens are core-defined ([node:title], [node:author:name],
 * [site:name], date chains) so both stacks resolve identical values through
 * their own pipelines, with the langcode option cycling through all 80
 * languages against a node translated into every one of them.
 *
 * RUNNING (label each run so the results files can sit side by side):
 *
 *   TOKEN_BENCH_LABEL=engine ddev exec vendor/bin/phpunit -c core \
 *     core/modules/system/tests/src/Kernel/Token/TokenMultilingualBenchmark.php
 *   ddev exec cat /tmp/token_multilingual_benchmark_engine.txt
 *
 * Set TOKEN_BENCH_WARM_ITERATIONS to override the default of 4.
 */
#[Group('TokenBenchmark')]
#[RunTestsInSeparateProcesses]
class TokenMultilingualBenchmark extends EntityKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * Number of languages to enable, English included.
   */
  private const LANGUAGE_COUNT = 80;

  /**
   * {@inheritdoc}
   */
  // Deliberately does NOT list token_engine: this class IS the baseline run
  // (plain core pipeline; pair it with the upstream contrib token checkout,
  // which the engine-less container can actually load). Run the engine
  // configuration via TokenMultilingualEngineBenchmark, which adds
  // token_engine through the kernel-test cross-hierarchy $modules merge.
  // In the contrib world "plain core" is simply "module not enabled" — no
  // core checkout dance required.
  protected static $modules = [
    'node',
    'field',
    'text',
    'filter',
    'language',
    'path',
    'path_alias',
  ];

  /**
   * All enabled langcodes, English first.
   *
   * @var string[]
   */
  private array $langcodes = [];

  /**
   * The node translated into every enabled language.
   */
  private Node $node;

  /**
   * Admin user passed as viewer (ignored by plain core, enforced on HEAD).
   *
   * @var \Drupal\user\UserInterface
   */
  private $admin;

  /**
   * Whether the contrib token module was successfully enabled.
   */
  private bool $contribTokenEnabled = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Enable the contrib token module when present, mirroring
    // TokenReplacementBenchmark: it supplies the legacy field tokens that let
    // plain core resolve (and translate) the S4 field-chain workload.
    if (file_exists(\Drupal::root() . '/modules/contrib/token/token.info.yml')) {
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

    $this->installConfig(['system', 'filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);
    $this->config('system.site')->set('name', 'MultilingualBenchSite')->save();

    // English plus the first 79 other standard languages. The English config
    // entity is created explicitly because language_install() does not run in
    // kernel tests and the language manager needs >1 configured language to
    // report the site as multilingual.
    $standard = array_keys(LanguageManager::getStandardLanguageList());
    $others = array_values(array_diff($standard, ['en']));
    $this->langcodes = array_merge(['en'], array_slice($others, 0, self::LANGUAGE_COUNT - 1));
    foreach ($this->langcodes as $langcode) {
      ConfigurableLanguage::createFromLangcode($langcode)->save();
    }

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
    FieldStorageConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related',
    ])->save();

    $this->admin = $this->createUser([], NULL, TRUE);
    $author = $this->createUser([], 'bench_author');

    $related = Node::create([
      'type' => 'article',
      'title' => 'Related en',
      'field_subtitle' => 'Related subtitle en',
      'status' => 1,
    ]);
    $this->node = Node::create([
      'type' => 'article',
      'title' => 'Bench title en',
      'uid' => $author->id(),
      'field_subtitle' => 'Bench subtitle en',
      'status' => 1,
    ]);
    foreach ($this->langcodes as $langcode) {
      if ($langcode === 'en') {
        continue;
      }
      $related->addTranslation($langcode, [
        'title' => "Related $langcode",
        'field_subtitle' => "Related subtitle $langcode",
      ]);
      $this->node->addTranslation($langcode, [
        'title' => "Bench title $langcode",
        'uid' => $author->id(),
        'field_subtitle' => "Bench subtitle $langcode",
      ]);
    }
    $related->save();
    $this->node->set('field_related', $related->id());
    foreach ($this->langcodes as $langcode) {
      if ($langcode !== 'en') {
        $this->node->getTranslation($langcode)->set('field_related', $related->id());
      }
    }
    $this->node->save();
  }

  /**
   * The metatag-shaped workload: 24 short strings of core-defined tokens.
   *
   * @return string[]
   *   Workload strings keyed for replaceMultiple().
   */
  private function coreWorkload(): array {
    $texts = [];
    foreach (range(1, 4) as $i) {
      $texts["title_$i"] = "[node:title] | [site:name] #$i";
      $texts["author_$i"] = "By [node:author:name] on [node:created:custom:Y-m-d] #$i";
      $texts["summary_$i"] = "[node:title] updated [node:changed:custom:Y-m-d] ([node:langcode]) #$i";
      $texts["site_$i"] = "[site:name]: [node:title] #$i";
      $texts["date_$i"] = "[node:created:custom:Y] [node:changed:custom:m] #$i";
      $texts["mix_$i"] = "[node:title] [node:author:name] [site:name] #$i";
    }
    return $texts;
  }

  /**
   * The engine-only workload: multi-segment field chains.
   *
   * @return string[]
   *   Workload strings keyed for replaceMultiple().
   */
  private function fieldChainWorkload(): array {
    $texts = [];
    foreach (range(1, 4) as $i) {
      $texts["subtitle_$i"] = "[node:field_subtitle:0] #$i";
      $texts["ref_label_$i"] = "[node:field_related:entity] #$i";
      $texts["ref_subtitle_$i"] = "[node:field_related:entity:field_subtitle] #$i";
    }
    return $texts;
  }

  /**
   * Switches the current content language, as a request in $langcode would.
   */
  private function switchLanguage(string $langcode): void {
    $this->container->get('language.default')->set(ConfigurableLanguage::load($langcode));
    $this->container->get('language_manager')->reset();
  }

  /**
   * Replaces every workload string individually via Token::replace().
   *
   * @return float
   *   Wall-clock milliseconds for the whole workload.
   */
  private function replaceWorkload(array $texts, string $langcode, bool $translateRoot = TRUE): float {
    $token = $this->container->get('token');
    // With $translateRoot, pass the translation object for the language, as
    // real callers (pathauto, metatag) do: legacy core node tokens read the
    // object they are handed and never re-translate scalars like the title.
    // Without it, pass the untranslated default object so the stack under
    // test has to select every translation itself from the langcode option.
    $data = ['node' => $translateRoot ? $this->node->getTranslation($langcode) : $this->node];
    $options = ['langcode' => $langcode, 'viewer' => $this->admin, 'clear' => TRUE];
    $start = hrtime(TRUE);
    foreach ($texts as $text) {
      $token->replace($text, $data, $options, new BubbleableMetadata());
    }
    return (hrtime(TRUE) - $start) / 1e6;
  }

  /**
   * Runs all scenarios and writes the results table.
   */
  public function testMultilingualBenchmark(): void {
    $label = getenv('TOKEN_BENCH_LABEL') ?: 'unlabelled';
    $warmIterations = (int) (getenv('TOKEN_BENCH_WARM_ITERATIONS') ?: 4);
    $workload = $this->coreWorkload();
    $workloadSize = count($workload);

    // Sanity: the language switch actually moves the current content language
    // (S1's per-language build cost depends on it), the workload resolves, and
    // translations are honoured by whichever stack is under test.
    $this->switchLanguage('de');
    $current = $this->container->get('language_manager')
      ->getCurrentLanguage(LanguageInterface::TYPE_CONTENT)->getId();
    $this->assertSame('de', $current, 'Switching language.default moves the current content language.');
    $probe = (string) $this->container->get('token')->replace(
      '[node:title]',
      ['node' => $this->node->getTranslation('de')],
      ['langcode' => 'de', 'viewer' => $this->admin],
      new BubbleableMetadata(),
    );
    $this->assertSame('Bench title de', $probe, 'Core node tokens read the passed translation under the stack being benchmarked.');

    // S1 + S2: per-language first call, then warm iterations.
    $firstCallMs = [];
    $warmPerOpUs = [];
    foreach ($this->langcodes as $langcode) {
      $this->switchLanguage($langcode);
      $firstCallMs[$langcode] = $this->replaceWorkload($workload, $langcode);
      $warmMs = [];
      for ($i = 0; $i < $warmIterations; $i++) {
        $warmMs[] = $this->replaceWorkload($workload, $langcode);
      }
      $warmPerOpUs[$langcode] = (self::median($warmMs) * 1000) / $workloadSize;
    }

    // S3: token-related cache entries written along the way. Kernel tests use
    // a memory cache backend, so interrogate the bin itself; on a production
    // site these entries land in the database cache_default table. Sizes are
    // the serialized payload, the same thing a database row would store.
    $cacheRows = [];
    $bin = $this->container->get('cache.default');
    $reflection = new \ReflectionObject($bin);
    if ($reflection->hasProperty('cache')) {
      foreach ($reflection->getProperty('cache')->getValue($bin) as $cid => $item) {
        if (str_starts_with((string) $cid, 'token')) {
          $cacheRows[$cid] = strlen(is_string($item->data) ? $item->data : serialize($item->data));
        }
      }
    }
    $cacheBytes = array_sum($cacheRows);
    $registrySlices = count(array_filter(array_keys($cacheRows), fn(string $cid): bool => str_starts_with($cid, 'token_registry')));

    // S4: field chains with the translation work done by the stack under
    // test: untranslated root, explicit langcode. Individual replace() first,
    // then batch when available.
    $this->switchLanguage('en');
    $chainWorkload = $this->fieldChainWorkload();
    $chainMs = [];
    foreach (array_slice($this->langcodes, 0, 10) as $langcode) {
      $chainMs[] = $this->replaceWorkload($chainWorkload, $langcode, FALSE);
    }
    $chainMedianUs = (self::median($chainMs) * 1000) / count($chainWorkload);

    $batchLine = 'S4b batch (replaceMultiple):    unavailable in this stack';
    $token = $this->container->get('token');
    if (method_exists($token, 'replaceMultiple')) {
      $batchMs = [];
      foreach (array_slice($this->langcodes, 0, 10) as $langcode) {
        $start = hrtime(TRUE);
        $token->replaceMultiple($chainWorkload, ['node' => $this->node], [
          'langcode' => $langcode,
          'viewer' => $this->admin,
          'clear' => TRUE,
        ], new BubbleableMetadata());
        $batchMs[] = (hrtime(TRUE) - $start) / 1e6;
      }
      $batchLine = sprintf('S4b batch (replaceMultiple):    %8.1f us/string (median, 10 languages)', (self::median($batchMs) * 1000) / count($chainWorkload));
    }

    // The apples-to-apples contract: both stacks resolve the chain workload
    // to the SAME translated values from an untranslated root. With contrib
    // token enabled this must hold on plain core too, so it is asserted, not
    // merely reported.
    $chainProbe = (string) $token->replace('[node:field_related:entity:field_subtitle]', ['node' => $this->node], [
      'langcode' => 'de',
      'viewer' => $this->admin,
      'clear' => TRUE,
    ], new BubbleableMetadata());
    $subtitleProbe = (string) $token->replace('[node:field_subtitle:0]', ['node' => $this->node], [
      'langcode' => 'de',
      'viewer' => $this->admin,
      'clear' => TRUE,
    ], new BubbleableMetadata());
    if ($this->contribTokenEnabled) {
      $this->assertSame('Related subtitle de', $chainProbe, 'The stack under test translates the dereferenced entity itself.');
      $this->assertSame('Bench subtitle de', $subtitleProbe, 'The stack under test translates the root field value itself.');
    }
    $chainResolved = $chainProbe === 'Related subtitle de';

    $lines = [
      sprintf('=== Token multilingual benchmark [%s] %s languages, workload %d strings, contrib token %s ===', $label, count($this->langcodes), $workloadSize, $this->contribTokenEnabled ? 'ENABLED' : 'absent'),
      sprintf('S1 first call per language:     median %8.2f ms | p90 %8.2f ms | total (all %d) %8.1f ms', self::median($firstCallMs), self::percentile($firstCallMs, 90), count($firstCallMs), array_sum($firstCallMs)),
      sprintf('S2 warm replace() throughput:   median %8.1f us/string (across languages, %d iterations each)', self::median($warmPerOpUs), $warmIterations),
      sprintf('S3 token cache footprint:       %d entries (%d registry slices), %d bytes total', count($cacheRows), $registrySlices, $cacheBytes),
      sprintf('S4 field chains (%s):    %8.1f us/string (median, 10 languages)', $chainResolved ? 'RESOLVED' : 'UNRESOLVED', $chainMedianUs),
      $batchLine,
      sprintf('S4 chain probe [de]:            %s', var_export($chainProbe, TRUE)),
      '',
    ];
    file_put_contents('/tmp/token_multilingual_benchmark_' . $label . '.txt', implode("\n", $lines));

    // The benchmark "passes" whenever it runs to completion; the numbers live
    // in the results file named above.
    $this->assertNotEmpty($firstCallMs);
  }

  /**
   * Returns the median of an array of numbers.
   */
  private static function median(array $values): float {
    sort($values);
    $count = count($values);
    $middle = intdiv($count, 2);
    return $count % 2 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
  }

  /**
   * Returns the given percentile of an array of numbers.
   */
  private static function percentile(array $values, int $percentile): float {
    sort($values);
    $index = (int) ceil(($percentile / 100) * count($values)) - 1;
    return (float) $values[max(0, $index)];
  }

}
