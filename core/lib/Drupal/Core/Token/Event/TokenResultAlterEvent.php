<?php

declare(strict_types=1);

namespace Drupal\Core\Token\Event;

use Drupal\Core\Token\TokenResolutionContext;
use Drupal\Core\Token\TokenResult;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event fired after an engine-resolved token's result is final.
 *
 * This is the canonical extension point replacing hook_tokens_alter() for
 * tokens resolved structurally by the engine: it fires once per
 * engine-resolved token (including tokens whose chain was resumed from a
 * memoized prefix), immediately after the composed TokenResult is final —
 * root-activation contributions merged in, where applicable — but strictly
 * before the caller's BubbleableMetadata composition, the access gate, and
 * rendering. Tokens that fall through to the legacy hook pipeline do not fire
 * this event; they keep flowing through hook_tokens_alter() exactly as
 * before. Modules migrating an alter hook to cover engine-resolved tokens
 * subscribe to this event; their existing hook_tokens_alter() implementation
 * keeps covering whatever remains legacy.
 *
 * A subscriber that wants to replace the value must supply a whole new
 * TokenResult via setResult(), not a bare value, because the engine's merge
 * discipline requires every contributor to state its own cacheability and
 * access rather than silently inheriting the previous contributor's. The
 * naive-but-safe recipe for a subscriber that only cares about the value and
 * is happy to be unconditionally cacheable and allowed is:
 * @code
 * $event->setResult(TokenResult::fromValue($newValue));
 * @endcode
 *
 * This is also, deliberately, exactly as dangerous as hook_tokens_alter()
 * always was: a subscriber can set an access result that is MORE permissive
 * than the one it replaces, widening access to a value the resolver chain
 * itself would have denied. Nothing here closes that door; it is the same
 * trust granted to any alter hook. What differs from a resolver's own
 * contribution is only when the consequences of that trust are applied: the
 * engine's access gate and cacheability bubbling both run AFTER this event
 * returns, against the TokenResult the subscriber leaves behind. So a
 * subscriber's own AccessResult::forbidden() still drops the value to an
 * empty string exactly as it would for a resolver, and the subscriber's own
 * cacheability always bubbles into the caller's BubbleableMetadata, whether
 * or not access is ultimately allowed.
 *
 * @see \Drupal\Core\Token\TokenResolutionEngine::generateWithMemo()
 * @see \Drupal\Core\Token\TokenResult
 */
final class TokenResultAlterEvent extends Event {

  /**
   * The event name dispatched by the engine after a token result is final.
   */
  const RESULT_ALTER = 'token.result.alter';

  /**
   * @param string $rawToken
   *   The raw token string as it appeared in the source text, e.g.
   *   '[node:uid:entity:mail]'.
   * @param string $type
   *   The token type being replaced, e.g. 'node'.
   * @param string $name
   *   The token name within $type, e.g. 'uid:entity:mail'.
   * @param \Drupal\Core\Token\TokenResolutionContext $context
   *   The resolution context the chain was walked with, including the actor
   *   the value was access-checked against.
   * @param \Drupal\Core\Token\TokenResult $result
   *   The final composed result. Use getResult() to read it and setResult()
   *   to replace it.
   */
  public function __construct(
    public readonly string $rawToken,
    public readonly string $type,
    public readonly string $name,
    public readonly TokenResolutionContext $context,
    private TokenResult $result,
  ) {}

  /**
   * Returns the current result.
   *
   * @return \Drupal\Core\Token\TokenResult
   *   The current result, reflecting any prior subscriber's setResult() call.
   */
  public function getResult(): TokenResult {
    return $this->result;
  }

  /**
   * Replaces the result.
   *
   * @param \Drupal\Core\Token\TokenResult $result
   *   The replacement result. Must state its own cacheability and access; see
   *   the class docblock for the naive-but-safe recipe and the access-gate
   *   warning.
   */
  public function setResult(TokenResult $result): void {
    $this->result = $result;
  }

}
