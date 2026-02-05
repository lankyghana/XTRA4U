<?php
$s = file_get_contents(__DIR__ . '/../storage/logs/vendor_dashboard_debug.html');
$plain = strip_tags($s);
if (strpos($plain, 'Recent Orders') !== false) {
    echo "FOUND\n";
} else {
    echo "NOTFOUND\n";
}
