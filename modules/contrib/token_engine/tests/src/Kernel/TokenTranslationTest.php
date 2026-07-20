<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\token_engine\ActorContext;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the engine resolves values from the right entity translation.
 *
 * The legacy pipeline translates inside each hook implementation
 * (getTranslationFromContext() in node tokens and contrib token's field
 * tokens). The engine is structural, so translation selection is the engine's
 * job: the root input and every entity produced mid-chain must be translated
 * to the resolved langcode before values are read from it. These tests pin
 * that behaviour at every boundary: the root entity, the mid-chain deref, the
 * terminal entity label, the langcode fallback, and the batch/memo path.
 *
 * @see \Drupal\token_engine\TokenResolutionEngine::translateResult()
 */
#[CoversClass(\Drupal\token_engine\TokenResolutionEngine::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenTranslationTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'filter', 'language'];

  /**
   * A node with an English original and a German translation.
   */
  protected Node $node;

  /**
   * A referenced node, also translated, reachable via field_related.
   */
  protected Node $referenced;

  /**
   * An admin account used as the viewer.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $admin;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // On an installed site language_install() creates the English language
    // entity; kernel tests skip install hooks, so create it explicitly or the
    // language manager reports the site as not multilingual as soon as the
    // default language is anything but English.
    ConfigurableLanguage::createFromLangcode('en')->save();
    ConfigurableLanguage::createFromLangcode('de')->save();

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

    $this->referenced = Node::create([
      'type' => 'article',
      'title' => 'Referenced English',
      'field_subtitle' => 'Referenced English subtitle',
      'status' => 1,
    ]);
    $this->referenced->addTranslation('de', [
      'title' => 'Referenced German',
      'field_subtitle' => 'Referenced German subtitle',
    ]);
    $this->referenced->save();

    $this->node = Node::create([
      'type' => 'article',
      'title' => 'Root English',
      'field_subtitle' => 'Root English subtitle',
      'field_related' => $this->referenced->id(),
      'status' => 1,
    ]);
    $this->node->addTranslation('de', [
      'title' => 'Root German',
      'field_subtitle' => 'Root German subtitle',
      'field_related' => $this->referenced->id(),
    ]);
    $this->node->save();

    $this->admin = $this->createUser([], NULL, TRUE);
  }

  /**
   * Tests the langcode option selects the root entity's translation.
   *
   * The ':0' identity segment makes the chain multi-segment so it routes
   * through the engine (single-segment tokens deliberately stay legacy).
   * The de-vs-en pair is its own negative control: only the langcode differs
   * between the two calls.
   */
  public function testLangcodeOptionSelectsRootTranslation(): void {
    $german = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $this->node],
      ['langcode' => 'de', 'viewer' => $this->admin],
    );
    $this->assertSame('Root German subtitle', (string) $german, 'The German translation is read when langcode is de.');

    $english = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $this->node],
      ['langcode' => 'en', 'viewer' => $this->admin],
    );
    $this->assertSame('Root English subtitle', (string) $english, 'The English original is read when langcode is en.');
  }

  /**
   * Tests root translation is normalised in both directions.
   *
   * A caller may hold any translation object; the langcode option, not the
   * object the caller happened to pass, decides the language values are read
   * in. This mirrors getTranslationFromContext() in the legacy hooks.
   */
  public function testPassedTranslationObjectIsNormalised(): void {
    $germanObject = $this->node->getTranslation('de');

    $result = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $germanObject],
      ['langcode' => 'en', 'viewer' => $this->admin],
    );
    $this->assertSame('Root English subtitle', (string) $result, 'Passing the German object with langcode en still reads the English original.');
  }

  /**
   * Tests a mid-chain deref produces the referenced entity's translation.
   *
   * Referenced entities load in their default language; the engine must
   * select the translation when the reference is dereferenced, or chains
   * return source-language values no matter what the caller asked for.
   */
  public function testMidChainDerefSelectsTranslation(): void {
    $subtitle = $this->tokenService->replace(
      '[node:field_related:entity:field_subtitle]',
      ['node' => $this->node],
      ['langcode' => 'de', 'viewer' => $this->admin],
    );
    $this->assertSame('Referenced German subtitle', (string) $subtitle, 'The deref chain reads the referenced entity in the requested language.');

    $label = $this->tokenService->replace(
      '[node:field_related:entity]',
      ['node' => $this->node],
      ['langcode' => 'de', 'viewer' => $this->admin],
    );
    $this->assertSame('Referenced German', (string) $label, 'A terminal entity renders the translated label.');
  }

  /**
   * Tests a langcode with no matching translation falls back like legacy.
   *
   * The repository applies the language fallback chain, so a missing
   * translation yields the default translation rather than nothing.
   */
  public function testMissingTranslationFallsBack(): void {
    ConfigurableLanguage::createFromLangcode('fr')->save();

    $result = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $this->node],
      ['langcode' => 'fr', 'viewer' => $this->admin],
    );
    $this->assertSame('Root English subtitle', (string) $result, 'An untranslated langcode falls back to the default translation.');
  }

  /**
   * Tests that without a langcode option the root object's language wins.
   *
   * This is the legacy semantic the ecosystem grew around (contrib token's
   * field tokens): passing a specific translation object means "resolve in
   * this translation's language". The whole chain follows, including
   * dereferenced entities, and since the language is pinned by the passed
   * object rather than negotiated from the request, no language cache context
   * is declared.
   */
  public function testRootObjectLanguageDecidesWithoutOption(): void {
    $germanObject = $this->node->getTranslation('de');

    $metadata = new BubbleableMetadata();
    $subtitle = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $germanObject],
      ['viewer' => $this->admin],
      $metadata,
    );
    $this->assertSame('Root German subtitle', (string) $subtitle, 'Passing the German object without a langcode resolves in German.');
    $this->assertNotContains('languages:language_content', $metadata->getCacheContexts(), 'A language pinned by the root object does not vary by request.');

    $chained = $this->tokenService->replace(
      '[node:field_related:entity:field_subtitle]',
      ['node' => $germanObject],
      ['viewer' => $this->admin],
    );
    $this->assertSame('Referenced German subtitle', (string) $chained, 'Dereferenced entities follow the root object language.');

    $english = $this->tokenService->replace(
      '[node:field_subtitle:0]',
      ['node' => $this->node],
      ['viewer' => $this->admin],
    );
    $this->assertSame('Root English subtitle', (string) $english, 'Passing the English object without a langcode resolves in English.');
  }

  /**
   * Tests the content-language fallback declares its cache context.
   *
   * Only a non-entity root reaches the current-content-language fallback (an
   * entity root pins the language itself), and then any multi-translation
   * entity selected mid-chain varies by the request's language. That
   * combination is exercised at the resolveChain() API level with a context
   * whose langcode is marked as request-derived, the way generateWithMemo()
   * constructs it for non-entity roots.
   */
  public function testRequestDerivedLanguageAddsCacheContext(): void {
    /** @var \Drupal\token_engine\TokenResolutionEngineInterface $engine */
    $engine = $this->container->get('token_engine.resolution_engine');

    $requestDerived = new TokenResolutionContext(
      ['node' => $this->node],
      ActorContext::fromSingleActor($this->admin),
      OutputContext::Html,
      [],
      'de',
      TRUE,
    );
    $result = $engine->resolveChain('entity:node', ['field_subtitle', '0'], $this->node, $requestDerived);
    $this->assertSame('Root German subtitle', $result?->value, 'The chain resolves in the request-derived language.');
    $this->assertContains('languages:language_content', $result->cacheability->getCacheContexts(), 'A request-derived language declares the content language cache context.');

    $pinned = new TokenResolutionContext(
      ['node' => $this->node],
      ActorContext::fromSingleActor($this->admin),
      OutputContext::Html,
      ['langcode' => 'de'],
      'de',
    );
    $result = $engine->resolveChain('entity:node', ['field_subtitle', '0'], $this->node, $pinned);
    $this->assertNotContains('languages:language_content', $result->cacheability->getCacheContexts(), 'A pinned language declares no request-language variance.');
  }

  /**
   * Tests the batch path translates through the shared chain-prefix memo.
   *
   * Both texts share the [node:field_related:entity] prefix, so the second
   * resolution resumes from the memo; the memoized state must already hold
   * the translated entity.
   */
  public function testBatchReplacementTranslates(): void {
    $results = $this->tokenService->replaceMultiple(
      [
        'subtitle' => '[node:field_related:entity:field_subtitle]',
        'label' => '[node:field_related:entity]',
      ],
      ['node' => $this->node],
      ['langcode' => 'de', 'viewer' => $this->admin],
    );

    $this->assertSame('Referenced German subtitle', (string) $results['subtitle'], 'The batch path reads the translated referenced entity.');
    $this->assertSame('Referenced German', (string) $results['label'], 'The memoized prefix carries the translated entity.');
  }

  /**
   * Tests the renderer's own translation seam for direct callers.
   *
   * The renderer is a public service: a direct caller may hand it an
   * untranslated entity, and the label is the last point where the wrong
   * translation could leak into output.
   */
  public function testRendererTranslatesEntityLabel(): void {
    $renderer = $this->container->get('token_engine.renderer');

    $this->assertSame(
      'Referenced German',
      $renderer->render($this->referenced, 'entity:node', OutputContext::PlainText, 'de'),
      'The renderer labels the translation matching the langcode.',
    );
    $this->assertSame(
      'Referenced English',
      $renderer->render($this->referenced, 'entity:node', OutputContext::PlainText, 'en'),
      'The renderer labels the original for the original langcode.',
    );
  }

}
