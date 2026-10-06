<?php

namespace Tests\Feature\Support;

use App\Models\Order;
use App\Models\SupportAttachment;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportQuickReply;
use App\Services\Support\SupportAudioInspector;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportHardeningTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private function sendVoice($vendor, $conversation, UploadedFile $file, int $duration = 5)
    {
        return $this->actingAs($vendor, 'vendor')->post(
            route('vendor.support.send', $conversation->id),
            ['voice' => $file, 'voice_duration' => $duration],
            self::JSON
        );
    }

    // ---- Audio: video-bearing containers ------------------------------

    public function test_audio_only_webm_passes_the_inspector(): void
    {
        $inspector = new SupportAudioInspector;

        $this->assertNull($inspector->rejectionReason('webm', $this->webmBytes([2])));
        $this->assertNull($inspector->rejectionReason('webm', $this->webmBytes([2, 2])));
    }

    public function test_webm_with_a_video_track_is_rejected_by_the_inspector(): void
    {
        $inspector = new SupportAudioInspector;

        $this->assertNotNull($inspector->rejectionReason('webm', $this->webmBytes([1])), 'video only');
        $this->assertNotNull($inspector->rejectionReason('webm', $this->webmBytes([2, 1])), 'audio + video');
        $this->assertNotNull($inspector->rejectionReason('webm', $this->webmBytes([1, 2])), 'video + audio');
        $this->assertNotNull($inspector->rejectionReason('webm', $this->webmBytes([17])), 'subtitle track');
    }

    public function test_webm_without_a_readable_track_list_is_rejected_fail_closed(): void
    {
        $inspector = new SupportAudioInspector;

        $this->assertNotNull($inspector->rejectionReason('webm', $this->webmBytes([2], 64, false)), 'no Tracks element');
        $this->assertNotNull($inspector->rejectionReason('webm', substr($this->webmBytes([2]), 0, 20)), 'truncated');
        $this->assertNotNull($inspector->rejectionReason('webm', "\x1A\x45\xDF\xA3"), 'header only');
        $this->assertNotNull($inspector->rejectionReason('webm', str_repeat("\xFF", 200)), 'garbage');
    }

    public function test_ogg_and_mp4_video_streams_are_rejected_by_the_inspector(): void
    {
        $inspector = new SupportAudioInspector;

        $this->assertNull($inspector->rejectionReason('ogg', "OggS\x00\x02".str_repeat("\0", 20).'OpusHead'.str_repeat("\0", 30)));
        $this->assertNotNull($inspector->rejectionReason('ogg', "OggS\x00\x02".str_repeat("\0", 20)."\x80theora".str_repeat("\0", 30)));
        $this->assertNotNull($inspector->rejectionReason('ogg', "OggS\x00\x02".str_repeat("\0", 40)), 'no codec announced');

        $this->assertNull($inspector->rejectionReason('mp4', $this->mp4Bytes('soun')));
        $this->assertNotNull($inspector->rejectionReason('mp4', $this->mp4Bytes('vide')));
        $this->assertNull($inspector->rejectionReason('mp3', 'ID3whatever'));
    }

    public function test_video_webm_upload_is_refused_end_to_end_and_nothing_is_stored(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $video = UploadedFile::fake()->createWithContent('voice.webm', $this->webmBytes([1, 2]));
        $this->sendVoice($vendor, $c, $video)->assertStatus(422)->assertJsonValidationErrors('voice');

        $mp4 = UploadedFile::fake()->createWithContent('voice.m4a', $this->mp4Bytes('vide'));
        $this->sendVoice($vendor, $c, $mp4)->assertStatus(422);

        $this->assertSame(0, SupportAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_served_audio_is_always_private_nosniff_and_sandboxed(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->sendVoice($vendor, $c, $this->webm())->assertCreated();
        $attachment = SupportAttachment::firstOrFail();

        $response = $this->actingAs($vendor, 'vendor')->get(route('vendor.support.attachments.show', $attachment->id))->assertOk();

        $this->assertSame('audio/webm', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString("default-src 'none'", $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringNotContainsString('public', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('inline; filename="support-'.$attachment->id.'.webm"', $response->headers->get('Content-Disposition'));
        Storage::disk('public')->assertMissing($attachment->storage_path);
    }

    // ---- Audio duration is not trusted ---------------------------------

    public function test_client_reported_duration_is_clamped_and_never_exceeds_the_limit(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->sendVoice($vendor, $c, $this->webm(), 181)->assertCreated();   // within tolerance, clamped
        $this->assertSame(180, (int) SupportAttachment::first()->duration_seconds);

        $this->sendVoice($vendor, $c, $this->webm(), 9999)->assertStatus(422);
        $this->sendVoice($vendor, $c, $this->webm(), -4)->assertStatus(422);
    }

    // ---- Request structure / upload limits -----------------------------

    public function test_four_images_and_a_voice_note_are_accepted_in_one_request_but_five_images_are_not(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $this->actingAs($vendor, 'vendor');

        $four = array_map(fn ($i) => $this->png("{$i}.png"), range(1, 4));
        $this->post(route('vendor.support.send', $c->id), ['message' => 'all of it', 'images' => $four, 'voice' => $this->webm(), 'voice_duration' => 3], self::JSON)
            ->assertCreated();
        $this->assertSame(5, SupportAttachment::count());
        $this->assertSame('mixed', SupportMessage::latest('id')->first()->message_type);

        $five = array_map(fn ($i) => $this->png("{$i}.png"), range(1, 5));
        $this->post(route('vendor.support.send', $c->id), ['images' => $five], self::JSON)->assertStatus(422);
    }

    public function test_worst_case_request_size_matches_the_documented_php_limits(): void
    {
        $images = config('support.images');
        $perImage = $images['max_kb'] * 1024;
        $voice = config('support.audio.max_kb') * 1024;

        $worstCaseFiles = $images['max_per_message'] * $perImage + $voice;   // 4 x 5 MiB + 5 MiB
        $this->assertSame(25 * 1024 * 1024, $worstCaseFiles);

        // Validation lets a bit more than the limit through so the processor (not PHP) answers with a clear
        // message: the largest single file PHP must therefore accept is max_kb + 512 KiB.
        $rules = (new \App\Http\Requests\SupportMessageRequest)->rules();
        $this->assertContains('max:'.($images['max_kb'] + 512), $rules['images.*']);
        $this->assertContains('max:'.(config('support.audio.max_kb') + 512), $rules['voice']);
    }

    public function test_image_that_cannot_fit_in_memory_is_refused_gracefully_not_fatally(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $file = UploadedFile::fake()->image('big.png', 3000, 3000);   // needs ~72 MB + overhead to decode

        $original = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage() + 40 * 1024 * 1024));
        try {
            $this->actingAs($vendor, 'vendor')->post(route('vendor.support.send', $c->id), ['images' => [$file]], self::JSON)
                ->assertStatus(422)->assertJsonValidationErrors('images');
        } finally {
            ini_set('memory_limit', $original);
        }
        $this->assertSame(0, SupportAttachment::count());
    }

    // ---- Untrusted query-string shapes ---------------------------------

    public function test_array_and_hostile_query_parameters_never_cause_server_errors(): void
    {
        $vendor = $this->vendor();
        $this->startConversation($vendor, 'normal');
        $this->actingAs($this->adminModel(), 'admin');

        $bad = [
            '?q[]=a&view[]=x&sort[]=x&from[]=a&to[]=b&category[]=1&vendor[]=1&has_image[]=1&has_voice[]=1',
            '?q='.urlencode("' OR 1=1 --").'&view=waiting_admin',
            '?q='.urlencode('%').'&sort='.urlencode('id; DROP TABLE support_messages'),
            '?q='.urlencode('_\\').'&from=not-a-date&to=9999-99-99',
            '?q='.str_repeat('a', 5000).'&category=abc&vendor=-1',
            '?view=closed&sort=newest&page=999999',
            '?page[]=1',
        ];
        foreach ($bad as $qs) {
            $status = $this->get(route('admin.support.index').$qs)->getStatusCode();
            $this->assertLessThan(500, $status, "admin inbox 500 for {$qs}");
        }

        $this->assertSame(1, SupportConversation::count());
        $this->assertDatabaseCount('support_messages', 1);

        $this->actingAs($vendor, 'vendor');
        foreach (['?related_type[]=order&related_id[]=1&category[]=x', '?related_type=order&related_id=abc'] as $qs) {
            $this->assertLessThan(500, $this->get(route('vendor.support.new').$qs)->getStatusCode(), $qs);
        }
        $this->get(route('vendor.support.related', ['type' => ['order']]), self::JSON)->assertNotFound();
        $this->get(route('vendor.support.messages', [SupportConversation::first()->id, 'after' => ['x'], 'before' => ['y']]), self::JSON)->assertOk();
    }

    // ---- XSS -----------------------------------------------------------

    public function test_message_subject_label_and_filename_payloads_are_never_rendered_as_markup(): void
    {
        $vendor = $this->vendor();
        $payload = '</script><script>alert(1)</script><img src=x onerror=alert(2)>';
        $order = Order::create([
            'recipient_phone_number' => '0240000000', 'mobile_money_number' => '0240000000',
            'service_purchased' => '<img src=x onerror=alert(3)>', 'amount_paid' => 5,
            'vendor_id' => $vendor->id, 'status' => 'Processing', 'payment_status' => 'paid',
        ]);
        $evilName = '"><img src=x onerror=alert(4)>.png';

        $c = $this->startConversation($vendor, $payload, [
            'subject' => '<script>alert("subject")</script>',
            'related_type' => 'order', 'related_id' => $order->id,
            'images' => [UploadedFile::fake()->image($evilName, 20, 20)],
        ]);
        $this->reply($this->adminModel(), $c, $payload);

        $pages = [
            $this->actingAs($vendor, 'vendor')->get(route('vendor.support.show', $c->id))->assertOk(),
            $this->get(route('vendor.support.index'))->assertOk(),
            $this->actingAs($this->adminModel(), 'admin')->get(route('admin.support.show', $c->id))->assertOk(),
            $this->get(route('admin.support.index', ['q' => $payload]))->assertOk(),
        ];

        foreach ($pages as $page) {
            $html = $page->getContent();
            $this->assertStringNotContainsString('<script>alert', $html);
            $this->assertStringNotContainsString('</script><script>', $html);
            $this->assertStringNotContainsString('<img src=x onerror', $html);
        }

        // The sanitised display name never contains markup characters.
        $stored = SupportAttachment::firstOrFail()->original_name;
        $this->assertDoesNotMatchRegularExpression('/[<>"\'\/\\\\]/', (string) $stored);

        // JSON payloads keep the text as inert data.
        $json = $this->actingAs($vendor, 'vendor')->get(route('vendor.support.messages', $c->id), self::JSON)->json();
        $this->assertSame($payload, $json['messages'][0]['body']);
    }

    public function test_quick_replies_render_escaped_and_mass_assignment_is_ignored(): void
    {
        $this->actingAs($this->adminModel(), 'admin');
        $this->post(route('admin.support.quick-replies.store'), [
            'title' => '<b>bold</b>', 'body' => '<script>alert(9)</script>', 'is_active' => '1',
            'id' => 777, 'sort_order' => -5, 'created_at' => '2000-01-01',
        ])->assertRedirect();

        $reply = SupportQuickReply::where('title', '<b>bold</b>')->firstOrFail();
        $this->assertNotSame(777, $reply->id);
        $this->assertGreaterThan(0, $reply->sort_order);
        $this->assertNotSame('2000', $reply->created_at->format('Y'));

        $html = $this->get(route('admin.support.quick-replies.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>alert(9)</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(9)&lt;/script&gt;', $html);
    }

    // ---- Range requests ------------------------------------------------

    public function test_range_requests_are_authorized_exactly_like_full_requests(): void
    {
        $owner = $this->vendor();
        $other = $this->vendor();
        $c = $this->startConversation($owner);
        $this->sendVoice($owner, $c, $this->webm('v.webm', 4000))->assertCreated();
        $id = SupportAttachment::firstOrFail()->id;
        $range = ['Range' => 'bytes=0-9'];

        $this->actingAs($owner, 'vendor')->get(route('vendor.support.attachments.show', $id), $range)
            ->assertStatus(206)->assertHeader('Content-Range');

        $this->actingAs($other, 'vendor')->get(route('vendor.support.attachments.show', $id), $range)->assertNotFound();

        auth('vendor')->logout();
        $this->get(route('vendor.support.attachments.show', $id), $range)->assertRedirect(route('vendor.login.form'));
        $this->get(route('admin.support.attachments.show', $id), $range)->assertRedirect(route('admin.login'));
    }
}
