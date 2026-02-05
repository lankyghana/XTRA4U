<?php
$s = file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_paid_order_debug.html');
$pos = strpos($s, '#1');
if ($pos === false) {
    echo "#1 not found in raw\n";
    exit;
}
$start = max(0, $pos - 10);
$chunk = substr($s, $start, 20);
echo "RAW CHUNK: ".json_encode($chunk)."\n";
for ($i=0; $i<strlen($chunk); $i++) {
    $c = $chunk[$i];
    printf("%d: %d %s\n", $start+$i, ord($c), json_encode($c));
}
$plain = strip_tags($s);
$posPlain = strpos($plain, '#1');
if ($posPlain === false) {
    echo "#1 not found in plain\n";
    // show plain around where '#1' was expected
    $approx = strpos($plain, 'Order ID');
    echo "Plain fragment: ".substr($plain, max(0, $approx-50), 200)."\n";
} else {
    echo "Found in plain at $posPlain\n";
}
