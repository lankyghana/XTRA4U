<?php

namespace App\Services\DeveloperApi\Docs;

use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;

/**
 * The vendor-facing API documentation served inside the Developer Portal.
 *
 * Single source of truth: the guides in `resources/developer-docs/` (the same allowlisted files the Developer
 * Assistant reads, so the portal and the assistant can never disagree) plus one portal-only page, the quickstart with
 * copy-paste examples, in `resources/developer-portal-docs/`.
 *
 * Fail closed. A page is reachable only by a slug in the map below; the slug is looked up, never used to build a path,
 * and the resolved file must sit inside its own directory. Markdown is rendered with raw HTML stripped and unsafe link
 * schemes refused, so file content can never inject markup.
 */
class PortalDocs
{
    public const QUICKSTART = 'quickstart';

    public const SLUG_PATTERN = '[a-z0-9][a-z0-9-]{1,39}';

    /**
     * Reading order: the quickstart first, then the guides in the assistant manifest's order.
     *
     * @return array<string,array{title:string,summary:string,path:string}>
     */
    public function pages(): array
    {
        $pages = [
            self::QUICKSTART => [
                'title' => 'Quickstart and examples',
                'summary' => 'Base URL, headers and a copy-paste request and response for every step from catalog to webhook.',
                'path' => resource_path('developer-portal-docs/quickstart.md'),
            ],
        ];

        foreach (DeveloperDocumentCatalog::MANIFEST as $slug => $meta) {
            $pages[$slug] = [
                'title' => $meta['title'],
                'summary' => $meta['summary'],
                'path' => resource_path('developer-docs/'.$meta['file']),
            ];
        }

        return $pages;
    }

    public function has(string $slug): bool
    {
        return isset($this->pages()[$slug]) && $this->read($slug) !== null;
    }

    /**
     * @return array{slug:string,title:string,summary:string,html:string,headings:list<array{id:string,text:string}>}|null
     */
    public function page(string $slug): ?array
    {
        $meta = $this->pages()[$slug] ?? null;
        $markdown = $meta ? $this->read($slug) : null;

        if ($meta === null || $markdown === null) {
            return null;
        }

        $html = $this->convert($markdown);

        preg_match_all('/<h2 id="([a-z0-9-]+)">(.*?)<\/h2>/s', $html, $m, PREG_SET_ORDER);
        $headings = array_map(fn (array $h) => ['id' => $h[1], 'text' => trim(strip_tags($h[2]))], $m);

        return [
            'slug' => $slug,
            'title' => $meta['title'],
            'summary' => $meta['summary'],
            'html' => $html,
            'headings' => $headings,
        ];
    }

    private function read(string $slug): ?string
    {
        $path = $this->pages()[$slug]['path'] ?? null;

        if ($path === null) {
            return null;
        }

        $real = realpath($path);
        $dirs = array_filter([realpath(resource_path('developer-docs')), realpath(resource_path('developer-portal-docs'))]);

        if ($real === false || ! is_file($real)) {
            return null;
        }

        foreach ($dirs as $dir) {
            if (str_starts_with($real, $dir.DIRECTORY_SEPARATOR)) {
                $contents = file_get_contents($real);

                return $contents === false ? null : $contents;
            }
        }

        return null;
    }

    private function convert(string $markdown): string
    {
        $environment = new Environment([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
            // Anchors for the "On this page" list. No permalink icon is inserted, only an id on the heading itself.
            'heading_permalink' => ['insert' => 'none', 'apply_id_to_heading' => true, 'min_heading_level' => 2, 'max_heading_level' => 3],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new HeadingPermalinkExtension);

        return $this->decorate(trim((new MarkdownConverter($environment))->convert($markdown)->getContent()));
    }

    /**
     * Presentation only: mark HTTP methods so the stylesheet can show them as badges. Runs on the HTML the Markdown
     * converter already escaped, and only ever inserts fixed, allowlisted literals (never document text), so it cannot
     * introduce markup that was not in the source. The visible text is unchanged apart from the wrapping tag.
     */
    private function decorate(string $html): string
    {
        $methods = 'GET|POST|PUT|PATCH|DELETE';
        $badge = fn (string $m) => '<span class="docs-method docs-method-'.strtolower($m).'">'.$m.'</span>';

        // `POST /api/v1/orders` (inline code that starts with a method and a path)
        $html = preg_replace_callback('~<code>('.$methods.') (/[^<]*)</code>~', fn ($m) => '<code class="docs-endpoint">'.$badge($m[1]).' '.$m[2].'</code>', $html);

        // | POST | table cells that hold only a method
        return preg_replace_callback('~<td>('.$methods.')</td>~', fn ($m) => '<td>'.$badge($m[1]).'</td>', $html);
    }
}
