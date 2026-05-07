<?php

namespace App\Console\Commands;

use App\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class FixProductGigshubMappings extends Command
{
    protected $signature = 'products:fix-gigshub-mappings
                          {--dry-run : Show what would be changed without making changes}
                          {--product-id= : Fix only a specific product ID}';

    protected $description = 'Ensure all products have required Gigshub external mappings';

    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $productId = $this->option('product-id');

        $query = Product::query();
        if ($productId) {
            $query->where('id', $productId);
        }

        $products = $query->get();

        if ($products->isEmpty()) {
            $this->info('No products found.');
            return self::SUCCESS;
        }

        $this->info("Processing " . $products->count() . " product(s)...");

        $fixed = 0;
        $skipped = 0;

        foreach ($products as $product) {
            $desc = $product->decoded_description;

            // Check if already has complete Gigshub mapping
            if ($this->hasCompleteMapping($desc)) {
                $this->line("✓ Product {$product->id} ({$product->name}) - already has complete mapping");
                $skipped++;
                continue;
            }

            $this->line("⚠ Product {$product->id} ({$product->name}) - missing mappings, will fix");

            // Build the complete mapping
            $updated = $this->ensureGigshubMapping($desc);
            $jsonStr = json_encode($updated, JSON_UNESCAPED_SLASHES);

            if (!$dryRun) {
                $product->description = $jsonStr;
                $product->save();
                $this->line("  ✓ Saved");
            } else {
                $this->line("  [DRY-RUN] Would update to:");
                $this->line("    " . json_encode($updated['external_mappings']['gigshub'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }

            $fixed++;
        }

        $this->info("\n" . ($dryRun ? '[DRY-RUN] ' : '') . "Results: {$fixed} fixed, {$skipped} skipped");

        return self::SUCCESS;
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
            $gigshub['offer_slug'] = $this->inferOfferSlug($desc, $gigshub);
        }

        if (empty($gigshub['network'])) {
            $gigshub['network'] = $this->inferNetwork($desc, $gigshub);
        }

        if (empty($gigshub['volume'])) {
            $gigshub['volume'] = $this->inferVolume($desc, $gigshub);
        }

        return $desc;
    }

    private function inferOfferSlug(array $desc, array $gigshub): string
    {
        // Try to extract from name if available
        $name = $desc['name'] ?? '';
        if ($name) {
            // Convert "MTN 1GB DATA" -> "mtn_data"
            $slug = Str::slug(strtolower($name));
            $slug = preg_replace('/\-\d+.*$/', '', $slug); // Remove trailing numbers and sizes
            return $slug ?: 'unknown_offer';
        }

        return 'unknown_offer';
    }

    private function inferNetwork(array $desc, array $gigshub): string
    {
        // Check various network fields
        if (!empty($desc['network'])) {
            return strtolower($desc['network']);
        }

        if (!empty($desc['external_network'])) {
            return strtolower($desc['external_network']);
        }

        // Default to MTN if nothing found
        return 'mtn';
    }

    private function inferVolume(array $desc, array $gigshub): string
    {
        // Try to extract from size field (e.g., "1GB" -> "1")
        if (!empty($desc['size'])) {
            if (preg_match('/(\d+)\s*gb/i', $desc['size'], $matches)) {
                return (string) $matches[1];
            }
        }

        // Default to 1
        return '1';
    }
}
