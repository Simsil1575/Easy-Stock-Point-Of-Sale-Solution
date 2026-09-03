<?php
declare(strict_types=1);

$odReceiptJs = $odReceiptJs ?? 'receipt.php?js=true';
$odDrawerUrl = $odDrawerUrl ?? 'open_drawer.php';
$odCashierUsername = $odCashierUsername ?? ($_SESSION['username'] ?? '');
?>
<script src="<?= htmlspecialchars($odReceiptJs, ENT_QUOTES, 'UTF-8') ?>"></script>
<script>
function openCashDrawer() {
    if (typeof openCashDrawerShared === 'function') {
        return openCashDrawerShared(<?= json_encode($odCashierUsername, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    }

    var drawerData = {
        open_drawer_only: true,
        cashier_username: <?= json_encode($odCashierUsername, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
    };

    if (typeof sendToPrinter === 'function') {
        return sendToPrinter(drawerData).catch(function() {
            return { success: false };
        });
    }

    return fetch(<?= json_encode($odDrawerUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(drawerData)
    })
        .then(function(response) { return response.json(); })
        .then(function(result) {
            if (result && result.requires_client_print && typeof sendToPrinter === 'function') {
                return sendToPrinter(result.receipt_data || result.order_data || drawerData);
            }
            return result;
        })
        .catch(function() { return { success: false }; });
}
</script>
