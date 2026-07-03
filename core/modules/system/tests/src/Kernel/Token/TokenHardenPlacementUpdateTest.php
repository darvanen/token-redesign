<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests system_update_12002(), which mediates system.token across upgrades.
 *
 * System.token only ships as install-time default configuration
 * (system.token.yml, harden_placement: true), so a site that predates it
 * never receives the file: the object is simply absent from active storage
 * until something creates it. TokenPlacementConstraintValidator::hardened()
 * treats a missing value as TRUE (`?->get('harden_placement') ?? TRUE`), so
 * the absent-config state is already hardened, not relaxed -- an upgrading
 * site would silently start rejecting placements it always allowed, exactly
 * what the schema comment on system.token.schema.yml says must not happen.
 * system_update_12002() is the fix: it creates system.token with
 * harden_placement: FALSE for any site where the object does not already
 * exist, pinning existing sites to their pre-upgrade behaviour, while never
 * touching a config object a site already has (a fresh install's real
 * TRUE default, or a previous run of this same update).
 *
 * This is a kernel test of the update function's logic directly, not an
 * UpdatePathTestBase run: that base class replays a full database fixture
 * dump to exercise update.php end to end, which is the right tool for
 * schema/data migrations but heavy overkill for a single idempotent config
 * write. Here the update function is loaded the same way core's own
 * UserInstallTest loads and calls user_install() directly -- via
 * ModuleHandlerInterface::loadInclude() followed by a direct call -- and its
 * effect is pinned two ways: the raw config object state, and the resulting
 * behaviour of the placement gate that actually consumes it.
 *
 * @see \Drupal\Core\Token\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see \Drupal\Tests\system\Kernel\Token\TokenPlacementConstraintTest
 */
#[CoversFunction('system_update_12002')]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenHardenPlacementUpdateTest extends KernelTestBase {

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
    'token_context_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');

    // Deliberately do not installConfig(['system']): that would install
    // system.token from its install defaults, masking the very state (the
    // config object absent entirely) this test needs to start from in order
    // to reproduce a pre-existing site mid-upgrade.
    $this->setUpCurrentUser(permissions: []);
  }

  /**
   * Validates a text value against the TokenPlacement constraint directly.
   */
  private function violationsFor(string $text): ConstraintViolationListInterface {
    $definition = DataDefinition::create('string')->addConstraint('TokenPlacement');
    return $this->container->get('typed_data_manager')->create($definition, $text)->validate();
  }

  /**
   * Tests the pre-update state: absent config is hardened, not relaxed.
   *
   * This is the bug the update hook exists to fix: without it, an upgrading
   * site is gated exactly like a brand new install the moment this code
   * ships, because the validator's null-coalescing default is TRUE.
   */
  public function testAbsentConfigDefaultsToHardened(): void {
    $this->assertTrue(\Drupal::config('system.token')->isNew(), 'system.token does not exist before the update runs.');

    $violations = $this->violationsFor('[placement_probe:opaque:more]');
    $this->assertCount(1, $violations, 'With system.token absent, an unverifiable chain is already blocked as if hardening were on.');
  }

  /**
   * Tests the update creates system.token with hardening off.
   */
  public function testUpdateCreatesUnhardenedConfig(): void {
    \Drupal::moduleHandler()->loadInclude('system', 'install');
    system_update_12002();

    $config = \Drupal::config('system.token');
    $this->assertFalse($config->isNew(), 'system.token exists after the update runs.');
    $this->assertFalse($config->get('harden_placement'), 'The update persists harden_placement: FALSE.');

    $this->assertCount(0, $this->violationsFor('[placement_probe:opaque:more]'), 'After the update, the same unverifiable chain is allowed again: pre-upgrade behaviour is preserved.');
  }

  /**
   * Tests the denied branch is always-on and is not governed by the flag.
   *
   * This is intentional, not a regression to guard against by accident:
   * harden_placement only relaxes chains the validator could not verify (see
   * testUpdateCreatesUnhardenedConfig() above, which is the flag's actual
   * scope). A chain that verifiably reaches data the author cannot access --
   * here, [current-user:mail] reaching the 'mail' base field on 'entity:user'
   * -- is blocked by TokenPlacementConstraintValidator::gate() regardless of
   * harden_placement, in both the pre-update (config absent, defaults
   * hardened) and post-update (harden_placement FALSE) states. Pinning this
   * post-update guards against a future change conflating "unhardened" with
   * "the security fix is off": the flag governs unverifiable chains only, and
   * the always-on denied branch is what actually closes the exfiltration
   * vulnerability this validator exists for.
   */
  public function testDeniedBranchIsAlwaysOnRegardlessOfFlag(): void {
    \Drupal::moduleHandler()->loadInclude('system', 'install');
    system_update_12002();

    $config = \Drupal::config('system.token');
    $this->assertFalse($config->isNew(), 'system.token exists after the update runs.');
    $this->assertFalse($config->get('harden_placement'), 'The update persists harden_placement: FALSE.');

    $this->setUpCurrentUser(permissions: []);
    $violations = $this->violationsFor('[current-user:mail]');
    $this->assertCount(1, $violations, 'With harden_placement FALSE, an author denied the mail field is still blocked: the denied branch is not governed by the flag.');
  }

  /**
   * Tests the update leaves an already-existing system.token alone.
   *
   * Covers the third state the update mediates: a site that already has the
   * object -- a fresh install's real TRUE default, or a second run of this
   * same update on an already-updated site -- must not be touched.
   */
  public function testUpdateDoesNotOverwriteExistingConfig(): void {
    $this->config('system.token')->set('harden_placement', TRUE)->save();

    \Drupal::moduleHandler()->loadInclude('system', 'install');
    system_update_12002();

    $this->assertTrue(\Drupal::config('system.token')->get('harden_placement'), 'An existing system.token is left untouched by the update.');
    $this->assertCount(1, $this->violationsFor('[placement_probe:opaque:more]'), 'Hardening set by a pre-existing config object still applies after the update runs.');
  }

}
