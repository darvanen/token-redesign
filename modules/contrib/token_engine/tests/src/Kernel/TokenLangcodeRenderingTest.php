<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenResolutionEngineInterface;
use Drupal\token_context_test\Plugin\Token\SlugProbeCityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the langcode replacement option drives locale-sensitive rendering.
 *
 * The 'langcode' option (which callers such as pathauto set per translation)
 * must thread through the engine to the renderer, so that locale-sensitive
 * serialization - URL-slug transliteration here - matches the language the
 * value was resolved for. The de-vs-en pair is its own negative control: the
 * only thing that changes between the two assertions is the langcode, so a
 * difference can only come from the option being honoured.
 *
 * @see \Drupal\token_engine\TokenResolutionEngine::resolveLangcode()
 * @see \Drupal\token_engine\TokenRenderer
 * @see \Drupal\token_context_test\Plugin\Token\SlugProbeCityResolver
 */
#[CoversClass(SlugProbeCityResolver::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenLangcodeRenderingTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_context_test'];

  /**
   * The resolution engine service.
   */
  protected TokenResolutionEngineInterface $engine;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->engine = $this->container->get('token_engine.resolution_engine');
  }

  /**
   * Resolves [slug_probe:city] to a URL slug for the given options.
   */
  private function resolveCitySlug(array $options): string {
    $options['output_context'] = OutputContext::UrlSlug;
    $replacements = $this->engine->generate(
      'slug_probe',
      ['city' => '[slug_probe:city]'],
      ['slug_probe' => 'probe'],
      $options,
      new BubbleableMetadata(),
    );
    return (string) $replacements['[slug_probe:city]'];
  }

  /**
   * Tests the langcode option selects the language's transliteration rules.
   */
  public function testLangcodeOptionDrivesSlugTransliteration(): void {
    // "Zürich" romanises to "zuerich" under German rules (ü → ue) but "zurich"
    // under the generic table. Only the langcode differs between the two calls.
    $this->assertSame('zuerich', $this->resolveCitySlug(['langcode' => 'de']), 'German langcode applies German romanisation end to end.');
    $this->assertSame('zurich', $this->resolveCitySlug(['langcode' => 'en']), 'A different langcode uses the generic romanisation.');
  }

  /**
   * Tests that with no langcode option the default content language is used.
   *
   * The kernel default language is English, so the generic romanisation is the
   * expected fall back when the option is absent.
   */
  public function testFallsBackToDefaultLanguageWhenOptionAbsent(): void {
    $this->assertSame('zurich', $this->resolveCitySlug([]), 'Absent langcode falls back to the default content language.');
  }

}
