<?php
$products = App\Models\Product::get();
foreach ($products as $p) {
    $meta = json_decode($p->description, true) ?? [];
    echo "Product {$p->id}: \n";
    print_r($meta['external_mappings'] ?? []);
    echo "\n";
}
