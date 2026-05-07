<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Str;

class EnsureProductsHaveGigshubMappings extends Migration
{
    public function up()
    {
        $products = \DB::table('products')->get();

        foreach ($products as $product) {
            $description = $product->description;
            if (!$description) {
                continue;
            }

            $desc = json_decode($description, true);
            if (!is_array($desc)) {
                continue;
            }

            // Check if already has complete mapping
            if ($this->hasCompleteMapping($desc)) {
                continue;
            }

            // Add/fix missing mappings
            $updated = $this->ensureGigshubMapping($desc);
            $jsonStr = json_encode($updated, JSON_UNESCAPED_SLASHES);

            \DB::table('products')
                ->where('id', $product->id)
                ->update(['description' => $jsonStr]);
        }
    }

    public function down()
    {
        // Mappings are additive, safe to leave in place
    }

    private function hasCompleteMapping(array $desc): bool
    {
        $mapping = $desc['external_mappings']['gigshub'] ?? [];

        return !empty($mapping['offer_slug']) &&
               !empty($mapping['network']) &&
               isset($mapping['volume']);
    }

    private function ensureGigshubMapping(array $desc): array
    {
        if (!isset($desc['external_mappings'])) {
            $desc['external_mappings'] = [];
        }

        if (!isset($desc['external_mappings']['gigshub'])) {
            $desc['external_mappings']['gigshub'] = [];
        }

        $gigshub = &$desc['external_mappings']['gigshub'];

        // Preserve existing values, fill in missing ones
        if (empty($gigshub['offer_slug'])) {
            $gigshub['offer_slug'] = $this->inferOfferSlug($desc);
        }

        if (empty($gigshub['network'])) {
            $gigshub['network'] = $this->inferNetwork($desc);
        }

        if (empty($gigshub['volume'])) {
            $gigshub['volume'] = $this->inferVolume($desc);
        }

        return $desc;
    }

    private function inferOfferSlug(array $desc): string
    {
        $name = $desc['name'] ?? '';
        if ($name) {
            $slug = Str::slug(strtolower($name));
            $slug = preg_replace('/\-\d+.*$/', '', $slug);
            return $slug ?: 'unknown_offer';
        }

        return 'unknown_offer';
    }

    private function inferNetwork(array $desc): string
    {
        if (!empty($desc['network'])) {
            return strtolower($desc['network']);
        }

        if (!empty($desc['external_network'])) {
            return strtolower($desc['external_network']);
        }

        return 'mtn';
    }

    private function inferVolume(array $desc): string
    {
        if (!empty($desc['size'])) {
            if (preg_match('/(\d+)\s*gb/i', $desc['size'], $matches)) {
                return (string) $matches[1];
            }
        }

        return '1';
    }
}
