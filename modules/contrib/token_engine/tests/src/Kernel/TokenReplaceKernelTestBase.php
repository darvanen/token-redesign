<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\KernelTests\Core\Entity\EntityKernelTestBase;

/**
 * Base class for token replacement tests.
 */
abstract class TokenReplaceKernelTestBase extends EntityKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_engine'];

  /**
   * The interface language.
   *
   * @var \Drupal\Core\Language\LanguageInterface
   */
  protected $interfaceLanguage;

  /**
   * Token service.
   *
   * @var \Drupal\Core\Utility\Token
   */
  protected $tokenService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Install default system configuration.
    $this->installConfig(['system']);

    $this->interfaceLanguage = \Drupal::languageManager()->getCurrentLanguage();
    $this->tokenService = \Drupal::token();
  }

}
