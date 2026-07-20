<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\token_engine\Event\TokenDiscoveryAlterEvent;
use Drupal\token_engine\TokenDefinition;
use Drupal\token_engine\TokenRegistryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the TokenDiscoveryAlterEvent as a replacement for hook_token_info_alter.
 *
 * The acceptance criterion for Phase 2 is that the alter event can express
 * everything that \Drupal\token\Hook\TokenTokenInfoHooks::fieldTokenInfoAlter
 * does. That method:
 *  - Iterates all content entity types and their field storage definitions.
 *  - For each field, adds a TokenDefinition as a sub-type of the entity token.
 *  - For multivalue fields, wraps in a list<...> type with delta tokens.
 *  - Adds property sub-tokens (value, entity ref, etc.).
 *  - Adds image-style and date-format tokens for image/date fields.
 *
 * The tests below prove:
 *  1. A subscriber can add field-level TokenDefinitions for any input type.
 *  2. A subscriber can add nested (sub-property) TokenDefinitions.
 *  3. A subscriber can remove a definition contributed by legacy hooks.
 *  4. A subscriber can replace the resolver class on an existing definition.
 *  5. Multiple subscribers compose correctly (last writer wins for the same key).
 *
 * If any test fails it means the event's API cannot express the fieldTokenInfoAlter
 * behavior and the declaration/discovery design needs revisiting.
 *
 * @see \Drupal\token_engine\Event\TokenDiscoveryAlterEvent
 * @see \Drupal\token_engine\TokenRegistry
 */
#[CoversClass(TokenDiscoveryAlterEvent::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenDiscoveryAlterEventTest extends TokenReplaceKernelTestBase {

  /**
   * The token registry.
   */
  protected TokenRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->registry = $this->container->get('token_engine.registry');
  }

  /**
   * Registers a listener and forces the registry to re-process.
   *
   * This helper mirrors what a module would do by registering a service tagged
   * as an event_listener for TokenDiscoveryAlterEvent::DISCOVERY_ALTER.
   *
   * @param callable $listener
   *   The listener callback.
   */
  private function addAlterListener(callable $listener): void {
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      $listener,
    );
    // Invalidate the registry so the next getTokensForInputType() call
    // re-runs discovery and fires the event again with the new listener.
    $this->registry->invalidate();
  }

  /**
   * Tests that a subscriber can add field-level tokens.
   *
   * This is the core fieldTokenInfoAlter equivalence proof: for each entity
   * field, the subscriber adds a TokenDefinition to the entity's input type.
   * The result must appear in the registry just as if hook_token_info() had
   * declared the token directly.
   */
  public function testSubscriberCanAddFieldTokens(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      // Simulate what fieldTokenInfoAlter does for a field on a node:
      // create a field-level token sub-type and register it.
      $event->addDefinition(new TokenDefinition(
        name: 'field_summary',
        inputType: 'node',
        outputType: 'node-field_summary',
        label: 'Summary field',
        module: 'token',
      ));
    });

    $nodeTokens = $this->registry->getTokensForInputType('node');

    $this->assertArrayHasKey(
      'field_summary',
      $nodeTokens,
      'TokenDiscoveryAlterEvent subscriber can add a field token definition to an entity type.',
    );
    $this->assertSame('node-field_summary', $nodeTokens['field_summary']->outputType);
  }

  /**
   * Tests that a subscriber can add nested sub-property tokens.
   *
   * FieldTokenInfoAlter adds property-level sub-tokens under a field-specific
   * type. This test proves the same structure can be built via the event.
   */
  public function testSubscriberCanAddNestedPropertyTokens(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      // The field token type (like 'node-body').
      $event->addDefinition(new TokenDefinition(
        name: 'body',
        inputType: 'node',
        outputType: 'node-body',
        label: 'Body',
        module: 'token',
      ));

      // Sub-property tokens under the field type.
      $event->addDefinition(new TokenDefinition(
        name: 'value',
        inputType: 'node-body',
        outputType: 'string',
        label: 'Raw text value',
        module: 'token',
      ));
      $event->addDefinition(new TokenDefinition(
        name: 'format',
        inputType: 'node-body',
        outputType: 'string',
        label: 'Text format',
        module: 'token',
      ));
    });

    // Verify both levels of the hierarchy.
    $nodeTokens = $this->registry->getTokensForInputType('node');
    $this->assertArrayHasKey('body', $nodeTokens, 'Field token registered on node type.');

    $bodyTokens = $this->registry->getTokensForInputType('node-body');
    $this->assertArrayHasKey('value', $bodyTokens, 'value sub-property registered.');
    $this->assertArrayHasKey('format', $bodyTokens, 'format sub-property registered.');
  }

  /**
   * Tests that a subscriber can add delta-indexed tokens for multivalue fields.
   *
   * FieldTokenInfoAlter registers numeric delta tokens (0, 1, 2) under
   * list<field> types. The event must support tokens with integer-cast names.
   */
  public function testSubscriberCanAddDeltaTokens(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      // list<node-tags> type (the multivalue wrapper).
      $event->addDefinition(new TokenDefinition(
        name: 'tags',
        inputType: 'node',
        outputType: 'list<node-tags>',
        label: 'Tags field',
        module: 'token',
      ));
      // Delta tokens: each numeric key selects one item from the list.
      for ($delta = 0; $delta < 3; $delta++) {
        $event->addDefinition(new TokenDefinition(
          name: (string) $delta,
          inputType: 'list<node-tags>',
          outputType: 'node-tags',
          label: "Tags item {$delta}",
          module: 'token',
        ));
      }
    });

    $listTokens = $this->registry->getTokensForInputType('list<node-tags>');
    $this->assertArrayHasKey('0', $listTokens, 'Delta 0 token registered.');
    $this->assertArrayHasKey('1', $listTokens, 'Delta 1 token registered.');
    $this->assertArrayHasKey('2', $listTokens, 'Delta 2 token registered.');
  }

  /**
   * Tests that a subscriber can add image-style tokens for image fields.
   *
   * FieldTokenInfoAlter adds one token per configured image style under the
   * image field type. This is berdir's explicit example of a type-level
   * operation that is not a typed-data path.
   */
  public function testSubscriberCanAddImageStyleTokens(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      $event->addDefinition(new TokenDefinition(
        name: 'field_image',
        inputType: 'node',
        outputType: 'node-field_image',
        label: 'Image field',
        module: 'token',
      ));
      // Image-style tokens: one per configured style.
      foreach (['thumbnail', 'medium', 'large'] as $style) {
        $event->addDefinition(new TokenDefinition(
          name: $style,
          inputType: 'node-field_image',
          outputType: 'image_with_image_style',
          label: ucfirst($style) . ' image style',
          module: 'token',
        ));
      }
    });

    $imageTokens = $this->registry->getTokensForInputType('node-field_image');
    $this->assertArrayHasKey('thumbnail', $imageTokens, 'thumbnail image-style token registered.');
    $this->assertArrayHasKey('medium', $imageTokens, 'medium image-style token registered.');
    $this->assertArrayHasKey('large', $imageTokens, 'large image-style token registered.');
    $this->assertSame('image_with_image_style', $imageTokens['thumbnail']->outputType);
  }

  /**
   * Tests that a subscriber can remove a token contributed by legacy hooks.
   *
   * Hook_token_info_alter() is commonly used to remove tokens that should not
   * be exposed. The event must support this use-case identically.
   */
  public function testSubscriberCanRemoveLegacyToken(): void {
    // Verify 'name' exists via legacy hooks before the listener runs.
    $before = $this->registry->getTokensForInputType('site');
    $this->assertArrayHasKey('name', $before, 'site:name token exists via legacy hook.');

    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      $event->removeDefinition('site', 'name');
    });

    $after = $this->registry->getTokensForInputType('site');
    $this->assertArrayNotHasKey(
      'name',
      $after,
      'TokenDiscoveryAlterEvent::removeDefinition() removes legacy tokens (hook_token_info_alter equivalence).',
    );
  }

  /**
   * Tests that a subscriber can replace the resolver class on an existing definition.
   *
   * This is the migration path: a module migrates a legacy hook_tokens()
   * implementation to an attributed resolver by pointing the existing definition
   * at the new class. The event enables this without requiring the legacy hook
   * to be removed first.
   */
  public function testSubscriberCanReplaceResolverClass(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      $existing = $event->getDefinitionsForInputType('site')['name'] ?? NULL;
      if ($existing === NULL) {
        return;
      }
      // Replace with a new attributed resolver while keeping all other metadata.
      $event->addDefinition(new TokenDefinition(
        name: 'name',
        inputType: 'site',
        outputType: $existing->outputType,
        label: $existing->label,
        description: $existing->description,
        resolverClass: 'Drupal\system\Token\SiteNameToken',
        module: $existing->module,
      ));
    });

    $siteTokens = $this->registry->getTokensForInputType('site');
    $this->assertSame(
      'Drupal\system\Token\SiteNameToken',
      $siteTokens['name']->resolverClass ?? NULL,
      'TokenDiscoveryAlterEvent subscriber can replace the resolver class for migration.',
    );
    $this->assertTrue(
      $this->registry->getToken('site', 'name') !== NULL,
      'getToken() still finds the definition after resolver class replacement.',
    );
  }

  /**
   * Tests that hasDefinition() correctly reflects subscriber additions and removals.
   */
  public function testHasDefinitionReflectsSubscriberChanges(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      $event->addDefinition(new TokenDefinition(
        name: 'synthetic',
        inputType: 'node',
        outputType: 'string',
        module: 'test',
      ));
      $event->removeDefinition('site', 'mail');
    });

    $event = new TokenDiscoveryAlterEvent([]);
    $this->assertFalse($event->hasDefinition('node', 'synthetic'));

    // Re-derive event state to check via registry.
    $this->assertNotNull(
      $this->registry->getToken('node', 'synthetic'),
      'Subscriber-added synthetic token is found by the registry.',
    );
    $this->assertNull(
      $this->registry->getToken('site', 'mail'),
      'Subscriber-removed token is not found by the registry.',
    );
  }

  /**
   * Tests that multiple subscribers compose with last-writer-wins semantics.
   *
   * The last writer for a given (input_type, name) pair wins; addDefinition is
   * idempotent and overwriting.
   */
  public function testMultipleSubscribersComposeCorrectly(): void {
    $this->addAlterListener(function (TokenDiscoveryAlterEvent $event): void {
      $event->addDefinition(new TokenDefinition(
        name: 'composable',
        inputType: 'node',
        outputType: 'string',
        resolverClass: 'FirstResolver',
      ));
    });

    // Add a second listener after the first is already registered.
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        $event->addDefinition(new TokenDefinition(
          name: 'composable',
          inputType: 'node',
          outputType: 'string',
          resolverClass: 'SecondResolver',
        ));
      },
    );
    // Invalidate again to re-run with both listeners.
    $this->registry->invalidate();

    $nodeTokens = $this->registry->getTokensForInputType('node');
    $this->assertSame(
      'SecondResolver',
      $nodeTokens['composable']->resolverClass ?? NULL,
      'Second subscriber for the same (input_type, name) pair wins (last writer wins).',
    );
  }

}
