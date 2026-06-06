<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Token\TokenResult;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests \Drupal\Core\Token\TokenResult.
 */
#[CoversClass(TokenResult::class)]
#[Group('Token')]
class TokenResultTest extends UnitTestCase {

  /**
   * Tests fromValue() creates a result with no cacheability and full access.
   */
  public function testFromValue(): void {
    $result = TokenResult::fromValue('hello');
    $this->assertSame('hello', $result->value);
    $this->assertTrue($result->access->isAllowed());
    $this->assertEmpty($result->cacheability->getCacheTags());
    $this->assertEmpty($result->cacheability->getCacheContexts());
  }

  /**
   * Tests withAccess() intersects access with AND logic.
   */
  public function testWithAccessIntersects(): void {
    $result = TokenResult::fromValue('data');
    $restricted = $result->withAccess(AccessResult::forbidden());

    $this->assertTrue($result->access->isAllowed());
    $this->assertFalse($restricted->access->isAllowed());
  }

  /**
   * Tests merge() composes cacheability from both results and uses downstream value.
   */
  public function testMergeComposesMetadataAndUsesDownstreamValue(): void {
    $upstream = new TokenResult(
      value: 'upstream',
      cacheability: (new CacheableMetadata())->addCacheTags(['config:system.site']),
      access: AccessResult::allowed(),
    );
    $downstream = new TokenResult(
      value: 'downstream',
      cacheability: (new CacheableMetadata())->addCacheTags(['node:42']),
      access: AccessResult::allowed(),
    );

    $merged = $upstream->merge($downstream);

    $this->assertSame('downstream', $merged->value, 'Downstream value wins.');
    $this->assertContains('config:system.site', $merged->cacheability->getCacheTags(), 'Upstream tags present.');
    $this->assertContains('node:42', $merged->cacheability->getCacheTags(), 'Downstream tags present.');
  }

  /**
   * Tests merge() ANDs access results: forbidden upstream blocks downstream.
   */
  public function testMergeAccessIsForbiddenWhenUpstreamForbids(): void {
    $upstream = new TokenResult(
      value: 'x',
      cacheability: new CacheableMetadata(),
      access: AccessResult::forbidden(),
    );
    $downstream = new TokenResult(
      value: 'y',
      cacheability: new CacheableMetadata(),
      access: AccessResult::allowed(),
    );

    $merged = $upstream->merge($downstream);
    $this->assertFalse($merged->access->isAllowed());
  }

}
