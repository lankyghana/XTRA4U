<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        DB::table('products')
            ->orderBy('id')
            ->chunkById(100, function ($products) {
                foreach ($products as $product) {
                    $decoded = $this->decodeDescription($product->description ?? '');

                    if (! empty($decoded['network']) || ! empty($decoded['size']) || ! empty($decoded['validity']) || ! empty($decoded['tag'])) {
                        continue;
                    }

                    $payload = $this->buildPayload($product);

                    if (empty($payload)) {
                        continue;
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'description' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // No-op: reverting to the previous free-form description format would lose structured data.
    }

    private function buildPayload(object $product): array
    {
        $text = trim(($product->name ?? '') . ' ' . ($product->description ?? ''));
        $description = trim($product->description ?? '') ?: null;

        $payload = [
            'network' => $this->detectNetwork($text),
            'size' => $this->detectSize($text),
            'validity' => $this->detectValidity($text),
            'tag' => $this->detectTag($text),
            'notes' => $description,
            'category' => $this->guessCategory($text),
            'description' => $description,
        ];

        return array_filter($payload, fn ($value) => ! is_null($value) && $value !== '');
    }

    private function decodeDescription(?string $value): array
    {
        if (! $value) {
            return [];
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE && is_array($decoded) ? $decoded : [];
    }

    private function detectNetwork(string $text): ?string
    {
        $map = [
            'mtn' => 'MTN',
            'airteltigo' => 'AirtelTigo',
            'airtel tigo' => 'AirtelTigo',
            'vodafone' => 'Vodafone',
            'telecel' => 'Telecel',
            'ecg' => 'ECG',
            'results checker' => 'Results Checker',
        ];

        $haystack = Str::lower($text);

        foreach ($map as $needle => $label) {
            if (Str::contains($haystack, $needle)) {
                return $label;
            }
        }

        return 'Digital Bundles';
    }

    private function detectSize(string $text): ?string
    {
        if (preg_match('/(\d+(?:\.\d+)?)\s*(GB|MB|Units?)/i', $text, $matches)) {
            $unit = strtoupper($matches[2]);
            $unit = $unit === 'UNITS' ? 'Units' : $unit;

            return trim($matches[1] . ' ' . $unit);
        }

        return null;
    }

    private function detectValidity(string $text): ?string
    {
        $validityKeywords = [
            'non expiry' => 'Non-Expiry',
            'non-expiry' => 'Non-Expiry',
            'no expiry' => 'Non-Expiry',
            'daily' => 'Daily',
            'weekly' => 'Weekly',
            'monthly' => 'Monthly',
            '30 days' => '30 Days',
            '90 days' => '90 Days',
        ];

        $haystack = Str::lower($text);

        foreach ($validityKeywords as $needle => $label) {
            if (Str::contains($haystack, $needle)) {
                return $label;
            }
        }

        return null;
    }

    private function detectTag(string $text): ?string
    {
        $haystack = Str::lower($text);

        if (Str::contains($haystack, 'special')) {
            return 'Special Rate';
        }

        if (Str::contains($haystack, 'promo') || Str::contains($haystack, 'promotion')) {
            return 'Promo';
        }

        return null;
    }

    private function guessCategory(string $text): string
    {
        $haystack = Str::lower($text);

        if (Str::contains($haystack, 'ecg')) {
            return 'ecg';
        }

        if (Str::contains($haystack, 'result')) {
            return 'results';
        }

        if (Str::contains($haystack, 'shop')) {
            return 'shop';
        }

        return 'data';
    }
};
