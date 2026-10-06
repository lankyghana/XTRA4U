<?php

namespace Tests\Feature\Support;

use App\Models\SupportAttachment;
use App\Models\SupportMessage;
use App\Support\Support\SupportPrincipal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SupportAttachmentsTest extends SupportTestCase
{
    private const JSON = ['Accept' => 'application/json'];

    private function send($vendor, $conversation, array $data)
    {
        return $this->actingAs($vendor, 'vendor')->post(route('vendor.support.send', $conversation->id), $data, self::JSON);
    }

    public function test_valid_image_is_accepted_stored_privately_and_reencoded(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->send($vendor, $c, ['images' => [$this->png('..\\..\\evil.php.png')]])->assertCreated();

        $attachment = SupportAttachment::firstOrFail();
        $this->assertSame('image', $attachment->kind);
        $this->assertSame('image/png', $attachment->mime_type);
        $this->assertSame('local', $attachment->disk);

        // Server-controlled path; client filename never reaches the path.
        $this->assertMatchesRegularExpression('#^support/'.$vendor->id.'/'.$c->id.'/[0-9a-z]{26}\.png$#', $attachment->storage_path);
        $this->assertStringNotContainsString('evil', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);
        Storage::disk('public')->assertMissing($attachment->storage_path);

        // Stored bytes are a freshly encoded, decodable PNG.
        $this->assertNotFalse(imagecreatefromstring(Storage::disk('local')->get($attachment->storage_path)));

        // The path is never leaked in API output.
        $json = $this->actingAs($vendor, 'vendor')->get(route('vendor.support.messages', $c->id), self::JSON)->json();
        $this->assertStringNotContainsString('storage_path', json_encode($json));
        $this->assertStringNotContainsString($attachment->storage_path, json_encode($json));
    }

    public function test_jpeg_with_appended_payload_is_stripped_by_reencoding(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $path = tempnam(sys_get_temp_dir(), 'img');
        imagejpeg(imagecreatetruecolor(20, 20), $path);
        file_put_contents($path, '<?php echo "pwned"; ?>', FILE_APPEND);
        $file = new UploadedFile($path, 'x.jpg', 'image/jpeg', null, true);

        $this->send($vendor, $c, ['images' => [$file]])->assertCreated();

        $stored = Storage::disk('local')->get(SupportAttachment::firstOrFail()->storage_path);
        $this->assertStringNotContainsString('pwned', $stored);
    }

    public function test_non_image_files_disguised_as_images_are_rejected(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $php = UploadedFile::fake()->createWithContent('shell.png', '<?php system($_GET["c"]); ?>');
        $this->send($vendor, $c, ['images' => [$php]])->assertStatus(422);

        $svg = UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->send($vendor, $c, ['images' => [$svg]])->assertStatus(422);

        $gif = UploadedFile::fake()->createWithContent('x.png', "GIF89a\x01\x00\x01\x00\x00\x00\x00;");
        $this->send($vendor, $c, ['images' => [$gif]])->assertStatus(422);

        $exe = UploadedFile::fake()->createWithContent('run.jpg', "MZ\x90\x00\x03\x00\x00\x00");
        $this->send($vendor, $c, ['images' => [$exe]])->assertStatus(422);

        $this->assertSame(0, SupportAttachment::count());
        $this->assertSame(1, SupportMessage::count());
    }

    public function test_oversized_image_is_rejected(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->send($vendor, $c, ['images' => [UploadedFile::fake()->image('big.jpg', 10, 10)->size(6000)]])->assertStatus(422);
        $this->assertSame(0, SupportAttachment::count());
    }

    public function test_too_many_images_are_rejected(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $images = array_map(fn ($i) => $this->png("{$i}.png"), range(1, 5));
        $this->send($vendor, $c, ['images' => $images])->assertStatus(422);
        $this->assertSame(0, SupportAttachment::count());
    }

    public function test_valid_audio_is_accepted_with_server_controlled_name_and_normalized_type(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->send($vendor, $c, ['voice' => $this->webm('../../hack.php'), 'voice_duration' => 12])->assertCreated();

        $attachment = SupportAttachment::firstOrFail();
        $this->assertSame('audio', $attachment->kind);
        $this->assertSame('audio/webm', $attachment->mime_type);
        $this->assertSame(12, (int) $attachment->duration_seconds);
        $this->assertMatchesRegularExpression('#^support/\d+/\d+/[0-9a-z]{26}\.webm$#', $attachment->storage_path);
        Storage::disk('local')->assertExists($attachment->storage_path);
        $this->assertSame('voice', SupportMessage::latest('id')->first()->message_type);

        $this->actingAs($vendor, 'vendor')
            ->get(route('vendor.support.attachments.show', $attachment->id))
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/webm')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    public function test_ogg_and_mp4_audio_containers_are_accepted(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $ogg = UploadedFile::fake()->createWithContent('v.ogg', "OggS\x00\x02\x00\x00\x00\x00\x00\x00\x00\x00\x01\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x01\x13OpusHead\x01\x01\x38\x01\x80\xbb\x00\x00\x00\x00\x00");
        $mp4 = UploadedFile::fake()->createWithContent('v.m4a', $this->mp4Bytes('soun'));

        $this->send($vendor, $c, ['voice' => $ogg, 'voice_duration' => 3])->assertCreated();
        $this->send($vendor, $c, ['voice' => $mp4, 'voice_duration' => 3])->assertCreated();

        $this->assertEqualsCanonicalizing(['audio/ogg', 'audio/mp4'], SupportAttachment::pluck('mime_type')->all());
    }

    public function test_invalid_audio_is_rejected(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $script = UploadedFile::fake()->createWithContent('voice.webm', '<?php system($_GET["c"]); ?>');
        $this->send($vendor, $c, ['voice' => $script, 'voice_duration' => 3])->assertStatus(422);

        $exe = UploadedFile::fake()->createWithContent('voice.mp3', "MZ\x90\x00\x03\x00\x00\x00 padding padding");
        $this->send($vendor, $c, ['voice' => $exe, 'voice_duration' => 3])->assertStatus(422);

        // An image is not audio even if it is named like audio.
        $this->send($vendor, $c, ['voice' => UploadedFile::fake()->image('voice.webm'), 'voice_duration' => 3])->assertStatus(422);

        $this->assertSame(0, SupportAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_oversized_or_too_long_audio_is_rejected(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        $this->send($vendor, $c, ['voice' => $this->webm('big.webm', 6 * 1024 * 1024), 'voice_duration' => 10])->assertStatus(422);
        $this->send($vendor, $c, ['voice' => $this->webm(), 'voice_duration' => 600])->assertStatus(422);

        $this->assertSame(0, SupportAttachment::count());
    }

    public function test_failed_message_creation_removes_already_written_files(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);

        // Force a failure after the first attachment file/row is written.
        $calls = 0;
        SupportAttachment::creating(function () use (&$calls) {
            if (++$calls === 2) {
                throw new \RuntimeException('boom');
            }
        });

        try {
            $this->service()->sendMessage(SupportPrincipal::forVendor($vendor), $c, [
                'body' => 'two images',
                'images' => [$this->png('a.png'), $this->png('b.png')],
            ]);
            $this->fail('expected failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->assertSame(1, SupportMessage::count());
        $this->assertSame(0, SupportAttachment::count());
        $this->assertCount(0, Storage::disk('local')->allFiles());
    }

    public function test_admin_attachments_follow_the_same_rules(): void
    {
        $vendor = $this->vendor();
        $c = $this->startConversation($vendor);
        $admin = $this->adminModel();

        $this->actingAs($admin, 'admin')->post(route('admin.support.send', $c->id), [
            'images' => [$this->png()], 'voice' => $this->webm(), 'voice_duration' => 4,
        ], self::JSON)->assertCreated();
        $this->assertSame(2, SupportAttachment::count());

        $this->post(route('admin.support.send', $c->id), [
            'images' => [UploadedFile::fake()->createWithContent('a.png', '<?php ?>')],
        ], self::JSON)->assertStatus(422);

        // Another vendor still cannot read the admin's attachment.
        $other = $this->vendor();
        $this->actingAs($other, 'vendor')
            ->get(route('vendor.support.attachments.show', SupportAttachment::first()->id))
            ->assertNotFound();
    }

    public function test_attachments_are_never_written_to_the_public_disk(): void
    {
        $vendor = $this->vendor();
        $this->startConversation($vendor, 'x', ['images' => [$this->png()]]);

        $this->assertCount(0, Storage::disk('public')->allFiles());
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }
}
