<?php

namespace TMAn1An\FormBuilder\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * The ONE place admin-authored HTML is cleaned: form HTML blocks, custom
 * HTML before/after a form, page Paragraph and Custom-HTML blocks. Built on
 * symfony/html-sanitizer -- a real DOM-parsing allowlist sanitizer, not
 * regex stripping. See docs/FORMS.md §Custom HTML sanitization.
 *
 * Allowlist, not blocklist: only the formatting elements/attributes below
 * survive. Everything else is either dropped with its contents (script,
 * style, iframe, object, embed, form controls, svg/math, template...) or
 * unwrapped to its text (any other unknown tag). Event-handler attributes
 * (on*) and `style` are never allowed; links/images accept only
 * http/https/mailto/tel URLs (or relative ones), so `javascript:`/`data:`
 * never survive.
 *
 * Content is sanitized when saved AND again when rendered, so a row that
 * reached the database some other way still can't inject markup.
 */
final class HtmlSanitizer
{
    public const MAX_INPUT_LENGTH = 40000;

    private const GLOBAL_ATTRIBUTES = ['class', 'title', 'lang', 'dir'];

    /** element => extra attributes allowed on it (on top of GLOBAL_ATTRIBUTES) */
    private const ALLOWED = [
        'h2' => [], 'h3' => [], 'h4' => [], 'h5' => [], 'h6' => [],
        'p' => [], 'br' => [], 'hr' => [], 'div' => [], 'span' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'small' => [], 'mark' => [], 'sub' => [], 'sup' => [], 'abbr' => [],
        'blockquote' => ['cite'], 'q' => ['cite'], 'code' => [], 'pre' => [],
        'ul' => [], 'ol' => ['start', 'type'], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height'],
        'figure' => [], 'figcaption' => [],
        'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [],
        'tr' => [], 'th' => ['colspan', 'rowspan', 'scope'], 'td' => ['colspan', 'rowspan'],
    ];

    /** Removed together with everything inside them. */
    private const DROPPED = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'form', 'input', 'button', 'select', 'textarea', 'option', 'link', 'meta', 'base',
        'svg', 'math', 'template', 'noscript', 'video', 'audio', 'source', 'track', 'canvas',
    ];

    private ?SymfonyHtmlSanitizer $sanitizer = null;

    public function sanitize(?string $html): string
    {
        if ($html === null || trim($html) === '') {
            return '';
        }

        return trim($this->sanitizer()->sanitize($html));
    }

    private function sanitizer(): SymfonyHtmlSanitizer
    {
        if ($this->sanitizer !== null) {
            return $this->sanitizer;
        }

        $config = (new HtmlSanitizerConfig)
            // Unknown elements are unwrapped (text kept), never passed through.
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['https', 'http', 'mailto', 'tel'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['https', 'http'])
            ->allowRelativeMedias()
            // Any link opened in a new tab can't reach back via window.opener.
            ->forceAttribute('a', 'rel', 'noopener noreferrer nofollow')
            ->withMaxInputLength(self::MAX_INPUT_LENGTH);

        foreach (self::ALLOWED as $element => $attributes) {
            $config = $config->allowElement($element, array_merge(self::GLOBAL_ATTRIBUTES, $attributes));
        }
        foreach (self::DROPPED as $element) {
            $config = $config->dropElement($element);
        }

        return $this->sanitizer = new SymfonyHtmlSanitizer($config);
    }
}
