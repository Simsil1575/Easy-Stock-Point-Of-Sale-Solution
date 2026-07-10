<?php

$inactivity_was_preloaded = isset($inactivity_enabled_for_user);
if (!isset($inactivity_enabled_for_user)) {
    $inactivity_enabled_for_user = false;
    $inactivity_idle_seconds = 120;

    try {
        require_once __DIR__ . '/inactivity_settings_helper.php';
        $inactivityDb = new PDO('sqlite:' . __DIR__ . '/pos.db');
        $inactivityDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $inactivitySettings = loadInactivitySettings($inactivityDb);
        $inactivity_idle_seconds = (int) $inactivitySettings['idle_seconds'];
        $inactivity_enabled_for_user = inactivityEnabledForSession($inactivitySettings);
    } catch (Throwable $e) {
        $inactivity_enabled_for_user = false;
        $inactivity_idle_seconds = 120;
    }
}

if (!isset($inactivity_idle_seconds)) {
    if (isset($cashier_idle_timeout_seconds)) {
        $inactivity_idle_seconds = (int) $cashier_idle_timeout_seconds;
    } else {
        $inactivity_idle_seconds = 120;
    }
}
?>
<script>
    if (typeof window.CASHIER_INACTIVITY_ENABLED === 'undefined') {
        window.CASHIER_INACTIVITY_ENABLED = <?= !empty($inactivity_enabled_for_user) ? 'true' : 'false' ?>;
    }
    if (typeof window.CASHIER_IDLE_TIMEOUT_SECONDS === 'undefined') {
        window.CASHIER_IDLE_TIMEOUT_SECONDS = <?= (int) $inactivity_idle_seconds ?>;
    }
</script>
<script src="/cashier_inactivity.js"></script>
