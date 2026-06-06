<?php

declare(strict_types=1);

namespace Drupal\Tests\webform\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Plugin\Token\WebformIdToken;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves the [webform:id] migration to an attributed resolver works.
 *
 * Four independent proofs combine into a deductive guarantee:
 *  1. The resolver computes the correct value in isolation.
 *  2. The engine routes the (webform, id) token to that resolver class.
 *  3. The token resolves end to end through the public Token::replace() API.
 *  4. The migrated output is identical to the retained legacy hook output.
 *
 * Proofs 1 and 2 together are deductive: the engine calls this resolver class
 * for the token, and this resolver class returns the webform id, so the token
 * resolves through the new pipeline. Proof 4 shows the migration did not change
 * behaviour.
 *
 * @group webform
 */
#[Group('webform')]
#[RunTestsInSeparateProcesses]
class WebformIdTokenMigrationProofTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'path',
    'path_alias',
    'user',
    'field',
    'filter',
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform']);
  }

  /**
   * Creates a saved webform with a known id.
   */
  private function createWebform(string $id): Webform {
    $webform = Webform::create(['id' => $id, 'title' => 'Proof: ' . $id]);
    $webform->save();
    return $webform;
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
   * Proof 1: the resolver returns the webform id in isolation.
   */
  public function testResolverProducesValueInIsolation(): void {
    // The resolver is an attributed Plugin\Token plugin, created lazily by the
    // token resolver plugin manager.
    $resolver = $this->container->get('plugin.manager.token_resolver')->createInstance('webform:id');
    $this->assertInstanceOf(WebformIdToken::class, $resolver);

    $webform = $this->createWebform('proof_webform');
    $result = $resolver->resolve($webform, ['name' => 'id'], $this->context());
    $this->assertSame('proof_webform', $result->value, 'The resolver returns the webform id.');

    // A non-webform input degrades gracefully to an empty string.
    $this->assertSame('', $resolver->resolve('not-a-webform', ['name' => 'id'], $this->context())->value);
  }

  /**
   * Proof 2: the engine routes (webform, id) to the migrated resolver.
   */
  public function testEngineRoutesTokenToResolver(): void {
    /** @var \Drupal\Core\Token\TokenRegistryInterface $registry */
    $registry = $this->container->get('token.registry');
    $definition = $registry->getResolvableToken('webform', 'id');

    $this->assertNotNull($definition, 'The token is registered as a resolvable new-system token.');
    $this->assertSame(WebformIdToken::class, $definition->resolverClass, 'The engine routes the token to WebformIdToken.');
  }

  /**
   * Proof 3: the token resolves end to end through Token::replace().
   */
  public function testTokenResolvesEndToEnd(): void {
    $webform = $this->createWebform('end_to_end_webform');
    $result = \Drupal::token()->replace('[webform:id]', ['webform' => $webform]);
    $this->assertSame('end_to_end_webform', $result, 'The token resolves end to end via the public API.');
  }

  /**
   * Proof 4: the migrated output matches the retained legacy implementation.
   */
  public function testMigratedOutputMatchesLegacyImplementation(): void {
    $webform = $this->createWebform('parity_webform');
    $data = ['webform' => $webform];
    $tokens = ['id' => '[webform:id]'];

    $engine = \Drupal::token()->generate('webform', $tokens, $data, [], new BubbleableMetadata());

    /** @var \Drupal\Core\Token\LegacyTokenBridge $bridge */
    $bridge = $this->container->get('token.legacy_bridge');
    $legacy = $bridge->generate('webform', $tokens, $data, [], new BubbleableMetadata());

    $this->assertSame(
      (string) ($legacy['[webform:id]'] ?? ''),
      (string) ($engine['[webform:id]'] ?? ''),
      'The migrated resolver output is identical to the legacy hook output.',
    );
    $this->assertSame('parity_webform', (string) $engine['[webform:id]']);
  }

}
