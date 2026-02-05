<?php
$plain = strip_tags(file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_debug.html'));
$start = 700;
echo "PLAIN CHUNK from $start for 200 chars:\n";
echo substr($plain, $start, 200)."\n";
$start2 = 760;
echo "\nPLAIN CHUNK from $start2 for 200 chars:\n";
echo substr($plain, $start2, 200)."\n";
