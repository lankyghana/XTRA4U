<?php

namespace App\Services\VendorContactExport;

use Generator;

/**
 * Streams contacts (from VendorContactExporter::contacts) to an open output stream in the chosen format.
 * Nothing is accumulated: each contact is written as it is produced.
 */
final class VendorContactWriter
{
    public const FORMATS = [
        'txt' => ['mime' => 'text/plain; charset=UTF-8', 'ext' => 'txt'],
        'csv' => ['mime' => 'text/csv; charset=UTF-8', 'ext' => 'csv'],
        'xlsx' => ['mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ext' => 'xlsx'],
        'vcf' => ['mime' => 'text/vcard; charset=UTF-8', 'ext' => 'vcf'],
    ];

    /** @param Generator<int, array<string, mixed>> $contacts */
    public function write(string $format, Generator $contacts, array $o, $out): void
    {
        match ($format) {
            'txt' => $this->txt($contacts, $o, $out),
            'csv' => $this->csv($contacts, $o, $out),
            'xlsx' => $this->xlsx($contacts, $o, $out),
            'vcf' => $this->vcf($contacts, $o, $out),
        };
    }

    private function txt(Generator $contacts, array $o, $out): void
    {
        $withName = ($o['txt_style'] ?? 'phones') === 'name_phone';
        foreach ($contacts as $c) {
            if ($c['phone'] === '') {
                continue;
            }
            fwrite($out, ($withName ? $this->displayName($c).' - ' : '').$c['phone']."\n");
        }
    }

    private function csv(Generator $contacts, array $o, $out): void
    {
        $fields = $this->fields($o);
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel reads names correctly
        fputcsv($out, $this->headers($fields), ',', '"', '');
        foreach ($contacts as $c) {
            fputcsv($out, array_map(fn ($f) => $this->csvSafe($f, $c[$f]), $fields), ',', '"', '');
        }
    }

    private function xlsx(Generator $contacts, array $o, $out): void
    {
        $fields = $this->fields($o);
        $xlsx = new SimpleXlsxWriter('Vendor Contacts');
        try {
            $xlsx->addRow($this->headers($fields), true);
            foreach ($contacts as $c) {
                $xlsx->addRow(array_map(fn ($f) => $f === 'id' ? (int) $c[$f] : (string) $c[$f], $fields));
            }
        } catch (\Throwable $e) {
            $xlsx->abort();
            throw $e;
        }
        $xlsx->finish($out);
    }

    private function vcf(Generator $contacts, array $o, $out): void
    {
        $prefix = ($o['vcf_name'] ?? 'xtra4u_vendor_name') === 'xtra4u_vendor_name';
        $fields = $o['fields'] ?? [];
        foreach ($contacts as $c) {
            $name = $this->displayName($c);
            $name = $prefix ? 'XTRA4U - '.$name : $name;

            $lines = [
                'BEGIN:VCARD',
                'VERSION:3.0',
                'FN:'.self::vcfEscape($name),
                'N:;'.self::vcfEscape($name).';;;',
            ];
            if ($c['phone'] !== '') {
                $lines[] = 'TEL;TYPE=CELL:'.self::vcfEscape($c['phone']);
            }
            if (in_array('email', $fields, true) && $c['email'] !== '') {
                $lines[] = 'EMAIL;TYPE=INTERNET:'.self::vcfEscape($c['email']);
            }
            if (in_array('vendor_code', $fields, true) && $c['vendor_code'] !== '') {
                $lines[] = 'NOTE:'.self::vcfEscape('Vendor code '.$c['vendor_code']);
            }
            $lines[] = 'END:VCARD';

            foreach ($lines as $line) {
                fwrite($out, self::vcfFold($line)."\r\n");
            }
        }
    }

    /** Name used for a contact: vendor name, falling back to code, email, then id. */
    private function displayName(array $c): string
    {
        foreach (['name', 'vendor_code', 'email'] as $key) {
            if (($c[$key] ?? '') !== '') {
                return $c[$key];
            }
        }

        return 'Vendor #'.$c['id'];
    }

    /** @return list<string> whitelisted, ordered as in VendorContactExporter::FIELDS */
    private function fields(array $o): array
    {
        $chosen = $o['fields'] ?? ['name', 'phone'];

        return array_values(array_filter(array_keys(VendorContactExporter::FIELDS), fn ($f) => in_array($f, $chosen, true)));
    }

    private function headers(array $fields): array
    {
        return array_map(fn ($f) => VendorContactExporter::FIELDS[$f], $fields);
    }

    /** Neutralise spreadsheet formula injection in vendor-controlled text. Phones/ids are generated, not free text. */
    private function csvSafe(string $field, mixed $value): mixed
    {
        if (in_array($field, ['name', 'email', 'vendor_code', 'tier'], true)
            && is_string($value) && preg_match('/^[=+\-@\t\r]/', $value)) {
            return "'".$value;
        }

        return $value;
    }

    public static function vcfEscape(string $value): string
    {
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\\;', '\\,', '\\n', '\\n', '\\n'], $value);
    }

    /** RFC 6350/2426 line folding: max 75 octets per line, continuation lines start with one space. */
    public static function vcfFold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $current = '';
        $limit = 75;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            if (strlen($current) + strlen($char) > $limit) {
                $out .= $current."\r\n ";
                $current = '';
                $limit = 74;
            }
            $current .= $char;
        }

        return $out.$current;
    }
}
