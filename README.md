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
output (defaults to the current user, but settable, e.g. the recipient of a
queued email). When the access check fails, the token renders empty and is never handed to the legacy hooks as a fallback (which would have produced the value with no access check).
The check is layered: root entity view access, traversed-to entities at
each `entity` deref, and field-level access on every read - the check token
systems classically omit (entity access does not imply field access), which is
how fields like `user.mail` leak. Each has a test that flips one permission,
with a negative control showing the leak when the guard is removed.

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
Gating is on presence, not on what changed, so editing content that already
holds a restricted token challenges an author who is not entitled to it. A
system-module `hook_entity_bundle_field_info_alter()` auto-attaches the
constraint to every string and text field, and it fires on form/API submission
(the untrusted path), leaving trusted programmatic writes alone. A chain that
cannot be statically walked to its leaf (a polymorphic reference) is blocked only
when the `system.token` `harden_placement` flag is on and the author lacks the
`place unverifiable tokens` permission. Tested, with the privileged-viewer chain
attack and a hardening/override pair, in
[`TokenPlacementConstraintTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementConstraintTest.php).

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
| [drupal #3587726](https://www.drupal.org/project/drupal/issues/3587726) | sensitive tokens placeable by low-privilege authors | placement / author | [`TokenPlacementConstraintTest`](core/modules/system/tests/src/Kernel/Token/TokenPlacementConstraintTest.php) |

The placement issues are the case resolution-access alone cannot close: the
viewer is entitled to the value, so only stopping the author from placing the
token prevents the leak.

One limit: the placement tests demonstrate the *mechanism* against
`[user:mail]` and entity-reference chains, which the gate walks to a field
token. The literal token in those three issues is `[current-user:mail]`, and the
gate does not yet cover it, because `current-user` is a legacy token-type alias
not mapped to an entity type (see Open questions). The mechanism is what would
gate it once that alias is mapped; it is not claimed as a complete fix today.

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

There are almost certainly other use-cases we need to solve for, this is a
work in progress.

## Open questions

- I chose to respect view permissions on the base entity in resolveChain but I'm
  pretty sure that will break BC, so I'd want a second opinion before we rely on it.

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
  that need scrutiny: legacy token-type aliases like `current-user` are not
  mapped to an entity type, so `[current-user:mail]` is not walked; only base
  fields on a chained-to entity type are field-access checked, not configurable
  ones; it covers entity text fields only, not config text (mail templates) or
  programmatic `Token::replace()`; and presence-gating means a low-privilege
  editor re-saving grandfathered content can be challenged for a token they did
  not add (intended, but contentious). How much of this to close, and whether a
  complementary render-time format filter should back it for defence in depth,
  is something we need to decide.

## Where the code lives

- Engine and value objects:
  [`core/lib/Drupal/Core/Token/`](core/lib/Drupal/Core/Token/). Key pieces: the
  [resolution engine](core/lib/Drupal/Core/Token/TokenResolutionEngine.php), the
  lazy [registry](core/lib/Drupal/Core/Token/TokenRegistry.php),
  [typed-data field discovery](core/lib/Drupal/Core/Token/Discovery/TypedDataFieldDiscovery.php),
  the [`#[Token]` attribute](core/lib/Drupal/Core/Token/Attribute/Token.php), and
  the [locale-aware renderer](core/lib/Drupal/Core/Token/TokenRenderer.php).
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
resolver registered as a `token.resolver` service. The legacy hook is retained
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
[`ArrayJoinPathToken`](modules/contrib/pathauto/src/Token/ArrayJoinPathToken.php),
registered in
[`pathauto.services.yml`](modules/contrib/pathauto/pathauto.services.yml).
Tested by
[`ArrayJoinPathTokenMigrationProofTest`](modules/contrib/pathauto/tests/src/Kernel/ArrayJoinPathTokenMigrationProofTest.php)
and
[`TokenEngineBackwardCompatibilityTest`](modules/contrib/pathauto/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php).

### ECA

Migrates `[node:entity_type]` (the entity type id) to
[`NodeEntityTypeToken`](modules/contrib/eca/src/Token/NodeEntityTypeToken.php),
registered in [`eca.services.yml`](modules/contrib/eca/eca.services.yml). ECA
adds an `entity_type` token to every content entity type via
`hook_token_info_alter()`; because a resolver is identified by a single
`(input_type, name)` pair, the migration is scoped to the `node` type and the
legacy hook still serves the others. Tested by
[`NodeEntityTypeTokenMigrationProofTest`](modules/contrib/eca/tests/src/Kernel/NodeEntityTypeTokenMigrationProofTest.php)
and
[`TokenEngineBackwardCompatibilityTest`](modules/contrib/eca/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php).

### webform

Migrates `[webform:id]` (the webform machine id) to
[`WebformIdToken`](modules/contrib/webform/src/Token/WebformIdToken.php),
registered in
[`webform.services.yml`](modules/contrib/webform/webform.services.yml). Tested by
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
