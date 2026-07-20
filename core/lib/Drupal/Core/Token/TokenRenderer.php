<?php

declare(strict_types=1);

namespace Drupal\Core\Token;

use Drupal\Component\Render\MarkupInterface;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityRepositoryInterface;
use Drupal\Core\Entity\TranslatableInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Default leaf serialization for all output types.
 *
 * This class provides the default rendering for every output type so that any
 * chain can terminate at any segment and still produce a human-readable string.
 * Rendering is context-aware: the same value may serialize differently for HTML
 * vs. plain text vs. URL slug contexts.
 *
 * Per-type defaults:
 *  - string: identity (already a string).
 *  - entity:*: entity label, escaped for HTML or stripped for plain text.
 *  - timestamp: locale-sensitive formatted date via DateFormatter.
 *  - bool: 'true'/'false' for all contexts.
 *  - int, float: string-cast.
 *  - Fallback: (string) cast.
 *
 * @internal
 */
final class TokenRenderer implements TokenRendererInterface {

  /**
   * @param \Drupal\Core\Datetime\DateFormatterInterface $dateFormatter
   *   Used for timestamp → string conversion.
   * @param \Drupal\Component\Transliteration\TransliterationInterface $transliteration
   *   Converts non-ASCII text to an ASCII approximation before slugging.
   * @param \Drupal\Core\Entity\EntityRepositoryInterface $entityRepository
   *   Selects the entity translation matching $langcode before labelling.
   */
  public function __construct(
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TransliterationInterface $transliteration,
    private readonly EntityRepositoryInterface $entityRepository,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function render(mixed $value, string $outputType, OutputContext $context, ?string $langcode = NULL): string {
    if ($value === NULL || $value === '') {
      return '';
    }

    // Entity output types: serialize as label.
    if (str_starts_with($outputType, 'entity:') || $value instanceof EntityInterface) {
      return $this->renderEntity($value, $context, $langcode);
    }

    // Timestamp: format as a date string.
    if ($outputType === 'timestamp' || $outputType === 'datetime') {
      return $this->renderTimestamp($value, $context, $langcode);
    }

    // MarkupInterface / TranslatableMarkup: strip or preserve per context.
    if ($value instanceof MarkupInterface || $value instanceof TranslatableMarkup) {
      return $this->renderMarkup($value, $context, $langcode);
    }

    if (is_bool($value)) {
      return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
      return (string) $value;
    }

    // Plain scalar/string fallback. In the HTML context the value is escaped
    // here so the engine can mark the rendered output safe; other contexts
    // return the plain representation (the engine and downstream pipeline do
    // not re-escape it).
    return $this->renderString((string) $value, $context, $langcode);
  }

  /**
   * Serializes a plain string for the given output context.
   */
  private function renderString(string $value, OutputContext $context, ?string $langcode): string {
    return match ($context) {
      OutputContext::Html => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      OutputContext::PlainText => $value,
      OutputContext::EmailSubject => $this->emailSubject($value),
      OutputContext::UrlSlug => $this->slugify($value, $langcode),
    };
  }

  /**
   * Converts an arbitrary string into a lowercase, hyphen-separated URL slug.
   *
   * Non-ASCII text is transliterated to an ASCII approximation first, via the
   * same service core uses for machine names and file names, so "Café" becomes
   * "cafe" and "Über uns" becomes "uber-uns" rather than losing characters.
   * Transliteration is language-aware: with a German langcode "Zürich" becomes
   * "zuerich" (ü → ue) rather than the generic "zurich", since the langcode is
   * the language the value was resolved for. Whatever the transliteration
   * cannot map is then collapsed to separators.
   */
  private function slugify(string $value, ?string $langcode): string {
    // 'en' carries no language-specific override, so it is the generic table:
    // the right default when no language was resolved for the value.
    $ascii = $this->transliteration->transliterate($value, $langcode ?? 'en', '-');
    $slug = mb_strtolower(preg_replace('/[^a-z0-9]+/i', '-', $ascii) ?? '');
    return trim($slug, '-');
  }

  /**
   * Collapses a value into a single safe email-subject line.
   *
   * An email subject occupies one header line (RFC 5322). A token value that
   * contains a CR or LF would let an attacker smuggle additional headers (e.g.
   * an injected "Bcc:") when the subject is written into the message, so every
   * run of whitespace, newlines included, is collapsed to a single space and
   * the result trimmed. Unlike the HTML context the value is not
   * entity-encoded: a subject is literal text, and entities would surface
   * verbatim in mail clients.
   */
  private function emailSubject(string $value): string {
    return trim((string) preg_replace('/\s+/', ' ', $value));
  }

  /**
   * Renders an entity to its label for the given output context.
   */
  private function renderEntity(mixed $entity, OutputContext $context, ?string $langcode): string {
    if (!($entity instanceof EntityInterface)) {
      return '';
    }

    // Label the translation matching the language the value was resolved for.
    // The engine hands over already-translated entities, making this a cheap
    // repeat lookup, but the renderer is a public seam: direct callers may
    // pass an untranslated entity, and the label is the last point where the
    // wrong translation could leak into output. A NULL langcode selects the
    // current content language, mirroring getTranslationFromContext().
    if ($entity instanceof TranslatableInterface) {
      $entity = $this->entityRepository->getTranslationFromContext($entity, $langcode);
    }

    $label = $entity->label() ?? '';

    return match ($context) {
      OutputContext::Html => htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
      OutputContext::PlainText => PlainTextOutput::renderFromHtml((string) $label),
      OutputContext::EmailSubject => $this->emailSubject(PlainTextOutput::renderFromHtml((string) $label)),
      OutputContext::UrlSlug => $this->slugify(PlainTextOutput::renderFromHtml((string) $label), $langcode),
    };
  }

  /**
   * Renders a Unix timestamp for the given output context.
   */
  private function renderTimestamp(mixed $timestamp, OutputContext $context, ?string $langcode): string {
    if (!is_int($timestamp) && !is_numeric($timestamp)) {
      return '';
    }

    $format = match ($context) {
      OutputContext::Html, OutputContext::PlainText, OutputContext::EmailSubject => 'medium',
      OutputContext::UrlSlug => 'Y-m-d',
    };

    // Date formatting is locale-sensitive (month and day names): render it in
    // the language the value was resolved for, not the ambient interface
    // language. A NULL langcode lets the formatter fall back to its default.
    return $this->dateFormatter->format((int) $timestamp, $format, '', NULL, $langcode);
  }

  /**
   * Renders a MarkupInterface or TranslatableMarkup value.
   */
  private function renderMarkup(mixed $markup, OutputContext $context, ?string $langcode): string {
    $string = (string) $markup;

    return match ($context) {
      OutputContext::Html => $string,
      OutputContext::PlainText => PlainTextOutput::renderFromHtml($string),
      OutputContext::EmailSubject => $this->emailSubject(PlainTextOutput::renderFromHtml($string)),
      OutputContext::UrlSlug => $this->slugify(strip_tags($string), $langcode),
    };
  }

}
