<?php

declare(strict_types=1);

namespace Drupal\token_engine\Plugin\Token;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\token_engine\Attribute\Token;
use Drupal\token_engine\TokenResolutionContext;
use Drupal\token_engine\TokenResolverInterface;
use Drupal\token_engine\TokenResult;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Attributed root resolver for the [current-user] token.
 *
 * A root token (input_type '') requires no input object; its plugin ID is
 * ":current-user". The acting viewer, not the current_user service, is the
 * account this token means: $context->actor->viewer is the account the
 * rendered output is for, which stays correct once tiered actor resolution
 * (a viewer distinct from the request's current user) lands. Loading the full
 * entity lets the chain continue into user fields exactly like the legacy
 * [current-user:mail] path, which resolves 'current-user' to 'user' and loads
 * the same entity via the current-user account ID.
 *
 * Resolution is two-tiered on the actor. In the enforced tier (a viewer is
 * present; see ActorContext::isEnforced()), TokenResolutionEngine's root
 * branch in generateWithMemo() consults getResolvableToken('', $type) and,
 * when it finds this definition, calls resolve() directly to bind the viewer
 * before walking the rest of the chain from 'entity:user' -- so
 * [current-user:*] is resolution-live and access-checked in that tier. In the
 * unenforced tier (no viewer; the legacy-compatible default) the engine skips
 * root activation entirely, so [current-user:*] falls through unchanged to
 * the legacy hook pipeline, byte-identical to pre-existing behaviour. What
 * registering this plugin changed immediately, in both tiers, is placement:
 * the token placement validator's root lookup (getResolvableToken('', $type))
 * finds this definition and can walk the chain into 'entity:user' field
 * access, closing the gap where [current-user:*] chains were previously
 * ungated.
 *
 * @see https://www.drupal.org/project/drupal/issues/3587726
 */
#[Token(
  name: 'current-user',
  input_type: '',
  output_type: 'entity:user',
  label: new TranslatableMarkup('Current user'),
  description: new TranslatableMarkup('The currently logged in user.'),
)]
final class CurrentUserToken implements TokenResolverInterface, ContainerFactoryPluginInterface {

  /**
   * Constructs a CurrentUserToken.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager, used to load the viewer's user entity.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function resolve(mixed $input, array $arguments, TokenResolutionContext $context): TokenResult {
    if (!$context->actor->isEnforced()) {
      // Defence in depth: the engine's root-activation gate in
      // generateWithMemo() only calls this resolver when $actor->isEnforced()
      // is TRUE, so $context->actor->viewer is never NULL in the codepath
      // that reaches here today. Guarding it anyway means a future caller
      // that invokes this resolver directly (bypassing that gate) gets the
      // same NULL-value, neutral-access shape as a missing entity below,
      // rather than a fatal error dereferencing a NULL viewer.
      $cacheability = new CacheableMetadata();
      $cacheability->addCacheContexts(['user']);
      return new TokenResult(
        value: NULL,
        cacheability: $cacheability,
        access: AccessResult::neutral()->addCacheContexts(['user']),
        outputType: 'entity:user',
      );
    }

    $viewer = $context->actor->viewer;

    $cacheability = new CacheableMetadata();
    $cacheability->addCacheContexts(['user']);

    $entity = $this->entityTypeManager->getStorage('user')->load($viewer->id());

    if ($entity === NULL) {
      // The viewer has no backing user entity (e.g. anonymous before
      // user_install() seeds uid 0). Nothing to chain into or check access
      // against.
      return new TokenResult(
        value: NULL,
        cacheability: $cacheability,
        access: AccessResult::neutral()->addCacheContexts(['user']),
        outputType: 'entity:user',
      );
    }

    $cacheability->addCacheableDependency($entity);

    // Self-view is allowed by core's user access control handler, so the
    // common case (a user's own [current-user:*] output) matches legacy
    // behaviour, which performs no access check at all.
    return new TokenResult(
      value: $entity,
      cacheability: $cacheability,
      access: $entity->access('view', $viewer, TRUE),
      outputType: 'entity:user',
    );
  }

}
