<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\Discovery\TypedDataFieldDiscovery;
use Drupal\token_engine\EntityDerefToken;
use Drupal\token_engine\EntityReferenceFieldToken;
use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\token_engine\FieldValueToken;
use Drupal\token_engine\TokenDefinition;
use Drupal\token_engine\TokenRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the fieldTokenInfoAlter equivalence with REAL typed-data discovery.
 *
 * The contrib token module's fieldTokenInfoAlter() iterates content entity
 * field definitions and registers per-field token definitions (scalar fields,
 * entity references, deltas). TypedDataFieldDiscovery does the same job
 * structurally and at runtime, and the discovery-alter event still composes on
 * top, so a subscriber can add, remove, or modify the discovered field tokens.
 *
 * This is the genuine acceptance proof: field tokens are produced by discovery,
 * not hand-simulated in the test.
 *
 * @see \Drupal\token_engine\Discovery\TypedDataFieldDiscovery
 * @see \Drupal\token_engine\Event\TokenDiscoveryAlterEvent
 */
#[CoversClass(TypedDataFieldDiscovery::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenTypedDataDiscoveryTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'filter'];

  /**
   * The token registry.
   */
  protected TokenRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
    $this->registry = $this->container->get('token_engine.registry');
  }

  /**
   * Tests that typed-data discovery produces scalar field tokens for an entity.
   */
  public function testDiscoveryProducesScalarFieldTokens(): void {
    $tokens = $this->registry->getTokensForInputType('entity:node');

    $this->assertArrayHasKey('title', $tokens, 'The node title field is discovered as a token.');
    $this->assertSame('string', $tokens['title']->outputType);
    $this->assertSame(FieldValueToken::class, $tokens['title']->resolverClass, 'Scalar fields use the shared FieldValueToken resolver.');
  }

  /**
   * Tests that an entity-reference field is discovered as a reference token.
   */
  public function testDiscoveryProducesEntityReferenceTokens(): void {
    $tokens = $this->registry->getTokensForInputType('entity:node');

    $this->assertArrayHasKey('uid', $tokens, 'The node author reference field is discovered.');
    $this->assertSame('entity_reference:user', $tokens['uid']->outputType, 'Reference fields output an entity_reference type.');
    $this->assertSame(EntityReferenceFieldToken::class, $tokens['uid']->resolverClass);
  }

  /**
   * Tests that the paired 'entity' deref token exists for the reference type.
   */
  public function testDiscoveryProducesEntityDerefToken(): void {
    $tokens = $this->registry->getTokensForInputType('entity_reference:user');

    $this->assertArrayHasKey('entity', $tokens, "The 'entity' deref token exists for the reference type.");
    $this->assertSame('entity:user', $tokens['entity']->outputType);
    $this->assertSame(EntityDerefToken::class, $tokens['entity']->resolverClass);
  }

  /**
   * Tests identity is always (input_type, name) for discovered field tokens.
   */
  public function testDiscoveredTokensUseCompoundIdentity(): void {
    $tokens = $this->registry->getTokensForInputType('entity:node');
    $this->assertSame('entity:node:uid', $tokens['uid']->getIdentityKey());
    $this->assertSame('entity:node', $tokens['uid']->inputType);
  }

  /**
   * Tests that a subscriber can modify the discovered field token set en masse.
   *
   * This is the fieldTokenInfoAlter use case: a subscriber reaches in and adds
   * to, removes from, or relabels the discovered field tokens.
   */
  public function testAlterEventComposesOnTopOfDiscovery(): void {
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        // Remove a discovered field and add a synthetic computed one.
        $event->removeDefinition('entity:node', 'title');
        $event->addDefinition(new TokenDefinition(
          name: 'computed_label',
          inputType: 'entity:node',
          outputType: 'string',
          resolverClass: FieldValueToken::class,
          module: 'test',
        ));
      },
    );
    $this->registry->invalidate();

    $tokens = $this->registry->getTokensForInputType('entity:node');
    $this->assertArrayNotHasKey('title', $tokens, 'A subscriber can remove a discovered field token.');
    $this->assertArrayHasKey('computed_label', $tokens, 'A subscriber can add a token alongside discovered field tokens.');
    // Other discovered fields remain.
    $this->assertArrayHasKey('uid', $tokens, 'Unaffected discovered fields remain.');
  }

  /**
   * Tests the resolution variant also sees discovered field tokens.
   *
   * GetResolvableToken() must surface discovery without building legacy info.
   */
  public function testResolvableTokenSurfacesDiscovery(): void {
    $this->assertNotNull($this->registry->getResolvableToken('entity:node', 'uid'), 'Resolution path sees discovered reference field.');
    $this->assertNotNull($this->registry->getResolvableToken('entity_reference:user', 'entity'), 'Resolution path sees the deref token.');
  }

}
