<?php

namespace App\Support\Cms;

use DOMDocument;
use DOMElement;
use DOMNode;
use League\CommonMark\CommonMarkConverter;

/**
 * Admin-authored rich text is stored as Markdown and rendered here.
 *
 * Two independent layers, because "an admin typed it" is not a trust boundary:
 *   1. CommonMark with raw HTML stripped and unsafe link schemes refused.
 *   2. A strict DOM whitelist over the generated HTML: only the tags below
 *      survive (images, scripts, styles, iframes ... are removed), every
 *      attribute is dropped except a validated <a href>, and links are
 *      re-validated through CmsLink.
 *
 * The output is safe to print unescaped.
 */
final class CmsMarkdown
{
    /** Tag => may keep children formatting. Everything else is unwrapped or dropped. */
    private const ALLOWED = ['p', 'h2', 'h3', 'h4', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'blockquote', 'hr', 'br', 'code', 'pre'];

    /** Card accent rotation for headed sections: [icon, colour, shadow, tinted]. */
    public const CARD_ACCENTS = [
        ['document', 'var(--x4-violet)', null, false],
        ['shield', '#16a34a', 'rgba(22,163,74,0.3)', false],
        ['lock', '#4f46e5', 'rgba(79,70,229,0.3)', false],
        ['users', '#9333ea', 'rgba(147,51,234,0.3)', false],
        ['clock', '#d97706', 'rgba(217,119,6,0.3)', false],
        ['close', '#dc2626', 'rgba(220,38,38,0.3)', true],
    ];

    /** Dropped together with their content. */
    private const DROP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'form', 'textarea', 'select', 'button', 'img', 'video', 'audio', 'link', 'meta', 'base', 'head', 'title'];

    public static function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);
        if ($markdown === '') {
            return '';
        }

        $converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        return self::sanitize($converter->convert($markdown)->getContent());
    }

    /** Plain-text excerpt for meta descriptions. */
    public static function excerpt(?string $markdown, int $limit = 160): string
    {
        $text = trim(preg_replace('/\s+/', ' ', strip_tags(self::toHtml($markdown))));

        return mb_strlen($text) > $limit ? rtrim(mb_substr($text, 0, $limit - 1)).'…' : $text;
    }

    public static function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = self::load($html);
        $root = $doc->getElementById('cms-root');
        if (! $root) {
            return '';
        }

        self::clean($root, $doc);

        return self::inner($root);
    }

    /**
     * Split sanitized HTML into the public legal-page card layout.
     * Content before the first heading is the intro; everything after a
     * horizontal rule that follows at least one card is the closing note.
     *
     * Chunks come back already decorated with the storefront's inline styles
     * (generated here from constants, never from content).
     *
     * @return array{intro: string, sections: list<array{title: string, html: string, icon: string, color: string, shadow: ?string, tinted: bool}>, closing: string}
     */
    public static function cards(string $sanitizedHtml): array
    {
        $out = ['intro' => '', 'sections' => [], 'closing' => ''];
        if (trim($sanitizedHtml) === '') {
            return $out;
        }

        $doc = self::load($sanitizedHtml);
        $root = $doc->getElementById('cms-root');
        if (! $root) {
            return $out;
        }

        $bucket = 'intro';
        $current = null;
        foreach (iterator_to_array($root->childNodes) as $node) {
            $name = $node instanceof DOMElement ? $node->nodeName : null;

            if ($name === 'hr' && ($out['sections'] !== [] || $current !== null)) {
                if ($current !== null) {
                    $out['sections'][] = $current;
                    $current = null;
                }
                $bucket = 'closing';

                continue;
            }

            if ($name === 'h2' && $bucket !== 'closing') {
                if ($current !== null) {
                    $out['sections'][] = $current;
                }
                $current = ['title' => trim($node->textContent), 'html' => ''];
                $bucket = 'section';

                continue;
            }

            $chunk = $doc->saveHTML($node) ?: '';
            if ($bucket === 'section' && $current !== null) {
                $current['html'] .= $chunk;
            } elseif ($bucket === 'closing') {
                $out['closing'] .= $chunk;
            } else {
                $out['intro'] .= $chunk;
            }
        }
        if ($current !== null) {
            $out['sections'][] = $current;
        }

        $out['intro'] = self::decorate($out['intro'], 'intro', 'var(--x4-violet)');
        $out['closing'] = self::decorate($out['closing'], 'closing', 'var(--x4-violet)');
        foreach ($out['sections'] as $i => $section) {
            [$icon, $color, $shadow, $tinted] = self::CARD_ACCENTS[$i % count(self::CARD_ACCENTS)];
            $out['sections'][$i] = [
                'title' => $section['title'],
                'html' => self::decorate($section['html'], 'card', $color),
                'icon' => $icon,
                'color' => $color,
                'shadow' => $shadow,
                'tinted' => $tinted,
            ];
        }

        return $out;
    }

    /**
     * Add the storefront typography to already-sanitized HTML. Only constant
     * strings are written as attributes.
     */
    public static function decorate(string $html, string $variant, string $accent): string
    {
        if (trim($html) === '') {
            return '';
        }

        $doc = self::load($html);
        $root = $doc->getElementById('cms-root');
        if (! $root) {
            return '';
        }

        $paragraph = $variant === 'intro'
            ? ['x4-body-lg', 'color: var(--x4-primary-deep);']
            : ['x4-body-md', 'color: var(--x4-ink-sec);'];

        $check = '<svg class="w-4 h-4 flex-shrink-0 mt-0.5" style="color: '.$accent.';" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><polyline points="20 6 9 17 4 12"/></svg>';

        foreach (iterator_to_array($root->getElementsByTagName('*')) as $el) {
            switch ($el->nodeName) {
                case 'p':
                    $next = $el->nextSibling;
                    while ($next && ! $next instanceof DOMElement) {
                        $next = $next->nextSibling;
                    }
                    $spaced = $next && in_array($next->nodeName, ['ul', 'ol'], true);
                    $el->setAttribute('class', $paragraph[0].($spaced ? ' mb-4' : ''));
                    $el->setAttribute('style', $paragraph[1]);
                    break;
                case 'strong':
                    $el->setAttribute('style', 'font-weight: 500;');
                    break;
                case 'a':
                    $el->setAttribute('style', 'color: var(--x4-violet); text-decoration: underline;');
                    break;
                case 'ul':
                    $el->setAttribute('class', 'space-y-2.5');
                    foreach (iterator_to_array($el->childNodes) as $li) {
                        if (! $li instanceof DOMElement || $li->nodeName !== 'li') {
                            continue;
                        }
                        $span = $doc->createElement('span');
                        $span->setAttribute('class', 'x4-body-md');
                        $span->setAttribute('style', 'color: var(--x4-ink-sec);');
                        while ($li->firstChild) {
                            $span->appendChild($li->firstChild);
                        }
                        $icon = $doc->createDocumentFragment();
                        $icon->appendXML($check);
                        $li->appendChild($icon);
                        $li->appendChild($span);
                        $li->setAttribute('class', 'flex items-start gap-2.5');
                    }
                    break;
                case 'ol':
                    $el->setAttribute('class', 'x4-body-md');
                    $el->setAttribute('style', 'color: var(--x4-ink-sec); list-style: decimal; padding-left: 1.25rem; display: grid; gap: 0.625rem;');
                    break;
                case 'blockquote':
                    $el->setAttribute('style', 'border-left: 3px solid var(--x4-violet); padding-left: 1rem; color: var(--x4-ink-sec);');
                    break;
                case 'h3':
                case 'h4':
                    $el->setAttribute('class', 'x4-heading-md mb-2');
                    $el->setAttribute('style', 'color: var(--x4-ink);');
                    break;
                case 'hr':
                    $el->setAttribute('style', 'border: 0; border-top: 1px solid var(--x4-hairline); margin: 1rem 0;');
                    break;
            }
        }

        return self::inner($root);
    }

    private static function load(string $html): DOMDocument
    {
        $doc = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="utf-8" ?><html><body><div id="cms-root">'.$html.'</div></body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $doc;
    }

    private static function inner(DOMNode $node): string
    {
        $doc = $node->ownerDocument;
        $html = '';
        foreach ($node->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }

        return trim($html);
    }

    private static function clean(DOMNode $parent, DOMDocument $doc): void
    {
        foreach (iterator_to_array($parent->childNodes) as $node) {
            if ($node->nodeType === XML_COMMENT_NODE || $node->nodeType === XML_PI_NODE || $node->nodeType === XML_CDATA_SECTION_NODE) {
                $parent->removeChild($node);

                continue;
            }
            if (! $node instanceof DOMElement) {
                continue;                                   // text nodes are escaped on output
            }

            $tag = strtolower($node->nodeName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($node);

                continue;
            }

            if ($tag === 'h1') {
                $node = self::rename($node, 'h2', $doc);
                $tag = 'h2';
            } elseif ($tag === 'h5' || $tag === 'h6') {
                $node = self::rename($node, 'h4', $doc);
                $tag = 'h4';
            }

            if (! in_array($tag, self::ALLOWED, true)) {
                self::clean($node, $doc);
                while ($node->firstChild) {
                    $parent->insertBefore($node->firstChild, $node);
                }
                $parent->removeChild($node);

                continue;
            }

            if ($tag === 'a') {
                $href = CmsLink::safe($node->getAttribute('href'));
                if ($href === null || str_starts_with($href, '{')) {
                    self::clean($node, $doc);
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);

                    continue;
                }
                self::stripAttributes($node);
                $node->setAttribute('href', $href);
                if (CmsLink::isExternal($href)) {
                    $node->setAttribute('target', '_blank');
                    $node->setAttribute('rel', 'noopener noreferrer nofollow');
                }
            } else {
                self::stripAttributes($node);
            }

            self::clean($node, $doc);
        }
    }

    private static function stripAttributes(DOMElement $el): void
    {
        foreach (iterator_to_array($el->attributes) as $attr) {
            $el->removeAttributeNode($attr);
        }
    }

    private static function rename(DOMElement $el, string $to, DOMDocument $doc): DOMElement
    {
        $new = $doc->createElement($to);
        while ($el->firstChild) {
            $new->appendChild($el->firstChild);
        }
        $el->parentNode->replaceChild($new, $el);

        return $new;
    }
}
