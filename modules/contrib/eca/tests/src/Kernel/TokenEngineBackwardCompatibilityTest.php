<?php

declare(strict_types=1);

namespace Drupal\Tests\eca\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Utility\Token;
use Drupal\token_engine\Token as EngineToken;
use Drupal\eca\EcaEvents;
use Drupal\eca\Event\TokenGenerateEvent;
use Drupal\eca\Token\CoreToken;
use Drupal\eca\Plugin\Token\NodeEntityTypeToken;
use Drupal\eca\Token\TokenInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\RoleInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves ECA's token decorator works correctly with the new resolution engine.
 *
 * The core Token::generate() now delegates to TokenResolutionEngine, which
 * routes unregistered tokens to LegacyTokenBridge (hook_tokens). This test
 * confirms that:
 *
 * - ECA's CoreToken decorator is still wired as a decorator over Token.
 * - addTokenData() injects runtime data that reaches hook_tokens via generate().
 * - ECA's generate() still dispatches EcaEvents::TOKEN at recursion level 1.
 * - Token replacement produces correct output end-to-end.
 */
#[Group('eca')]
#[Group('eca_core')]
#[RunTestsInSeparateProcesses]
class TokenEngineBackwardCompatibilityTest extends KernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'filter',
    'text',
    'node',
    'eca',
    'modeler_api',
    'token_engine',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(static::$modules);
    $this->createContentType(['type' => 'article', 'name' => 'Article']);
  }

  /**
   * Verifies CoreToken is a subclass of the core Token service.
   */
  public function testCoreTokenExtendsBaseToken(): void {
    /** @var \Drupal\eca\Token\CoreToken $core_token */
    $core_token = \Drupal::service('eca.service.token');

    $this->assertInstanceOf(CoreToken::class, $core_token, 'eca.service.token must be an instance of CoreToken.');
    $this->assertInstanceOf(Token::class, $core_token, 'CoreToken must extend the core Token class.');
    $this->assertInstanceOf(TokenInterface::class, $core_token, 'CoreToken must implement ECA TokenInterface.');
  }

  /**
   * Verifies addTokenData() makes data available to token replacement.
   *
   * The key BC concern: ECA adds data at runtime via addTokenData(), and that
   * data must flow through to the legacy hook_tokens() pipeline. Under the new
   * engine, generate() is called on the inner decorated Token (which routes to
   * the resolution engine and ultimately to LegacyTokenBridge). The decorator
   * injects data into the $data array before calling the decorated generate(),
   * so this path must continue to work.
   */
  public function testAddTokenDataReachesReplacement(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'BC test node title',
      'uid' => 0,
      'status' => 1,
    ]);
    $node->save();

    /** @var \Drupal\eca\Token\TokenInterface $token_services */
    $token_services = \Drupal::service('eca.token_services');
    $token_services->clearTokenData();
    $token_services->addTokenData('node', $node);

    // replace() merges addTokenData() values with the $data array, so this
    // should produce the node title without passing $data explicitly.
    $result = $token_services->replace('[node:title]');

    $this->assertSame('BC test node title', $result, 'addTokenData() must supply node to token replacement via the decorated generate() path.');
  }

  /**
   * Verifies ECA's generate() dispatches EcaEvents::TOKEN at recursion level 1.
   *
   * The event is dispatched inside TokenDecoratorTrait::generate() only when
   * $this->recursionLevel === 1. This is critical ECA behaviour: it allows
   * actions to react to token generation and enrich data. Under the new engine
   * the decorated generate() still runs on CoreToken first, so the event must
   * still fire.
   */
  public function testTokenEventIsDispatched(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Event dispatch test',
      'uid' => 0,
      'status' => 1,
    ]);
    $node->save();

    $dispatched_events = [];

    // Attach a subscriber to capture TOKEN events.
    $listener = static function (TokenGenerateEvent $event) use (&$dispatched_events): void {
      $dispatched_events[] = [
        'type' => $event->getType(),
        'name' => $event->getName(),
      ];
    };

    /** @var \Symfony\Contracts\EventDispatcher\EventDispatcherInterface $dispatcher */
    $dispatcher = \Drupal::service('event_dispatcher');
    $dispatcher->addListener(EcaEvents::TOKEN, $listener);

    /** @var \Drupal\eca\Token\CoreToken $core_token */
    $core_token = \Drupal::service('eca.service.token');
    $core_token->clearTokenData();

    $bubbleable = new BubbleableMetadata();
    $core_token->generate('node', ['title' => '[node:title]'], ['node' => $node], [], $bubbleable);

    $dispatcher->removeListener(EcaEvents::TOKEN, $listener);

    $this->assertNotEmpty($dispatched_events, 'EcaEvents::TOKEN must be dispatched by CoreToken::generate().');
    $this->assertSame('node', $dispatched_events[0]['type'], 'The dispatched event must carry the token type "node".');
    $this->assertSame('title', $dispatched_events[0]['name'], 'The dispatched event must carry the token name "title".');
  }

  /**
   * Verifies that full token replacement produces correct output end-to-end.
   *
   * This exercises the complete chain:
   *   TokenServices::replace()
   *   -> Token::replace() (parent, calls doReplace/generate)
   *   -> CoreToken::generate() (dispatches event, delegates to inner token)
   *   -> Token::generate() (routes to resolution engine)
   *   -> TokenResolutionEngine::generate() (partitions, falls through to legacy)
   *   -> LegacyTokenBridge::generate() (invokes hook_tokens)
   */
  public function testEndToEndReplacementThroughNewEngine(): void {
    $title = 'End-to-end token test - ' . $this->randomMachineName(8);
    $node = Node::create([
      'type' => 'article',
      'title' => $title,
      'uid' => 0,
      'status' => 1,
    ]);
    $node->save();

    /** @var \Drupal\eca\Token\TokenInterface $token_services */
    $token_services = \Drupal::service('eca.token_services');
    $token_services->clearTokenData();
    $token_services->addTokenData('node', $node);

    // Test 1: replacement via stored token data (no $data arg).
    $result = $token_services->replace('[node:title]');
    $this->assertSame($title, $result, 'Token replacement must use stored token data when no $data arg is given.');

    // Test 2: explicit $data arg overrides stored data.
    $other_node = Node::create([
      'type' => 'article',
      'title' => 'Other node',
      'uid' => 0,
      'status' => 1,
    ]);
    $other_node->save();
    $result2 = $token_services->replace('[node:title]', ['node' => $other_node]);
    $this->assertSame('Other node', $result2, 'Explicit $data must take precedence over stored token data.');

    // Test 3: clear token in output when no data and clear option set.
    $token_services->clearTokenData();
    $result3 = $token_services->replace('[node:title]', [], ['clear' => TRUE]);
    $this->assertSame('', $result3, 'With no data and clear=TRUE the token must be replaced with an empty string.');
  }

  /**
   * Verifies the decorated Token service still receives the resolution engine.
   *
   * Under the BC contract, CoreToken extends Token and its parent __construct
   * accepts an optional 6th $resolution_engine parameter. When wired via
   * "parent: token" in services.yml, the container passes all 6 arguments.
   * This test confirms the engine is present on the inner token.
   */
  public function testResolutionEngineWiredOnDecoratedToken(): void {
    /** @var \Drupal\eca\Token\CoreToken $core_token */
    $core_token = \Drupal::service('eca.service.token');

    // Access the protected $resolutionEngine property via reflection. On the
    // contrib stack the property lives on token_engine's Token subclass, not
    // on core's; CoreToken extends it so the decorator carries the engine.
    // setAccessible() is a no-op since PHP 8.1 and deprecated in PHP 8.5.
    $ref = new \ReflectionClass(EngineToken::class);
    $prop = $ref->getProperty('resolutionEngine');
    $engine = $prop->getValue($core_token);

    $this->assertNotNull($engine, 'CoreToken (which extends Token) must have $resolutionEngine injected when wired via parent:token in the service container.');
  }

  /**
   * Verifies the [node:entity_type] token resolver is registered on the engine.
   *
   * ECA's hook_tokens() 'entity_type' case has been migrated to the attributed
   * resolver NodeEntityTypeToken. The token registry must therefore expose a
   * resolvable (node, entity_type) definition backed by that resolver class.
   */
  public function testEntityTypeTokenResolverIsRegistered(): void {
    /** @var \Drupal\token_engine\TokenRegistryInterface $registry */
    $registry = $this->container->get('token_engine.registry');

    $definition = $registry->getResolvableToken('node', 'entity_type');

    $this->assertNotNull($definition, 'The (node, entity_type) token must be resolvable on the new engine.');
    $this->assertSame(NodeEntityTypeToken::class, $definition->resolverClass, 'The (node, entity_type) token must be backed by the migrated resolver class.');
  }

  /**
   * Verifies [node:entity_type] resolves through the engine to the migration.
   *
   * The output context marks replacements as safe Markup, so the value is cast
   * to string before comparison. The deterministic result is the literal
   * entity type id 'node', matching the legacy hook output exactly.
   */
  public function testEntityTypeTokenResolvesThroughNewEngine(): void {
    // The resolution engine enforces view access on the root entity against the
    // viewer (the current user here). Grant anonymous access to published nodes
    // so the default viewer may view the node and the chain resolves.
    Role::load(RoleInterface::ANONYMOUS_ID)
      ->grantPermission('access content')
      ->save();

    $node = Node::create([
      'type' => 'article',
      'title' => 'Entity type token test',
      'uid' => 0,
      'status' => 1,
    ]);
    $node->save();

    /** @var \Drupal\eca\Token\TokenInterface $token_services */
    $token_services = \Drupal::service('eca.token_services');
    $token_services->clearTokenData();
    $token_services->addTokenData('node', $node);

    $result = $token_services->replace('[node:entity_type]');

    $this->assertSame('node', (string) $result, 'The [node:entity_type] token must resolve to the entity type id via the migrated resolver.');
  }

}
