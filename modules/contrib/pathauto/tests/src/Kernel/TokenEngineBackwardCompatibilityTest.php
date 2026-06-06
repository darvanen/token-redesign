<?php

declare(strict_types=1);

namespace Drupal\Tests\pathauto\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Token\TokenResolutionEngineInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\pathauto\Entity\PathautoPattern;
use Drupal\Tests\pathauto\Functional\PathautoTestHelperTrait;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves that pathauto's token usage works unchanged through the BC layer.
 *
 * Core now delegates Token::generate() through TokenResolutionEngine, which
 * itself falls back to LegacyTokenBridge (hook_tokens/hook_tokens_alter) for
 * any token type not yet migrated to attributed resolvers. Pathauto tokens
 * are entirely hook-based, so every call must route through the legacy bridge
 * without any observable behaviour change.
 *
 * @group pathauto
 */
#[Group('pathauto')]
#[RunTestsInSeparateProcesses]
class TokenEngineBackwardCompatibilityTest extends KernelTestBase {

  use PathautoTestHelperTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'field',
    'filter',
    'node',
    'path',
    'path_alias',
    'pathauto',
    'system',
    'text',
    'token',
    'user',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installConfig(['pathauto', 'system', 'node']);
    $this->installSchema('node', ['node_access']);

    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
  }

  /**
   * Tests that the token.resolution_engine service is present in the container.
   *
   * This confirms that core has wired the new engine and that pathauto's test
   * environment inherits it without any extra configuration.
   */
  public function testResolutionEngineServiceIsRegistered(): void {
    $engine = $this->container->get('token.resolution_engine');
    $this->assertInstanceOf(
      TokenResolutionEngineInterface::class,
      $engine,
      'The token.resolution_engine service resolves to a TokenResolutionEngineInterface.'
    );
  }

  /**
   * Tests that Token::replace() produces correct output for node tokens.
   *
   * The node token implementation lives in the contrib token module's
   * hook_tokens() and is not yet migrated to attributed resolvers, so every
   * replacement must travel through LegacyTokenBridge inside the resolution
   * engine.
   */
  public function testNodeTokenReplacement(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'Hello World',
    ]);
    $node->save();

    $result = \Drupal::token()->replace(
      '[node:title]',
      ['node' => $node],
      ['clear' => TRUE]
    );

    $this->assertSame('Hello World', $result, '[node:title] replaced correctly through the resolution engine BC layer.');
  }

  /**
   * Tests that Token::replace() produces correct output for user tokens.
   */
  public function testUserTokenReplacement(): void {
    $account = User::create([
      'name' => 'testuser',
      'mail' => 'testuser@example.com',
      'status' => 1,
    ]);
    $account->save();

    $result = \Drupal::token()->replace(
      '[user:name]',
      ['user' => $account],
      ['clear' => TRUE]
    );

    $this->assertSame('testuser', $result, '[user:name] replaced correctly through the resolution engine BC layer.');
  }

  /**
   * Tests that pathauto's join-path array token fires via the engine.
   *
   * The [array:join-path] token has been migrated to the attributed resolver
   * \Drupal\pathauto\Plugin\Token\ArrayJoinPathToken, so the engine resolves it through
   * the new pipeline (returning a safe-marked Markup value in the HTML context)
   * rather than the legacy hook. The cleaned string value is unchanged.
   */
  public function testPathautoJoinPathTokenFires(): void {
    $array = ['First Item', 'Second Item'];
    $bubbleable_metadata = new BubbleableMetadata();
    $replacements = \Drupal::token()->generate(
      'array',
      ['join-path' => '[array:join-path]'],
      ['array' => $array],
      [],
      $bubbleable_metadata
    );

    $this->assertArrayHasKey('[array:join-path]', $replacements, 'The [array:join-path] token was replaced.');
    $this->assertSame(
      'first-item/second-item',
      (string) $replacements['[array:join-path]'],
      'The [array:join-path] token value matches expected cleaned output.'
    );
  }

  /**
   * Tests that the resolution engine and a direct LegacyTokenBridge call agree.
   *
   * This is the core of the BC proof: calling Token::generate() (which routes
   * through TokenResolutionEngine) must produce the same replacements as
   * calling LegacyTokenBridge::generate() directly for every pathauto-relevant
   * token. Any divergence would indicate a regression in the engine's
   * fallback path.
   */
  public function testResolutionEngineMatchesDirectBridgeCall(): void {
    $node = Node::create([
      'type' => 'page',
      'title' => 'BC Test Node',
    ]);
    $node->save();

    $type = 'node';
    $tokens = ['title' => '[node:title]'];
    $data = ['node' => $node];
    $options = [];

    // Resolution via the full engine (Token::generate()).
    $engineMetadata = new BubbleableMetadata();
    $engineReplacements = \Drupal::token()->generate($type, $tokens, $data, $options, $engineMetadata);

    // Resolution via the legacy bridge directly.
    /** @var \Drupal\Core\Token\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token.legacy_bridge');
    $bridgeMetadata = new BubbleableMetadata();
    $bridgeReplacements = $bridge->generate($type, $tokens, $data, $options, $bridgeMetadata);

    $this->assertSame(
      $bridgeReplacements,
      $engineReplacements,
      'Token::generate() through the resolution engine produces identical output to a direct LegacyTokenBridge invocation.'
    );
  }

  /**
   * Tests that pathauto alias generation still fires for a node with a pattern.
   *
   * This exercises the full pathauto pipeline: PathautoGenerator calls
   * Token::replace() with a pattern string containing [node:title], which must
   * round-trip through the resolution engine and back without any difference in
   * the resulting alias.
   */
  public function testPathautoAliasGenerationUsesResolutionEngine(): void {
    PathautoPattern::create([
      'id' => 'node_page',
      'type' => 'canonical_entities:node',
      'pattern' => '/content/[node:title]',
      'weight' => 0,
    ])->save();

    $node = Node::create([
      'type' => 'page',
      'title' => 'My Token Test',
    ]);
    $node->save();

    $this->assertEntityAlias($node, '/content/my-token-test');
  }

  /**
   * Tests the migrated [array:join-path] result matches the legacy bridge.
   *
   * Since the token is now resolved by the attributed ArrayJoinPathToken, the
   * engine returns a safe-marked Markup value while the legacy bridge returns a
   * plain string. The migration is behaviour-preserving, so the string values
   * must be identical: this is the migration-correctness proof.
   */
  public function testJoinPathEngineMatchesDirectBridgeCall(): void {
    $array = ['Alpha Item', 'Beta Item'];
    $type = 'array';
    $tokens = ['join-path' => '[array:join-path]'];
    $data = ['array' => $array];
    $options = [];

    $engineMetadata = new BubbleableMetadata();
    $engineReplacements = \Drupal::token()->generate($type, $tokens, $data, $options, $engineMetadata);

    /** @var \Drupal\Core\Token\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token.legacy_bridge');
    $bridgeMetadata = new BubbleableMetadata();
    $bridgeReplacements = $bridge->generate($type, $tokens, $data, $options, $bridgeMetadata);

    $this->assertSame(
      array_map('strval', $bridgeReplacements),
      array_map('strval', $engineReplacements),
      'The migrated [array:join-path] string value is identical to the legacy bridge output.'
    );
    $this->assertNotEmpty($engineReplacements['[array:join-path]'], 'The migrated token produced a value.');
  }

  /**
   * Tests that [array:join-path] is registered as an attributed resolver.
   *
   * This is the migration proof: the token resolves through the new pipeline
   * via ArrayJoinPathToken, not through the legacy hook fallback.
   */
  public function testJoinPathIsMigratedToAttributedResolver(): void {
    /** @var \Drupal\Core\Token\TokenRegistryInterface $registry */
    $registry = $this->container->get('token.registry');
    $definition = $registry->getResolvableToken('array', 'join-path');

    $this->assertNotNull($definition, 'The [array:join-path] token is registered as a resolvable (new-system) token.');
    $this->assertSame(
      \Drupal\pathauto\Plugin\Token\ArrayJoinPathToken::class,
      $definition->resolverClass,
      'The [array:join-path] token is backed by the migrated attributed resolver.'
    );
  }

}
