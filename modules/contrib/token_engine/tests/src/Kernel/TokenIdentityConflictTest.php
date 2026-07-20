<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Logger\RfcLoggerTrait;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\token_engine\ActorContext;
use Drupal\token_engine\OutputContext;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverManager;
use Drupal\KernelTests\KernelTestBase;
use Drupal\token_conflict_test_alpha\Plugin\Token\AlphaMarkerResolver;
use Drupal\token_conflict_test_beta\Plugin\Token\BetaMarkerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Log\LoggerInterface;

/**
 * Tests deterministic resolution of `#[Token]` identity conflicts.
 *
 * Two `#[Token]` classes in different modules can declare the same identity
 * (input_type, name), i.e. the same plugin ID. token_conflict_test_alpha and
 * token_conflict_test_beta both declare `token_conflict_probe:marker` for
 * exactly this purpose. They are two brand-new, minimal fixture modules
 * dedicated to this test — kept entirely separate from token_context_test
 * (whose existing identities are relied on by other tests) rather than adding
 * a colliding plugin there, so this test cannot destabilise anything else.
 *
 * This test registers itself as a logger (like
 * \Drupal\KernelTests\Core\Extension\ModuleInstallerTest does) to capture the
 * warning \Drupal\token_engine\TokenResolverManager logs on the 'token' channel
 * when a conflict is resolved.
 *
 * @see \Drupal\token_engine\TokenResolverManager
 */
#[CoversClass(TokenResolverManager::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenIdentityConflictTest extends KernelTestBase implements LoggerInterface {

  use RfcLoggerTrait;

  /**
   * The identity both fixture modules' `#[Token]` classes claim.
   */
  private const IDENTITY = 'token_conflict_probe:marker';

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['token_engine', 'system'];

  /**
   * Messages logged on the 'token' channel at WARNING level or worse.
   *
   * @var list<array{message: string, context: array}>
   */
  private array $tokenWarnings = [];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // Register this test as a logger so TokenResolverManager's warning about
    // the identity conflict can be captured and asserted on, the same
    // technique \Drupal\KernelTests\Core\Extension\ModuleInstallerTest uses.
    $container->register(__CLASS__, __CLASS__)
      ->setSynthetic(TRUE)
      ->addTag('logger');
    $container->set(__CLASS__, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function log($level, string|\Stringable $message, array $context = []): void {
    if ((int) $level > RfcLogLevel::WARNING || ($context['channel'] ?? NULL) !== 'token') {
      return;
    }
    $this->tokenWarnings[] = ['message' => (string) $message, 'context' => $context];
  }

  /**
   * Tests the alphabetically-last module wins, installed alpha then beta.
   *
   * Both modules have the default weight (0), so the tie is broken by module
   * name; 'token_conflict_test_beta' sorts after 'token_conflict_test_alpha'.
   */
  public function testDeterministicWinnerInstallOrderAlphaThenBeta(): void {
    $this->installModulesInOrder(['token_conflict_test_alpha', 'token_conflict_test_beta']);
    $this->assertConflictResolvedInFavourOf(BetaMarkerResolver::class, AlphaMarkerResolver::class, 'from-beta');
  }

  /**
   * Tests the winner is unchanged when the install order is reversed.
   *
   * This is the same scenario as
   * testDeterministicWinnerInstallOrderAlphaThenBeta(), with the two modules
   * installed in the opposite order, proving the winner does not depend on
   * discovery/install order.
   */
  public function testDeterministicWinnerInstallOrderBetaThenAlpha(): void {
    $this->installModulesInOrder(['token_conflict_test_beta', 'token_conflict_test_alpha']);
    $this->assertConflictResolvedInFavourOf(BetaMarkerResolver::class, AlphaMarkerResolver::class, 'from-beta');
  }

  /**
   * Tests a higher module weight wins even against alphabetical order.
   *
   * 'token_conflict_test_alpha' sorts before 'token_conflict_test_beta', so
   * without a weight change beta would win (see the two tests above). Giving
   * alpha a higher weight must flip the winner, proving weight takes
   * precedence over the alphabetical tie-break.
   */
  public function testHigherModuleWeightWinsOverAlphabeticalOrder(): void {
    $this->installModulesInOrder(['token_conflict_test_alpha', 'token_conflict_test_beta']);
    module_set_weight('token_conflict_test_alpha', 10);

    $manager = $this->container->get('plugin.manager.token_engine_resolver');
    $manager->clearCachedDefinitions();
    $this->tokenWarnings = [];

    $definition = $manager->getDefinition(self::IDENTITY);
    $this->assertSame(AlphaMarkerResolver::class, $definition['class']);

    $conflicts = $manager->getIdentityConflicts();
    $this->assertSame([AlphaMarkerResolver::class, BetaMarkerResolver::class], $conflicts[self::IDENTITY]);

    $result = $manager->createInstance(self::IDENTITY)->resolve(NULL, [], $this->tokenResolutionContext());
    $this->assertSame('from-alpha', $result->value, 'The higher-weight module actually resolves, not just wins the definition.');
  }

  /**
   * Tests conflicts are still reported after a fresh plugin-cache round-trip.
   *
   * GetIdentityConflicts() must not go empty just because the definitions
   * came from the persistent plugin cache instead of a fresh discovery scan;
   * a brand new manager instance sharing the same cache backend has no
   * in-memory state at all, so it can only answer from the cache.
   */
  public function testConflictsSurviveCacheRoundTrip(): void {
    $this->installModulesInOrder(['token_conflict_test_alpha', 'token_conflict_test_beta']);

    // Force discovery to run once, populating the persistent cache.
    $original = $this->container->get('plugin.manager.token_engine_resolver');
    $this->assertNotEmpty($original->getIdentityConflicts());

    $fresh = new TokenResolverManager(
      $this->container->get('container.namespaces'),
      $this->container->get('cache.discovery'),
      $this->container->get('module_handler'),
    );
    $conflicts = $fresh->getIdentityConflicts();
    $this->assertArrayHasKey(self::IDENTITY, $conflicts, 'A fresh manager instance recovers conflicts from the persistent cache.');
    $this->assertSame([BetaMarkerResolver::class, AlphaMarkerResolver::class], $conflicts[self::IDENTITY]);

    // The fresh instance's definitions are correct too, proving the cached
    // payload is not just the conflicts list on its own.
    $this->assertSame(BetaMarkerResolver::class, $fresh->getDefinition(self::IDENTITY)['class']);
  }

  /**
   * Tests the dropped definition is logged as a warning on the 'token' channel.
   */
  public function testConflictIsLoggedAsWarning(): void {
    $this->installModulesInOrder(['token_conflict_test_alpha', 'token_conflict_test_beta']);
    $this->container->get('plugin.manager.token_engine_resolver')->getDefinitions();

    $matching = array_filter(
      $this->tokenWarnings,
      fn (array $entry): bool => ($entry['context']['%id'] ?? NULL) === self::IDENTITY,
    );
    $this->assertNotEmpty($matching, 'A warning naming the conflicting identity was logged on the token channel.');
    $entry = reset($matching);
    $this->assertSame(BetaMarkerResolver::class, $entry['context']['%winner']);
    $this->assertSame(AlphaMarkerResolver::class, $entry['context']['%loser']);
  }

  /**
   * Installs modules, via the real installer, in the given order.
   *
   * Uses \Drupal\Core\Extension\ModuleInstaller::install() rather than
   * \Drupal\KernelTests\KernelTestBase::enableModules(), because the real
   * installer sorts the module list by weight then name
   * (see module_config_sort()) regardless of the order modules are passed in,
   * which is exactly the guarantee this test needs to exercise.
   *
   * @param string[] $modules
   *   Module machine names, in install call order.
   */
  private function installModulesInOrder(array $modules): void {
    $this->container->get('module_installer')->install($modules);
  }

  /**
   * Asserts the identity conflict resolved in favour of $winnerClass.
   *
   * @param class-string $winnerClass
   *   The expected winning resolver class.
   * @param class-string $loserClass
   *   The expected losing (dropped) resolver class.
   * @param string $expectedValue
   *   The value the winner's resolve() returns.
   */
  private function assertConflictResolvedInFavourOf(string $winnerClass, string $loserClass, string $expectedValue): void {
    $manager = $this->container->get('plugin.manager.token_engine_resolver');

    $definition = $manager->getDefinition(self::IDENTITY);
    $this->assertSame($winnerClass, $definition['class']);

    $conflicts = $manager->getIdentityConflicts();
    $this->assertArrayHasKey(self::IDENTITY, $conflicts);
    $this->assertSame([$winnerClass, $loserClass], $conflicts[self::IDENTITY]);

    $result = $manager->createInstance(self::IDENTITY)->resolve(NULL, [], $this->tokenResolutionContext());
    $this->assertSame($expectedValue, $result->value, 'The winner actually resolves.');
  }

  /**
   * Builds a minimal resolution context for directly invoking a resolver.
   */
  private function tokenResolutionContext(): TokenResolutionContext {
    return new TokenResolutionContext(
      [],
      ActorContext::fromSingleActor($this->container->get('current_user')),
      OutputContext::Html,
    );
  }

}
