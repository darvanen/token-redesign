<?php

declare(strict_types=1);

namespace Drupal\token_engine;

use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;

/**
 * Records viewer-less (unenforced) token resolution for the status report.
 *
 * The contrib replacement for the core-patch attempt's trigger_error()
 * deprecation: a contrib module has no removal-timeline lever, and firing a
 * deprecation on every legacy replace() call would drown real signals. Instead
 * every unenforced resolution increments a state-backed counter surfaced on
 * the status report, and at most one log entry per hour points site owners at
 * the callers that should start passing a viewer.
 *
 * State writes are batched per request (one write in the destructor), so the
 * hot path costs an in-memory increment.
 */
class UnenforcedUsageMonitor {

  public const STATE_COUNT = 'token_engine.unenforced_count';
  public const STATE_LAST_LOG = 'token_engine.unenforced_last_log';

  /**
   * Seconds between log entries.
   */
  private const LOG_INTERVAL = 3600;

  /**
   * Unenforced resolutions recorded this request, flushed on destruct.
   */
  private int $pending = 0;

  public function __construct(
    private readonly StateInterface $state,
    private readonly LoggerInterface $logger,
  ) {}

  /**
   * Records one viewer-less resolution batch.
   */
  public function record(): void {
    $this->pending++;
    if ($this->pending === 1) {
      $lastLog = (int) $this->state->get(self::STATE_LAST_LOG, 0);
      $now = time();
      if ($now - $lastLog >= self::LOG_INTERVAL) {
        $this->state->set(self::STATE_LAST_LOG, $now);
        $this->logger->notice('Token replacement ran without a viewer; output was produced with no access enforcement. Pass options[\'viewer\'] to Token::replace() calls. This entry is logged at most hourly; the status report shows the running total.');
      }
    }
  }

  /**
   * Returns the all-time count of unenforced resolution batches.
   */
  public function count(): int {
    return (int) $this->state->get(self::STATE_COUNT, 0) + $this->pending;
  }

  /**
   * Flushes this request's pending count into state.
   */
  public function __destruct() {
    if ($this->pending > 0) {
      $this->state->set(self::STATE_COUNT, (int) $this->state->get(self::STATE_COUNT, 0) + $this->pending);
      $this->pending = 0;
    }
  }

}
