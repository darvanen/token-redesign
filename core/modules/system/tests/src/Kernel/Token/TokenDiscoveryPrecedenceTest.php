<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Token\EntityReferenceFieldToken;
use Drupal\Core\Token\Event\TokenDiscoveryAlterEvent;
use Drupal\Core\Token\FieldValueToken;
use Drupal\Core\Token\TokenDefinition;
use Drupal\token_context_test\Plugin\Token\OverrideNodeTitleResolver;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves discovery precedence: auto-discovery never overrides explicit tokens.
 *
 * Auto-discovery (typed-data field discovery) is the lowest layer. A token
 * defined deliberately elsewhere wins for the same (input_type, name):
 *  - an attributed `#[Token]` resolver beats the auto-discovered field;
 *  - the discovery-alter event beats even the attributed resolver.
 * Fields that nobody declares explicitly are still provided by auto-discovery.
 *
 * @see \Drupal\Core\Token\TokenRegistry
 */
#[CoversClass(\Drupal\Core\Token\TokenRegistry::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenDiscoveryPrecedenceTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'filter', 'token_context_test'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('node');
  }

  /**
   * Tests an attributed resolver overrides the auto-discovered field token.
   */
  public function testAttributedDeclarationBeatsAutoDiscovery(): void {
    $registry = $this->container->get('token.registry');

    $definition = $registry->getResolvableToken('entity:node', 'title');
    $this->assertNotNull($definition);
    $this->assertSame(
      OverrideNodeTitleResolver::class,
      $definition->resolverClass,
      'An explicit attributed resolver wins over the auto-discovered field of the same identity.',
    );
  }

  /**
   * Tests auto-discovery still provides fields that nobody declares explicitly.
   */
  public function testUndeclaredFieldsStillComeFromAutoDiscovery(): void {
    $registry = $this->container->get('token.registry');

    // 'uid' has no attributed declaration, so the auto-discovered reference
    // field token stands.
    $definition = $registry->getResolvableToken('entity:node', 'uid');
    $this->assertNotNull($definition);
    $this->assertSame(EntityReferenceFieldToken::class, $definition->resolverClass);
  }

  /**
   * Tests the alter event has the final say, above the attributed resolver.
   */
  public function testAlterEventBeatsAttributedDeclaration(): void {
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        $event->addDefinition(new TokenDefinition(
          name: 'title',
          inputType: 'entity:node',
          outputType: 'string',
          resolverClass: FieldValueToken::class,
          module: 'token_context_test',
        ));
      },
    );
    $this->container->get('token.registry')->invalidate();

    $definition = $this->container->get('token.registry')->getResolvableToken('entity:node', 'title');
    $this->assertSame(
      FieldValueToken::class,
      $definition->resolverClass,
      'The discovery-alter event overrides even an attributed declaration.',
    );
  }

}
