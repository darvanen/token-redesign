<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Token\ActorContext;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests Spec E's tiered no-viewer semantics.
 *
 * A caller that supplies neither 'viewer' nor 'token_actor' gets the
 * unenforced tier (ActorContext with a NULL viewer): legacy-equivalent output
 * with no access enforcement anywhere, plus a deprecation notice. A caller
 * that supplies either gets the enforced tier, byte-for-byte identical to the
 * engine's behaviour before this tiering existed.
 *
 * @see \Drupal\Core\Token\ActorContext
 * @see \Drupal\Core\Token\TokenResolutionEngine
 */
#[CoversClass(\Drupal\Core\Token\ActorContext::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenTieredEnforcementTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * The exact deprecation message resolveActor() raises for a missing viewer.
   */
  private const NO_VIEWER_DEPRECATION = "Calling Drupal\Core\Utility\Token::replace() without a 'viewer' option is deprecated in drupal:12.0.0 and unenforced token resolution is removed from drupal:13.0.0. Pass options['viewer'] to enable access-checked replacement. See https://www.drupal.org/node/3593502";

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'filter'];

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
   * Creates a node authored by a freshly created user with the given mail.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\user\UserInterface}
   *   The node and its author.
   */
  private function createAuthoredNode(string $authorName, string $mail): array {
    $author = $this->createUser([], $authorName, FALSE, ['mail' => $mail]);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Tiered enforcement node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    return [$node, $author];
  }

  /**
   * Tests item (1): viewer-less replacement over a real engine chain.
   *
   * [current-user:mail] is the concrete case where the same conceptual token
   * has both a legacy-bridge computation (the 'current-user' hook, which loads
   * \Drupal::currentUser() and never checks access) and, once enforced, an
   * engine-structural computation (the root-activated [current-user] chain).
   * With no viewer the root activation in generateWithMemo() never triggers
   * (guarded on isEnforced()), so the token falls through to
   * LegacyTokenBridge exactly as it always has: this is the BC proof, plus the
   * deprecation that flags the tier as going away in drupal:13.0.0.
   */
  #[IgnoreDeprecations]
  public function testViewerlessReplacementMatchesLegacyBridgeAndDeprecates(): void {
    $this->expectUserDeprecationMessage(self::NO_VIEWER_DEPRECATION);
    $this->setUpCurrentUser(values: ['mail' => 'berdir@example.com'], permissions: []);

    $bubbleable_metadata = new BubbleableMetadata();
    $legacy = $this->container->get('token.legacy_bridge')->generate(
      'current-user',
      ['mail' => '[current-user:mail]'],
      [],
      [],
      $bubbleable_metadata,
    );

    $replaced = $this->tokenService->replace('[current-user:mail]');

    $this->assertSame($legacy['[current-user:mail]'], $replaced, 'Viewer-less replacement is byte-for-byte identical to the legacy bridge.');
  }

  /**
   * Tests item (2): the enforced tier is unchanged by the tiering refactor.
   *
   * A viewer lacking access to the referenced user still gets '', and the
   * denied chain's cacheability (the referenced user's cache tag) still
   * bubbles regardless of the denial, exactly as it did before ActorContext
   * could ever carry a NULL viewer.
   */
  public function testViewerSuppliedRestrictedChainUnchanged(): void {
    [$node, $author] = $this->createAuthoredNode('restricted_author', 'restricted@example.com');
    $viewerDenied = $this->createUser(['access content']);

    $bubbleable_metadata = new BubbleableMetadata();
    $result = $this->tokenService->replace(
      '[node:uid:entity:mail]',
      ['node' => $node],
      ['viewer' => $viewerDenied],
      $bubbleable_metadata,
    );

    $this->assertSame('', $result, 'A viewer without field access is still denied under the enforced tier.');
    $this->assertContains('user:' . $author->id(), $bubbleable_metadata->getCacheTags(), 'Cacheability still bubbles for a denied chain.');
  }

  /**
   * Tests the unenforced tier for a real field/entity-reference chain.
   *
   * This goes beyond the six required items: it proves the unenforced tier
   * skips all three guarded check sites, not just the current-user root case.
   * The viewer-less call cannot possibly prove access (there is no viewer),
   * so the engine root-entity check, the 'entity' deref check, and the
   * field-level check all return AccessResult::allowed() and the value
   * resolves, matching legacy resolution, which never checked access either.
   */
  #[IgnoreDeprecations]
  public function testUnenforcedTierSkipsFieldAndEntityAccessChecks(): void {
    $this->expectUserDeprecationMessage(self::NO_VIEWER_DEPRECATION);
    [$node] = $this->createAuthoredNode('unenforced_author', 'unenforced@example.com');

    $result = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node]);

    $this->assertSame('unenforced@example.com', $result, 'The unenforced tier resolves the field value with no viewer to check access against.');
  }

  /**
   * Tests item (3): [current-user:mail] unenforced equals the legacy string.
   */
  #[IgnoreDeprecations]
  public function testCurrentUserMailUnenforcedEqualsLegacyString(): void {
    $this->expectUserDeprecationMessage(self::NO_VIEWER_DEPRECATION);
    $this->setUpCurrentUser(values: ['mail' => 'unenforced-current-user@example.com'], permissions: []);

    $result = $this->tokenService->replace('[current-user:mail]');

    $this->assertSame('unenforced-current-user@example.com', $result, 'Unenforced [current-user:mail] equals the legacy hook output.');
  }

  /**
   * Tests item (4): [current-user:mail] enforced for a self-viewing account.
   *
   * Root-token engine activation (rule 5) resolves the root [current-user]
   * definition, which binds the viewer and loads its user entity, then walks
   * 'mail' from 'entity:user' with full enforcement. Self-view of one's own
   * mail is allowed by core's user access control unconditionally (both the
   * entity-level "users can view own profiles at all times" rule and the
   * field-level is_own_account rule), so the value resolves, with the 'user'
   * cache context composed from the root resolver's own contribution.
   */
  public function testCurrentUserMailEnforcedSelfViewComposesUserCacheContext(): void {
    $viewer = $this->setUpCurrentUser(values: ['mail' => 'self-view@example.com'], permissions: []);

    $bubbleable_metadata = new BubbleableMetadata();
    $result = $this->tokenService->replace(
      '[current-user:mail]',
      [],
      ['viewer' => $viewer],
      $bubbleable_metadata,
    );

    $this->assertSame('self-view@example.com', $result, 'Enforced self-view resolves the mail value.');
    $this->assertContains('user', $bubbleable_metadata->getCacheContexts(), 'The user cache context composed from the root resolver.');
  }

  /**
   * Tests item (5): enforced anonymous [current-user:mail], verified reality.
   *
   * Verified reality: UserAccessControlHandler::checkAccess() denies 'view' on
   * the anonymous user entity (uid 0) unconditionally -- "The anonymous user's
   * profile can neither be viewed, updated nor deleted" is the literal
   * first-checked rule, before any permission or self-view logic runs. This
   * makes the outcome forbidden regardless of the viewer, not merely "no
   * permission", so both the root resolver's own access check and the
   * engine's root-entity check independently deny it, and the composed
   * result is dropped to ''. This differs from the unenforced tier's legacy
   * fallback deliberately: the enforced tier is a new, correctly-secured
   * behaviour that is not required to match legacy for a chain legacy never
   * gated at all.
   */
  public function testCurrentUserMailEnforcedAnonymousIsForbidden(): void {
    // setUpCurrentUser() ensures the anonymous (uid 0) user entity exists,
    // which CurrentUserToken needs to load before access can even be checked.
    $this->setUpCurrentUser();
    $anonymous = new AnonymousUserSession();

    $result = $this->tokenService->replace('[current-user:mail]', [], ['viewer' => $anonymous]);

    $this->assertSame('', $result, 'Viewing the anonymous user entity is unconditionally forbidden, so the chain is dropped.');
  }

  /**
   * Tests item (6): 'token_actor' behaves exactly like an equivalent 'viewer'.
   */
  public function testTokenActorOptionBehavesLikeViewer(): void {
    [$node, $author] = $this->createAuthoredNode('token_actor_author', 'token-actor@example.com');
    $viewerAllowed = $this->createUser(['access content', 'access user profiles', 'view user email addresses']);
    $viewerDenied = $this->createUser(['access content']);

    $allowedViaViewer = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerAllowed]);
    $allowedViaActor = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['token_actor' => ActorContext::fromSingleActor($viewerAllowed)]);
    $deniedViaViewer = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerDenied]);
    $deniedViaActor = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['token_actor' => ActorContext::fromSingleActor($viewerDenied)]);

    $this->assertSame($allowedViaViewer, $allowedViaActor, "'token_actor' matches the equivalent 'viewer' for an allowed account.");
    $this->assertSame($author->getEmail(), $allowedViaActor, "'token_actor' resolves the value for an allowed account.");
    $this->assertSame($deniedViaViewer, $deniedViaActor, "'token_actor' matches the equivalent 'viewer' for a denied account.");
    $this->assertSame('', $deniedViaActor, "'token_actor' is denied exactly like the equivalent 'viewer'.");
  }

}
