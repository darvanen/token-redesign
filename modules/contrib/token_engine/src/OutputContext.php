<?php

declare(strict_types=1);

namespace Drupal\token_engine;

/**
 * Closed enumeration of output rendering contexts for token resolution.
 *
 * The rendering step – converting a typed resolved value into a string –
 * depends on the target output context. Adding cases here in future versions
 * is non-breaking provided rendering code handles unknown cases gracefully.
 *
 * This is intentionally a closed enum with no plugin system in v1.
 */
enum OutputContext: string {

  // HTML output: values may contain markup and must be safely escaped.
  case Html = 'html';

  // Plain-text output: markup stripped; suitable for email bodies and logs.
  case PlainText = 'plain_text';

  // URL slug output: suitable for path aliases and machine-readable keys.
  case UrlSlug = 'url_slug';

  // Email subject output: single-line plain text with no HTML entities.
  case EmailSubject = 'email_subject';

}
