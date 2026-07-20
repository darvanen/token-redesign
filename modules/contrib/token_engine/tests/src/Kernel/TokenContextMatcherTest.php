<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Plugin\Context\Context;
use Drupal\Core\Plugin\Context\EntityContextDefinition;
use Drupal\token_engine\TokenContextMatcher;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests resolving available contexts to the tokens relevant to them.
 *
 * This is the entry-filter a context-aware token browser needs: given the
 * contexts available where a token is being placed, surface only the tokens
 * that can start a chain there, rather than the whole token universe. The
 * matching uses core's context objects on one side and the registry's
 * per-input-type slices on the other, bridged by the entity-type mapper.
 *
 * @see \Drupal\token_engine\TokenContextMatcher
 * @see \Drupal\token_engine\TokenEntityTypeMapper
 */
#[CoversClass(TokenContextMatcher::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenContextMatcherTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_engine', 'system', 'user', 'node', 'field', 'text', 'filter'];

  /**
   * The context matcher under test.
   */
  protected TokenContextMatcher $matcher;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    // The matcher uses the info path, which builds full hook_token_info(); the
    // system date tokens need date-format config to be present.
    $this->installConfig(['system']);
    $this->matcher = $this->container->get('token_engine.context_matcher');
  }

  /**
   * Builds a value-less context for an entity type.
   */
  private function entityContext(string $entityTypeId): Context {
    return new Context(EntityContextDefinition::fromEntityTypeId($entityTypeId));
  }

  /**
   * Tests a context surfaces its own tokens and not another type's.
   */
  public function testContextSurfacesOnlyRelevantTokens(): void {
    $node = $this->matcher->rootTokensForContexts([$this->entityContext('node')]);
    $this->assertArrayHasKey('node', $node);
    $this->assertArrayHasKey('title', $node['node'], 'A node context surfaces the node title token.');
    $this->assertArrayNotHasKey('mail', $node['node'], 'A node context does not surface the user mail token.');

    $user = $this->matcher->rootTokensForContexts([$this->entityContext('user')]);
    $this->assertArrayHasKey('user', $user);
    $this->assertArrayHasKey('mail', $user['user'], 'A user context surfaces the user mail token.');
    $this->assertArrayNotHasKey('title', $user['user'], 'A user context does not surface the node title token.');
  }

  /**
   * Tests multiple advertised contexts compose into one relevant set.
   */
  public function testMultipleContextsCompose(): void {
    $both = $this->matcher->rootTokensForContexts([
      $this->entityContext('node'),
      $this->entityContext('user'),
    ]);
    $this->assertArrayHasKey('node', $both);
    $this->assertArrayHasKey('user', $both);
  }

  /**
   * Tests no advertised contexts surface no entity-rooted tokens.
   */
  public function testNoContextsSurfaceNoEntityTokens(): void {
    $this->assertSame([], $this->matcher->rootTokensForContexts([]));
  }

  /**
   * Tests the entity-type mapper bridges the taxonomy term alias.
   */
  public function testTaxonomyTermAliasIsMapped(): void {
    $mapper = $this->container->get('token_engine.entity_type_mapper');
    $this->assertSame('term', $mapper->getTokenType('taxonomy_term'));
    $this->assertSame('taxonomy_term', $mapper->getEntityTypeId('term'));
  }

}
