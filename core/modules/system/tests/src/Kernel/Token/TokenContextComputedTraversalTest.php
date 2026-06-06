<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\Event\TokenDiscoveryAlterEvent;
use Drupal\Core\Token\TokenDefinition;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenRegistryInterface;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\token_context_test\Plugin\Token\FakeCommentEntityLabelResolver;
use Drupal\token_context_test\Plugin\Token\FakeCommentEntityResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the computed-traversal (comment:entity) hard case.
 *
 * Proves that a resolver can:
 *  1. Compose a value from multiple fields (entity_type + entity_id -> entity).
 *  2. Store the composed entity in the TokenResolutionContext for downstream
 *     resolvers via $context->set().
 *  3. Allow the next resolver in the chain to read it from the context via
 *     $context->get().
 *
 * The test uses a fake_comment input type (an associative array carrying
 * entity_type and entity_id) rather than the real Comment entity so that the
 * comment module is not required as a dependency. The pattern is identical to
 * the real comment:entity case described in the design brief.
 *
 * @see \Drupal\token_context_test\Plugin\Token\FakeCommentEntityResolver
 * @see \Drupal\token_context_test\Plugin\Token\FakeCommentEntityLabelResolver
 */
#[CoversClass(FakeCommentEntityResolver::class)]
#[CoversClass(FakeCommentEntityLabelResolver::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenContextComputedTraversalTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'node',
    'filter',
    'token_context_test',
  ];

  /**
   * The token registry.
   */
  protected TokenRegistryInterface $registry;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->installEntitySchema('user');
    $this->registry = $this->container->get('token.registry');
  }

  /**
   * Tests that the discovery subscriber registers the two token definitions.
   */
  public function testSubscriberRegistersComputedTraversalDefinitions(): void {
    $fakeCommentTokens = $this->registry->getTokensForInputType('fake_comment');

    $this->assertArrayHasKey(
      'entity',
      $fakeCommentTokens,
      'The subscriber registered the entity token on the fake_comment input type.',
    );

    $entityDef = $fakeCommentTokens['entity'];
    $this->assertSame('fake_comment_entity', $entityDef->outputType);
    $this->assertSame(FakeCommentEntityResolver::class, $entityDef->resolverClass);

    $entityTokens = $this->registry->getTokensForInputType('fake_comment_entity');
    $this->assertArrayHasKey(
      'label',
      $entityTokens,
      'The subscriber registered the label token on the fake_comment_entity input type.',
    );

    $labelDef = $entityTokens['label'];
    $this->assertSame('string', $labelDef->outputType);
    $this->assertSame(FakeCommentEntityLabelResolver::class, $labelDef->resolverClass);
  }

  /**
   * Tests that FakeCommentEntityResolver loads the entity and stores it in context.
   *
   * This is the "compose from multiple fields" proof: the resolver assembles
   * entity_type + entity_id into a loaded entity and writes it to the context.
   */
  public function testEntityResolverComposesAndStoresInContext(): void {
    // Create a node to act as the referenced entity.
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'The Commented Article',
      'status' => 1,
    ]);
    $node->save();

    // Build the fake comment data (entity_type + entity_id).
    $fakeComment = [
      'entity_type' => 'node',
      'entity_id' => $node->id(),
    ];

    $actor = ActorContext::fromSingleActor($this->container->get('current_user'));
    $context = new TokenResolutionContext([], $actor);

    $resolver = new FakeCommentEntityResolver(
      $this->container->get('entity_type.manager'),
    );

    $result = $resolver->resolve($fakeComment, [], $context);

    // The resolver should return the loaded entity as the value.
    $this->assertSame($node->id(), $result->value->id(), 'Resolver returned the loaded node as its value.');
    $this->assertSame('node', $result->value->getEntityTypeId(), 'Resolver returned a node entity.');

    // The resolver must have stored the entity in the context.
    $storedEntity = $context->get('fake_comment_entity');
    $this->assertNotNull($storedEntity, 'Entity was stored in context by the resolver.');
    $this->assertSame($node->id(), $storedEntity->id(), 'Correct entity is stored in context.');
  }

  /**
   * Tests that FakeCommentEntityLabelResolver reads the entity from context.
   *
   * This is the "downstream reads from context" proof: the label resolver does
   * not receive the entity through $input – it reads the value that the
   * preceding segment stored in the context.
   */
  public function testLabelResolverReadsEntityFromContext(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'The Commented Article',
      'status' => 1,
    ]);
    $node->save();

    $actor = ActorContext::fromSingleActor($this->container->get('current_user'));
    // Pre-populate the context as if FakeCommentEntityResolver already ran.
    $context = new TokenResolutionContext(
      ['fake_comment_entity' => $node],
      $actor,
    );

    $resolver = new FakeCommentEntityLabelResolver();

    // Pass NULL as $input to prove the label comes from context, not $input.
    $result = $resolver->resolve(NULL, [], $context);

    $this->assertSame(
      'The Commented Article',
      $result->value,
      'Label resolver read the entity label from context, not from $input.',
    );
  }

  /**
   * Tests the full two-segment chain: entity resolver -> label resolver.
   *
   * This is the end-to-end proof that the computed traversal works: the first
   * resolver composes and stores the entity, the second reads it.
   */
  public function testFullChainEntityResolverThenLabelResolver(): void {
    $this->createContentType(['type' => 'article']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Article Title For Chain Test',
      'status' => 1,
    ]);
    $node->save();

    $fakeComment = [
      'entity_type' => 'node',
      'entity_id' => $node->id(),
    ];

    $actor = ActorContext::fromSingleActor($this->container->get('current_user'));
    $context = new TokenResolutionContext([], $actor);

    // Step 1: entity resolver runs, stores entity in context.
    $entityResolver = new FakeCommentEntityResolver(
      $this->container->get('entity_type.manager'),
    );
    $entityResult = $entityResolver->resolve($fakeComment, [], $context);

    $this->assertNotNull($entityResult->value, 'Entity resolver returned a value.');

    // Step 2: label resolver runs, reads entity from context.
    $labelResolver = new FakeCommentEntityLabelResolver();
    // Note: pass NULL as $input to prove context-read path, not $input path.
    $labelResult = $labelResolver->resolve(NULL, [], $context);

    $this->assertSame(
      'Article Title For Chain Test',
      $labelResult->value,
      'Full chain: entity resolver stored entity, label resolver read it from context.',
    );
  }

  /**
   * Tests that FakeCommentEntityResolver returns NULL for invalid input.
   */
  public function testEntityResolverGracefullyHandlesInvalidInput(): void {
    $actor = ActorContext::fromSingleActor($this->container->get('current_user'));
    $context = new TokenResolutionContext([], $actor);
    $resolver = new FakeCommentEntityResolver(
      $this->container->get('entity_type.manager'),
    );

    // Non-array input.
    $result = $resolver->resolve('not-an-array', [], $context);
    $this->assertNull($result->value, 'Non-array input returns NULL value.');

    // Array missing entity_type.
    $result = $resolver->resolve(['entity_id' => 1], [], $context);
    $this->assertNull($result->value, 'Missing entity_type returns NULL value.');

    // Array missing entity_id.
    $result = $resolver->resolve(['entity_type' => 'node'], [], $context);
    $this->assertNull($result->value, 'Missing entity_id returns NULL value.');
  }

  /**
   * Tests that the TokenDiscoveryAlterEvent can add computed traversal tokens.
   *
   * Verifies the event API is sufficient to express the full comment:entity
   * pattern using subscriber-registered definitions.
   */
  public function testDiscoveryAlterEventCanExpressComputedTraversalPattern(): void {
    // Use the event directly to add a second set of tokens for a different
    // fake entity type, proving the pattern is not hardcoded to fake_comment.
    $this->container->get('event_dispatcher')->addListener(
      TokenDiscoveryAlterEvent::DISCOVERY_ALTER,
      function (TokenDiscoveryAlterEvent $event): void {
        $event->addDefinition(new TokenDefinition(
          name: 'entity',
          inputType: 'another_comment',
          outputType: 'another_comment_entity',
          label: 'Commented entity',
          resolverClass: FakeCommentEntityResolver::class,
          module: 'test',
        ));
        $event->addDefinition(new TokenDefinition(
          name: 'label',
          inputType: 'another_comment_entity',
          outputType: 'string',
          label: 'Entity label',
          resolverClass: FakeCommentEntityLabelResolver::class,
          module: 'test',
        ));
      },
    );
    $this->registry->invalidate();

    $tokens = $this->registry->getTokensForInputType('another_comment');
    $this->assertArrayHasKey('entity', $tokens, 'Computed traversal entity token registered via event.');

    $entityTokens = $this->registry->getTokensForInputType('another_comment_entity');
    $this->assertArrayHasKey('label', $entityTokens, 'Downstream label token registered via event.');
  }

}
