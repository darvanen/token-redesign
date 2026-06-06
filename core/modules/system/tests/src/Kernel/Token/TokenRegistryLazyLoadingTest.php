<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the registry loads per-input-type slices lazily, never the full set.
 *
 * Performance cannot be asserted as wall-clock time in a unit test, but the
 * property that drives it can: the registry must build a definition slice only
 * for an input type that is actually traversed, and never build the whole token
 * set up front. The persistent cache is the observable. After resolving a
 * single chain, only the slices for the types on that chain's path should have
 * been built and cached; an installed-but-untraversed entity type's slice must
 * be absent.
 *
 * @see \Drupal\Core\Token\TokenRegistry
 */
#[CoversClass(\Drupal\Core\Token\TokenRegistry::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenRegistryLazyLoadingTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * The persistent cache backend the registry writes slices to.
   */
  private const RESOLVE = 'token_registry:v1:resolve:en:';

  /**
   * The info-variant cache prefix, built only by the token-browser path.
   */
  private const INFO = 'token_registry:v1:info:en:';

  /**
   * {@inheritdoc}
   *
   * Taxonomy is installed purely as a foil: taxonomy_term is a fieldable
   * entity whose field tokens are discoverable, so if the registry built
   * everything up front its slice would appear in the cache. The test chain
   * never traverses a term, so its slice must stay unbuilt.
   */
  protected static $modules = ['node', 'filter', 'taxonomy'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->createContentType(['type' => 'article']);
  }

  /**
   * Tests that only the slices for traversed input types are ever built.
   */
  public function testOnlyTraversedSlicesAreBuilt(): void {
    $author = $this->createUser([], 'lazy_author');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Lazy loading node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $cache = $this->container->get('cache.default');
    $registry = $this->container->get('token.registry');

    // Start cold: drop any slices built incidentally during setup.
    $registry->invalidate();
    $this->assertFalse($cache->get(self::RESOLVE . 'entity:node'), 'No node slice is cached before any token is resolved.');
    $this->assertFalse($cache->get(self::RESOLVE . 'entity:user'), 'No user slice is cached before any token is resolved.');

    // Resolve a single chain: node -> uid (reference) -> entity -> user name.
    $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $admin]);

    // The three input types actually walked were built on demand and cached.
    $this->assertNotFalse($cache->get(self::RESOLVE . 'entity:node'), 'The entity:node slice was built on demand.');
    $this->assertNotFalse($cache->get(self::RESOLVE . 'entity_reference:user'), 'The entity_reference:user slice was built on demand.');
    $this->assertNotFalse($cache->get(self::RESOLVE . 'entity:user'), 'The entity:user slice was built on demand.');

    // The untraversed entity type's slice was never built, even though its
    // field tokens are discoverable. This is the lazy-loading guarantee: the
    // full token set is not assembled up front.
    $this->assertFalse($cache->get(self::RESOLVE . 'entity:taxonomy_term'), 'An installed but untraversed entity type slice is never built.');
  }

  /**
   * Tests that resolving a token never builds the heavier token-info variant.
   *
   * The legacy hook_token_info()-backed "info" slices (used by the token
   * browser) are a separate, heavier code path. A plain token replacement must
   * not trigger them, which is what keeps replacement off the full
   * hook_token_info() build.
   */
  public function testResolutionDoesNotBuildInfoVariant(): void {
    $author = $this->createUser([], 'info_author');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Info variant node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $cache = $this->container->get('cache.default');
    $this->container->get('token.registry')->invalidate();

    $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $admin]);

    $this->assertFalse($cache->get(self::INFO . 'entity:node'), 'Resolution does not build the token-info variant for entity:node.');
    $this->assertFalse($cache->get(self::INFO . 'node'), 'Resolution does not build the token-info variant for the legacy node type.');
  }

  /**
   * Tests that the info variant is built only when explicitly requested.
   *
   * Asking the registry for an input type's full slice (the token-browser path)
   * builds the info variant; this confirms the two paths are genuinely distinct
   * and that the laziness above is not just an empty registry.
   */
  public function testInfoVariantIsBuiltOnlyWhenRequested(): void {
    $cache = $this->container->get('cache.default');
    $registry = $this->container->get('token.registry');
    $registry->invalidate();

    $this->assertFalse($cache->get(self::INFO . 'node'), 'The node info slice is not cached up front.');

    $registry->getTokensForInputType('node');

    $this->assertNotFalse($cache->get(self::INFO . 'node'), 'The node info slice is built when explicitly requested.');
    // ...but requesting one type still does not build another.
    $this->assertFalse($cache->get(self::INFO . 'user'), 'Requesting the node info slice does not build the user info slice.');
  }

}
