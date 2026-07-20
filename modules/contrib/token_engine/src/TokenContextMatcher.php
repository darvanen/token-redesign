<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Finds the tokens relevant to a set of available contexts.
 *
 * This is the entry point a context-aware token browser needs: a form, block,
 * or field widget advertises the contexts available where a token will be
 * placed (the same \Drupal\Core\Plugin\Context\ContextInterface objects the
 * rest of Drupal uses to describe ambient context), and this returns the token
 * chains that may start from those contexts. The browser then drills into each
 * via the registry's per-input-type slices, fetched lazily as the user expands.
 *
 * Matching is by the context's typed-data data type: an 'entity:node' context
 * surfaces both the auto-discovered field tokens (input type 'entity:node') and
 * the curated tokens registered under the token type ('node'), bridged by the
 * entity-type mapper so 'entity:taxonomy_term' correctly reaches the 'term'
 * tokens. Relevance is at the entity-type level, which is the bar that matters:
 * a node context never surfaces term tokens. Narrowing further by bundle is a
 * possible refinement.
 *
 * Context-free global tokens (site, date) are always available regardless of
 * context and are intentionally not returned here; they are a separate,
 * always-on list a browser adds on top.
 *
 * @internal
 */
final class TokenContextMatcher {

  public function __construct(
    private readonly TokenRegistryInterface $registry,
    private readonly TokenEntityTypeMapper $entityTypeMapper,
  ) {}

  /**
   * Returns the tokens that may start a chain given the available contexts.
   *
   * @param \Drupal\Core\Plugin\Context\ContextInterface[] $contexts
   *   The contexts available where tokens are being placed.
   *
   * @return array<string, array<string, \Drupal\token_engine\TokenDefinition>>
   *   Relevant token definitions keyed by token type, then by token name.
   */
  public function rootTokensForContexts(array $contexts): array {
    $result = [];
    foreach ($contexts as $context) {
      $dataType = $context->getContextDefinition()->getDataType();
      // Only entity contexts map to token roots in this version.
      if (!str_starts_with($dataType, 'entity:')) {
        continue;
      }
      $entityTypeId = substr($dataType, strlen('entity:'));
      $tokenType = $this->entityTypeMapper->getTokenType($entityTypeId);

      // Curated tokens live under the token type name; auto-discovered field
      // tokens live under the typed-data input type. Both are relevant to this
      // context, surfaced together under the token type.
      foreach ([$tokenType, $dataType] as $inputType) {
        $tokens = $this->registry->getTokensForInputType($inputType);
        if ($tokens !== []) {
          $result[$tokenType] = ($result[$tokenType] ?? []) + $tokens;
        }
      }
    }
    return $result;
  }

}
