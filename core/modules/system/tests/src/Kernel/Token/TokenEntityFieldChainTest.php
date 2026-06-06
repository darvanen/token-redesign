<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a real entity-reference field chain resolved via typed-data discovery.
 *
 * This is the design brief's happy-path chain (hard case #1), proven against
 * real fields rather than fakes: [node:uid:entity:name] traverses the node's
 * 'uid' entity-reference field, derefs to the referenced user, and reads the
 * user's 'name' field, all from token definitions derived at runtime by
 * TypedDataFieldDiscovery (no hand-written declarations for the fields).
 *
 * It also proves the actor model: access is checked against the explicit viewer
 * (not the current user), and the engine drops the value when the viewer is not
 * allowed to view the referenced entity. This is what closes the documented
 * token exfiltration vulnerabilities.
 *
 * @see \Drupal\Core\Token\Discovery\TypedDataFieldDiscovery
 * @see \Drupal\Core\Token\EntityReferenceFieldToken
 * @see \Drupal\Core\Token\EntityDerefToken
 * @see \Drupal\Core\Token\FieldValueToken
 */
#[CoversClass(\Drupal\Core\Token\Discovery\TypedDataFieldDiscovery::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenEntityFieldChainTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

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
   * Creates a node authored by a freshly created user with the given name.
   *
   * @return array{0: \Drupal\node\NodeInterface, 1: \Drupal\user\UserInterface}
   *   The node and its author.
   */
  private function createAuthoredNode(string $authorName): array {
    $author = $this->createUser([], $authorName);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Field chain node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();
    return [$node, $author];
  }

  /**
   * Tests the full entity-reference field chain with a privileged viewer.
   */
  public function testEntityReferenceFieldChainResolves(): void {
    [$node, $author] = $this->createAuthoredNode('field_chain_author');
    $admin = $this->createUser([], NULL, TRUE);

    $result = $this->tokenService->replace(
      '[node:uid:entity:name]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $this->assertSame($author->getAccountName(), $result, 'The reference chain resolved through real typed-data field discovery.');
  }

  /**
   * Tests resolveChain() composes cacheability across the real field chain.
   */
  public function testFieldChainBubblesCacheability(): void {
    [$node] = $this->createAuthoredNode('cache_author');
    $admin = $this->createUser([], NULL, TRUE);

    $bubbleable = new BubbleableMetadata();
    $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $admin], $bubbleable);

    // The referenced user's cache tags must have bubbled into the metadata.
    $this->assertContains('user:' . $node->getOwnerId(), $bubbleable->getCacheTags(), 'The referenced user cache tag bubbled from the chain.');
  }

  /**
   * Tests that the chain is access-checked against the viewer, not the current user.
   *
   * An anonymous viewer cannot view user profiles, so the engine must drop the
   * value rather than leak the referenced user's name.
   */
  public function testChainAccessIsCheckedAgainstViewer(): void {
    [$node, $author] = $this->createAuthoredNode('secret_author');

    $result = $this->tokenService->replace(
      'Author: [node:uid:entity:name]',
      ['node' => $node],
      ['viewer' => new AnonymousUserSession()],
    );

    $this->assertStringNotContainsString($author->getAccountName(), $result, 'The author name is not leaked to an unauthorised viewer.');
    $this->assertSame('Author: ', $result, 'The denied token is replaced with an empty string.');
  }

  /**
   * Proves the outcome flips on the viewer's actual access permission alone.
   *
   * The node, the token, and the placer are held constant. The only variable is
   * whether the viewer holds 'access user profiles'. A viewer with the
   * permission sees the referenced user's name; an otherwise-identical viewer
   * without it gets an empty string. This isolates the behaviour to a real
   * access decision against the viewer, not to admin bypass or an incidental
   * resolution failure.
   */
  public function testOutcomeDependsOnlyOnViewerPermission(): void {
    [$node, $author] = $this->createAuthoredNode('permission_author');

    // Both viewers can view the root node ('access content'); they differ only
    // in whether they can view the referenced user ('access user profiles').
    $viewerAllowed = $this->createUser(['access content', 'access user profiles']);
    $viewerDenied = $this->createUser(['access content']);

    $allowed = $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $viewerAllowed]);
    $denied = $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $viewerDenied]);

    $this->assertSame($author->getAccountName(), $allowed, 'A viewer with access sees the name.');
    $this->assertSame('', $denied, 'An identical viewer lacking the permission sees nothing.');
    $this->assertNotSame($allowed, $denied, 'Flipping only the permission flips the outcome.');
  }

  /**
   * Proves field-level access is enforced, not merely entity-level access.
   *
   * This is the classic token exfiltration subclass: checking only entity
   * access leaks fields the viewer may not see. Using core's own rules, the
   * user 'mail' field requires 'view user email addresses' to view, while the
   * user entity (and its 'name' field) is viewable with 'access user profiles'.
   * A viewer holding only the latter can read [node:uid:entity:name] but must be
   * denied [node:uid:entity:mail] on the very same user.
   */
  public function testFieldLevelAccessIsEnforcedNotJustEntityAccess(): void {
    $author = $this->createUser([], 'mail_author', FALSE, ['mail' => 'author@example.com']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Field access node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    // Both viewers can view the root node and the referenced user; they differ
    // only in whether they can view the user's email field.
    $viewerWithFieldAccess = $this->createUser(['access content', 'access user profiles', 'view user email addresses']);
    $viewerEntityOnly = $this->createUser(['access content', 'access user profiles']);

    $mailAllowed = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerWithFieldAccess]);
    $mailDenied = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerEntityOnly]);
    // The entity-only viewer CAN read a field it is permitted to see on the
    // same user, proving entity access is satisfied and only field access
    // differs between the two outcomes.
    $nameForEntityOnly = $this->tokenService->replace('[node:uid:entity:name]', ['node' => $node], ['viewer' => $viewerEntityOnly]);

    $this->assertSame('author@example.com', $mailAllowed, 'A viewer with field access sees the email.');
    $this->assertSame('', $mailDenied, 'A viewer with entity access but no field access is denied the email.');
    $this->assertSame($author->getAccountName(), $nameForEntityOnly, 'The same entity-only viewer reads a permitted field, so the mail denial is field-level, not entity-level.');
  }

  /**
   * Proves view access on the ROOT entity is enforced against the viewer.
   *
   * The viewer is granted everything downstream (view the referenced user and
   * its email) but is NOT granted 'access content', so it cannot view the root
   * node. The whole chain must be denied. Granting 'access content' to an
   * otherwise-identical viewer lets the same chain resolve, proving the root
   * node's view access is the deciding factor.
   */
  public function testRootEntityViewAccessIsEnforced(): void {
    $author = $this->createUser([], 'root_author', FALSE, ['mail' => 'root@example.com']);
    $node = Node::create([
      'type' => 'article',
      'title' => 'Root access node',
      'uid' => $author->id(),
      'status' => 1,
    ]);
    $node->save();

    $downstreamPerms = ['access user profiles', 'view user email addresses'];
    $viewerNoRoot = $this->createUser($downstreamPerms);
    $viewerWithRoot = $this->createUser(array_merge($downstreamPerms, ['access content']));

    $denied = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerNoRoot]);
    $allowed = $this->tokenService->replace('[node:uid:entity:mail]', ['node' => $node], ['viewer' => $viewerWithRoot]);

    $this->assertSame('', $denied, 'A viewer who cannot view the root node is denied the whole chain.');
    $this->assertSame('root@example.com', $allowed, 'Granting root node view access lets the same chain resolve.');
  }

  /**
   * Tests that a single-segment curated field token still routes to legacy.
   *
   * [node:title] must not be intercepted by auto field discovery; it stays on
   * the legacy pipeline so curated formatting and behaviour are preserved.
   */
  public function testSingleSegmentFieldTokenStaysLegacy(): void {
    [$node] = $this->createAuthoredNode('legacy_author');

    $result = $this->tokenService->replace('[node:title]', ['node' => $node]);
    $this->assertSame('Field chain node', $result, 'Single-segment [node:title] resolved via the legacy pipeline.');
  }

}
