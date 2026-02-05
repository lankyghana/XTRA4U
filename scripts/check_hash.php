<?php
$s = file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_debug.html');
if (strpos($s, '#1') !== false) echo "FOUND\n"; else echo "NOTFOUND\n";
