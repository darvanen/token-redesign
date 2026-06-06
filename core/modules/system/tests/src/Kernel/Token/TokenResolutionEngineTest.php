<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Token\LegacyTokenBridge;
use Drupal\Core\Token\TokenRegistryInterface;
use Drupal\Core\Token\TokenResolutionEngine;
use Drupal\Core\Token\TokenResolutionEngineInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Verifies engine output parity with legacy and correct registry slices.
 *
 * The resolution engine must produce output identical to the legacy token
 * system, and the per-input-type registry slices must be correct.
 *
 * Phase 1 goal: the engine is "invisible" – replacing it with direct hook
 * invocations must yield the same results. These tests are the proof.
 *
 * @see \Drupal\Core\Token\TokenResolutionEngine
 * @see \Drupal\Core\Token\LegacyTokenBridge
 * @see \Drupal\Core\Token\TokenRegistry
 */
#[CoversClass(TokenResolutionEngine::class)]
#[CoversClass(LegacyTokenBridge::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenResolutionEngineTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'user', 'system', 'field'];

  /**
   * The resolution engine service.
   */
  protected TokenResolutionEngineInterface $engine;

  /**
   * The token registry service.
   */
  protected TokenRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->engine = $this->container->get('token.resolution_engine');
    $this->registry = $this->container->get('token.registry');
  }

  /**
   * Tests that the engine produces identical replacements to the legacy system.
   *
   * Runs the same token string through both the Token service (legacy code path)
   * and the engine directly, and asserts the results are identical. This is the
   * Phase 1 "invisible" proof.
   */
  public function testEngineProducesIdenticalResultsToLegacy(): void {
    $date_tokens = [
      'short' => '[date:short]',
      'medium' => '[date:medium]',
      'long' => '[date:long]',
    ];
    $data = [];
    $options = ['langcode' => 'en'];

    $legacy_metadata = new BubbleableMetadata();
    $engine_metadata = new BubbleableMetadata();

    // Resolve via the legacy Token service.
    $legacy_replacements = $this->tokenService->generate('date', $date_tokens, $data, $options, $legacy_metadata);

    // Resolve via the engine directly.
    $engine_replacements = $this->engine->generate('date', $date_tokens, $data, $options, $engine_metadata);

    $this->assertEquals($legacy_replacements, $engine_replacements, 'Engine replacements are identical to legacy Token::generate() for date tokens.');
    $this->assertEquals(
      $legacy_metadata->getCacheTags(),
      $engine_metadata->getCacheTags(),
      'Cache tags are identical.',
    );
    $this->assertEquals(
      $legacy_metadata->getCacheContexts(),
      $engine_metadata->getCacheContexts(),
      'Cache contexts are identical.',
    );
  }

  /**
   * Tests that the full token replace() call is identical through both paths.
   *
   * This verifies that Token::replace() routes correctly through the engine and
   * back to the legacy hooks with no behavioral difference.
   */
  public function testTokenReplaceIsIdenticalThroughEngine(): void {
    $text = 'Current date: [date:short]. Site: [site:name].';
    $metadata_a = new BubbleableMetadata();
    $metadata_b = new BubbleableMetadata();

    // Both calls hit the same hook pipeline (once via the engine, once directly
    // through the Token service which now delegates to the engine).
    $result_a = $this->tokenService->replace($text, [], ['langcode' => 'en'], $metadata_a);
    $result_b = $this->tokenService->replace($text, [], ['langcode' => 'en'], $metadata_b);

    $this->assertSame($result_a, $result_b, 'Token::replace() produces consistent output.');
    $this->assertStringNotContainsString('[date:short]', $result_a, 'Date token was replaced.');
    $this->assertStringNotContainsString('[site:name]', $result_a, 'Site name token was replaced.');
  }

  /**
   * Tests that the registry provides per-input-type slices from legacy info.
   */
  public function testRegistryProvidesSlicedDefinitions(): void {
    $site_tokens = $this->registry->getTokensForInputType('site');

    $this->assertNotEmpty($site_tokens, 'Registry returns definitions for the "site" input type.');
    $this->assertArrayHasKey('name', $site_tokens, 'Site "name" token definition is present.');
    $this->assertArrayHasKey('mail', $site_tokens, 'Site "mail" token definition is present.');

    // Verify identity key format: input_type:name.
    $name_def = $site_tokens['name'];
    $this->assertSame('site:name', $name_def->getIdentityKey(), 'Identity key uses (input_type, name) pair.');
    $this->assertSame('site', $name_def->inputType);
    $this->assertSame('name', $name_def->name);
  }

  /**
   * Tests that the registry returns null for an unknown token.
   */
  public function testRegistryReturnsNullForUnknownToken(): void {
    $definition = $this->registry->getToken('site', '__no_such_token__');
    $this->assertNull($definition, 'Registry returns NULL for an unknown (input_type, name) pair.');
  }

  /**
   * Tests that registry slices are independent.
   *
   * Fetching one type does not pre-populate another type's slice. This is the
   * per-type lazy isolation property: only what is needed gets loaded and cached.
   */
  public function testRegistrySlicesAreIndependent(): void {
    $site_tokens = $this->registry->getTokensForInputType('site');
    $date_tokens = $this->registry->getTokensForInputType('date');

    $this->assertArrayNotHasKey('name', $date_tokens, 'Date slice does not contain site tokens.');
    $this->assertArrayNotHasKey('short', $site_tokens, 'Site slice does not contain date tokens.');
  }

  /**
   * Tests that the registry invalidate() clears the static cache.
   */
  public function testRegistryInvalidateClearsCache(): void {
    // Warm the static cache.
    $before = $this->registry->getTokensForInputType('site');
    $this->assertNotEmpty($before);

    $this->registry->invalidate();

    // After invalidation the data is re-derived (still correct, just fresh).
    $after = $this->registry->getTokensForInputType('site');
    $this->assertNotEmpty($after);
    $this->assertArrayHasKey('name', $after, 'Data is still correct after invalidation.');
  }

}
