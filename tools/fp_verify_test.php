<?php
require dirname(__DIR__) . '/userdb_fingerprint_helpers.php';
require dirname(__DIR__) . '/fingerprint_local_match.php';
fp_ensure_native_match_loaded();

$db = new PDO('sqlite:' . dirname(__DIR__) . '/user.db');
$rows = userdb_fetch_users_with_templates($db);
$plan = fp_collect_template_entries($rows);

$t0 = microtime(true);
fp_native_is_available();
$t1 = microtime(true);
echo 'is_available_ms=' . round(($t1 - $t0) * 1000) . ' (no ping)' . PHP_EOL;

if ($plan['native_tokens'] !== []) {
    $t2 = microtime(true);
    fp_native_batch_fmd_similarity($plan['native_tokens'][0], $plan['native_tokens']);
    $t3 = microtime(true);
    echo 'old_path_batch_ms=' . round(($t3 - $t2) * 1000) . PHP_EOL;
}

echo 'verify_scan needs raw scan from device — engine ready=' . (fp_native_is_available() ? 'yes' : 'no') . PHP_EOL;
