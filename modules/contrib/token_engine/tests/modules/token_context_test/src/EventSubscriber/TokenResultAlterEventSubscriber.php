<?php

declare(strict_types=1);

namespace Drupal\token_context_test\EventSubscriber;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\token_engine\Event\TokenResultAlterEvent;
use Drupal\token_engine\TokenResult;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Configurable spy/alter subscriber for TokenResultAlterEvent kernel tests.
 *
 * Tests fetch this service from the container and set its public properties
 * before invoking token replacement, then assert on $calls afterwards. Every
 * dispatch is recorded in $calls regardless of configuration, which is what
 * proves (or disproves) that the event fired for a given token. When
 * $replaceRawToken matches the dispatched event's rawToken, the subscriber
 * replaces the result with $replacementValue, $cacheTags, and either allowed
 * or forbidden access depending on $forbidAccess.
 */
final class TokenResultAlterEventSubscriber implements EventSubscriberInterface {

  /**
   * Every dispatch this subscriber has observed, in call order.
   *
   * @var array<int, array{rawToken: string, type: string, name: string}>
   */
  public array $calls = [];

  /**
   * The raw token string to replace the result for, or NULL to only spy.
   */
  public ?string $replaceRawToken = NULL;

  /**
   * The value to set on the replacement TokenResult.
   */
  public string $replacementValue = '';

  /**
   * Cache tags to attach to the replacement TokenResult's cacheability.
   *
   * @var string[]
   */
  public array $cacheTags = [];

  /**
   * Whether the replacement TokenResult carries forbidden access.
   */
  public bool $forbidAccess = FALSE;

  /**
   * Records the dispatch and, if configured, replaces the result.
   */
  public function onResultAlter(TokenResultAlterEvent $event): void {
    $this->calls[] = [
      'rawToken' => $event->rawToken,
      'type' => $event->type,
      'name' => $event->name,
    ];

    if ($this->replaceRawToken === NULL || $event->rawToken !== $this->replaceRawToken) {
      return;
    }

    $cacheability = new CacheableMetadata();
    if ($this->cacheTags !== []) {
      $cacheability->addCacheTags($this->cacheTags);
    }

    $access = $this->forbidAccess
      ? AccessResult::forbidden('token_context_test subscriber forbids this token')
      : AccessResult::allowed();

    $event->setResult(new TokenResult($this->replacementValue, $cacheability, $access));
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      TokenResultAlterEvent::RESULT_ALTER => 'onResultAlter',
    ];
  }

}
