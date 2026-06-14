<?php

namespace App\Console\Commands;

use App\Models\Vendor;
use Illuminate\Console\Command;

class ExportVendorNumbers extends Command
{
    protected $signature = 'vendors:export-numbers
                            {--output= : Path to write CSV file (default: storage/exports/vendor_numbers.csv)}
                            {--approved-only : Only export approved vendors}
                            {--with-details : Include name, email, vendor_code, wallet_balance in export}
                            {--stdout : Print to terminal instead of writing a file}';

    protected $description = 'Export vendor phone numbers to a CSV file';

    public function handle(): int
    {
        $outputPath = $this->option('output')
            ?? storage_path('exports/vendor_numbers.csv');

        $approvedOnly  = (bool) $this->option('approved-only');
        $withDetails   = (bool) $this->option('with-details');
        $toStdout      = (bool) $this->option('stdout');

        $query = Vendor::query()
            ->when($approvedOnly, fn ($q) => $q->where('is_approved', true))
            ->orderBy('id');

        $columns = $withDetails
            ? ['id', 'name', 'email', 'phone_number', 'vendor_code', 'wallet_balance', 'is_approved']
            : ['id', 'phone_number'];

        $total = $query->count();

        if ($total === 0) {
            $this->warn('No vendors found matching the given criteria.');
            return self::SUCCESS;
        }

        $this->info("Exporting {$total} vendor(s)...");

        if ($toStdout) {
            $handle = STDOUT;
        } else {
            $dir = dirname($outputPath);
            if (! is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $handle = fopen($outputPath, 'w');
            if ($handle === false) {
                $this->error("Could not open file for writing: {$outputPath}");
                return self::FAILURE;
            }
        }

        // Write header row
        fputcsv($handle, $columns);

        // Stream results in chunks to avoid memory issues with large tables
        $query->select($columns)->chunkById(500, function ($vendors) use ($handle, $columns) {
            foreach ($vendors as $vendor) {
                fputcsv($handle, array_map(fn ($col) => $vendor->$col, $columns));
            }
        });

        if (! $toStdout) {
            fclose($handle);
            $this->info("Export complete: {$outputPath}");
        }

        return self::SUCCESS;
    }
}
