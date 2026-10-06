<?php

namespace Tests\Feature\Support;

use App\Services\Support\SupportInbox;
use App\Support\Support\SupportPrincipal;
use Illuminate\Support\Facades\DB;

class SupportPerformanceAndCspTest extends SupportTestCase
{
    private function seedConversations(int $count, $admin): void
    {
        for ($i = 0; $i < $count; $i++) {
            $vendor = $this->vendor();
            $c = $this->startConversation($vendor, "msg {$i}", ['images' => [$this->png()]]);
            $this->reply($admin, $c, 'reply');
            $this->vendorSays($vendor, $c, 'again');
        }
    }

    private function queryCount(\Closure $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $n = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $n;
    }

    public function test_admin_inbox_query_count_does_not_grow_with_the_number_of_conversations(): void
    {
        $admin = $this->adminModel();
        $principal = SupportPrincipal::forAdmin($admin);
        $this->seedConversations(2, $admin);
        $this->actingAs($admin, 'admin');

        $small = $this->queryCount(fn () => $this->get(route('admin.support.index'))->assertOk());

        $this->seedConversations(15, $admin);
        $this->actingAs($admin, 'admin');
        $large = $this->queryCount(fn () => $this->get(route('admin.support.index'))->assertOk());

        $this->assertSame($small, $large, "inbox issued {$small} queries for 2 rows but {$large} for 17");
        $this->assertLessThanOrEqual(12, $large);
        $this->assertSame(17, app(SupportInbox::class)->forAdmin($principal)->total());
    }

    public function test_inbox_never_loads_message_rows_for_the_list(): void
    {
        $admin = $this->adminModel();
        $this->seedConversations(3, $admin);

        $queries = [];
        DB::listen(function ($q) use (&$queries) {
            $queries[] = $q->sql;
        });
        app(SupportInbox::class)->forAdmin(SupportPrincipal::forAdmin($admin))->each(fn ($c) => $c->vendor?->name);

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/from "support_messages"(?! as "?m"?)/i', $sql, 'list query loaded messages: '.$sql);
        }
        $this->assertLessThanOrEqual(4, count($queries));   // page + count + vendors + categories
    }

    public function test_thread_query_count_is_constant_regardless_of_message_and_attachment_count(): void
    {
        $vendor = $this->vendor();
        $admin = $this->adminModel();
        $c = $this->startConversation($vendor, 'one', ['images' => [$this->png()]]);
        $this->actingAs($admin, 'admin');
        $this->reply($admin, $c);
        $small = $this->queryCount(fn () => $this->get(route('admin.support.messages', $c->id), ['Accept' => 'application/json'])->assertOk());

        foreach (range(1, 12) as $i) {
            $this->service()->sendMessage($i % 2 ? SupportPrincipal::forVendor($vendor) : SupportPrincipal::forAdmin($this->adminModel()), $c, [
                'body' => "m{$i}", 'images' => [$this->png()],
            ]);
        }
        $this->actingAs($admin, 'admin');
        $large = $this->queryCount(fn () => $this->get(route('admin.support.messages', $c->id), ['Accept' => 'application/json'])->assertOk());

        $this->assertSame($small, $large);
    }

    // ---- CSP / Permissions-Policy -----------------------------------

    private function csp($response, string $directive): ?string
    {
        foreach (explode(';', (string) $response->headers->get('Content-Security-Policy')) as $part) {
            $part = trim($part);
            if (str_starts_with($part, $directive.' ') || $part === $directive) {
                return $part;
            }
        }

        return null;
    }

    public function test_support_composer_pages_allow_the_microphone_and_blob_audio(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $new = $this->actingAs($vendor, 'vendor')->get(route('vendor.support.new'));
        $show = $this->get(route('vendor.support.show', $c->id));
        $admin = $this->actingAs($this->adminModel(), 'admin')->get(route('admin.support.show', $c->id));

        foreach ([$new, $show, $admin] as $response) {
            $response->assertOk();
            $this->assertStringContainsString('microphone=(self)', $response->headers->get('Permissions-Policy'));
            $this->assertSame("media-src 'self' blob:", $this->csp($response, 'media-src'));
            // Still no wildcard / third-party loosening of anything else.
            $this->assertStringContainsString('camera=()', $response->headers->get('Permissions-Policy'));
            $this->assertStringContainsString('geolocation=()', $response->headers->get('Permissions-Policy'));
            $this->assertStringContainsString("object-src 'none'", $response->headers->get('Content-Security-Policy'));
        }
    }

    public function test_unrelated_pages_keep_the_restrictive_microphone_policy(): void
    {
        $vendor = $this->vendor();

        $pages = [
            $this->get('/'),
            $this->actingAs($vendor, 'vendor')->get(route('vendor.dashboard')),
            $this->get(route('vendor.support.index')),          // the support LIST has no recorder
            $this->actingAs($this->adminModel(), 'admin')->get(route('admin.support.index')),
            $this->get(route('admin.support.quick-replies.index')),
            $this->get(route('admin.dashboard')),
        ];

        foreach ($pages as $response) {
            $response->assertOk();
            $this->assertStringContainsString('microphone=()', $response->headers->get('Permissions-Policy'));
            $this->assertStringNotContainsString('microphone=(self)', $response->headers->get('Permissions-Policy'));
            $this->assertNull($this->csp($response, 'media-src'));
        }
    }
}
