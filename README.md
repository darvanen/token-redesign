# Drupal token system redesign, packaged as a contrib module

A prototype redesign of Drupal's token system. It keeps the familiar
`[node:title]` syntax and every legacy `hook_tokens()` implementation working,
while replacing the engine underneath with something structural, efficient,
and access-aware.

**This branch is the contrib packaging.** The same engine that the
[`main` branch](https://github.com/darvanen/token-redesign/tree/main) builds
into Drupal core lives here as a standalone module,
[`token_engine`](modules/contrib/token_engine/), running on **completely
unpatched core** (pinned upstream commit, zero modifications). Both branches
exist so the core-vs-contrib question can be argued from working code instead
of intuition. The short version of what we learned: the contrib layer costs
nothing measurable at runtime, and it cannot close the security holes for
anyone who doesn't install it. Details in
[What this packaging cannot fix](#what-this-packaging-cannot-fix).

You can clone this and run it using [DDEV](https://ddev.com/get-started/).

This is just a proof of concept, it's not ready for thorough review of the
code, interrogate the ideas instead 🙏

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

## How a module takes over core's token system

The whole takeover is one service class swap plus one appended constructor
argument, and it is the part of this branch worth reading first:

- [`TokenEngineServiceProvider`](modules/contrib/token_engine/src/TokenEngineServiceProvider.php)
  alters the core `token` service. If the service still has core's class, it
  swaps in [`Drupal\token_engine\Token`](modules/contrib/token_engine/src/Token.php)
  (a subclass of core's) and appends the resolution engine argument. If another
  module already installed a subclass of ours, only the argument is appended.
  If some unknown class is there, it backs off entirely rather than break a
  stranger's constructor.
- The contrib **token** module's branch re-parents its own `Token` class onto
  ours, so its metadata API (`getInfo()` caching, the token browser) rides on
  top of engine dispatch. Its service provider runs first (alphabetical module
  order), sets its class, and ours detects the subclass and appends the engine.
- Everything else is ordinary contrib machinery: the placement gate attaches
  through `hook_entity_bundle_field_info_alter()`, the permission and settings
  form are the module's own, and `\Drupal::token()` callers notice nothing.

Core is the pinned upstream commit with zero changes. In this architecture the
baseline for any comparison is simply "module not enabled".

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
   caches them independently. A type's list stacks three sources: tokens declared
   by #[Token] classes, field tokens read automatically from an entity's fields,
   and an event to adjust the whole list at once (today's
   hook_token_info_alter). The full set is never built up front.
3. **Resolution** - the engine converts a chain plus a data context into a
   structured `TokenResult` carrying the value, cacheability, and an access
   result. Cacheability and access combine automatically as the chain is
   walked; providers only declare their own contribution. Entity values are
   translated to the resolution language at every boundary (root and each
   dereference), with the langcode precedence the ecosystem grew around:
   explicit option, then the passed object's own language, then the current
   content language - see
   [`TokenTranslationTest`](modules/contrib/token_engine/tests/src/Kernel/TokenTranslationTest.php).
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
an unenforced tier that reproduces legacy output exactly. The engine never
depends on the current user implicitly, and the BC test pins viewer-less
output byte-for-byte against the legacy bridge
([`TokenTieredEnforcementTest`](modules/contrib/token_engine/tests/src/Kernel/TokenTieredEnforcementTest.php)).

A contrib module has no deprecation-and-removal lever, so viewer-less usage is
made **visible instead of deprecated**: each request that resolves tokens
without a viewer increments a counter on the status report, with at most one
log entry per hour
([`UnenforcedUsageMonitor`](modules/contrib/token_engine/src/UnenforcedUsageMonitor.php)).
The core-patch branch fires a real deprecation with a removal target here;
this is one of the places the two packagings genuinely differ.

In the enforced tier, when the access check fails the token renders empty and
is never handed to the legacy hooks as a fallback (which would have produced
the value with no access check). The check is layered: root entity view
access, traversed-to entities at each `entity` deref, and field-level access
on every read - the check token systems classically omit (entity access does
not imply field access), which is how fields like `user.mail` leak.

Modules that post-process replacements have a successor to
`hook_tokens_alter()` for engine-resolved tokens:
[`TokenResultAlterEvent`](modules/contrib/token_engine/src/Event/TokenResultAlterEvent.php)
fires once per engine-resolved token after the chain's result is fully
composed but before the access gate and cacheability bubbling. Legacy-path
tokens do not fire this event - they keep flowing through
`hook_tokens_alter()` - so a module altering both kinds implements both until
its tokens finish migrating.

Rendering then decides how a value is emitted safely, per output context: HTML
escapes it, email subject collapses CR/LF to one line (no header injection),
URL slug transliterates and normalises to `[a-z0-9-]`, all locale-aware.

### Placement

Placement is the authoring-side counterpart: "may this author put this token
here?", checked at save time against the author, not the viewer. An
unprivileged author drops `[node:uid:entity:mail]` into reusable content; a
privileged user later views a page it renders on, the mail field-access check
passes for that viewer, and the value is exfiltrated through them. The viewer
was entitled to see it; the author was never entitled to place a token that
reads it.

The [`TokenPlacement`](modules/contrib/token_engine/src/Plugin/Validation/Constraint/TokenPlacementConstraintValidator.php)
constraint walks each token's chain against the definitions (no entity is
loaded) and blocks a save when any step exposes data the author cannot
access. The walker mirrors the engine's traversal rules, pinned by
[`TokenPlacementTraversalParityTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementTraversalParityTest.php).

The hardening posture is **ask-at-install**, and this differs from the core
branch by necessity. Core can ship new installs hardened and grandfather
existing sites through an update hook; a contrib module installs on existing
sites only, so it ships no default at all. The status report carries an error
until a site owner chooses on the settings form
(`/admin/config/system/token-engine`), and while undecided the gate leans
grandfathered so installing the module never breaks an editor's save. The
always-on branch is unaffected in every posture: a chain that verifiably
reaches data the author cannot access blocks at save regardless - that is the
security fix itself. Tested in
[`TokenPlacementUndecidedPostureTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementUndecidedPostureTest.php)
and
[`TokenPlacementConstraintTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementConstraintTest.php).

### Reported issues this maps to

| Issue | Vector | Layer | Test |
|---|---|---|---|
| [core #3489852](https://www.drupal.org/project/drupal/issues/3489852) | "Token replace system has no access checking" (the umbrella) | both | the suites below |
| [token #3593501](https://www.drupal.org/project/token/issues/3593501) | field value chained through an entity reference leaks a restricted entity | resolution / viewer | [`TokenEntityFieldChainTest`](modules/contrib/token_engine/tests/src/Kernel/TokenEntityFieldChainTest.php) |
| [redirect #3591834](https://www.drupal.org/project/redirect/issues/3591834) | a token discloses a field of an entity the user cannot access | resolution / viewer | [`TokenEntityFieldChainTest`](modules/contrib/token_engine/tests/src/Kernel/TokenEntityFieldChainTest.php) |
| [token_filter #3587719](https://www.drupal.org/project/token_filter/issues/3587719) | author embeds `[…:mail]` in markup; exfiltrated when a privileged user views it | placement / author | [`TokenPlacementConstraintTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementConstraintTest.php) |
| [metatag #3587720](https://www.drupal.org/project/metatag/issues/3587720) | same, in metatag fields | placement / author | [`TokenPlacementConstraintTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementConstraintTest.php) |
| [drupal #3587726](https://www.drupal.org/project/drupal/issues/3587726) | sensitive tokens placeable by low-privilege authors | placement / author | [`TokenPlacementCurrentUserTest`](modules/contrib/token_engine/tests/src/Kernel/TokenPlacementCurrentUserTest.php) (exact `[current-user:mail]` repro) |

**The important caveat for this branch: every protection above applies only to
sites that install the module.** Which brings us to:

## What this packaging cannot fix

Built honestly from what we hit while making this branch work. Each of these
is structural, not a matter of more code:

1. **The sites that don't install it.** The driving issues are core security
   bugs; an opt-in module cannot close them for the ecosystem, and the
   security team cannot mark them fixed because a module exists.
2. **Other modules' Token subclasses silently missing the engine.** In core,
   the engine lives in the one class everybody already extends. As contrib,
   any module extending `Drupal\Core\Utility\Token` directly gets an object
   without the engine on it, and it fails quietly. This happened for real
   during the build: ECA's token decorator inherited the decorated service's
   constructor arguments, core's five-parameter constructor silently dropped
   the appended engine, and everything kept "working" minus the batch API and
   output-context defaults. We fixed ECA by hand
   ([the commit](modules/contrib/eca/) re-parents its `CoreToken`); we cannot
   fix the pattern for an ecosystem. Core makes this bug class impossible by
   construction.
3. **Core's own callers never pass a viewer.** Core's mail and user call sites
   invoke replacement without a viewer, and contrib cannot change core call
   sites, so everything core itself replaces stays on the unenforced tier. We
   count it; we cannot fix it.
4. **No way to retire the unsafe path.** Core deprecates, publishes a change
   record, and removes in a major. Contrib's strongest pressure is a status
   report warning a site owner can ignore forever.
5. **Core's own tokens stay legacy.** `[node:title]`, `[user:mail]` and
   friends keep resolving through the old hook pipeline with no per-token
   access metadata, except where they appear mid-chain. Shadowing them from
   contrib would mean maintaining a drifting copy of core's definitions; we
   deliberately did not.
6. **Safe-by-default for new sites.** Core can make every new Drupal install
   hardened out of the box. Contrib cannot tell a new site from an old one,
   which is exactly why the posture is ask-at-install.

The one-line version: contrib proves the architecture and protects the sites
that opt in, at no measured runtime cost; only core protects everyone, covers
its own callers, and removes the silent-bypass bug class. That is the case
for contrib-as-incubator rather than contrib-as-destination.

## Backward compatibility

This is not a parallel system behind a flag. The new engine is a superset of
the old one: the legacy hook system still works, bridged into the engine
rather than replaced.

- Token syntax is unchanged, and `\Drupal::token()` callers change nothing.
- `hook_token_info()` and `hook_tokens()` keep working, untouched. The engine
  only takes a token when there's an explicit resolver for it, or when it's a
  field the legacy hooks never exposed. Auto-discovered fields never shadow a
  token a hook already defines.
- Chains the engine cannot complete fall back to the legacy pipeline, which is
  why contrib token's field-token hooks are deliberately retained on its
  branch: they still serve single-segment tokens, formatter-based field
  rendering, and image-style derivatives.
- `#[Token]` identity collisions are deterministic and logged, pinned in
  [`TokenIdentityConflictTest`](modules/contrib/token_engine/tests/src/Kernel/TokenIdentityConflictTest.php).
- The escaping seam between legacy and engine replacements in one string is
  test-proven in
  [`TokenMixedProvenanceEscapingTest`](modules/contrib/token_engine/tests/src/Kernel/TokenMixedProvenanceEscapingTest.php).

## Performance

Full results and methodology in [BENCHMARKS.md](BENCHMARKS.md); section B4 is
this branch. The two headline facts:

- **The contrib layer is performance-neutral.** Engine-as-contrib measures
  within run noise of engine-in-core on every scenario. The takeover really is
  just a class swap.
- **The structural win is unchanged:** on an 80-language site, multi-segment
  field chains resolve roughly an order of magnitude faster than the legacy
  hook pipeline for identical translated output, and the batch API
  (`replaceMultiple()`) adds about another 5x on metatag-shaped workloads. The
  cost side is also unchanged: access enforcement roughly doubles enforced
  replacement, and legacy-routed tokens carry the engine's routing overhead.

## See it running

```bash
ddev start
ddev drush si standard --account-name=admin -y
ddev drush en token_engine token pathauto -y
ddev drush uli
```

Then:

1. **Status report** (`/admin/reports/status`): a red "Token Engine placement
   hardening: Not decided" row (the ask-at-install posture), and a "Token
   Engine access enforcement" row counting viewer-less replacements as they
   happen.
2. **Settings** (`/admin/config/system/token-engine`): choose a posture, watch
   the red row clear.
3. **The placement gate**: as an editor without `administer users`, put
   `[current-user:mail]` in an article body. Save is denied with a validation
   error - issue #3587726's exact attack, blocked at save. As admin it saves.
4. **An engine chain in the wild**: add a pathauto pattern like
   `articles/[node:uid:entity:name]/[node:title]` and create an article; the
   alias segment for the author's name is resolved structurally by the engine.
5. **Per-viewer enforcement**:

```bash
ddev drush ev '$n = \Drupal\node\Entity\Node::load(1); $t = \Drupal::token();
print "admin:  " . $t->replace("[node:uid:entity:mail]", ["node" => $n], ["viewer" => \Drupal\user\Entity\User::load(1), "clear" => TRUE]) . "\n";
print "other:  [" . $t->replace("[node:uid:entity:mail]", ["node" => $n], ["viewer" => user_load_by_name("editor"), "clear" => TRUE]) . "]\n";'
```

Same token, two viewers, two answers: the value for the entitled account,
an empty string for the other - dropped, never leaked, never handed to legacy.

## Where the code lives

- The module: [`modules/contrib/token_engine/`](modules/contrib/token_engine/).
  Key pieces: the
  [resolution engine](modules/contrib/token_engine/src/TokenResolutionEngine.php),
  the lazy [registry](modules/contrib/token_engine/src/TokenRegistry.php),
  [typed-data field discovery](modules/contrib/token_engine/src/Discovery/TypedDataFieldDiscovery.php),
  the [`#[Token]` attribute](modules/contrib/token_engine/src/Attribute/Token.php),
  the [renderer](modules/contrib/token_engine/src/TokenRenderer.php), the
  [service takeover](modules/contrib/token_engine/src/TokenEngineServiceProvider.php),
  and the [placement gate](modules/contrib/token_engine/src/Plugin/Validation/Constraint/).
- Tests: [kernel](modules/contrib/token_engine/tests/src/Kernel/) and
  [unit](modules/contrib/token_engine/tests/src/Unit/), 178 green against
  unpatched core.
- Core: unmodified upstream. `git log core` shows nothing of ours.

## Contrib consumers

The contrib **token** module is the primary consumer on this branch: it
depends on token_engine, re-parents its service class onto the engine's, and
registers its field-derived token definitions through the discovery alter
event. Its `hook_tokens()` machinery is deliberately retained (see Backward
compatibility). Its test suite runs at exact parity with the core-patch
branch: same three pre-existing failures, and the multilingual field test
passes here too.

The three canary migrations from the core-patch branch carry over with their
namespaces and dependencies updated, each keeping its four-part proof test:
pathauto's [`ArrayJoinPathToken`](modules/contrib/pathauto/src/Plugin/Token/ArrayJoinPathToken.php),
ECA's [`NodeEntityTypeToken`](modules/contrib/eca/src/Plugin/Token/NodeEntityTypeToken.php)
(plus the generic `EntityTypeToken` at the `entity` input type), and webform's
[`WebformIdToken`](modules/contrib/webform/src/Plugin/Token/WebformIdToken.php).

These modules are pinned clones; their broader suites carry unrelated Drupal
12 incompatibilities. Run the specific files below, not the full module
suites.

## Running the tests

```bash
# The whole engine surface (discovery, resolution, rendering, access, placement).
ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/token_engine/tests/src/Kernel/ \
  modules/contrib/token_engine/tests/src/Unit/ \
  --exclude-group TokenBenchmark"

# Contrib token on the engine.
ddev exec "php vendor/bin/phpunit -c core modules/contrib/token/tests/src/Kernel/"

# Canary migration + backward-compatibility proofs.
ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/pathauto/tests/src/Kernel/ArrayJoinPathTokenMigrationProofTest.php \
  modules/contrib/pathauto/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php \
  modules/contrib/eca/tests/src/Kernel/NodeEntityTypeTokenMigrationProofTest.php \
  modules/contrib/eca/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php \
  modules/contrib/webform/tests/src/Kernel/WebformIdTokenMigrationProofTest.php \
  modules/contrib/webform/tests/src/Kernel/TokenEngineBackwardCompatibilityTest.php"

# Benchmarks (see BENCHMARKS.md section B4 for the module-off baseline recipe).
TOKEN_BENCH_LABEL=engine ddev exec "php vendor/bin/phpunit -c core \
  modules/contrib/token_engine/tests/src/Kernel/TokenMultilingualEngineBenchmark.php"
```

## Status

While this took a lot of time and effort to put together (obviously
AI-assisted), it is by no means a solved case. This branch exists to make the
core-vs-contrib argument concrete; the architecture itself can be thrown out
if it's not fit for purpose, please don't hold back.

Join the #token channel on Drupal Slack to discuss.
