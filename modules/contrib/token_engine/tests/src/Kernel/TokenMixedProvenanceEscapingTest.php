<?php

declare(strict_types=1);

namespace Drupal\Tests\token_engine\Kernel;

use Drupal\Component\Utility\Html;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\Tests\node\Traits\ContentTypeCreationTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Proves each provenance is escaped exactly once when mixed in one string.
 *
 * A single token-bearing string can contain both a legacy hook_tokens() token
 * (routed through LegacyTokenBridge, returning a raw string the pipeline must
 * escape) and an engine-path token served by an attributed resolver (routed
 * through TokenResolutionEngine::generateWithMemo(), which - per its own
 * comment - already escapes the value via TokenRenderer for the HTML output
 * context and wraps it in Markup::create() so the pipeline does not escape it
 * again). The two provenances reach Token::doReplace() through different
 * code paths but must produce the same "escaped exactly once" guarantee:
 *  - Legacy: doReplace() wraps a non-MarkupInterface value in
 *    \Drupal\Component\Render\HtmlEscapedText, whose __toString() calls
 *    \Drupal\Component\Utility\Html::escape() (htmlspecialchars(ENT_QUOTES |
 *    ENT_SUBSTITUTE)) - one escape.
 *  - Engine: TokenRenderer::renderString() applies the identical
 *    htmlspecialchars() call for OutputContext::Html, and
 *    TokenResolutionEngine::generateWithMemo() then wraps the already-escaped
 *    string in Markup::create() so doReplace()'s
 *    "instanceof MarkupInterface" check treats it as pre-sanitised and does
 *    not escape it a second time - also one escape.
 *
 * Both mechanisms bottom out in the same htmlspecialchars() call, so a
 * correctly-behaving pipeline must produce byte-identical escaped output for
 * the same raw payload regardless of provenance. That equivalence is the
 * oracle used throughout this test: expected values are computed with
 * \Drupal\Component\Utility\Html::escape(), never copied from observed
 * engine output.
 *
 * The "engine-path field-chain token" fixture is a real multi-value 'string'
 * field discovered by TypedDataFieldDiscovery and read through
 * FieldValueToken + ListDeltaResolver (token spelling '[node:field_multi:0]',
 * two segments, so it routes to entity:node per generateWithMemo()'s
 * multi-segment rule rather than being shadowed by the legacy bridge). No new
 * fixture resolvers were needed in token_context_test: real field storage
 * already produces arbitrary HTML-special values, and node.tokens.inc's
 * '[node:title]' and '[node:body]' already exercise the legacy hook path
 * (the latter returning a MarkupInterface value once run through the
 * 'plain_text' filter, which is exactly the "legacy hook returns Markup"
 * scenario).
 *
 * @see \Drupal\token_engine\TokenRenderer
 * @see \Drupal\token_engine\TokenResolutionEngine::generateWithMemo()
 * @see \Drupal\Core\Utility\Token::doReplace()
 * @see \Drupal\Component\Render\HtmlEscapedText
 * @see \Drupal\Component\Utility\Xss::filter()
 */
#[CoversClass(\Drupal\Core\Utility\Token::class)]
#[Group('Token')]
#[RunTestsInSeparateProcesses]
class TokenMixedProvenanceEscapingTest extends TokenReplaceKernelTestBase {

  use ContentTypeCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['node', 'field', 'filter', 'text'];

  /**
   * A legacy-token payload: HTML tag, ampersand, and quotes.
   *
   * Resolved via node.tokens.inc's '[node:title]', which is a single-segment
   * curated token and therefore always routed to the legacy bridge (see
   * TokenEntityFieldChainTest::testSingleSegmentFieldTokenStaysLegacy() and
   * generateWithMemo()'s "single-segment curated legacy tokens ... never
   * shadowed" rule). hook_tokens() returns $node->getTitle() unmodified, i.e.
   * a raw, unescaped string.
   */
  private const TITLE_PAYLOAD = '<em>Aa & "Bb"</em>';

  /**
   * An engine-path payload: a script tag and an ampersand.
   *
   * Resolved via '[node:field_multi:0]' (FieldValueToken + ListDeltaResolver),
   * which TokenRenderer escapes for the HTML context before the engine marks
   * it safe.
   */
  private const FIELD_PAYLOAD = '<script>alert(1)</script> & "field"';

  /**
   * A legacy body payload that hook_tokens() returns as MarkupInterface.
   *
   * '[node:body]' with format 'plain_text' resolves through FilterHtmlEscape,
   * which does trim(Html::escape($text)) and returns a FilteredMarkup (a
   * MarkupInterface) - i.e. a legacy token that itself returns pre-sanitised
   * markup, exactly like a module author who runs Xss::filter() before
   * returning a hook_tokens() replacement.
   */
  private const BODY_PAYLOAD = '<em>Legacy body</em> & <script>xss()</script>';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['filter', 'node']);
    $this->installEntitySchema('node');
    $this->createContentType(['type' => 'article']);

    // A multi-value 'string' field on 'article', discovered by
    // TypedDataFieldDiscovery under the 'entity:node' input type. Two or more
    // segments ('field_multi' + a delta) are required to reach the engine;
    // see TokenCardinalityChangeTest's note on single-segment field tokens
    // staying on the legacy bridge.
    FieldStorageConfig::create([
      'field_name' => 'field_multi',
      'entity_type' => 'node',
      'type' => 'string',
      'cardinality' => FieldStorageConfig::CARDINALITY_UNLIMITED,
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_multi',
      'entity_type' => 'node',
      'bundle' => 'article',
      'label' => 'Multi-value probe field',
    ])->save();
  }

  /**
   * Creates an article with a title, one field_multi value, and optionally a body.
   */
  private function createArticle(string $title, string $fieldMultiValue, ?string $bodyValue = NULL): NodeInterface {
    $values = [
      'type' => 'article',
      'title' => $title,
      'status' => 1,
      'field_multi' => [['value' => $fieldMultiValue]],
    ];
    if ($bodyValue !== NULL) {
      $values['body'] = [['value' => $bodyValue, 'format' => 'plain_text']];
    }
    $node = Node::create($values);
    $node->save();
    return $node;
  }

  /**
   * Scenario 1: legacy + engine tokens via replace() (HTML context).
   *
   * Each provenance must be escaped exactly once: the legacy '[node:title]'
   * value via HtmlEscapedText, the engine '[node:field_multi:0]' value via
   * TokenRenderer + Markup::create(). Neither the raw '<script>' tag nor a
   * doubled entity ('&amp;amp;', '&amp;lt;') may appear in the output.
   */
  public function testHtmlContextEscapesEachProvenanceExactlyOnce(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle(self::TITLE_PAYLOAD, self::FIELD_PAYLOAD);

    $result = $this->tokenService->replace(
      '[node:title] :: [node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $expected = Html::escape(self::TITLE_PAYLOAD) . ' :: ' . Html::escape(self::FIELD_PAYLOAD);
    $this->assertSame($expected, $result, 'Legacy and engine provenance are each escaped exactly once, matching Html::escape() applied once per payload.');
    $this->assertStringNotContainsString('<script>', $result, 'The engine-path script tag must not survive unescaped.');
    $this->assertStringNotContainsString('&amp;amp;', $result, 'The legacy ampersand must not be escaped twice.');
    $this->assertStringNotContainsString('&amp;lt;', $result, 'The engine-path "<" must not be escaped twice.');
    $this->assertStringNotContainsString('&amp;quot;', $result, 'Quotes from either provenance must not be escaped twice.');
  }

  /**
   * Scenario 2: legacy + engine tokens via replacePlain() (plain-text context).
   *
   * Per TokenRenderer's class docs ("string: identity (already a string)")
   * and its renderString() match arm (`OutputContext::PlainText => $value`),
   * a generic string value is passed through unchanged for the plain-text
   * context - it is not entity-encoded. doReplace(FALSE, ...) mirrors this
   * for the legacy path: a non-MarkupInterface value is used as-is. So both
   * provenances must come back byte-identical to their raw payloads, with no
   * entities introduced by either path.
   */
  public function testPlainTextContextIntroducesNoEntities(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle(self::TITLE_PAYLOAD, self::FIELD_PAYLOAD);

    $result = $this->tokenService->replacePlain(
      '[node:title] :: [node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $expected = self::TITLE_PAYLOAD . ' :: ' . self::FIELD_PAYLOAD;
    $this->assertSame($expected, $result, 'Plain-text context leaves both legacy and engine-path values exactly as-is.');
    $this->assertStringNotContainsString('&amp;', $result, 'No ampersand entity is introduced in plain-text context.');
    $this->assertStringNotContainsString('&lt;', $result, 'No "<" entity is introduced in plain-text context.');
    $this->assertStringContainsString('<script>', $result, 'The raw tag is passed through unmodified, per the "string: identity" contract.');
  }

  /**
   * Scenario 3: a legacy hook token that itself returns Markup, mixed with an engine token.
   *
   * '[node:body]' (format 'plain_text') resolves to a FilteredMarkup value -
   * i.e. the legacy hook already escaped/sanitised it before returning it.
   * doReplace()'s "instanceof MarkupInterface" check must let it through
   * unescaped a second time, exactly as it does for the engine's
   * Markup::create()-wrapped value. Mixing the two in one string proves the
   * "already-safe" contract is honoured identically regardless of which code
   * path produced the MarkupInterface instance.
   */
  public function testLegacyMarkupIsNotDoubleEscapedAlongsideEngineToken(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle('Body node', self::FIELD_PAYLOAD, self::BODY_PAYLOAD);

    $result = $this->tokenService->replace(
      '[node:body] + [node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $expectedBody = (string) $node->body->processed;
    $expectedField = Html::escape(self::FIELD_PAYLOAD);
    $this->assertSame($expectedBody . ' + ' . $expectedField, $result, 'The pre-sanitised legacy Markup value passes through untouched; the engine value is escaped exactly once.');
    $this->assertStringNotContainsString('&amp;lt;', $result, 'The legacy Markup value must not be re-escaped.');
    $this->assertStringNotContainsString('&amp;amp;', $result, 'Neither provenance is escaped twice.');
  }

  /**
   * Scenario 4: the replace() result used as '#markup' must not double-escape.
   *
   * doReplace() returns a plain PHP string (str_replace() on a mix of
   * MarkupInterface and HtmlEscapedText objects coerces everything to
   * strings), so assigning it to '#markup' runs it through
   * Renderer::ensureMarkupIsSafe(), which XSS-filters it with the admin tag
   * list. Xss::filter() is specifically idempotent on well-formed entities:
   * it "defuses" every literal '&' to '&amp;' and then restores only
   * recognised named/numeric entities (e.g. '&amp;amp;' -> '&amp;',
   * '&amp;lt;' -> '&lt;'). Because both provenances were escaped with
   * standard htmlspecialchars(ENT_QUOTES) entities, rendering must reproduce
   * the same string byte-for-byte - proving neither provenance is
   * double-escaped by the render pipeline.
   */
  public function testMarkupRenderDoesNotDoubleEscapeEitherProvenance(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle(self::TITLE_PAYLOAD, self::FIELD_PAYLOAD);

    $result = $this->tokenService->replace(
      '[node:title] :: [node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $build = ['#markup' => $result];
    $rendered = (string) $this->container->get('renderer')->renderInIsolation($build);

    $this->assertSame($result, $rendered, 'Rendering the replace() result as #markup reproduces it exactly: Xss::filter() round-trips well-formed entities from either provenance without altering them.');
    $this->assertStringNotContainsString('&amp;amp;', $rendered, 'Rendering must not double-escape the legacy ampersand.');
    $this->assertStringNotContainsString('&amp;lt;', $rendered, 'Rendering must not double-escape the engine-path "<".');
  }

  /**
   * Scenario 5: adjacent tokens with no separator splice cleanly at the seam.
   *
   * doReplace() performs one str_replace() call across all raw token strings
   * and their replacement values. With no literal text between two tokens,
   * a splicing defect (lost or duplicated bytes at the boundary) would show
   * up as a corrupted concatenation. The expected value is computed
   * independently per payload and concatenated, so this pins the exact
   * byte sequence at the seam between a legacy-escaped value and an
   * engine-escaped value.
   */
  public function testAdjacentTokensSpliceCleanlyWithNoSeparator(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle(self::TITLE_PAYLOAD, self::FIELD_PAYLOAD);

    $result = $this->tokenService->replace(
      '[node:title][node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin],
    );

    $expected = Html::escape(self::TITLE_PAYLOAD) . Html::escape(self::FIELD_PAYLOAD);
    $this->assertSame($expected, $result, 'Adjacent tokens with no separator splice without losing or duplicating bytes at the seam.');
  }

  /**
   * Extra seam: the 'callback' option sees each provenance already escaped once.
   *
   * doReplace() invokes options['callback'] AFTER the markup/HtmlEscapedText
   * wrapping loop and BEFORE the final str_replace(), with no further
   * escaping step afterwards. A callback that concatenates onto an existing
   * replacement value (a common real-world pattern, e.g. appending a suffix)
   * forces PHP to stringify the MarkupInterface/HtmlEscapedText object via
   * __toString() - which is exactly where the single escape happens for
   * each provenance. This proves the callback extension point observes a
   * stable "escaped exactly once" value regardless of provenance, and that
   * mutating it afterwards does not trigger a second escaping pass.
   */
  public function testCallbackOptionSeesSingleEscapedValuesFromBothProvenances(): void {
    $admin = $this->createUser([], NULL, TRUE);
    $node = $this->createArticle(self::TITLE_PAYLOAD, self::FIELD_PAYLOAD);

    $seen = [];
    $callback = function (array &$replacements, array $data, array $options, BubbleableMetadata $bubbleable_metadata) use (&$seen): void {
      foreach ($replacements as $token => $value) {
        $seen[$token] = (string) $value;
        $replacements[$token] = $value . '!';
      }
    };

    $result = $this->tokenService->replace(
      '[node:title] :: [node:field_multi:0]',
      ['node' => $node],
      ['viewer' => $admin, 'callback' => $callback],
    );

    $this->assertSame(Html::escape(self::TITLE_PAYLOAD), $seen['[node:title]'], 'The callback observes the legacy value already escaped exactly once.');
    $this->assertSame(Html::escape(self::FIELD_PAYLOAD), $seen['[node:field_multi:0]'], 'The callback observes the engine value already escaped exactly once.');

    $expected = Html::escape(self::TITLE_PAYLOAD) . '!' . ' :: ' . Html::escape(self::FIELD_PAYLOAD) . '!';
    $this->assertSame($expected, $result, 'Concatenating onto an already-escaped value inside the callback does not trigger a second escaping pass.');
  }

}
