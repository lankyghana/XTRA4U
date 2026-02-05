<?php
$s = file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_debug.html');
$plain = strip_tags($s);
$needle = 'Recent Orders';
$posRaw = strpos($s, $needle);
$posPlain = strpos($plain, $needle);

echo "raw pos: ".var_export($posRaw, true)."\n";
echo "plain pos: ".var_export($posPlain, true)."\n\n";
echo "if 'Recent' in plain: ";
$p = strpos($plain, 'Recent');
var_export($p);
echo "\n";
echo "if 'Orders' in plain: ";
$p2 = strpos($plain, 'Orders');
var_export($p2);
echo "\n\n";
if ($posRaw !== false) {
    echo "=== RAW AROUND ===\n";
    echo substr($s, $posRaw - 50, 200)."\n";
}

if ($posPlain !== false) {
    echo "=== PLAIN AROUND ===\n";
    echo substr($plain, $posPlain - 50, 200)."\n";
} else {
    echo "PLAIN DOES NOT CONTAIN. Showing plain fragment around where HTML had the h3 tag:\n";
    // find the h3 tag around raw position
    $h3pos = strpos($s, '<h3', $posRaw - 200);
    $end = strpos($s, '</h3>', $h3pos) + 5;
    echo substr($plain, max(0, $h3pos - 100), 400)."\n";
    echo "\nSTRIPPED VERSION OF H3: \n";
    $h3 = substr($s, $h3pos, $end - $h3pos);
    echo strip_tags($h3)."\n";
}
