<?php

declare(strict_types=1);

namespace Drupal\Tests\webform\Kernel;

use Drupal\Core\Render\BubbleableMetadata;
use Drupal\KernelTests\KernelTestBase;
use Drupal\webform\Entity\Webform;
use Drupal\webform\Entity\WebformSubmission;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves webform hook_token_info() / hook_tokens() work with the new engine.
 *
 * The core Token::generate() now delegates to TokenResolutionEngine, which
 * routes all tokens not found in the attributed-resolver registry to
 * LegacyTokenBridge::generate(). That bridge invokes hook_tokens() exactly
 * as the old Token::generate() did. This test confirms:
 *
 * - hook_token_info() still registers [webform:*] and [webform_submission:*].
 * - [webform:id] and [webform:title] replace correctly through the new engine.
 * - [webform_submission:sid] replaces correctly through the new engine.
 * - BubbleableMetadata is populated (cache dependencies attached).
 * - The BC layer passes all data correctly to webform's token hooks.
 */
#[Group('webform')]
#[RunTestsInSeparateProcesses]
class TokenEngineBackwardCompatibilityTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'path',
    'path_alias',
    'user',
    'field',
    'filter',
    'token_engine',
    'webform',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('path_alias');
    $this->installEntitySchema('user');
    $this->installEntitySchema('webform_submission');
    $this->installSchema('webform', ['webform']);
    $this->installConfig(['system', 'webform']);
  }

  /**
   * Verifies hook_token_info() registers webform token types and tokens.
   *
   * Hook_token_info() is invoked by Token::getInfo(), which is not affected by
   * the new engine. This test confirms the hook is still reachable.
   */
  public function testWebformTokenInfoRegistered(): void {
    $token_service = \Drupal::token();
    $info = $token_service->getInfo();

    $this->assertArrayHasKey('webform', $info['types'], 'The "webform" token type must be registered by hook_token_info().');
    $this->assertArrayHasKey('webform_submission', $info['types'], 'The "webform_submission" token type must be registered by hook_token_info().');

    $this->assertArrayHasKey('webform', $info['tokens'], 'Webform token definitions must exist.');
    $this->assertArrayHasKey('id', $info['tokens']['webform'], 'The [webform:id] token must be declared.');
    $this->assertArrayHasKey('title', $info['tokens']['webform'], 'The [webform:title] token must be declared.');

    $this->assertArrayHasKey('webform_submission', $info['tokens'], 'Webform submission token definitions must exist.');
    $this->assertArrayHasKey('sid', $info['tokens']['webform_submission'], 'The [webform_submission:sid] token must be declared.');
  }

  /**
   * Verifies [webform:id] and [webform:title] replace correctly.
   *
   * These tokens are handled by WebformTokensHooks::tokens() (hook_tokens).
   * Under the new engine the call path is:
   *   Token::generate() -> TokenResolutionEngine::generate()
   *   -> partitionTokens() (no attributed resolver, falls to legacy)
   *   -> LegacyTokenBridge::generate() -> hook_tokens()
   *   -> WebformTokensHooks::tokens()
   */
  public function testWebformIdAndTitleTokenReplacement(): void {
    $webform = Webform::create([
      'id' => 'test_bc_webform',
      'title' => 'BC Test Webform',
    ]);
    $webform->save();

    $token_service = \Drupal::token();
    $bubbleable = new BubbleableMetadata();

    $id_result = $token_service->replace('[webform:id]', ['webform' => $webform], [], $bubbleable);
    $this->assertSame('test_bc_webform', $id_result, '[webform:id] must be replaced via LegacyTokenBridge under the new engine.');

    $title_result = $token_service->replace('[webform:title]', ['webform' => $webform], [], $bubbleable);
    $this->assertSame('BC Test Webform', $title_result, '[webform:title] must be replaced via LegacyTokenBridge under the new engine.');
  }

  /**
   * Verifies [webform_submission:sid] replaces correctly.
   *
   * Confirms that the $data array is passed unchanged through the new engine
   * pipeline to WebformTokensHooks::tokens().
   */
  public function testWebformSubmissionSidTokenReplacement(): void {
    $webform = Webform::create([
      'id' => 'test_bc_submission_webform',
      'title' => 'BC Submission Webform',
    ]);
    $webform->save();

    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
    ]);
    $submission->save();

    $sid = $submission->id();
    $this->assertNotNull($sid, 'The webform submission must have an ID after saving.');

    $token_service = \Drupal::token();
    $bubbleable = new BubbleableMetadata();

    $result = $token_service->replace(
      '[webform_submission:sid]',
      ['webform_submission' => $submission],
      [],
      $bubbleable,
    );

    $this->assertSame((string) $sid, $result, '[webform_submission:sid] must be replaced with the submission ID via the new engine.');
  }

  /**
   * Verifies generate() returns correct replacements and populates metadata.
   *
   * Calls Token::generate() directly (the method the new engine overrides) and
   * confirms that:
   * - The replacements array is keyed by raw token strings.
   * - BubbleableMetadata receives the webform as a cache dependency.
   */
  public function testGenerateReturnsReplacementsAndMetadata(): void {
    $webform = Webform::create([
      'id' => 'test_bc_generate',
      'title' => 'Generate BC Webform',
    ]);
    $webform->save();

    $token_service = \Drupal::token();
    $bubbleable = new BubbleableMetadata();

    // Tokens array as passed by Token::doReplace(): name => raw-token-string.
    $tokens = [
      'id' => '[webform:id]',
      'title' => '[webform:title]',
    ];

    $replacements = $token_service->generate(
      'webform',
      $tokens,
      ['webform' => $webform],
      [],
      $bubbleable,
    );

    $this->assertArrayHasKey('[webform:id]', $replacements, 'generate() must return a replacement keyed by the raw token string [webform:id].');
    // [webform:id] is migrated to the attributed-resolver mechanism, so its raw
    // generate() output is a safe-marked Markup object in the HTML output
    // context. Cast to string to compare the underlying value.
    $this->assertSame('test_bc_generate', (string) $replacements['[webform:id]'], '[webform:id] replacement must equal the webform machine name.');

    $this->assertArrayHasKey('[webform:title]', $replacements, 'generate() must return a replacement keyed by [webform:title].');
    $this->assertSame('Generate BC Webform', $replacements['[webform:title]'], '[webform:title] replacement must equal the webform title.');

    // The LegacyTokenBridge adds the webform entity as a cache dependency.
    $cache_tags = $bubbleable->getCacheTags();
    $this->assertNotEmpty($cache_tags, 'BubbleableMetadata must contain cache tags after token generation.');
    $this->assertContains('config:webform.webform.test_bc_generate', $cache_tags, 'The webform config entity cache tag must appear in bubbleable metadata.');
  }

  /**
   * Verifies generate() also works when called with multiple token types.
   *
   * The new engine handles one $type per generate() call. This test ensures
   * that sequential calls for different token types all flow correctly through
   * the legacy bridge.
   */
  public function testMultipleTokenTypesViaSequentialGenerateCalls(): void {
    $webform = Webform::create([
      'id' => 'multi_type_bc',
      'title' => 'Multi Type BC Webform',
    ]);
    $webform->save();

    $submission = WebformSubmission::create([
      'webform_id' => $webform->id(),
    ]);
    $submission->save();

    $token_service = \Drupal::token();
    $bubbleable = new BubbleableMetadata();

    $webform_replacements = $token_service->generate(
      'webform',
      ['id' => '[webform:id]'],
      ['webform' => $webform],
      [],
      $bubbleable,
    );
    // [webform:id] is migrated; its raw generate() output is a Markup object.
    $this->assertSame('multi_type_bc', (string) $webform_replacements['[webform:id]']);

    $submission_replacements = $token_service->generate(
      'webform_submission',
      ['sid' => '[webform_submission:sid]'],
      ['webform_submission' => $submission],
      [],
      $bubbleable,
    );
    $this->assertSame((string) $submission->id(), $submission_replacements['[webform_submission:sid]']);
  }

  /**
   * Verifies the [webform:id] token is migrated to an attributed resolver.
   *
   * Confirms the resolution engine routes the (webform, id) identity to
   * \Drupal\webform\Plugin\Token\WebformIdToken rather than falling back to the
   * legacy hook bridge.
   */
  public function testWebformIdTokenResolverRegistered(): void {
    /** @var \Drupal\token_engine\TokenRegistryInterface $registry */
    $registry = $this->container->get('token_engine.registry');
    $definition = $registry->getResolvableToken('webform', 'id');

    $this->assertNotNull($definition, 'The [webform:id] token must be registered as an attributed resolver.');
    $this->assertSame(
      'Drupal\webform\Plugin\Token\WebformIdToken',
      $definition->resolverClass,
      'The [webform:id] token must resolve via the WebformIdToken resolver class.',
    );
  }

}
