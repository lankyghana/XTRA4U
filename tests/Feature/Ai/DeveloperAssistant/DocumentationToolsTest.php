<?php

namespace Tests\Feature\Ai\DeveloperAssistant;

use App\Services\Ai\Developer\Docs\DeveloperDocumentCatalog;
use App\Services\Ai\Logging\Redactor;
use App\Support\DeveloperApi\ApiError;
use Illuminate\Support\Facades\Route;

/**
 * developer_search_docs / developer_read_doc: approved vendor-facing guides only, by stable id, bounded.
 */
class DocumentationToolsTest extends DeveloperAssistantTestCase
{
    private function catalog(): DeveloperDocumentCatalog
    {
        return app(DeveloperDocumentCatalog::class);
    }

    // ------------------------------------------------------------------------------------------------ search

    public function test_approved_documents_can_be_searched(): void
    {
        $result = $this->runTool('developer_search_docs', ['query' => 'MISSING_SCOPE required scope'], $this->developerContext());

        $this->assertTrue($result->isOk());
        $this->assertGreaterThan(0, $result->data['count']);

        $docs = array_unique(array_column($result->data['results'], 'doc'));
        $this->assertNotEmpty(array_intersect($docs, ['scopes', 'errors', 'troubleshooting']));

        foreach ($result->data['results'] as $hit) {
            $this->assertArrayHasKey($hit['doc'], DeveloperDocumentCatalog::MANIFEST);
            $this->assertLessThanOrEqual(200, mb_strlen($hit['snippet']));
            $this->assertNotNull($this->catalog()->section($hit['doc'], $hit['section']), 'every hit can be read');
        }
    }

    public function test_search_finds_the_right_guide_for_typical_questions(): void
    {
        $expect = [
            'how are webhook signatures verified' => 'webhooks',
            'what is the rate limit and Retry-After' => 'rate-limits',
            'sandbox simulate scenario payment_failed' => 'sandbox',
            'IDEMPOTENCY_KEY_REQUIRED' => 'errors',
            'ai:chat scope' => 'scopes',
        ];

        foreach ($expect as $query => $doc) {
            $result = $this->runTool('developer_search_docs', ['query' => $query], $this->developerContext(), 'c_'.md5($query));
            $this->assertTrue($result->isOk());
            $this->assertContains($doc, array_column($result->data['results'], 'doc'), $query);
        }
    }

    public function test_a_search_with_no_match_says_so_and_returns_nothing(): void
    {
        $result = $this->runTool('developer_search_docs', ['query' => 'zzzqqqxyz plumbing invoice'], $this->developerContext());

        $this->assertTrue($result->isOk());
        $this->assertSame(0, $result->data['count']);
        $this->assertSame([], $result->data['results']);
        $this->assertStringContainsString('do not guess', $result->data['next_step']);
    }

    public function test_search_only_ever_returns_text_from_the_approved_guides(): void
    {
        foreach (['audit', 'security finding', 'architecture plan', 'vulnerab', 'APP_KEY', 'DB_PASSWORD', 'controller', 'migration', 'orchestrator'] as $i => $query) {
            $result = $this->runTool('developer_search_docs', ['query' => $query], $this->developerContext(), 'c_'.$i);
            $json = json_encode($result->data);

            $this->assertTrue($result->isOk());
            $this->assertStringNotContainsString('M5.1', $json);
            $this->assertStringNotContainsString('<?php', $json);
            $this->assertStringNotContainsString('APP_KEY=', $json);
            foreach ($result->data['results'] as $hit) {
                $this->assertArrayHasKey($hit['doc'], DeveloperDocumentCatalog::MANIFEST);
            }
        }
    }

    public function test_search_arguments_are_bounded(): void
    {
        $ctx = $this->developerContext();

        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => 'x'], $ctx, 'a')->errorCode, 'too short');
        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => str_repeat('a', 201)], $ctx, 'b')->errorCode, 'too long');
        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => 'scopes', 'limit' => 6], $ctx, 'c')->errorCode);
        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => 'scopes', 'limit' => '3'], $ctx, 'd')->errorCode, 'no type coercion');
        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => ['scopes']], $ctx, 'e')->errorCode);
        $this->assertSame('invalid_arguments', $this->runTool('developer_search_docs', ['query' => 'scopes', 'path' => 'docs/'], $ctx, 'f')->errorCode, 'unexpected argument');
        $this->assertSame('prohibited_argument', $this->runTool('developer_search_docs', ['query' => 'scopes', 'vendor_id' => 1], $ctx, 'g')->errorCode);

        $five = $this->runTool('developer_search_docs', ['query' => 'key scope error limit', 'limit' => 5], $ctx, 'h');
        $this->assertTrue($five->isOk());
        $this->assertLessThanOrEqual(5, count($five->data['results']));
    }

    // ------------------------------------------------------------------------------------------------- read

    public function test_approved_documents_can_be_retrieved(): void
    {
        $outline = $this->runTool('developer_read_doc', ['doc' => 'authentication'], $this->developerContext());

        $this->assertTrue($outline->isOk());
        $this->assertTrue($outline->data['found']);
        $this->assertSame('authentication', $outline->data['doc']);
        $this->assertNotEmpty($outline->data['sections']);

        $first = $outline->data['sections'][0]['section'];
        $section = $this->runTool('developer_read_doc', ['doc' => 'authentication', 'section' => $first], $this->developerContext(), 'call_2');

        $this->assertTrue($section->isOk());
        $this->assertTrue($section->data['found']);
        $this->assertStringContainsString('Bearer', implode("\n", $section->data['content']));
    }

    public function test_every_section_of_every_guide_is_returned_whole_bounded_and_unmangled(): void
    {
        $this->assertSame(array_keys(DeveloperDocumentCatalog::MANIFEST), array_keys($this->catalog()->documents()), 'every manifest entry loads');

        $n = 0;
        foreach (array_keys($this->catalog()->documents()) as $doc) {
            foreach ($this->catalog()->outline($doc)['sections'] as $s) {
                $this->assertLessThanOrEqual(DeveloperDocumentCatalog::MAX_SECTION_CHARS, $s['chars'], "{$doc}/{$s['section']} is within the section limit");

                $direct = $this->catalog()->section($doc, $s['section']);
                $result = $this->runTool('developer_read_doc', ['doc' => $doc, 'section' => $s['section']], $this->developerContext(), 'c_'.(++$n));

                $this->assertTrue($result->isOk(), "{$doc}/{$s['section']} failed: ".($result->errorCode ?? ''));
                $this->assertFalse($result->truncated, "{$doc}/{$s['section']} was truncated");
                $this->assertLessThanOrEqual(8192, $result->resultBytes);
                $this->assertSame($direct['content'], $result->data['content'], "{$doc}/{$s['section']} was altered by redaction (an example looks like a secret?)");

                foreach ($direct['content'] as $block) {
                    $this->assertLessThanOrEqual(DeveloperDocumentCatalog::MAX_BLOCK_CHARS, mb_strlen($block));
                }
            }
        }

        $this->assertGreaterThan(40, $n, 'the guides are substantial');
    }

    public function test_outlines_are_stable_under_redaction_too(): void
    {
        foreach (array_keys($this->catalog()->documents()) as $doc) {
            $outline = $this->catalog()->outline($doc);
            $this->assertSame($outline['summary'], Redactor::redact($outline['summary']));
            $this->assertSame($outline['title'], Redactor::redact($outline['title']));
        }
    }

    public function test_internal_documents_cannot_be_retrieved(): void
    {
        $internal = array_map(fn ($f) => basename($f, '.md'), array_merge(glob(base_path('docs/*.md')), glob(base_path('docs/*/*.md'))));
        $this->assertNotEmpty($internal);
        $internal = array_merge($internal, ['00-audit-and-architecture-plan', '01-foundation', '02-tool-system', '03-developer-assistant', 'audit', 'security-audit', 'PROJECT_CONTEXT', 'CLAUDE', 'README']);

        $n = 0;
        foreach ($internal as $id) {
            $result = $this->runTool('developer_read_doc', ['doc' => strtolower($id)], $this->developerContext(), 'c_'.(++$n));
            $payload = $this->payload($result);

            $this->assertFalse($result->isOk() && ($result->data['found'] ?? false), "{$id} must not be readable");
            $this->assertStringNotContainsString('Milestone', $payload);
            $this->assertStringNotContainsString('M5.', $payload);
        }

        $this->assertSame(array_keys(DeveloperDocumentCatalog::MANIFEST), array_values(array_unique(array_keys($this->catalog()->documents()))));
        $this->assertEmpty(array_intersect(array_keys(DeveloperDocumentCatalog::MANIFEST), array_map('strtolower', $internal)), 'no internal doc id is in the manifest');
    }

    public function test_the_documentation_directory_holds_exactly_the_manifest_files(): void
    {
        $files = array_map('basename', glob(resource_path('developer-docs/*')));
        $expected = array_column(DeveloperDocumentCatalog::MANIFEST, 'file');
        sort($files);
        sort($expected);

        $this->assertSame($expected, $files, 'no stray file sits next to the approved guides');
    }

    public function test_source_code_and_environment_files_cannot_be_retrieved(): void
    {
        $n = 0;
        foreach (['.env', 'env', 'composer', 'app', 'config', 'database', 'routes', 'storage', 'aiorchestrator', 'vendor', 'artisan', 'index', 'readme', 'passwd', 'win-ini'] as $id) {
            $result = $this->runTool('developer_read_doc', ['doc' => $id], $this->developerContext(), 'c_'.(++$n));
            $payload = $this->payload($result);

            $this->assertFalse($result->isOk() && ($result->data['found'] ?? false), "{$id} must not be readable");
            $this->assertStringNotContainsString('<?php', $payload);
            $this->assertStringNotContainsString('APP_KEY', $payload);
            $this->assertStringNotContainsString('DB_PASSWORD', $payload);
        }
    }

    public function test_arbitrary_paths_and_path_traversal_are_rejected(): void
    {
        $hostile = [
            '../.env', '../../.env', '..\\..\\.env', '/etc/passwd', 'C:\\Windows\\win.ini', 'app/Models/Vendor.php', 'docs/ai/00-audit-and-architecture-plan.md',
            'overview.md', 'overview/../authentication', 'overview/', './overview', '%2e%2e%2f%2e%2e%2fenv', 'overview%00', "overview\0.md", 'file:///etc/passwd',
            'php://filter/resource=.env', 'Overview', 'OVERVIEW', ' overview', 'overview ', str_repeat('a', 41), 'a',
        ];

        $n = 0;
        foreach ($hostile as $doc) {
            $result = $this->runTool('developer_read_doc', ['doc' => $doc], $this->developerContext(), 'c_'.(++$n));

            $this->assertFalse($result->isOk(), 'rejected before reaching the tool: '.json_encode($doc));
            $this->assertSame('invalid_arguments', $result->errorCode);
            $this->assertFalse($result->wasExecuted());
        }

        foreach (['../x', '../../.env', 'a/b', 'a.b', "x\0", 'UP', '-x', str_repeat('a', 61)] as $section) {
            $result = $this->runTool('developer_read_doc', ['doc' => 'overview', 'section' => $section], $this->developerContext(), 'c_'.(++$n));
            $this->assertSame('invalid_arguments', $result->errorCode, json_encode($section));
        }

        // Well-formed ids that are simply not on the list: a harmless "not found" listing only the approved ids.
        $missing = $this->runTool('developer_read_doc', ['doc' => 'internal-notes'], $this->developerContext(), 'c_missing');
        $this->assertTrue($missing->isOk());
        $this->assertFalse($missing->data['found']);
        $this->assertSame(array_keys(DeveloperDocumentCatalog::MANIFEST), $missing->data['available_docs']);

        $badSection = $this->runTool('developer_read_doc', ['doc' => 'overview', 'section' => 'no-such-section'], $this->developerContext(), 'c_missing2');
        $this->assertFalse($badSection->data['found']);
    }

    public function test_read_arguments_cannot_carry_identity_or_extra_fields(): void
    {
        $ctx = $this->developerContext();

        $this->assertSame('prohibited_argument', $this->runTool('developer_read_doc', ['doc' => 'overview', 'vendor_id' => 1], $ctx, 'a')->errorCode);
        $this->assertSame('invalid_arguments', $this->runTool('developer_read_doc', ['doc' => 'overview', 'file' => 'x'], $ctx, 'b')->errorCode);
        $this->assertSame('invalid_arguments', $this->runTool('developer_read_doc', [], $ctx, 'c')->errorCode, 'doc is required');
    }

    public function test_results_respect_the_size_limits(): void
    {
        $limits = config('ai.tools.limits');

        $biggest = 0;
        foreach (array_keys($this->catalog()->documents()) as $doc) {
            foreach ($this->catalog()->outline($doc)['sections'] as $s) {
                $r = $this->runTool('developer_read_doc', ['doc' => $doc, 'section' => $s['section']], $this->developerContext(), 'x'.md5($doc.$s['section']));
                $biggest = max($biggest, $r->resultBytes);
                $this->assertLessThanOrEqual($limits['max_result_bytes'], $r->resultBytes);
            }
        }
        $this->assertGreaterThan(1000, $biggest);

        // A search can never return more than five hits, each a short snippet.
        $search = $this->runTool('developer_search_docs', ['query' => 'key', 'limit' => 5], $this->developerContext(), 'y');
        $this->assertLessThanOrEqual(5, count($search->data['results']));
        $this->assertLessThan(3000, $search->resultBytes);

        // The section ceiling is enforced when the catalog is built from unsafe content (see the next test).
        $this->assertSame(3500, DeveloperDocumentCatalog::MAX_SECTION_CHARS);
    }

    // ----------------------------------------------------------------------------------- catalog hardening

    public function test_the_catalog_refuses_manifest_entries_that_escape_the_documentation_directory(): void
    {
        $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-docs-'.bin2hex(random_bytes(4));
        $dir = $root.DIRECTORY_SEPARATOR.'approved';
        mkdir($dir, 0777, true);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'good.md', "## Fine\n\nVendor-safe text.\n");
        file_put_contents($root.DIRECTORY_SEPARATOR.'secret.md', "## Hidden\n\nTOP-SECRET-CANARY\n");
        file_put_contents($dir.DIRECTORY_SEPARATOR.'unlisted.md', "## Unlisted\n\nUNLISTED-CANARY\n");

        try {
            $catalog = new DeveloperDocumentCatalog($dir, [
                'good' => ['title' => 'Good', 'summary' => 's', 'file' => 'good.md'],
                'escape' => ['title' => 'Escape', 'summary' => 's', 'file' => '../secret.md'],
                'absolute' => ['title' => 'Abs', 'summary' => 's', 'file' => $root.DIRECTORY_SEPARATOR.'secret.md'],
                'nested' => ['title' => 'Nested', 'summary' => 's', 'file' => 'sub/x.md'],
                'wrongext' => ['title' => 'Ext', 'summary' => 's', 'file' => 'good.md.php'],
                'missing' => ['title' => 'Missing', 'summary' => 's', 'file' => 'nope.md'],
                'Bad_Id' => ['title' => 'Bad', 'summary' => 's', 'file' => 'good.md'],
            ]);

            $this->assertSame(['good'], array_keys($catalog->documents()));
            $this->assertNotNull($catalog->outline('good'));

            foreach (['escape', 'absolute', 'nested', 'wrongext', 'missing', 'Bad_Id', 'unlisted', '../secret', '..', ''] as $id) {
                $this->assertNull($catalog->outline($id), $id);
                $this->assertNull($catalog->section($id, 'hidden'), $id);
                $this->assertFalse($catalog->has($id));
            }

            $this->assertSame([], $catalog->search('TOP-SECRET-CANARY'));
            $this->assertSame([], $catalog->search('UNLISTED-CANARY'));
            $this->assertCount(1, $catalog->search('vendor-safe'));
        } finally {
            foreach (glob($dir.'/*') as $f) {
                @unlink($f);
            }
            @unlink($root.DIRECTORY_SEPARATOR.'secret.md');
            @rmdir($dir);
            @rmdir($root);
        }
    }

    public function test_headings_inside_code_fences_do_not_split_sections_and_long_blocks_are_chunked(): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'xtra4u-docs-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $long = str_repeat('word ', 400); // 2000 chars, one paragraph
        file_put_contents($dir.DIRECTORY_SEPARATOR.'t.md', "## One\n\n```\n## not a heading\n\nstill code\n```\n\n{$long}\n\n## Two\n\nSecond.\n");

        try {
            $catalog = new DeveloperDocumentCatalog($dir, ['tt' => ['title' => 'T', 'summary' => 's', 'file' => 't.md']]);

            $sections = array_column($catalog->outline('tt')['sections'], 'section');
            $this->assertSame(['one', 'two'], $sections);

            $blocks = $catalog->section('tt', 'one')['content'];
            $this->assertStringContainsString('## not a heading', $blocks[0], 'a fenced block stays whole');
            $this->assertGreaterThan(2, count($blocks));
            foreach ($blocks as $b) {
                $this->assertLessThanOrEqual(DeveloperDocumentCatalog::MAX_BLOCK_CHARS, mb_strlen($b));
            }
        } finally {
            @unlink($dir.DIRECTORY_SEPARATOR.'t.md');
            @rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------------- accuracy of the guides

    public function test_the_guides_do_not_present_unavailable_features_as_usable(): void
    {
        $all = '';
        foreach (array_keys($this->catalog()->documents()) as $doc) {
            foreach ($this->catalog()->outline($doc)['sections'] as $s) {
                $all .= "\n[{$doc}/{$s['section']}] ".implode("\n", $this->catalog()->section($doc, $s['section'])['content']);
            }
        }

        // ai:chat is only ever mentioned as NOT usable.
        preg_match_all('/[^\n]*ai:chat[^\n]*/', $all, $lines);
        $this->assertNotEmpty($lines[0]);
        foreach ($lines[0] as $line) {
            $this->assertMatchesRegularExpression('/not|cannot|no endpoint|unusable|inactive|do not/i', $line, 'ai:chat must be described as unavailable: '.$line);
        }

        // Every scope the guides name exists in config; every config scope is covered by the scope guide.
        preg_match_all('/`([a-z]+:[a-z]+)`/', $all, $m);
        foreach (array_unique($m[1]) as $scope) {
            if (in_array($scope, ['example:thing'], true)) {
                continue;
            }
            $this->assertArrayHasKey($scope, config('developer_api.scopes'), "unknown scope in the guides: {$scope}");
        }
        $scopesGuide = implode("\n", $this->catalog()->section('scopes', 'scopes-you-can-grant-today')['content']);
        foreach (array_keys(config('developer_api.scopes')) as $scope) {
            if ($scope !== 'ai:chat') {
                $this->assertStringContainsString("`{$scope}`", $scopesGuide);
            }
        }

        // Every error code the guides list really exists in the API's code list (and vice versa for the common ones).
        $codes = (new \ReflectionClass(ApiError::class))->getConstants();
        preg_match_all('/`([A-Z][A-Z_]{4,})`/', $all, $c);
        foreach (array_unique($c[1]) as $code) {
            if (in_array($code, ['MTN', 'TELECEL', 'AIRTELTIGO'], true)) {
                continue; // network names, not error codes
            }
            $this->assertContains($code, array_merge(array_values($codes), ['METHOD_NOT_ALLOWED']), "unknown error code in the guides: {$code}");
        }

        // Every endpoint path the guides name is a real route.
        $routes = collect(Route::getRoutes())->map(fn ($r) => $r->uri())->filter(fn ($u) => str_starts_with($u, 'api/v1'))->all();
        foreach (['health', 'networks', 'services', 'products', 'quotes', 'orders', 'transactions', 'sandbox/simulations'] as $path) {
            $this->assertNotEmpty(array_filter($routes, fn ($u) => str_starts_with($u, 'api/v1/'.$path)), "route /{$path} exists");
        }
    }
}
