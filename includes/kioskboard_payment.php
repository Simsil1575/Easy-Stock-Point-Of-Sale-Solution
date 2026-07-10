<?php
/**
 * POS virtual keyboard assets (plain JS side panel).
 * Set $kbAssetPrefix before include: '' for root, '../' for role subfolders.
 * Set $kbPart to 'styles', 'script', or 'both' (default).
 * Set $kbEnabled to override the business setting (optional).
 */
$kbAssetPrefix = $kbAssetPrefix ?? '';
$kbPart = $kbPart ?? 'both';

if (!isset($kbEnabled)) {
    $kbEnabled = false;
    try {
        require_once __DIR__ . '/../touch_keyboard_settings_helper.php';
        $kbSettingsDb = new PDO('sqlite:' . __DIR__ . '/../pos.db');
        $kbSettingsDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $kbEnabled = loadTouchKeyboardEnabled($kbSettingsDb);
    } catch (Throwable $e) {
        $kbEnabled = false;
    }
}

$kbEnabledJs = $kbEnabled ? 'true' : 'false';
$kbScriptSrc = htmlspecialchars($kbAssetPrefix, ENT_QUOTES, 'UTF-8') . 'js/kioskboard-payment-init.js?v=pos-kb-16';
?>
<?php if ($kbPart === 'both' || $kbPart === 'script'): ?>
<script>window.POS_TOUCH_KEYBOARD_ENABLED = <?= $kbEnabledJs ?>;</script>
<script src="<?= $kbScriptSrc ?>"></script>
<?php endif; ?>
<?php if ($kbPart === 'both' || $kbPart === 'styles'): ?>
<style>
    <?php if (!$kbEnabled): ?>
    body.pos-touch-kb-disabled .kioskboard-touch-icon,
    .kioskboard-touch-icon {
        display: none !important;
    }

    #KioskBoard-VirtualKeyboard {
        display: none !important;
    }
    <?php endif; ?>
    :root {
        --pos-kb-side-width: min(340px, 36vw);
    }

    /* Base panel — fixed to viewport (replaces removed KioskBoard CDN CSS) */
    #KioskBoard-VirtualKeyboard {
        position: fixed !important;
        z-index: 10050 !important;
        box-sizing: border-box;
        pointer-events: auto;
    }

    /* Side panel — docked right, modal stays visible on the left */
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel {
        position: fixed !important;
        top: 0 !important;
        bottom: 0 !important;
        right: 0 !important;
        left: auto !important;
        width: var(--pos-kb-side-width) !important;
        max-width: none !important;
        height: 100vh !important;
        max-height: 100vh !important;
        margin: 0 !important;
        border-radius: 0 !important;
        border-left: 2px solid #0d9488;
        border-top: none;
        box-shadow: -8px 0 32px rgba(15, 23, 42, 0.12);
        padding: 1rem 0.85rem;
        padding-bottom: env(safe-area-inset-bottom, 1rem);
        overflow-x: hidden;
        overflow-y: auto;
        display: flex !important;
        flex-direction: column;
        justify-content: center;
        align-items: stretch;
        background: #f3f4f6 !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel.kioskboard-fade,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel.kioskboard-slide {
        animation: pos-kb-slide-in-right 0.2s ease-out !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel.kioskboard-fade-remove,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel.kioskboard-slide-remove {
        animation: pos-kb-slide-out-right 0.18s ease-in forwards !important;
    }

    @keyframes pos-kb-slide-in-right {
        from { transform: translateX(100%); opacity: 0.6; }
        to { transform: translateX(0); opacity: 1; }
    }

    @keyframes pos-kb-slide-out-right {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }

    /* Bottom panel — cart cash numpad slides up without shifting page layout */
    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel {
        position: fixed !important;
        left: 0 !important;
        right: 0 !important;
        bottom: 0 !important;
        top: auto !important;
        width: 100% !important;
        max-width: none !important;
        height: auto !important;
        max-height: min(52vh, 420px) !important;
        margin: 0 !important;
        border-radius: 1rem 1rem 0 0 !important;
        border-top: 2px solid #0d9488;
        border-left: none;
        border-right: none;
        box-shadow: 0 -8px 32px rgba(15, 23, 42, 0.14);
        padding: 0.75rem 1rem;
        padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));
        overflow-x: hidden;
        overflow-y: auto;
        display: flex !important;
        flex-direction: column;
        justify-content: center;
        align-items: stretch;
        background: #f3f4f6 !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel.kioskboard-fade,
    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel.kioskboard-slide {
        animation: pos-kb-slide-in-bottom 0.2s ease-out !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel.kioskboard-fade-remove,
    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel.kioskboard-slide-remove {
        animation: pos-kb-slide-out-bottom 0.18s ease-in forwards !important;
    }

    @keyframes pos-kb-slide-in-bottom {
        from { transform: translateY(100%); opacity: 0.6; }
        to { transform: translateY(0); opacity: 1; }
    }

    @keyframes pos-kb-slide-out-bottom {
        from { transform: translateY(0); opacity: 1; }
        to { transform: translateY(100%); opacity: 0; }
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-wrapper {
        width: 100% !important;
        max-width: 360px;
        margin: 0 auto;
        display: flex !important;
        flex-direction: column !important;
        flex-wrap: nowrap !important;
        gap: 0.4rem;
        background: transparent !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-row {
        width: 100% !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        justify-content: center;
        align-items: stretch;
        gap: 0.4rem;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-row .kioskboard-key,
    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-row .pos-kb-clear-key {
        flex: 1 1 0 !important;
        width: auto !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 3rem !important;
        margin: 0 !important;
        padding: 0.55rem 0.25rem !important;
        border-radius: 0.5rem !important;
        border: 1px solid #d1d5db !important;
        background: #ffffff !important;
        color: #111827 !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08) !important;
        font-size: 1.25rem !important;
        font-weight: 600 !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        transform: none !important;
        user-select: none;
        -webkit-user-select: none;
        cursor: pointer;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-row span[class^="kioskboard-key"]:hover,
    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-row span[class^="kioskboard-key"]:active {
        background: #ccfbf1 !important;
        border-color: #14b8a6 !important;
        color: #0f766e !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-key-enter {
        background: #0d9488 !important;
        border-color: #0d9488 !important;
        color: #ffffff !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .kioskboard-key-backspace {
        background: #e5e7eb !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .pos-kb-actions-row {
        width: 100% !important;
        margin: 0.15rem 0 0 !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel .pos-kb-clear-key {
        width: 100% !important;
        flex: 1 1 100% !important;
        min-height: 3rem !important;
        background: #fef3c7 !important;
        border-color: #f59e0b !important;
        color: #92400e !important;
        font-size: 1.05rem !important;
        font-weight: 700 !important;
    }

    @media (min-width: 1024px) {
        #KioskBoard-VirtualKeyboard.pos-kb-bottom-panel {
            left: auto !important;
            right: 0.5rem !important;
            width: min(24rem, calc(100vw - 1rem)) !important;
            max-width: 24rem !important;
            border-radius: 1rem 1rem 0 0 !important;
        }
    }

    #cart .kioskboard-input-wrap {
        position: relative;
    }

    #cart .kioskboard-input-wrap .kioskboard-touch-icon {
        position: absolute;
        right: 0.65rem;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: #14b8a6;
        opacity: 0.75;
        width: 1rem;
        height: 1rem;
    }

    #cart #cashReceived.js-kioskboard-input {
        padding-right: 2rem;
    }

    /* Modal stays centered — keyboard overlays the right side only */
    body.pos-kb-side-active #paymentModal {
        overflow-y: auto;
    }

    body.pos-kb-side-active #paymentModal > div {
        margin-left: auto !important;
        margin-right: auto !important;
    }

    body.kioskboard-body-padding {
        padding-bottom: 0 !important;
        padding-top: 0 !important;
    }

    /* Plain-JS keys (same look as before) */
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-key,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .pos-kb-clear-key {
        user-select: none;
        -webkit-user-select: none;
        cursor: pointer;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-wrapper {
        width: 100% !important;
        max-width: 300px;
        margin: 0 auto;
        display: flex !important;
        flex-direction: column !important;
        flex-wrap: nowrap !important;
        gap: 0.4rem;
        background: transparent !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row {
        width: 100% !important;
        display: flex !important;
        flex-wrap: nowrap !important;
        justify-content: center;
        align-items: stretch;
        gap: 0.4rem;
        margin: 0 !important;
        padding: 0 !important;
        border: none !important;
    }

    /* Compact, touch-friendly keys */
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row .kioskboard-key,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row .pos-kb-clear-key {
        flex: 1 1 0 !important;
        width: auto !important;
        max-width: none !important;
        min-width: 0 !important;
        min-height: 3.25rem !important;
        margin: 0 !important;
        padding: 0.65rem 0.25rem !important;
        border-radius: 0.5rem !important;
        border: 1px solid #d1d5db !important;
        border-bottom-width: 1px !important;
        background: #ffffff !important;
        color: #111827 !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.08) !important;
        font-size: 1.35rem !important;
        font-weight: 600 !important;
        line-height: 1 !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        text-align: center !important;
        transform: none !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row span[class^="kioskboard-key"]:hover,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row span[class^="kioskboard-key"]:active {
        background: #ccfbf1 !important;
        border-color: #14b8a6 !important;
        color: #0f766e !important;
        transform: none !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-key-enter {
        background: #0d9488 !important;
        border-color: #0d9488 !important;
        color: #ffffff !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-key-enter:hover,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-key-enter:active {
        background: #0f766e !important;
        border-color: #0f766e !important;
        color: #ffffff !important;
    }

    #KioskBoard-VirtualKeyboard .kioskboard-key-capslock.pos-kb-caps-active,
    #KioskBoard-VirtualKeyboard .kioskboard-key-specialcharacter.pos-kb-symbols-active {
        background: #0d9488 !important;
        border-color: #0d9488 !important;
        color: #ffffff !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-symbols-mode .kioskboard-key-capslock {
        display: none !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) .kioskboard-row-bottom span.kioskboard-key-specialcharacter {
        flex: 0 1 auto !important;
        min-width: 3.25rem !important;
        font-size: 0.85rem !important;
        letter-spacing: 0.02em;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-key-backspace {
        background: #e5e7eb !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .pos-kb-actions-row {
        width: 100% !important;
        margin: 0.15rem 0 0 !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .pos-kb-clear-key {
        width: 100% !important;
        flex: 1 1 100% !important;
        min-height: 3.25rem !important;
        background: #fef3c7 !important;
        border-color: #f59e0b !important;
        color: #92400e !important;
        font-size: 1.1rem !important;
        font-weight: 700 !important;
        letter-spacing: 0.02em;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .pos-kb-clear-key:hover,
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .pos-kb-clear-key:active {
        background: #fde68a !important;
        border-color: #d97706 !important;
        color: #78350f !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel .kioskboard-row span[class^="kioskboard-key"] svg {
        position: static !important;
        left: auto !important;
        top: auto !important;
        width: 1.25rem !important;
        height: 1.25rem !important;
        margin: 0 auto !important;
        fill: #374151 !important;
    }

    /* Decimal pad: hide letter-keyboard chrome, keep Backspace + Done */
    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-key-capslock,
    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-key-specialcharacter,
    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-key-space {
        display: none !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-row-bottom {
        display: grid !important;
        grid-template-columns: 1fr 1fr;
        gap: 0.4rem;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-row-bottom span.kioskboard-key-backspace,
    #KioskBoard-VirtualKeyboard.pos-kb-decimal-mode .kioskboard-row-bottom span.kioskboard-key-enter {
        width: 100% !important;
        min-height: 3.25rem !important;
    }

    /* Full text keyboard — wider panel so letter keys are easier to hit */
    #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) {
        width: min(440px, 44vw) !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) .kioskboard-wrapper {
        max-width: 400px;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) .kioskboard-row span[class^="kioskboard-key"] {
        min-height: 2.6rem !important;
        font-size: 1rem !important;
        padding: 0.45rem 0.15rem !important;
    }

    #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) .kioskboard-row-bottom span.kioskboard-key-space {
        flex: 2 1 40% !important;
    }

    .js-kioskboard-input {
        touch-action: manipulation;
        cursor: text;
        -webkit-user-select: text !important;
        user-select: text !important;
    }

    input.js-kioskboard-input,
    textarea.js-kioskboard-input,
    .swal2-popup input,
    .swal2-popup textarea {
        -webkit-user-select: text !important;
        user-select: text !important;
        pointer-events: auto;
    }

    #paymentModal .js-kioskboard-input:not([readonly]),
    #editTabNameModal .js-kioskboard-input:not([readonly]),
    #searchBar.js-kioskboard-input,
    #loginForm .js-kioskboard-input:not([readonly]),
    .swal2-popup .js-kioskboard-input {
        min-height: 3rem;
        font-size: 1.125rem;
    }

    #paymentModal .kioskboard-input-wrap,
    #editTabNameModal .kioskboard-input-wrap,
    #loginForm .kioskboard-input-wrap {
        position: relative;
    }

    #loginForm .kioskboard-input-wrap > i.fas {
        left: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
    }

    #paymentModal .kioskboard-input-wrap .kioskboard-touch-icon,
    #editTabNameModal .kioskboard-input-wrap .kioskboard-touch-icon,
    #loginForm .kioskboard-input-wrap .kioskboard-touch-icon {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: #14b8a6;
        opacity: 0.75;
        width: 1.125rem;
        height: 1.125rem;
    }

    #paymentModal .js-kioskboard-input:not([readonly]),
    #editTabNameModal .js-kioskboard-input:not([readonly]) {
        padding-right: 2.5rem;
    }

    .swal2-popup .js-kioskboard-input {
        min-height: 3rem;
        font-size: 1.125rem;
    }

    .swal2-popup .kioskboard-input-wrap {
        position: relative;
    }

    .swal2-popup .kioskboard-input-wrap .kioskboard-touch-icon {
        position: absolute;
        right: 0.75rem;
        top: 50%;
        transform: translateY(-50%);
        pointer-events: none;
        color: #14b8a6;
        opacity: 0.75;
        width: 1.125rem;
        height: 1.125rem;
    }

    body.pos-kb-side-active .swal2-container {
        overflow: visible;
    }

    @media (max-width: 640px) {
        :root {
            --pos-kb-side-width: min(280px, 46vw);
        }

        #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) {
            width: min(320px, 52vw) !important;
        }

        #KioskBoard-VirtualKeyboard.pos-kb-side-panel:not(.pos-kb-decimal-mode) .kioskboard-wrapper {
            max-width: 300px;
        }
    }

    /* Hide virtual keyboard chrome on phones — native keyboard is used instead */
    @media (max-width: 767px) {
        #KioskBoard-VirtualKeyboard {
            display: none !important;
        }

        .kioskboard-touch-icon {
            display: none !important;
        }
    }
</style>
<?php endif; ?>
