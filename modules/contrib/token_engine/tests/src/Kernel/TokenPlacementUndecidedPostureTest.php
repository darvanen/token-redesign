<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\KernelTests\KernelTestBase;
use Drupal\token_engine\Hook\TokenEngineRequirementsHooks;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Tests the ask-at-install placement posture.
 *
 * As a contrib module, token_engine cannot mediate "existing site vs new
 * install" with an update hook the way the core-patch attempt's
 * system_update_12002() did: every install of the module happens on an
 * existing site. The posture is therefore deliberately unset at install and
 * hook_requirements() carries an error until a site owner chooses on the
 * settings form. While undecided the gate leans grandfathered — installing
 * the module must never break an editor's save — so the validator treats a
 * missing harden_placement as FALSE.
 *
 * The denied branch is always-on regardless: a chain that verifiably reaches
 * data the author cannot access is blocked in every posture, undecided
 * included. That branch is the security fix; harden_placement only governs
 * chains the validator cannot statically verify.
 *
 * @see \Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator
 * @see \Drupal\token_engine\Form\TokenEngineSettingsForm
 * @see token_engine_requirements()
 */
#[CoversClass(\Drupal\token_engine\Plugin\Validation\Constraint\TokenPlacementConstraintValidator::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenPlacementUndecidedPostureTest extends KernelTestBase {

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
    'token_context_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Deliberately no installConfig(): token_engine ships no settings default,
    // so the config object is absent — the undecided state under test.
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
   * Returns the module's runtime requirements, as the status report would.
   */
  private function requirements(): array {
    $hooks = new TokenEngineRequirementsHooks(
      $this->container->get('config.factory'),
      $this->container->get('state'),
    );
    return $hooks->runtimeRequirements();
  }

  /**
   * Tests the undecided posture allows unverifiable chains and reports red.
   */
  public function testUndecidedLeansGrandfathered(): void {
    $this->assertTrue(\Drupal::config('token_engine.settings')->isNew(), 'No posture has been chosen: token_engine.settings is absent.');

    $this->assertCount(0, $this->violationsFor('[placement_probe:opaque:more]'), 'While undecided, an unverifiable chain keeps saving: installing the module breaks no editorial workflow.');

    $this->assertSame(RequirementSeverity::Error, $this->requirements()['token_engine_placement']['severity'], 'The status report carries an error until a posture is chosen.');
  }

  /**
   * Tests each explicit posture applies, and clears the requirements error.
   */
  public function testExplicitPostureApplies(): void {
    $this->config('token_engine.settings')->set('harden_placement', TRUE)->save();
    $this->assertCount(1, $this->violationsFor('[placement_probe:opaque:more]'), 'Hardened: an unverifiable chain is denied at save.');
    $this->assertSame(RequirementSeverity::OK, $this->requirements()['token_engine_placement']['severity']);

    $this->config('token_engine.settings')->set('harden_placement', FALSE)->save();
    $this->assertCount(0, $this->violationsFor('[placement_probe:opaque:more]'), 'Relaxed: unverifiable chains keep saving.');
    $this->assertSame(RequirementSeverity::Warning, $this->requirements()['token_engine_placement']['severity']);
  }

  /**
   * Tests the denied branch is always-on in every posture.
   */
  public function testDeniedBranchIsAlwaysOn(): void {
    $this->assertCount(1, $this->violationsFor('[current-user:mail]'), 'Undecided: an author denied the mail field is still blocked.');

    $this->config('token_engine.settings')->set('harden_placement', FALSE)->save();
    $this->assertCount(1, $this->violationsFor('[current-user:mail]'), 'Relaxed: the denied branch is not governed by the flag.');
  }

}
