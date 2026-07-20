<?php

declare(strict_types=1);

namespace Drupal\token_engine\Hook;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\Requirement\RequirementSeverity;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\State\StateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\token_engine\UnenforcedUsageMonitor;

/**
 * Runtime requirements for the Token Engine module.
 */
class TokenEngineRequirementsHooks {

  use StringTranslationTrait;

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly StateInterface $state,
  ) {}

  /**
   * Implements hook_runtime_requirements().
   */
  #[Hook('runtime_requirements')]
  public function runtimeRequirements(): array {
    $requirements = [];

    // The placement posture is deliberately unset at install: the site owner
    // decides whether unverifiable chains harden or stay grandfathered, and
    // this requirement stays an error until they do. While undecided the gate
    // leans grandfathered, so installing the module never breaks a save.
    $harden = $this->configFactory->get('token_engine.settings')->get('harden_placement');
    if ($harden === NULL) {
      $requirements['token_engine_placement'] = [
        'title' => $this->t('Token Engine placement hardening'),
        'value' => $this->t('Not decided'),
        'description' => $this->t('Choose whether authors may save content containing unverifiable token chains. Until a choice is made, such chains are allowed (the pre-module behaviour). <a href=":url">Decide now</a>.', [':url' => Url::fromRoute('token_engine.settings')->toString()]),
        'severity' => RequirementSeverity::Error,
      ];
    }
    else {
      $requirements['token_engine_placement'] = [
        'title' => $this->t('Token Engine placement hardening'),
        'value' => $harden
          ? $this->t('Hardened: unverifiable token chains are denied at save.')
          : $this->t('Allowing unverifiable token chains (grandfathered).'),
        'severity' => $harden ? RequirementSeverity::OK : RequirementSeverity::Warning,
      ];
    }

    // Visibility for viewer-less (unenforced) resolution, replacing the core
    // attempt's deprecation notice: contrib has no removal-timeline lever, so
    // the pressure is a running counter here plus one log entry per hour.
    $count = (int) $this->state->get(UnenforcedUsageMonitor::STATE_COUNT, 0);
    $requirements['token_engine_unenforced'] = [
      'title' => $this->t('Token Engine access enforcement'),
      'value' => $count === 0
        ? $this->t('All observed token replacement passed a viewer.')
        : $this->formatPlural($count, '1 request resolved tokens without a viewer since install.', '@count requests resolved tokens without a viewer since install.'),
      'description' => $count === 0 ? NULL : $this->t("Viewer-less replacement produces output with no access enforcement, preserving pre-module behaviour. Update custom callers to pass options['viewer']."),
      'severity' => $count === 0 ? RequirementSeverity::OK : RequirementSeverity::Warning,
    ];

    return $requirements;
  }

}
