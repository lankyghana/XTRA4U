<?php

namespace App\Services\VendorContactExport;

final class ExportStats
{
    public int $scanned = 0;

    public int $skippedInvalid = 0;

    public int $duplicatesRemoved = 0;

    public int $exported = 0;
}
