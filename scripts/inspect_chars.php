<?php
$s = file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_debug.html');
$needle = 'Recent Orders';
$pos = strpos($s, $needle);
$start = max(0, $pos - 20);
$chunk = substr($s, $start, 60);

echo "RAW CHUNK: [".json_encode($chunk)."]\n";
for ($i=0;$i<strlen($chunk);$i++) {
    $c = $chunk[$i];
    echo ($i + $start) .":" . ord($c) . " ('".json_encode($c)."')\n";
}
