<?php

namespace App\Services\Support;

use App\Models\SupportAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turns an untrusted upload into a validated, server-controlled blob.
 *
 * Nothing the browser says about the file is trusted: not its filename, not its
 * extension, not its declared MIME type. The content is sniffed, images are
 * decoded and re-encoded (stripping metadata and any appended payload), and
 * audio must match a known container signature.
 */
class SupportAttachmentProcessor
{
    public function __construct(private SupportAudioInspector $audioInspector) {}

    // detected container => [normalized mime, extension, finfo mimes accepted]
    private const AUDIO_CONTAINERS = [
        'webm' => ['audio/webm', 'webm', ['audio/webm', 'video/webm']],
        'ogg' => ['audio/ogg', 'ogg', ['audio/ogg', 'application/ogg', 'video/ogg']],
        'mp4' => ['audio/mp4', 'm4a', ['audio/mp4', 'video/mp4', 'audio/x-m4a', 'audio/m4a']],
        'mp3' => ['audio/mpeg', 'mp3', ['audio/mpeg', 'audio/mp3']],
    ];

    /**
     * @return array{kind:string,content:string,mime:string,ext:string,size:int,original_name:?string,duration:?int}
     */
    public function prepareImage(UploadedFile $file, string $field = 'images'): array
    {
        $path = $this->readablePath($file, $field);
        $maxBytes = config('support.images.max_kb') * 1024;

        if (filesize($path) > $maxBytes) {
            $this->fail($field, 'Each image must be '.(config('support.images.max_kb') / 1024).' MB or smaller.');
        }

        $info = @getimagesize($path);
        $type = $info[2] ?? null;
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        $expected = [
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_WEBP => 'image/webp',
        ];

        if (! $info || ! isset($expected[$type]) || $expected[$type] !== $mime
            || ! in_array($mime, config('support.images.mimes'), true)) {
            $this->fail($field, 'Only JPEG, PNG or WebP images are allowed.');
        }

        if (($info[0] * $info[1]) > config('support.images.max_pixels')) {
            $this->fail($field, 'That image is too large in dimensions.');
        }

        $this->assertDecodeFitsInMemory((int) $info[0], (int) $info[1], $field);

        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            $this->fail($field, 'That image could not be read. Please try another file.');
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyExifOrientation($image, $path);
        }

        ob_start();
        $ok = match ($mime) {
            'image/png' => (function () use ($image) {
                imagealphablending($image, false);
                imagesavealpha($image, true);

                return imagepng($image, null, 6);
            })(),
            'image/webp' => imagewebp($image, null, 85),
            default => imagejpeg($image, null, 85),
        };
        $content = (string) ob_get_clean();
        imagedestroy($image);

        if (! $ok || $content === '') {
            $this->fail($field, 'That image could not be processed. Please try another file.');
        }

        return [
            'kind' => SupportAttachment::KIND_IMAGE,
            'content' => $content,
            'mime' => $mime,
            'ext' => ['image/png' => 'png', 'image/webp' => 'webp', 'image/jpeg' => 'jpg'][$mime],
            'size' => strlen($content),
            'original_name' => $this->displayName($file),
            'duration' => null,
        ];
    }

    /**
     * @return array{kind:string,content:string,mime:string,ext:string,size:int,original_name:?string,duration:?int}
     */
    public function prepareAudio(UploadedFile $file, ?int $durationSeconds, string $field = 'voice'): array
    {
        $path = $this->readablePath($file, $field);

        if (filesize($path) > config('support.audio.max_kb') * 1024) {
            $this->fail($field, 'Voice notes must be '.(config('support.audio.max_kb') / 1024).' MB or smaller.');
        }

        $maxSeconds = (int) config('support.audio.max_seconds');
        if ($durationSeconds !== null && ($durationSeconds < 0 || $durationSeconds > $maxSeconds + 2)) {
            $this->fail($field, 'Voice notes can be at most '.($maxSeconds / 60).' minutes long.');
        }

        $content = (string) file_get_contents($path);
        $container = $this->detectAudioContainer($content);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($path);

        if ($container === null || ! in_array($mime, self::AUDIO_CONTAINERS[$container][2], true)) {
            $this->fail($field, 'That audio format is not supported.');
        }

        if ($this->audioInspector->rejectionReason($container, $content) !== null) {
            $this->fail($field, 'That file is not a supported voice recording.');
        }

        [$normalizedMime, $ext] = self::AUDIO_CONTAINERS[$container];

        return [
            'kind' => SupportAttachment::KIND_AUDIO,
            'content' => $content,
            'mime' => $normalizedMime,
            'ext' => $ext,
            'size' => strlen($content),
            'original_name' => null,
            'duration' => $durationSeconds === null ? null : min($durationSeconds, $maxSeconds),
        ];
    }

    /** Writes a prepared blob to the private disk under a server-generated name. */
    public function store(array $prepared, int $vendorId, int $conversationId): string
    {
        $path = sprintf('support/%d/%d/%s.%s', $vendorId, $conversationId, Str::lower((string) Str::ulid()), $prepared['ext']);

        if (! Storage::disk(config('support.disk'))->put($path, $prepared['content'])) {
            throw new \RuntimeException('Unable to store support attachment.');
        }

        return $path;
    }

    private function detectAudioContainer(string $bytes): ?string
    {
        $head = substr($bytes, 0, 12);

        return match (true) {
            str_starts_with($head, "\x1A\x45\xDF\xA3") => 'webm',
            str_starts_with($head, 'OggS') => 'ogg',
            substr($head, 4, 4) === 'ftyp' => 'mp4',
            str_starts_with($head, 'ID3'),
            (strlen($head) >= 2 && ord($head[0]) === 0xFF && (ord($head[1]) & 0xE0) === 0xE0) => 'mp3',
            default => null,
        };
    }

    private function applyExifOrientation(\GdImage $image, string $path): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $angle = match ($exif['Orientation'] ?? 1) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle !== 0 && ($rotated = imagerotate($image, $angle, 0)) !== false) {
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    /**
     * GD holds a decoded image uncompressed (~4 bytes/pixel, roughly double while
     * rotating). Refuse with a friendly error instead of dying on the PHP memory
     * limit, which cannot be caught and would surface as a 500.
     */
    private function assertDecodeFitsInMemory(int $width, int $height, string $field): void
    {
        $limit = $this->memoryLimitBytes();
        if ($limit < 0) {
            return;
        }

        $needed = ($width * $height * 4 * 2) + (12 * 1024 * 1024);
        if ($needed > $limit - memory_get_usage()) {
            $this->fail($field, 'That image is too large to process. Please use a smaller image or a screenshot.');
        }
    }

    private function memoryLimitBytes(): int
    {
        $raw = trim((string) ini_get('memory_limit'));
        if ($raw === '' || $raw === '-1') {
            return -1;
        }

        $value = (int) $raw;

        return match (strtolower(substr($raw, -1))) {
            'g' => $value * 1024 ** 3,
            'm' => $value * 1024 ** 2,
            'k' => $value * 1024,
            default => $value,
        };
    }

    private function readablePath(UploadedFile $file, string $field): string
    {
        if (! $file->isValid() || ! is_readable((string) $file->getRealPath())) {
            $this->fail($field, 'The upload failed. Please try again.');
        }

        return (string) $file->getRealPath();
    }

    /** Client filename is display-only: stripped to a safe, short label. */
    private function displayName(UploadedFile $file): ?string
    {
        $name = preg_replace('/[^\pL\pN._ -]+/u', '', basename((string) $file->getClientOriginalName()));

        return $name === '' || $name === null ? null : mb_substr($name, 0, 100);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
