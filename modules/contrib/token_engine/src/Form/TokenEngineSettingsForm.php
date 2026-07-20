<?php

declare(strict_types=1);

namespace Drupal\token_engine\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Chooses the placement-hardening posture.
 *
 * The module deliberately installs with no value: enabling a security module
 * should not silently break existing editorial workflows, and silently
 * grandfathering would hide the decision. The status report stays red until a
 * site owner makes the call here. Note the always-on protection is not
 * configurable: chains that verifiably reach data the author cannot access
 * are denied at save regardless of this setting, which only governs chains
 * the gate cannot statically verify.
 */
class TokenEngineSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'token_engine_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['token_engine.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $current = $this->config('token_engine.settings')->get('harden_placement');

    $form['harden_placement'] = [
      '#type' => 'radios',
      '#title' => $this->t('Unverifiable token chains in content'),
      '#description' => $this->t('Applies when an author saves content containing a token chain whose target cannot be statically verified (for example a polymorphic reference). Chains that verifiably reach data the author cannot access are always denied, whichever option is chosen. Authors with the "Place unverifiable tokens" permission bypass hardening.'),
      '#options' => [
        1 => $this->t('Harden: deny unverifiable chains at save (recommended)'),
        0 => $this->t('Allow: keep accepting unverifiable chains, as sites without this module always have'),
      ],
      '#default_value' => $current === NULL ? NULL : (int) $current,
      '#required' => TRUE,
    ];

    if ($current === NULL) {
      $form['undecided'] = [
        '#type' => 'item',
        '#markup' => $this->t('No posture has been chosen yet. Until one is, unverifiable chains are being allowed (the pre-module behaviour) and the status report carries an error.'),
        '#weight' => -10,
      ];
    }

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('token_engine.settings')
      ->set('harden_placement', (bool) $form_state->getValue('harden_placement'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
