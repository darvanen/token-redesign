<?php

declare(strict_types=1);

namespace Drupal\Tests\Core\Token;

use Drupal\Component\Transliteration\PhpTransliteration;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Token\OutputContext;
use Drupal\Core\Token\TokenRenderer;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests \Drupal\Core\Token\TokenRenderer.
 */
#[CoversClass(TokenRenderer::class)]
#[Group('Token')]
class TokenRendererTest extends UnitTestCase {

  /**
   * The renderer under test.
   */
  private TokenRenderer $renderer;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $dateFormatter = $this->createStub(DateFormatterInterface::class);
    // Echo the langcode back so tests can assert it is threaded through to the
    // locale-sensitive date formatter.
    $dateFormatter->method('format')->willReturnCallback(
      fn (int $timestamp, string $type = 'medium', string $format = '', $timezone = NULL, ?string $langcode = NULL): string =>
        strtoupper($type) . ':' . $timestamp . ($langcode !== NULL ? ':' . $langcode : ''),
    );
    // Use the real component transliteration so the slug proof is genuine
    // rather than a faked stub.
    $this->renderer = new TokenRenderer($dateFormatter, new PhpTransliteration());
  }

  /**
   * Tests that empty values render to an empty string in every context.
   */
  public function testEmptyValues(): void {
    foreach (OutputContext::cases() as $context) {
      $this->assertSame('', $this->renderer->render(NULL, 'string', $context));
      $this->assertSame('', $this->renderer->render('', 'string', $context));
    }
  }

  /**
   * Tests string serialization per output context.
   */
  public function testStringSerialization(): void {
    $this->assertSame('&lt;b&gt;Hi &amp; bye&lt;/b&gt;', $this->renderer->render('<b>Hi & bye</b>', 'string', OutputContext::Html), 'HTML context escapes the string.');
    $this->assertSame('<b>Hi & bye</b>', $this->renderer->render('<b>Hi & bye</b>', 'string', OutputContext::PlainText), 'Plain-text context returns the string unchanged.');
    $this->assertSame('hello-world', $this->renderer->render('Hello World!', 'string', OutputContext::UrlSlug), 'URL slug context slugifies the string.');
  }

  /**
   * Tests the URL-slug context normalises punctuation, case and edges.
   *
   * The slug is the machine-readable contract: a path alias must be stable and
   * predictable regardless of how the source value is punctuated or cased.
   */
  public function testUrlSlugSerialization(): void {
    $this->assertSame('summer-menu-2024', $this->renderer->render('  Summer Menu (2024)!  ', 'string', OutputContext::UrlSlug), 'Runs of punctuation collapse to one hyphen and edges are trimmed.');
    $this->assertSame('a-b-c', $this->renderer->render('a---b___c', 'string', OutputContext::UrlSlug), 'Mixed separators collapse to single hyphens.');
    $this->assertSame('', $this->renderer->render('!!!', 'string', OutputContext::UrlSlug), 'A value with no slug-safe characters degrades to empty.');
  }

  /**
   * Tests the URL-slug context transliterates non-ASCII rather than dropping it.
   *
   * A core slug must stay usable for the whole multilingual audience, so accents
   * and other scripts are converted to an ASCII approximation (the same service
   * core uses for machine names) instead of being silently lost.
   */
  public function testUrlSlugTransliteratesNonAscii(): void {
    $this->assertSame('cafe', $this->renderer->render('Café', 'string', OutputContext::UrlSlug), 'Accented Latin transliterates to ASCII.');
    $this->assertSame('uber-uns', $this->renderer->render('Über uns', 'string', OutputContext::UrlSlug), 'German umlaut transliterates and spacing collapses.');
    $this->assertNotSame('', $this->renderer->render('日本語', 'string', OutputContext::UrlSlug), 'Non-Latin scripts produce a usable slug, not an empty string.');
  }

  /**
   * Tests slug transliteration follows the language the value was resolved for.
   *
   * German (an official Swiss language) romanises the umlaut differently from
   * the generic table: "Zürich" becomes "zuerich" under a German langcode but
   * "zurich" otherwise. The langcode is therefore not cosmetic; it changes the
   * machine-readable output, so the same value yields the alias its own language
   * would write.
   */
  public function testUrlSlugTransliterationIsLanguageAware(): void {
    $this->assertSame('zuerich', $this->renderer->render('Zürich', 'string', OutputContext::UrlSlug, 'de'), 'German langcode applies German romanisation (ü → ue).');
    $this->assertSame('zurich', $this->renderer->render('Zürich', 'string', OutputContext::UrlSlug, 'en'), 'A different langcode uses the generic table (ü → u).');
    $this->assertSame('zurich', $this->renderer->render('Zürich', 'string', OutputContext::UrlSlug), 'No langcode falls back to the generic table.');
  }

  /**
   * Tests timestamp rendering passes the langcode to the date formatter.
   *
   * Date formatting is locale-sensitive (month and day names), so the value
   * must be formatted in the language it was resolved for rather than the
   * ambient interface language. The stubbed formatter echoes the langcode it
   * received.
   */
  public function testTimestampRespectsLangcode(): void {
    $this->assertSame('MEDIUM:1700000000:de', $this->renderer->render(1700000000, 'timestamp', OutputContext::Html, 'de'), 'The resolved langcode reaches the date formatter.');
    $this->assertSame('MEDIUM:1700000000', $this->renderer->render(1700000000, 'timestamp', OutputContext::Html), 'A NULL langcode lets the formatter use its own default.');
  }

  /**
   * Tests the email-subject context collapses a value to one safe header line.
   *
   * The differentiator from plain text is header-injection safety: a CR or LF
   * in a token value must never reach the subject header, or an attacker could
   * smuggle additional headers (e.g. an injected Bcc). Every whitespace run,
   * newlines included, collapses to a single space.
   */
  public function testEmailSubjectSerialization(): void {
    $injection = "Order shipped\r\nBcc: attacker@example.com";
    $this->assertSame('Order shipped Bcc: attacker@example.com', $this->renderer->render($injection, 'string', OutputContext::EmailSubject), 'CR/LF collapse to a space, neutralising header injection.');

    $this->assertSame('Hello World', $this->renderer->render("  Hello\t\tWorld  ", 'string', OutputContext::EmailSubject), 'Tabs and runs of spaces collapse and the value is trimmed.');

    $this->assertSame('5 < 10 & "ok"', $this->renderer->render('5 < 10 & "ok"', 'string', OutputContext::EmailSubject), 'The subject is literal text and is not entity-encoded.');
  }

  /**
   * Tests email-subject differs from plain text only where it must.
   *
   * Negative control: a single-line value with no control characters renders
   * identically in both contexts, proving the email-subject path adds nothing
   * beyond the header-line guarantee (it is not a redundant alias *because* of
   * the CR/LF case above, but it must not gratuitously diverge either).
   */
  public function testEmailSubjectMatchesPlainTextForSingleLine(): void {
    $value = 'A perfectly ordinary subject';
    $this->assertSame(
      $this->renderer->render($value, 'string', OutputContext::PlainText),
      $this->renderer->render($value, 'string', OutputContext::EmailSubject),
      'A clean single-line value is identical in both contexts.',
    );
  }

  /**
   * Tests boolean and numeric serialization.
   */
  public function testScalarSerialization(): void {
    $this->assertSame('true', $this->renderer->render(TRUE, 'bool', OutputContext::Html));
    $this->assertSame('false', $this->renderer->render(FALSE, 'bool', OutputContext::Html));
    $this->assertSame('42', $this->renderer->render(42, 'int', OutputContext::Html));
    $this->assertSame('3.5', $this->renderer->render(3.5, 'float', OutputContext::Html));
  }

  /**
   * Tests timestamp serialization defers to the date formatter.
   */
  public function testTimestampSerialization(): void {
    $this->assertSame('MEDIUM:1700000000', $this->renderer->render(1700000000, 'timestamp', OutputContext::Html));
    $this->assertSame('Y-M-D:1700000000', $this->renderer->render(1700000000, 'timestamp', OutputContext::UrlSlug));
  }

  /**
   * Tests entity values serialize to their label, escaped for HTML.
   */
  public function testEntityLabelSerialization(): void {
    $entity = $this->createStub(EntityInterface::class);
    $entity->method('label')->willReturn('Tom & "Jerry"');

    $this->assertSame('Tom &amp; &quot;Jerry&quot;', $this->renderer->render($entity, 'entity:node', OutputContext::Html), 'Entity label is escaped for HTML.');
    $this->assertSame('Tom & "Jerry"', $this->renderer->render($entity, 'entity:node', OutputContext::PlainText), 'Entity label is plain in the plain-text context.');
  }

  /**
   * Tests an entity label is flattened to one line for the email subject.
   *
   * Labels are author-controlled and may carry markup or newlines, so the
   * email-subject path must decode entities, strip markup and collapse the
   * result to a single header-safe line.
   */
  public function testEntityLabelEmailSubject(): void {
    $entity = $this->createStub(EntityInterface::class);
    $entity->method('label')->willReturn("Weekly\r\ndigest &amp; <em>news</em>");

    $this->assertSame('Weekly digest & news', $this->renderer->render($entity, 'entity:node', OutputContext::EmailSubject), 'Label markup is stripped and CR/LF collapsed for the subject.');
    $this->assertSame('weekly-digest-news', $this->renderer->render($entity, 'entity:node', OutputContext::UrlSlug), 'Label slugifies for the URL context.');
  }

}
