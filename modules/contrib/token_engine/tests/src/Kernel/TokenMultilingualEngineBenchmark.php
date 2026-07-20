<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The engine configuration of the multilingual benchmark.
 *
 * Identical workload to the parent by inheritance; the only difference is
 * that token_engine is enabled (kernel tests merge $modules across the class
 * hierarchy). Compare:
 *
 *   TOKEN_BENCH_LABEL=engine ddev exec vendor/bin/phpunit -c core \
 *     modules/contrib/token_engine/tests/src/Kernel/TokenMultilingualEngineBenchmark.php
 *   TOKEN_BENCH_LABEL=plain ddev exec vendor/bin/phpunit -c core \
 *     modules/contrib/token_engine/tests/src/Kernel/TokenMultilingualBenchmark.php
 *
 * For the plain run, pair with the upstream contrib token checkout (the
 * token-engine branch of contrib token extends this module's classes and
 * cannot load with the module absent).
 */
#[Group('TokenBenchmark')]
#[RunTestsInSeparateProcesses]
class TokenMultilingualEngineBenchmark extends TokenMultilingualBenchmark {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_engine'];

}
