<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\PathToken;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\token_context_test\Plugin\Token\NodeTitlePathToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the PathToken base for declarative, no-method-body token resolvers.
 *
 * A PathToken subclass is the attribute and nothing else: it declares a
 * typed-data path and the base reads it. The engine supplies the declared path
 * as an argument, so the resolver needs no reflection and no factory.
 *
 * @see \Drupal\Core\Token\PathToken
 * @see \Drupal\token_context_test\Plugin\Token\NodeTitlePathToken
 */
#[CoversClass(PathToken::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class PathTokenTest extends TokenReplaceKernelTestBase {

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
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);
  }

  /**
   * Tests a PathToken subclass instantiates with no factory.
   *
   * The default plugin factory calls `new $class($configuration, $id, $def)`;
   * a PathToken subclass has no constructor dependencies, so it instantiates
   * cleanly without implementing ContainerFactoryPluginInterface.
   */
  public function testPathTokenInstantiatesWithoutFactory(): void {
    $resolver = $this->container->get('plugin.manager.token_resolver')->createInstance('entity:node:title_via_path');
    $this->assertInstanceOf(NodeTitlePathToken::class, $resolver);
    $this->assertInstanceOf(PathToken::class, $resolver);
  }

  /**
   * Tests the declared path resolves against the input, with no method body.
   */
  public function testPathTokenResolvesDeclaredPath(): void {
    $node = Node::create(['type' => 'article', 'title' => 'Declarative Title', 'status' => 1]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $context = new TokenResolutionContext([], ActorContext::fromSingleActor($admin), OutputContext::Html);
    $result = $this->container->get('token.resolution_engine')
      ->resolveChain('entity:node', ['title_via_path'], $node, $context);

    $this->assertNotNull($result);
    $this->assertSame('Declarative Title', $result->value, 'PathToken walked title.value with no resolve() body.');
  }

  /**
   * Tests PathToken degrades to an empty string for a non-entity input.
   */
  public function testPathTokenEmptyForNonEntityInput(): void {
    $context = new TokenResolutionContext([], ActorContext::fromSingleActor($this->container->get('current_user')), OutputContext::Html);
    $resolver = $this->container->get('plugin.manager.token_resolver')->createInstance('entity:node:title_via_path');

    $result = $resolver->resolve('not-an-entity', ['name' => 'title_via_path', 'path' => 'title.value'], $context);
    $this->assertSame('', $result->value);
  }

}
