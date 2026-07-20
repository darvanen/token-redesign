<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\entity_test\Entity\EntityTest;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\token_context_test\Plugin\Token\PlacementGatedSecretResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests placement-time token gating against the author's permissions.
 *
 * The placement gate is the authoring-side counterpart to resolution access:
 * it asks "is this author entitled to put this token here?", checked against
 * the author (the current user at save time), not the viewer. It walks each
 * token's chain and blocks any step that exposes data the author cannot access:
 * a restricted field (field access against the author), or an attributed token
 * declaring a place permission the author lacks. Chains it cannot verify are
 * blocked only when hardening is on.
 *
 * This is the only layer that stops the headline attack in the related core
 * issues: an unprivileged author placing a chain like [node:uid:entity:mail],
 * which resolution-access cannot catch because a privileged viewer is allowed
 * to see the value and exfiltrates it on render.
 *
 * @see \Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see https://www.drupal.org/project/drupal/issues/3489852
 * @see https://www.drupal.org/project/token/issues/3593501
 * @see https://www.drupal.org/project/token_filter/issues/3587719
 * @see https://www.drupal.org/project/metatag/issues/3587720
 * @see https://www.drupal.org/project/drupal/issues/3587726
 */
#[CoversClass(PlacementGatedSecretResolver::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenPlacementConstraintTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'token_engine',
    'system',
    'user',
    'field',
    'text',
    'filter',
    'entity_test',
    'token_context_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('entity_test');
    $this->installConfig(['filter', 'system']);

    // A formatted-text field on entity_test. The text/string field types get
    // the TokenPlacement constraint auto-attached by the system module hook.
    FieldStorageConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'entity_test',
      'type' => 'text_long',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_body',
      'entity_type' => 'entity_test',
      'bundle' => 'entity_test',
    ])->save();
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Tests an author without the permission cannot place a declared token.
   */
  public function testDeclaredTokenWithoutPermissionIsBlocked(): void {
    $this->setUpCurrentUser(permissions: []);

    $violations = $this->violationsFor('Reveal: [placement_probe:secret]');

    $this->assertCount(1, $violations, 'The gated declared token raises one violation.');
    $this->assertStringContainsString('[placement_probe:secret]', (string) $violations[0]->getMessage());
    $this->assertStringContainsString('place secret tokens', (string) $violations[0]->getMessage());
  }

  /**
   * Tests an author holding the permission may place the declared token.
   */
  public function testDeclaredTokenWithPermissionIsAllowed(): void {
    $this->setUpCurrentUser(permissions: ['place secret tokens']);

    $this->assertCount(0, $this->violationsFor('Reveal: [placement_probe:secret]'));
  }

  /**
   * Tests ungated tokens are never blocked.
   */
  public function testUngatedTokensAreNeverBlocked(): void {
    $this->setUpCurrentUser(permissions: []);

    $this->assertCount(0, $this->violationsFor('[site:name]'), 'A legacy hook token is ungated.');
    $this->assertCount(0, $this->violationsFor('No tokens here at all.'), 'Plain text is clean.');
  }

  /**
   * Tests the headline attack: a chain to a restricted field is gated.
   *
   * [user:mail] walks to the user mail base field, whose view access is checked
   * against the author with no entity bound. An unprivileged author cannot place
   * it; an author with 'administer users' can. This is the field-access path,
   * and the negative control is the with/without-permission pair.
   */
  public function testChainToRestrictedFieldIsGatedByFieldAccess(): void {
    $this->setUpCurrentUser(permissions: []);
    $blocked = $this->violationsFor('Harvest: [user:mail]');
    $this->assertCount(1, $blocked, 'An unprivileged author cannot place a token reading the mail field.');
    $this->assertStringContainsString('[user:mail]', (string) $blocked[0]->getMessage());

    $this->setUpCurrentUser(permissions: ['administer users']);
    $this->assertCount(0, $this->violationsFor('Harvest: [user:mail]'), 'An author who can view the mail field may place it.');
  }

  /**
   * Tests an unverifiable chain is blocked when hardening is on, else allowed.
   *
   * [placement_probe:opaque:more] lands on an opaque output type the registry
   * cannot traverse, so the gate cannot tell whether it reaches sensitive data.
   */
  public function testUnverifiableChainHonoursHardeningAndOverride(): void {
    $this->setUpCurrentUser(permissions: []);

    $this->config('token_engine.settings')->set('harden_placement', TRUE)->save();
    $hardened = $this->violationsFor('[placement_probe:opaque:more]');
    $this->assertCount(1, $hardened, 'Hardened: an unverifiable chain is blocked.');
    $this->assertStringContainsString('place unverifiable tokens', (string) $hardened[0]->getMessage());

    $this->config('token_engine.settings')->set('harden_placement', FALSE)->save();
    $this->assertCount(0, $this->violationsFor('[placement_probe:opaque:more]'), 'Relaxed: an unverifiable chain is allowed.');
  }

  /**
   * Tests the override permission allows an unverifiable chain even when hardened.
   */
  public function testUnverifiablePermissionOverridesHardening(): void {
    $this->setUpCurrentUser(permissions: ['place unverifiable tokens']);
    $this->config('token_engine.settings')->set('harden_placement', TRUE)->save();

    $this->assertCount(0, $this->violationsFor('[placement_probe:opaque:more]'), 'The override permission permits an unverifiable chain.');
  }

  /**
   * Tests the gate fires on a real entity field, via core auto-attachment.
   *
   * The constraint is attached to the text field by the system module hook, so
   * validating the entity exercises the field-value path end to end. Presence,
   * not delta: an author lacking the permission is challenged simply for the
   * content containing the token.
   */
  public function testGateAppliesToEntityTextFieldByAutoAttachment(): void {
    $this->setUpCurrentUser(permissions: []);
    $blocked = EntityTest::create([
      'name' => 'Sneaky',
      'field_body' => ['value' => 'Reveal: [placement_probe:secret]', 'format' => 'plain_text'],
    ]);
    $bodyViolations = $blocked->validate()->getByField('field_body');
    $this->assertGreaterThan(0, $bodyViolations->count(), 'The gated token is blocked on the entity field.');

    $this->setUpCurrentUser(permissions: ['place secret tokens']);
    $allowed = EntityTest::create([
      'name' => 'Trusted',
      'field_body' => ['value' => 'Reveal: [placement_probe:secret]', 'format' => 'plain_text'],
    ]);
    $this->assertCount(0, $allowed->validate()->getByField('field_body'), 'A permitted author may save the same content.');
  }

}
