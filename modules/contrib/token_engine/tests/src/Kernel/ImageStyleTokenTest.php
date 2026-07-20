<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\Core\Access\AccessResult;
use Drupal\token_engine\ActorContext;
use Drupal\token_engine\ImageStyleToken;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenDefinition;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResult;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests ImageStyleToken: the Phase 3 type-level operation pattern.
 *
 * An image style token is registered against the 'image' output type, not
 * against any individual field. One resolver class (ImageStyleToken) handles
 * ALL configured image styles by receiving the style machine name via
 * $arguments['style']. This test proves:
 *
 *  1. ImageStyleToken implements TokenResolverInterface correctly.
 *  2. The resolver resolves a URI to a styled URL using the named image style.
 *  3. The returned TokenResult carries the image style entity's cache tags.
 *  4. Missing or empty style arguments and unavailable styles degrade to ''.
 *  5. The type-level registration pattern: multiple styles share one class,
 *     each registered as a separate TokenDefinition against the 'image' type.
 *
 * @see \Drupal\token_engine\ImageStyleToken
 * @see \Drupal\token_engine\Event\TokenDiscoveryAlterEvent
 */
#[CoversClass(ImageStyleToken::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class ImageStyleTokenTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['image', 'file'];

  /**
   * The image style token resolver under test.
   */
  protected ImageStyleToken $resolver;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->resolver = new ImageStyleToken(
      $this->container->get('entity_type.manager'),
    );
  }

  /**
   * Returns a minimal TokenResolutionContext suitable for these tests.
   */
  private function makeContext(): TokenResolutionContext {
    $account = $this->container->get('current_user');
    return new TokenResolutionContext(
      data: [],
      actor: ActorContext::fromSingleActor($account),
      outputContext: OutputContext::Html,
    );
  }

  /**
   * Tests that a valid image style resolves to the expected URL.
   *
   * Creates an image style config entity, then resolves an image URI through
   * the resolver using $arguments['style']. The result must contain the style
   * machine name in the URL path and must carry the style entity's cache tags.
   */
  public function testResolvesValidImageStyleToUrl(): void {
    $style = ImageStyle::create([
      'name' => 'test_thumbnail',
      'label' => 'Test Thumbnail',
    ]);
    $style->save();

    $uri = 'public://test-image.jpg';
    $result = $this->resolver->resolve(
      $uri,
      ['style' => 'test_thumbnail'],
      $this->makeContext(),
    );

    $this->assertInstanceOf(TokenResult::class, $result);
    $this->assertNotEmpty($result->value, 'A non-empty URL is returned for a valid image style.');
    $this->assertStringContainsString('test_thumbnail', $result->value, 'The resolved URL contains the image style machine name.');
    $this->assertEquals(AccessResult::allowed(), $result->access, 'Access is allowed for a valid style.');
  }

  /**
   * Tests that the TokenResult carries the image style entity's cache tags.
   *
   * Image style config entities must be included in the cacheability of the
   * result so that page caches are properly invalidated when a style changes.
   */
  public function testResultCarriesImageStyleCacheTags(): void {
    $style = ImageStyle::create([
      'name' => 'cache_tag_test',
      'label' => 'Cache Tag Test',
    ]);
    $style->save();

    $result = $this->resolver->resolve(
      'public://some-image.png',
      ['style' => 'cache_tag_test'],
      $this->makeContext(),
    );

    $cacheTags = $result->cacheability->getCacheTags();
    $expectedTag = 'config:image.style.cache_tag_test';
    $this->assertContains(
      $expectedTag,
      $cacheTags,
      'The image style config entity cache tag is included in the result.',
    );
  }

  /**
   * Tests that a missing image style returns an empty TokenResult.
   *
   * When $arguments['style'] names an image style that does not exist the
   * resolver must not throw; it must return an empty-string TokenResult so that
   * token replacement degrades gracefully.
   */
  public function testMissingImageStyleReturnsEmpty(): void {
    $result = $this->resolver->resolve(
      'public://test-image.jpg',
      ['style' => 'nonexistent_style'],
      $this->makeContext(),
    );

    $this->assertSame('', $result->value, 'A non-existent image style returns an empty string.');
  }

  /**
   * Tests that an absent style argument returns an empty TokenResult.
   */
  public function testAbsentStyleArgumentReturnsEmpty(): void {
    $result = $this->resolver->resolve(
      'public://test-image.jpg',
      [],
      $this->makeContext(),
    );

    $this->assertSame('', $result->value, 'An absent style argument returns an empty string.');
  }

  /**
   * Tests that an empty image URI returns an empty TokenResult.
   */
  public function testEmptyUriReturnsEmpty(): void {
    ImageStyle::create(['name' => 'test_medium', 'label' => 'Test Medium'])->save();

    $result = $this->resolver->resolve('', ['style' => 'test_medium'], $this->makeContext());

    $this->assertSame('', $result->value, 'An empty image URI returns an empty string.');
  }

  /**
   * Tests the type-level registration pattern with multiple styles.
   *
   * This is the core Phase 3 proof: a single resolver class is registered once
   * per image style as a separate TokenDefinition, all against the same 'image'
   * input type. Each definition passes a different style name via
   * $arguments['style']. The resolver produces distinct URLs for each style.
   *
   * This proves berdir's canonical example: "an image style token registers as
   * an operation against the image output type rather than against any
   * individual field."
   */
  public function testTypeLevelRegistrationPattern(): void {
    $styleNames = ['test_small', 'test_large_custom', 'test_square'];
    foreach ($styleNames as $name) {
      ImageStyle::create(['name' => $name, 'label' => ucfirst($name)])->save();
    }

    $uri = 'public://photo.jpg';
    $context = $this->makeContext();
    $urls = [];

    // Simulate how the engine invokes the resolver: for each registered
    // TokenDefinition (one per style, all with inputType='image'), the engine
    // calls resolve() with the style name in $arguments.
    foreach ($styleNames as $styleName) {
      $result = $this->resolver->resolve($uri, ['style' => $styleName], $context);
      $this->assertNotEmpty($result->value, "Style '{$styleName}' returned a non-empty URL.");
      $this->assertStringContainsString($styleName, $result->value, "URL contains style name '{$styleName}'.");
      $urls[$styleName] = $result->value;
    }

    // All three URLs must be distinct.
    $this->assertCount(3, array_unique($urls), 'Each image style produces a distinct URL.');

    // Each result carries only its own style's cache tag, not other styles'.
    foreach ($styleNames as $styleName) {
      $result = $this->resolver->resolve($uri, ['style' => $styleName], $context);
      $tags = $result->cacheability->getCacheTags();
      $this->assertContains(
        "config:image.style.{$styleName}",
        $tags,
        "Result for '{$styleName}' carries its own cache tag.",
      );
      foreach (array_diff($styleNames, [$styleName]) as $otherStyle) {
        $this->assertNotContains(
          "config:image.style.{$otherStyle}",
          $tags,
          "Result for '{$styleName}' does not carry cache tag of '{$otherStyle}'.",
        );
      }
    }
  }

  /**
   * Tests TokenDefinition registration for image style tokens.
   *
   * Verifies that a TokenDiscoveryAlterEvent subscriber can register image style
   * tokens against the 'image' input type and that the registry reflects those
   * definitions, proving the type-level operation registration works end-to-end.
   */
  public function testTokenDefinitionRegistration(): void {
    $registry = $this->container->get('token_engine.registry');

    // Register image style token definitions via the event alter mechanism,
    // mirroring exactly how contrib's fieldTokenInfoAlter registers them.
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        foreach (['thumbnail', 'medium', 'large'] as $styleName) {
          $event->addDefinition(new TokenDefinition(
            name: $styleName,
            inputType: 'image',
            outputType: 'string',
            label: ucfirst($styleName) . ' image style',
            resolverClass: ImageStyleToken::class,
            module: 'image',
          ));
        }
      },
    );
    $registry->invalidate();

    $imageTokens = $registry->getTokensForInputType('image');

    foreach (['thumbnail', 'medium', 'large'] as $styleName) {
      $this->assertArrayHasKey(
        $styleName,
        $imageTokens,
        "Image style token '{$styleName}' is registered against the 'image' input type.",
      );
      $definition = $imageTokens[$styleName];
      $this->assertSame(ImageStyleToken::class, $definition->resolverClass);
      $this->assertSame('image', $definition->inputType);
      $this->assertSame('string', $definition->outputType);
    }
  }

}
