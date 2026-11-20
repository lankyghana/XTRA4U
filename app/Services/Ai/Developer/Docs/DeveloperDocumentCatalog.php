<?php

namespace App\Services\Ai\Developer\Docs;

use Illuminate\Support\Str;

/**
 * THE allowlist of documentation the Developer Assistant may read. Nothing outside this class's manifest can ever be
 * returned, because the model never supplies a path: it supplies a stable document id, and the id is looked up in the
 * manifest below. The manifest maps an id to ONE file name inside `resources/developer-docs/`.
 *
 * Fail closed:
 *  - an id that is not in the manifest does not exist (there is no "try the filesystem" fallback);
 *  - a manifest file name must be a plain `name.md` (no slash, no dots-dots), and its resolved real path must stay
 *    inside the documentation directory, or the document is treated as absent;
 *  - the directory contains vendor-facing guides only. Internal engineering, audit and security documents live in
 *    `docs/` and are not reachable from here.
 *
 * Documents are split into sections on `## ` headings; a section is the unit that is searched and returned. Every
 * section is bounded (MAX_SECTION_CHARS) and returned as short blocks (MAX_BLOCK_CHARS), so a result is never cut
 * off silently by the tool-result limits.
 */
class DeveloperDocumentCatalog
{
    public const MAX_SECTION_CHARS = 3500;

    public const MAX_BLOCK_CHARS = 900;

    public const MAX_SEARCH_RESULTS = 5;

    public const ID_PATTERN = '/^[a-z0-9][a-z0-9-]{1,39}$/D';

    public const SECTION_PATTERN = '/^[a-z0-9][a-z0-9-]{0,59}$/D';

    /** @var array<string,array{title:string,summary:string,file:string}> */
    public const MANIFEST = [
        'overview' => ['title' => 'API overview', 'summary' => 'What the Developer API is, who can use it, environments, endpoint list and the typical flow.', 'file' => 'overview.md'],
        'authentication' => ['title' => 'Authentication and API keys', 'summary' => 'How to send an API key, key format, key lifecycle, and why a key is refused.', 'file' => 'authentication.md'],
        'scopes' => ['title' => 'Scopes', 'summary' => 'Which scope each endpoint needs, scopes that are not usable, and sandbox:simulate.', 'file' => 'scopes.md'],
        'rate-limits' => ['title' => 'Rate limits', 'summary' => 'Per-application limits, rate limit headers, 429 handling and polling guidance.', 'file' => 'rate-limits.md'],
        'errors' => ['title' => 'Response format and errors', 'summary' => 'The JSON envelope, validation errors and the full list of error codes.', 'file' => 'errors.md'],
        'catalog' => ['title' => 'Catalog endpoints', 'summary' => 'Services, networks and products: query parameters, paging and offer fields.', 'file' => 'catalog.md'],
        'orders' => ['title' => 'Quotes and orders', 'summary' => 'Creating quotes and orders, idempotency, reading orders and order statuses.', 'file' => 'orders.md'],
        'payments' => ['title' => 'Payments and transactions', 'summary' => 'Starting a payment, following it, transaction lookup and what is not supported.', 'file' => 'payments.md'],
        'sandbox' => ['title' => 'Sandbox', 'summary' => 'Test keys, simulated payments and fulfilment, scenarios and sandbox webhooks.', 'file' => 'sandbox.md'],
        'webhooks' => ['title' => 'Webhooks', 'summary' => 'Events, endpoint rules, request headers, signature verification and retries.', 'file' => 'webhooks.md'],
        'troubleshooting' => ['title' => 'Troubleshooting guide', 'summary' => 'Common integration errors and how to resolve them.', 'file' => 'troubleshooting.md'],
        'limitations' => ['title' => 'What is not available', 'summary' => 'Features that do not exist in the Developer API, and what the assistant cannot do.', 'file' => 'limitations.md'],
    ];

    /** @var array<string,list<array{id:string,heading:string,body:string}>|null> */
    private array $parsed = [];

    /**
     * @param  array<string,array{title:string,summary:string,file:string}>|null  $manifest  tests only; production always uses MANIFEST
     */
    public function __construct(
        private readonly ?string $directory = null,
        private readonly ?array $manifest = null,
    ) {}

    /** @return array<string,array{title:string,summary:string}> id => details, only documents that really load */
    public function documents(): array
    {
        $out = [];

        foreach ($this->manifest() as $id => $meta) {
            if ($this->sections($id) !== null) {
                $out[$id] = ['title' => $meta['title'], 'summary' => $meta['summary']];
            }
        }

        return $out;
    }

    public function has(string $id): bool
    {
        return $this->sections($id) !== null;
    }

    /** @return array{doc:string,title:string,summary:string,sections:list<array{section:string,heading:string,chars:int}>}|null */
    public function outline(string $id): ?array
    {
        $sections = $this->sections($id);

        if ($sections === null) {
            return null;
        }

        $meta = $this->manifest()[$id];

        return [
            'doc' => $id,
            'title' => $meta['title'],
            'summary' => $meta['summary'],
            'sections' => array_map(fn (array $s) => [
                'section' => $s['id'],
                'heading' => $s['heading'],
                'chars' => mb_strlen($s['body']),
            ], $sections),
        ];
    }

    /** @return array{doc:string,title:string,section:string,heading:string,content:list<string>}|null */
    public function section(string $docId, string $sectionId): ?array
    {
        $sections = $this->sections($docId);

        if ($sections === null) {
            return null;
        }

        foreach ($sections as $s) {
            if ($s['id'] === $sectionId) {
                return [
                    'doc' => $docId,
                    'title' => $this->manifest()[$docId]['title'],
                    'section' => $s['id'],
                    'heading' => $s['heading'],
                    'content' => $this->blocks($s['body']),
                ];
            }
        }

        return null;
    }

    /**
     * Keyword search over the approved sections only. Deterministic and cheap: no database, no network.
     *
     * @return list<array{doc:string,doc_title:string,section:string,heading:string,snippet:string}>
     */
    public function search(string $query, int $limit = 4): array
    {
        $tokens = $this->tokens($query);

        if ($tokens === []) {
            return [];
        }

        $limit = max(1, min($limit, self::MAX_SEARCH_RESULTS));
        $scored = [];
        $order = 0;

        foreach (array_keys($this->manifest()) as $docId) {
            $title = mb_strtolower($this->manifest()[$docId]['title']);

            foreach ($this->sections($docId) ?? [] as $s) {
                $heading = mb_strtolower($s['heading']);
                $body = mb_strtolower($s['body']);
                $score = 0;

                foreach ($tokens as $t) {
                    $score += 2 * (int) str_contains($title, $t);
                    $score += 6 * (int) str_contains($heading, $t);
                    $score += min(5, substr_count($body, $t));
                }

                if ($score > 0) {
                    $scored[] = ['score' => $score, 'order' => $order++, 'doc' => $docId, 's' => $s];
                }
            }
        }

        usort($scored, fn ($a, $b) => [$b['score'], $a['order']] <=> [$a['score'], $b['order']]);

        return array_map(fn (array $r) => [
            'doc' => $r['doc'],
            'doc_title' => $this->manifest()[$r['doc']]['title'],
            'section' => $r['s']['id'],
            'heading' => $r['s']['heading'],
            'snippet' => $this->snippet($r['s']['body'], $tokens),
        ], array_slice($scored, 0, $limit));
    }

    /** @return array<string,array{title:string,summary:string,file:string}> */
    private function manifest(): array
    {
        return $this->manifest ?? self::MANIFEST;
    }

    private function directory(): string
    {
        return $this->directory ?? resource_path('developer-docs');
    }

    /**
     * Parsed sections of an allowlisted document, or null when the id is not allowlisted / the file is unusable.
     *
     * @return list<array{id:string,heading:string,body:string}>|null
     */
    private function sections(string $id): ?array
    {
        if (array_key_exists($id, $this->parsed)) {
            return $this->parsed[$id];
        }

        return $this->parsed[$id] = $this->load($id);
    }

    private function load(string $id): ?array
    {
        $meta = $this->manifest()[$id] ?? null;

        if ($meta === null || preg_match(self::ID_PATTERN, $id) !== 1 || preg_match('/^[a-z0-9][a-z0-9-]*\.md$/D', $meta['file']) !== 1) {
            return null;
        }

        $root = realpath($this->directory());
        $path = $root === false ? false : realpath($root.DIRECTORY_SEPARATOR.$meta['file']);

        if ($root === false || $path === false || ! str_starts_with($path, $root.DIRECTORY_SEPARATOR) || ! is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);

        return is_string($raw) ? $this->split(str_replace("\r\n", "\n", $raw)) : null;
    }

    /** @return list<array{id:string,heading:string,body:string}> */
    private function split(string $markdown): array
    {
        $sections = [];
        $current = null;
        $inFence = false;
        $used = [];

        foreach (explode("\n", $markdown) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inFence = ! $inFence;
            }

            if (! $inFence && str_starts_with($line, '## ')) {
                if ($current !== null) {
                    $sections[] = $current;
                }

                $heading = trim(substr($line, 3));
                $slug = Str::slug($heading) ?: 'section';
                $base = $slug;
                for ($i = 2; isset($used[$slug]); $i++) {
                    $slug = $base.'-'.$i;
                }
                $used[$slug] = true;
                $current = ['id' => $slug, 'heading' => $heading, 'body' => ''];

                continue;
            }

            if ($current !== null) {
                $current['body'] .= $line."\n";
            }
        }

        if ($current !== null) {
            $sections[] = $current;
        }

        return array_map(fn (array $s) => ['body' => trim($s['body'])] + $s, $sections);
    }

    /**
     * Short blocks (paragraphs, tables, code fences) so no single string exceeds the tool result's string limit.
     *
     * @return list<string>
     */
    private function blocks(string $body): array
    {
        $blocks = [];
        $buffer = [];
        $inFence = false;

        $flush = function () use (&$buffer, &$blocks): void {
            if ($buffer !== []) {
                $blocks[] = implode("\n", $buffer);
                $buffer = [];
            }
        };

        foreach (explode("\n", $body) as $line) {
            if (str_starts_with(ltrim($line), '```')) {
                $inFence = ! $inFence;
            }

            if (! $inFence && trim($line) === '') {
                $flush();

                continue;
            }

            $buffer[] = $line;
        }
        $flush();

        $out = [];
        foreach ($blocks as $block) {
            if (mb_strlen($block) <= self::MAX_BLOCK_CHARS) {
                $out[] = $block;

                continue;
            }

            $part = '';
            foreach (explode("\n", $block) as $line) {
                foreach (mb_str_split($line, self::MAX_BLOCK_CHARS) ?: [''] as $piece) {
                    if ($part !== '' && mb_strlen($part) + 1 + mb_strlen($piece) > self::MAX_BLOCK_CHARS) {
                        $out[] = $part;
                        $part = '';
                    }
                    $part .= ($part === '' ? '' : "\n").$piece;
                }
            }
            if ($part !== '') {
                $out[] = $part;
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function tokens(string $query): array
    {
        $stop = ['the', 'and', 'for', 'with', 'how', 'what', 'why', 'can', 'does', 'did', 'are', 'is', 'to', 'of', 'in', 'on', 'my', 'me', 'you', 'your', 'get', 'use', 'this', 'that', 'it', 'do', 'an', 'a', 'i', 'or', 'be', 'when', 'where', 'which', 'api'];
        $words = preg_split('/[^a-z0-9_]+/', mb_strtolower($query), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];

        foreach ($words as $w) {
            if (strlen($w) >= 2 && ! in_array($w, $stop, true) && ! in_array($w, $tokens, true)) {
                $tokens[] = $w;
            }
        }

        return array_slice($tokens, 0, 8);
    }

    /** @param list<string> $tokens */
    private function snippet(string $body, array $tokens): string
    {
        $flat = trim((string) preg_replace('/\s+/', ' ', str_replace(['`', '|', '#', '*'], ' ', $body)));
        $lower = mb_strtolower($flat);
        $at = null;

        foreach ($tokens as $t) {
            $pos = mb_strpos($lower, $t);
            if ($pos !== false && ($at === null || $pos < $at)) {
                $at = $pos;
            }
        }

        $start = max(0, ($at ?? 0) - 40);
        $text = trim(mb_substr($flat, $start, 180));

        return ($start > 0 ? '...' : '').$text.(mb_strlen($flat) > $start + 180 ? '...' : '');
    }
}
