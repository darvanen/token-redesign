<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Token\ActorContext;
use Drupal\Core\Token\EntityReferenceFieldToken;
use Drupal\Core\Token\FieldValueToken;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\TokenResolutionContext;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves typed-data discovery covers configurable (bundle) fields, not just base.
 *
 * Discovery uses field storage definitions, so configurable fields are
 * discovered too. A configurable entity-reference field chain resolves through
 * the new pipeline, while a configurable scalar field chain still falls back to
 * the legacy pipeline (its terminal output type 'string' has no sub-tokens).
 *
 * @see \Drupal\Core\Token\Discovery\TypedDataFieldDiscovery
 */
#[CoversClass(\Drupal\Core\Token\Discovery\TypedDataFieldDiscovery::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenConfigurableFieldDiscoveryTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'filter'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // A configurable entity-reference field (node -> user).
    FieldStorageConfig::create([
      'field_name' => 'field_related_user',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_related_user',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Related user',
    ])->save();

    // A configurable scalar field.
    FieldStorageConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_subtitle',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Subtitle',
    ])->save();
  }

  /**
   * Tests that configurable fields are discovered with the right contracts.
   */
  public function testConfigurableFieldsAreDiscovered(): void {
    $registry = $this->container->get('token.registry');

    $reference = $registry->getResolvableToken('entity:node', 'field_related_user');
    $this->assertNotNull($reference, 'The configurable reference field is discovered.');
    $this->assertSame('entity_reference:user', $reference->outputType);
    $this->assertSame(EntityReferenceFieldToken::class, $reference->resolverClass);

    $scalar = $registry->getResolvableToken('entity:node', 'field_subtitle');
    $this->assertNotNull($scalar, 'The configurable scalar field is discovered.');
    $this->assertSame('string', $scalar->outputType);
    $this->assertSame(FieldValueToken::class, $scalar->resolverClass);
  }

  /**
   * Tests a configurable entity-reference field chain resolves via the engine.
   */
  public function testConfigurableReferenceFieldChainResolves(): void {
    $author = $this->createUser([], 'configurable_author');
    $node = Node::create([
      'type' => 'article',
      'title' => 'Configurable field node',
      'field_related_user' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    $result = $this->tokenService->replace(
      '[node:field_related_user:entity:name]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $this->assertSame($author->getAccountName(), $result, 'The configurable reference field chain resolved through the new pipeline.');
  }

  /**
   * Tests a configurable scalar field chain falls back to the legacy pipeline.
   *
   * The scalar field resolves to a 'string', which has no sub-tokens, so the
   * ':value' segment cannot be completed structurally and resolveChain() returns
   * NULL, signalling the engine to fall through to legacy.
   */
  public function testScalarConfigurableFieldChainFallsBackToLegacy(): void {
    $node = Node::create([
      'type' => 'article',
      'title' => 'Scalar fallback node',
      'field_subtitle' => 'A subtitle',
      'status' => 1,
    ]);
    $node->save();
    $admin = $this->createUser([], NULL, TRUE);

    /** @var \Drupal\Core\Token\TokenResolutionEngineInterface $engine */
    $engine = $this->container->get('token.resolution_engine');
    $context = new TokenResolutionContext(
      ['node' => $node],
      ActorContext::fromSingleActor($admin),
      OutputContext::Html,
    );

    $result = $engine->resolveChain('entity:node', ['field_subtitle', 'value'], $node, $context);
    $this->assertNull($result, 'A scalar field chain cannot complete in the new pipeline and falls back to legacy.');
  }

}
