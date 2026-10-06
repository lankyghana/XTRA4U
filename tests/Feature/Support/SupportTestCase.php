<?php

namespace Tests\Feature\Support;

use App\Models\Admin;
use App\Models\SupportCategory;
use App\Models\SupportConversation;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Support\SupportService;
use App\Support\Support\SupportPrincipal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

abstract class SupportTestCase extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(config('support.disk'));
        Storage::fake('public');
    }

    protected function vendor(array $attrs = []): Vendor
    {
        return Vendor::factory()->create($attrs);
    }

    protected function adminModel(): Admin
    {
        return Admin::factory()->create();
    }

    protected function adminUser(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    protected function category(string $slug = 'order'): SupportCategory
    {
        return SupportCategory::where('slug', $slug)->firstOrFail();
    }

    protected function service(): SupportService
    {
        return app(SupportService::class);
    }

    /** Start a conversation as the given vendor through the service. */
    protected function startConversation(Vendor $vendor, string $body = 'Help please', array $extra = []): SupportConversation
    {
        return $this->service()->startConversation(SupportPrincipal::forVendor($vendor), array_merge([
            'category_id' => $this->category()->id,
            'body' => $body,
        ], $extra));
    }

    protected function reply($admin, SupportConversation $conversation, string $body = 'Looking into it')
    {
        return $this->service()->sendMessage(SupportPrincipal::forAdmin($admin), $conversation, ['body' => $body]);
    }

    protected function vendorSays(Vendor $vendor, SupportConversation $conversation, string $body = 'Any update?')
    {
        return $this->service()->sendMessage(SupportPrincipal::forVendor($vendor), $conversation, ['body' => $body]);
    }

    protected function png(string $name = 'shot.png'): UploadedFile
    {
        return UploadedFile::fake()->image($name, 60, 60);
    }

    /** EBML element: id + size vint + payload. */
    protected function ebml(string $id, string $payload): string
    {
        $len = strlen($payload);
        $size = $len < 127 ? chr(0x80 | $len) : chr(0x40 | ($len >> 8)).chr($len & 0xFF);

        return $id.$size.$payload;
    }

    /**
     * Raw bytes of a minimal WebM: EBML header, unknown-size Segment, Tracks
     * (one TrackEntry per given TrackType: 2=audio, 1=video), then an unknown-size
     * Cluster padded with $extraBytes.
     *
     * @param  array<int,int>  $trackTypes
     */
    protected function webmBytes(array $trackTypes = [2], int $extraBytes = 64, bool $withTracks = true): string
    {
        $header = $this->ebml("\x1A\x45\xDF\xA3", $this->ebml("\x42\x82", 'webm'));

        $entries = '';
        foreach ($trackTypes as $i => $type) {
            $entries .= $this->ebml("\xAE",
                $this->ebml("\xD7", chr($i + 1))
                .$this->ebml("\x83", chr($type))
                .$this->ebml("\x86", $type === 2 ? 'A_OPUS' : 'V_VP9'));
        }

        $segment = "\x18\x53\x80\x67\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF"
            .($withTracks ? $this->ebml("\x16\x54\xAE\x6B", $entries) : '')
            ."\x1F\x43\xB6\x75\x01\xFF\xFF\xFF\xFF\xFF\xFF\xFF".str_repeat("\0", $extraBytes);

        return $header.$segment;
    }

    /** A signature-valid, audio-only WebM upload. */
    protected function webm(string $name = 'voice.webm', int $extraBytes = 64): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->webmBytes([2], $extraBytes));
    }

    /** MP4/M4A bytes with the given handler type ('soun' audio, 'vide' video). */
    protected function mp4Bytes(string $handler = 'soun'): string
    {
        return "\x00\x00\x00\x18ftypM4A \x00\x00\x00\x00M4A mp42isom"
            ."\x00\x00\x00\x20hdlr\x00\x00\x00\x00\x00\x00\x00\x00".$handler.str_repeat("\0", 12);
    }
}
