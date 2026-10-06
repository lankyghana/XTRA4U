<?php

namespace App\Services\Support;

/**
 * Cheap, dependency-free "is this really audio-only?" check on a container
 * whose signature has already been identified. No ffmpeg, no full demuxer:
 * it reads only the small header region where track metadata lives.
 *
 *  webm : walks EBML  Segment > Tracks > TrackEntry > TrackType, and accepts the
 *         file only if every track is an audio track (fail-closed: if the track
 *         list cannot be found in the first 64 KB, the file is refused).
 *  ogg  : the first pages must announce an audio codec (Opus/Vorbis/Speex/FLAC)
 *         and must not announce a video stream (Theora).
 *  mp4  : refused if any track handler is 'vide' (bounded byte-pattern search).
 *  mp3  : cannot carry video.
 *
 * This is hygiene, not a security boundary: a video-bearing file is harmless
 * here (never executed, served as audio/* with nosniff from private storage).
 */
class SupportAudioInspector
{
    private const SCAN_BYTES = 65536;

    private const ID_EBML = "\x1A\x45\xDF\xA3";

    private const ID_SEGMENT = "\x18\x53\x80\x67";

    private const ID_TRACKS = "\x16\x54\xAE\x6B";

    private const ID_CLUSTER = "\x1F\x43\xB6\x75";

    private const ID_TRACK_ENTRY = "\xAE";

    private const ID_TRACK_TYPE = "\x83";

    private const TRACK_TYPE_AUDIO = 2;

    /** @return string|null reason the file is refused, or null when acceptable */
    public function rejectionReason(string $container, string $bytes): ?string
    {
        return match ($container) {
            'webm' => $this->webm(substr($bytes, 0, self::SCAN_BYTES)),
            'ogg' => $this->ogg(substr($bytes, 0, 4096)),
            'mp4' => preg_match('/hdlr.{8}vide/s', $bytes) === 1 ? 'MP4 contains a video track.' : null,
            default => null,
        };
    }

    private function webm(string $head): ?string
    {
        $pos = 0;

        $el = $this->element($head, $pos);
        if (! $el || $el['id'] !== self::ID_EBML || $el['size'] === null) {
            return 'Not a valid WebM file.';
        }
        $pos = $el['start'] + $el['size'];

        $el = $this->element($head, $pos);
        if (! $el || $el['id'] !== self::ID_SEGMENT) {
            return 'Not a valid WebM file.';
        }
        $pos = $el['start'];   // children follow directly (the Segment may be of unknown size)

        while (($el = $this->element($head, $pos)) !== null) {
            if ($el['id'] === self::ID_TRACKS) {
                if ($el['size'] === null || strlen($head) < $el['start'] + $el['size']) {
                    return 'WebM track list could not be read.';
                }

                return $this->tracks(substr($head, $el['start'], $el['size']));
            }
            if ($el['id'] === self::ID_CLUSTER || $el['size'] === null) {
                break;   // media data reached without a track list
            }
            $pos = $el['start'] + $el['size'];
        }

        return 'WebM track list was not found.';
    }

    private function tracks(string $body): ?string
    {
        $audio = 0;
        $pos = 0;

        while (($entry = $this->element($body, $pos)) !== null) {
            if ($entry['size'] === null) {
                return 'WebM track list is malformed.';
            }
            if ($entry['id'] === self::ID_TRACK_ENTRY) {
                $inner = substr($body, $entry['start'], $entry['size']);
                $type = null;
                for ($p = 0; ($child = $this->element($inner, $p)) !== null; $p = $child['start'] + (int) $child['size']) {
                    if ($child['size'] === null) {
                        return 'WebM track list is malformed.';
                    }
                    if ($child['id'] === self::ID_TRACK_TYPE) {
                        $type = $this->uint(substr($inner, $child['start'], $child['size']));
                    }
                }
                if ($type !== self::TRACK_TYPE_AUDIO) {
                    return 'WebM contains a non-audio track.';
                }
                $audio++;
            }
            $pos = $entry['start'] + $entry['size'];
        }

        return $audio > 0 ? null : 'WebM has no audio track.';
    }

    private function ogg(string $head): ?string
    {
        if (str_contains($head, "\x80theora") || str_contains($head, "\x01video\x00")) {
            return 'Ogg contains a video stream.';
        }

        foreach (['OpusHead', "\x01vorbis", 'Speex   ', "\x7FFLAC"] as $audioCodec) {
            if (str_contains($head, $audioCodec)) {
                return null;
            }
        }

        return 'Ogg audio codec could not be identified.';
    }

    /**
     * Reads one EBML element header at $pos.
     *
     * @return array{id:string,size:?int,start:int}|null size null = "unknown size"
     */
    private function element(string $buf, int $pos): ?array
    {
        $len = strlen($buf);
        if ($pos >= $len) {
            return null;
        }

        $idLen = $this->vintLength(ord($buf[$pos]));
        if ($idLen === null || $idLen > 4 || $pos + $idLen >= $len) {
            return null;
        }
        $id = substr($buf, $pos, $idLen);
        $pos += $idLen;

        $sizeLen = $this->vintLength(ord($buf[$pos]));
        if ($sizeLen === null || $pos + $sizeLen > $len) {
            return null;
        }

        $value = ord($buf[$pos]) & (0xFF >> $sizeLen);
        $allOnes = $value === (0xFF >> $sizeLen);
        for ($i = 1; $i < $sizeLen; $i++) {
            $byte = ord($buf[$pos + $i]);
            $value = ($value << 8) | $byte;
            $allOnes = $allOnes && $byte === 0xFF;
        }

        return ['id' => $id, 'size' => $allOnes ? null : $value, 'start' => $pos + $sizeLen];
    }

    /** Length of an EBML variable-length integer from its first byte (1-8), or null. */
    private function vintLength(int $firstByte): ?int
    {
        for ($n = 1; $n <= 8; $n++) {
            if ($firstByte & (0x100 >> $n)) {
                return $n;
            }
        }

        return null;
    }

    private function uint(string $bytes): int
    {
        $v = 0;
        foreach (str_split($bytes) as $b) {
            $v = ($v << 8) | ord($b);
        }

        return $v;
    }
}
