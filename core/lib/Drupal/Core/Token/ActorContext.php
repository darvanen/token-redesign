<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Core\Session\AccountInterface;

/**
 * Carries the viewer through token resolution.
 *
 * Access is checked against the viewer, the account that will see the rendered
 * output, rather than the current request user. A token placed by an admin but
 * rendered for an anonymous visitor must be access-checked against that visitor,
 * which is what closes the documented exfiltration vulnerabilities.
 *
 * Never assume the current user. Always carry this explicitly through the
 * resolution chain.
 *
 * A NULL viewer means the unenforced tier: legacy-equivalent resolution with no
 * access enforcement anywhere in the chain. This is what a caller gets when it
 * supplies neither 'viewer' nor 'token_actor' to Token::replace(); it exists
 * for BC with callers that predate the actor model (cron mail, drush, bulk
 * operations) and is deprecated (see TokenResolutionEngine::resolveActor()).
 *
 * Controlling which tokens an author is allowed to *place* in content is a
 * separate, placement-time concern (a text filter / field constraint), not a
 * resolution-time access check, so it is intentionally not modelled here.
 */
final class ActorContext {

  /**
   * @param \Drupal\Core\Session\AccountInterface|null $viewer
   *   The account that will see the rendered output, or NULL for the
   *   unenforced tier.
   */
  public function __construct(
    public readonly ?AccountInterface $viewer,
  ) {}

  /**
   * Creates a context for the given viewer.
   *
   * A convenience for the common case where the caller has a single account to
   * resolve against (e.g. programmatic replacement or tests).
   */
  public static function fromSingleActor(AccountInterface $account): static {
    return new static($account);
  }

  /**
   * Returns TRUE when this context enforces access.
   *
   * FALSE only for a NULL viewer (the unenforced tier); every resolver-level
   * view-access check in the engine is gated on this.
   */
  public function isEnforced(): bool {
    return $this->viewer !== NULL;
  }

}
