<?php
require __DIR__ . '/fingerprint_local_match.php';
fp_ensure_native_match_loaded();
echo json_encode([
    'php' => PHP_VERSION,
    'native' => fp_native_status(),
], JSON_PRETTY_PRINT) . PHP_EOL;
