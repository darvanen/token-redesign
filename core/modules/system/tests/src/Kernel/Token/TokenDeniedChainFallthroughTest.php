<?php

declare(strict_types=1);

namespace Drupal\Tests\system\Kernel\Token;

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves a denied chain never falls through to the unchecked legacy pipeline.
 *
 * A chain the engine denies at field or root level must resolve to an empty
 * string. Before the fix, the three resolveChain() sites that could not
 * complete a chain structurally returned NULL, discarding an accumulated
 * denial; generate() then routed the raw token to the legacy hook pipeline,
 * which performs no access check and emitted the real value. The fixture's
 * tokens() hook makes that leak observable: it resolves any '<field>:value'
 * user token to the raw field value with no access check, so if the engine
 * ever routes a denied chain to it, the value appears in the output.
 *
 * @see \Drupal\Core\Token\TokenResolutionEngine::resolveChain()
 */
#[CoversClass(\Drupal\Core\Token\TokenResolutionEngine::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenDeniedChainFallthroughTest extends TokenReplaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['field', 'token_denied_chain_test'];

  /**
   * The account whose fields the tokens read.
   */
  protected UserInterface $owner;

  /**
   * A viewer who can view the owner's profile but not the secret field.
   */
  protected UserInterface $fieldDenied;

  /**
   * A viewer who cannot view the owner's profile at all.
   */
  protected UserInterface $profileDenied;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // A field the fixture forbids to non-owners, and one it leaves open.
    foreach (['field_secret', 'field_open'] as $fieldName) {
      FieldStorageConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'user',
        'type' => 'string',
      ])->save();
      FieldConfig::create([
        'field_name' => $fieldName,
        'entity_type' => 'user',
        'bundle' => 'user',
        'label' => $fieldName,
      ])->save();
    }

    $this->owner = $this->createUser([], 'secret_owner', FALSE, [
      'field_secret' => 'MECHANISM-LEAK-42',
      'field_open' => 'OPEN-LEAK-42',
    ]);
    // Can view the owner's profile, so a field-level denial is isolated.
    $this->fieldDenied = $this->createUser(['access user profiles'], 'field_denied');
    // Cannot view the owner's profile, so root access resolves to neutral.
    $this->profileDenied = $this->createUser([], 'profile_denied');
  }

  /**
   * Tests a field-level denial with an unresolvable trailing segment.
   *
   * [user:field_secret:value] denies at field_secret (forbidden) and cannot
   * complete the trailing ':value' segment structurally. The denied chain must
   * resolve to '' and never reach the fixture's tokens() hook.
   */
  public function testForbiddenFieldChainDoesNotLeakToLegacy(): void {
    $leaked = $this->tokenService->replace(
      '[user:field_secret:value]',
      ['user' => $this->owner],
      ['viewer' => $this->fieldDenied],
    );

    $this->assertSame('', $leaked, 'A field-denied chain with an unresolvable trailing segment resolves to an empty string.');
    $this->assertStringNotContainsString('MECHANISM-LEAK', $leaked, 'The forbidden field value never reaches the legacy pipeline.');
  }

  /**
   * Tests a root-level neutral denial with an unresolvable trailing segment.
   *
   * A viewer without 'access user profiles' gets a neutral (not forbidden)
   * view result on the owner entity. [user:field_open:value] cannot complete
   * the trailing ':value' segment, and the enforced-tier neutral denial must
   * still block the legacy fall-through.
   */
  public function testEnforcedNeutralDenialDoesNotLeakToLegacy(): void {
    $leaked = $this->tokenService->replace(
      '[user:field_open:value]',
      ['user' => $this->owner],
      ['viewer' => $this->profileDenied],
    );

    $this->assertSame('', $leaked, 'An enforced-tier neutral root denial blocks the legacy fall-through.');
    $this->assertStringNotContainsString('OPEN-LEAK', $leaked, 'The field value never reaches the legacy pipeline when the viewer cannot view the profile.');
  }

  /**
   * Tests a denied chain that completes structurally is dropped (control).
   *
   * [user:field_secret:0] denies at field_secret, then Rule C consumes the
   * literal '0' and completes the walk, so the engine returns a forbidden
   * result the access gate drops. This path was already correct before the
   * fix; it anchors that completing denied chains keep dropping the value.
   */
  public function testDeniedStructuralChainDropsValue(): void {
    $result = $this->tokenService->replace(
      '[user:field_secret:0]',
      ['user' => $this->owner],
      ['viewer' => $this->fieldDenied],
    );

    $this->assertSame('', $result, 'A denied chain that completes structurally resolves to an empty string.');
  }

  /**
   * Tests the owner still reads the values through the same spellings.
   *
   * The owner is allowed at both field and root level, so the trailing
   * ':value' chains fall through to the legacy pipeline exactly as before and
   * the values resolve. This proves the fix blocks only denied chains, not
   * legitimate legacy resolution.
   */
  public function testOwnerStillReadsValues(): void {
    $secret = $this->tokenService->replace(
      '[user:field_secret:value]',
      ['user' => $this->owner],
      ['viewer' => $this->owner],
    );
    $open = $this->tokenService->replace(
      '[user:field_open:value]',
      ['user' => $this->owner],
      ['viewer' => $this->owner],
    );

    $this->assertSame('MECHANISM-LEAK-42', $secret, 'The owner reads field_secret through the legacy pipeline.');
    $this->assertSame('OPEN-LEAK-42', $open, 'The owner reads field_open through the legacy pipeline.');
  }

}
