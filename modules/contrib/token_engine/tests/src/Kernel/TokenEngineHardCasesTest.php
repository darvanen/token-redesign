<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\token_engine\ActorContext;
use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\token_engine\ImageStyleToken;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenDefinition;
use Drupal\token_engine\TokenRegistryInterface;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolutionEngineInterface;
use Drupal\image\Entity\ImageStyle;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the design brief's hard cases that run THROUGH the resolution engine.
 *
 * These exercise the engine wiring that was previously a Phase 3 TODO:
 *  - The numeric delta index operation on a list output type.
 *  - A trailing-argument token (the [...:custom:Y-m-d] pattern).
 *  - The type-level image-style operation, invoked by the engine which passes
 *    the token name as the style identifier.
 *
 * @see \Drupal\token_engine\TokenResolutionEngine
 * @see \Drupal\token_engine\ListDeltaResolver
 * @see \Drupal\token_engine\ImageStyleToken
 */
#[CoversClass(TokenResolutionEngineInterface::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenEngineHardCasesTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['image', 'token_context_test'];

  /**
   * The resolution engine.
   */
  protected TokenResolutionEngineInterface $engine;

  /**
   * The token registry.
   */
  protected TokenRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->engine = $this->container->get('token_engine.resolution_engine');
    $this->registry = $this->container->get('token_engine.registry');
  }

  /**
   * Builds a resolution context with the current user as a single actor.
   */
  private function context(): TokenResolutionContext {
    return new TokenResolutionContext(
      [],
      ActorContext::fromSingleActor($this->container->get('current_user')),
      OutputContext::Html,
    );
  }

  /**
   * Tests that a numeric segment on a list output type selects the delta.
   *
   * The chain [fake_list:items:0] resolves 'items' to a list<string> and the
   * engine recognises the numeric '0' as the built-in index operation, with no
   * per-token delta handling. This is the recommended treatment of the delta
   * segment from the design brief.
   */
  public function testNumericSegmentSelectsListDelta(): void {
    $result0 = $this->engine->resolveChain('fake_list', ['items', '0'], NULL, $this->context());
    $result1 = $this->engine->resolveChain('fake_list', ['items', '1'], NULL, $this->context());
    $result2 = $this->engine->resolveChain('fake_list', ['items', '2'], NULL, $this->context());

    $this->assertNotNull($result0);
    $this->assertSame('alpha', $result0->value, 'Delta 0 selects the first list item.');
    $this->assertSame('beta', $result1->value, 'Delta 1 selects the second list item.');
    $this->assertSame('gamma', $result2->value, 'Delta 2 selects the third list item.');
  }

  /**
   * Tests delta resolution end to end through generate() and rendering.
   */
  public function testDeltaThroughGenerate(): void {
    $bubbleable = new BubbleableMetadata();
    $replacements = $this->engine->generate('fake_list', ['items:1' => '[fake_list:items:1]'], ['fake_list' => TRUE], [], $bubbleable);

    $this->assertArrayHasKey('[fake_list:items:1]', $replacements);
    $this->assertSame('beta', (string) $replacements['[fake_list:items:1]'], 'Engine resolves and renders the delta-selected item.');
  }

  /**
   * Tests a trailing-argument token: [timestamp:custom:Y-m-d].
   *
   * The 'custom' token declares an argument name, so the engine consumes the
   * remaining chain ('Y-m-d') as the 'format' argument and terminates traversal
   * rather than treating 'Y-m-d' as a further segment lookup.
   */
  public function testTrailingArgumentToken(): void {
    // 2023-11-14T22:13:20 UTC.
    $timestamp = 1700000000;
    $result = $this->engine->resolveChain('timestamp', ['custom', 'Y-m-d'], $timestamp, $this->context());

    $this->assertNotNull($result, 'The trailing-argument chain resolved.');
    $this->assertSame('2023-11-14', $result->value, "'custom' consumed 'Y-m-d' as the format argument.");
  }

  /**
   * Tests that a colon-bearing format argument is preserved intact.
   *
   * 'H:i:s' contains colons; the engine must re-join the consumed remainder so
   * the resolver receives the whole format, not just the first piece.
   */
  public function testTrailingArgumentPreservesColons(): void {
    $timestamp = 1700000000;
    $result = $this->engine->resolveChain('timestamp', ['custom', 'H', 'i', 's'], $timestamp, $this->context());

    $this->assertNotNull($result);
    $this->assertSame('22:13:20', $result->value, 'The colon-delimited remainder is re-joined into one format argument.');
  }

  /**
   * Tests the image-style type-level operation invoked through the engine.
   *
   * One ImageStyleToken resolver serves every configured style; the engine
   * passes the token name as the style identifier. The style token is
   * contributed via the discovery-alter event (the registration pattern
   * documented on ImageStyleToken).
   */
  public function testImageStyleTokenThroughEngine(): void {
    ImageStyle::create(['name' => 'test_token_style', 'label' => 'Test token style'])->save();

    // Register one token per style against the 'image' input type, all sharing
    // the ImageStyleToken resolver class. This mirrors fieldTokenInfoAlter.
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        if ($event->hasDefinition('image', 'test_token_style')) {
          return;
        }
        $event->addDefinition(new TokenDefinition(
          name: 'test_token_style',
          inputType: 'image',
          outputType: 'string',
          resolverClass: ImageStyleToken::class,
          module: 'image',
        ));
      },
    );
    $this->registry->invalidate();

    $result = $this->engine->resolveChain('image', ['test_token_style'], 'public://example.png', $this->context());

    $this->assertNotNull($result, 'The image-style token resolved through the engine.');
    $this->assertIsString($result->value);
    $this->assertStringContainsString('test_token_style', $result->value, 'The engine passed the token name to ImageStyleToken as the style id.');
    $this->assertTrue($result->access->isAllowed());
  }

}
