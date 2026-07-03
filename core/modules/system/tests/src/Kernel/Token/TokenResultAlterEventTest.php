<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Token\Event\TokenResultAlterEvent;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\token_context_test\EventSubscriber\TokenResultAlterEventSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests TokenResultAlterEvent (Spec F).
 *
 * The event is the engine's replacement extension point for
 * hook_tokens_alter(), scoped to engine-resolved tokens only. These tests
 * prove:
 *  1. A subscriber can replace an engine-resolved field-chain token's value.
 *  2. A cache tag the subscriber attaches bubbles into BubbleableMetadata.
 *  3. A subscriber-set AccessResult::forbidden() still drops the value to ''
 *     (the access gate runs after the event, against whatever the subscriber
 *     leaves behind).
 *  4. Legacy-path tokens in the same replacement call do not fire the event.
 *  5. The event fires in both the enforced and unenforced tiers, because it
 *     is about alteration, not access.
 *
 * [node:uid:entity:mail] is the engine-resolved field-chain token used
 * throughout (routes through the entity-type slice, same as
 * TokenTieredEnforcementTest). [site:name] is the legacy-path control: by
 * default it has no resolverClass and falls straight through to
 * LegacyTokenBridge.
 *
 * @see \Drupal\Core\Token\Event\TokenResultAlterEvent
 * @see \Drupal\Core\Token\TokenResolutionEngine::generateWithMemo()
 */
#[CoversClass(TokenResultAlterEvent::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenResultAlterEventTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * The exact deprecation message the unenforced tier raises.
   */
  private const NO_VIEWER_DEPRECATION = "Calling Drupal\Core\Utility\Token::replace() without a 'viewer' option is deprecated in drupal:12.0.0 and unenforced token resolution is removed from drupal:13.0.0. Pass options['viewer'] to enable access-checked replacement. See https://www.drupal.org/node/3593502";

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'filter', 'token_context_test'];

  /**
   * The configurable spy/alter subscriber fixture.
   */
  protected TokenResultAlterEventSubscriber $subscriber;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);
    $this->subscriber = $this->container->get('token_context_test.result_alter_subscriber');
  }

  /**
   * Creates a node authored by a freshly created user with the given mail.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\user\UserInterface}
   *   The node and its author.
   */
  private function createAuthoredNode(string $authorName, string $mail): array {
    $author = $this->createUser([], $authorName, FALSE, ['mail' => $mail]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Result alter event node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    return [$node, $author];
  }

  /**
   * Creates a viewer allowed to see the author's mail field.
   */
  private function allowedViewer(): AccountInterface {
    return $this->createUser(['access content', 'access user profiles', 'view user email addresses']);
  }

  /**
   * Tests item (1): a subscriber can replace an engine-resolved value.
   */
  public function testSubscriberReplacesEngineResolvedValue(): void {
    [$node] = $this->createAuthoredNode('replace_author', 'original@example.com');
    $viewer = $this->allowedViewer();

    $this->subscriber->replaceRawToken = '[node:uid:entity:mail]';
    $this->subscriber->replacementValue = 'altered@example.com';

    $result = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewer]);

    $this->assertSame('altered@example.com', $result, 'The subscriber-replaced value is used in place of the resolved field value.');
  }

  /**
   * Tests item (2): a cache tag the subscriber attaches bubbles.
   */
  public function testSubscriberCacheTagBubbles(): void {
    [$node] = $this->createAuthoredNode('cache_tag_author', 'cache-tag@example.com');
    $viewer = $this->allowedViewer();

    $this->subscriber->replaceRawToken = '[node:uid:entity:mail]';
    $this->subscriber->replacementValue = 'cache-tag@example.com';
    $this->subscriber->cacheTags = ['token_context_test:custom_tag'];

    $bubbleable_metadata = new BubbleableMetadata();
    $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewer], $bubbleable_metadata);

    $this->assertContains(
      'token_context_test:custom_tag',
      $bubbleable_metadata->getCacheTags(),
      "The subscriber's own cache tag bubbles into the caller's BubbleableMetadata.",
    );
  }

  /**
   * Tests item (3): a subscriber-set forbidden() access drops the value.
   *
   * This proves the access gate runs AFTER the event: the subscriber's own
   * TokenResult carries forbidden access, and the engine still drops the
   * value to '' exactly as it would for a resolver-produced denial.
   */
  public function testSubscriberForbiddenAccessDropsValue(): void {
    [$node] = $this->createAuthoredNode('forbidden_author', 'forbidden@example.com');
    $viewer = $this->allowedViewer();

    $this->subscriber->replaceRawToken = '[node:uid:entity:mail]';
    $this->subscriber->replacementValue = 'should-not-be-seen@example.com';
    $this->subscriber->forbidAccess = TRUE;

    $result = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewer]);

    $this->assertSame('', $result, "A subscriber-set forbidden() access drops the value, proving the gate runs post-event.");
  }

  /**
   * Tests item (4): legacy-path tokens do not fire the event.
   *
   * [site:name] has no resolverClass by default and falls straight through
   * to LegacyTokenBridge, so it must not appear in the spy's call list, while
   * the engine-resolved [node:uid:entity:mail] in the same replacement call
   * does.
   */
  public function testLegacyTokensDoNotFireEvent(): void {
    [$node] = $this->createAuthoredNode('legacy_author', 'legacy@example.com');
    $viewer = $this->allowedViewer();

    $this->config('system.site')->set('name', 'Result Alter Test Site')->save();

    $result = $this->tokenService->replace(
      '[node:uid:entity:mail] [site:name]',
      ['node' => $node],
      ['viewer' => $viewer],
    );

    $this->assertSame('legacy@example.com Result Alter Test Site', $result, 'Both tokens still resolve correctly.');
    $this->assertSame(
      [
        ['rawToken' => '[node:uid:entity:mail]', 'type' => 'node', 'name' => 'uid:entity:mail'],
      ],
      $this->subscriber->calls,
      'The event fires exactly once, for the engine-resolved token only; the legacy [site:name] token never fires it.',
    );
  }

  /**
   * Tests item (5a): the event fires in the enforced tier.
   */
  public function testEventFiresInEnforcedTier(): void {
    [$node] = $this->createAuthoredNode('enforced_author', 'enforced@example.com');
    $viewer = $this->allowedViewer();

    $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewer]);

    $this->assertSame(
      [
        ['rawToken' => '[node:uid:entity:mail]', 'type' => 'node', 'name' => 'uid:entity:mail'],
      ],
      $this->subscriber->calls,
      'The event fires in the enforced tier.',
    );
  }

  /**
   * Tests item (5b): the event also fires in the unenforced tier.
   *
   * The event is about alteration, not access: even with no viewer supplied
   * (the deprecated unenforced tier), the token is still routed through the
   * engine and the event still fires.
   */
  #[IgnoreDeprecations]
  public function testEventFiresInUnenforcedTier(): void {
    $this->expectUserDeprecationMessage(self::NO_VIEWER_DEPRECATION);
    [$node] = $this->createAuthoredNode('unenforced_author', 'unenforced@example.com');

    $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node]);

    $this->assertSame(
      [
        ['rawToken' => '[node:uid:entity:mail]', 'type' => 'node', 'name' => 'uid:entity:mail'],
      ],
      $this->subscriber->calls,
      'The event fires in the unenforced tier too, because it is about alteration, not access.',
    );
  }

}
