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
 * Controlling which tokens an author is allowed to *place* in content is a
 * separate, placement-time concern (a text filter / field constraint), not a
 * resolution-time access check, so it is intentionally not modelled here.
 */
final class ActorContext {

  /**
   * @param \Drupal\Core\Session\AccountInterface $viewer
   *   The account that will see the rendered output.
   */
  public function __construct(
    public readonly AccountInterface $viewer,
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

}
