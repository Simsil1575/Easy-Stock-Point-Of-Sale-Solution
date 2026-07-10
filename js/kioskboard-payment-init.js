/**
 * POS side-panel virtual keyboard (plain JS — no third-party library).
 * Keeps existing CSS class names so styling in kioskboard_payment.php is unchanged.
 */
(function (window) {
    'use strict';

    var KB_ID = 'KioskBoard-VirtualKeyboard';
    var ANIM_MS = 200;
    var activeInput = null;
    var capsLock = false;
    var symbolsMode = false;
    var closeTimer = null;

    var DECIMAL_ROWS = [
        ['7', '8', '9'],
        ['4', '5', '6'],
        ['1', '2', '3'],
        ['.', '0', '00']
    ];

    var NUMBER_ROW = ['1', '2', '3', '4', '5', '6', '7', '8', '9', '0'];

    var TEXT_ROWS = [
        ['q', 'w', 'e', 'r', 't', 'y', 'u', 'i', 'o', 'p'],
        ['a', 's', 'd', 'f', 'g', 'h', 'j', 'k', 'l'],
        ['z', 'x', 'c', 'v', 'b', 'n', 'm']
    ];

    var SYMBOL_ROWS = [
        ['@', '#', '$', '%', '&', '*', '-', '_', '+', '='],
        ['.', ',', '/', '\\', ':', ';', '(', ')', '!', '?'],
        ['"', "'", '`', '~', '|', '[', ']', '{', '}', '<', '>']
    ];

    function normalizeElements(selectorOrElements) {
        if (!selectorOrElements) {
            return [];
        }
        if (typeof selectorOrElements === 'string') {
            return Array.prototype.slice.call(document.querySelectorAll(selectorOrElements));
        }
        if (selectorOrElements.nodeType === 1) {
            return [selectorOrElements];
        }
        if (selectorOrElements.length != null) {
            return Array.prototype.slice.call(selectorOrElements);
        }
        return [];
    }

    function dispatchInputEvents(input) {
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function isDecimalInput(input) {
        return input.classList.contains('js-kioskboard-decimal');
    }

    function getPlacement(input) {
        if (!input) {
            return 'side';
        }
        var placement = input.getAttribute('data-pos-kb-placement') || input.getAttribute('data-kioskboard-placement');
        return placement === 'bottom' ? 'bottom' : 'side';
    }

    function setBodyKbState(placement) {
        document.body.classList.remove('pos-kb-side-active', 'pos-kb-bottom-active');
        if (placement === 'bottom') {
            document.body.classList.add('pos-kb-bottom-active');
        } else if (placement === 'side') {
            document.body.classList.add('pos-kb-side-active');
        }
    }

    function clearBodyKbState() {
        document.body.classList.remove('pos-kb-side-active', 'pos-kb-bottom-active');
    }

    /** Phones use the native keyboard; desktop/tablet POS keeps the side panel. */
    function isMobileDevice() {
        if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
            return true;
        }
        var ua = navigator.userAgent || '';
        return /Android.+Mobile|iPhone|iPod|webOS|BlackBerry|IEMobile|Opera Mini/i.test(ua);
    }

    function insertText(input, text) {
        if (!input || text == null || text === '') {
            return;
        }

        if (input.type === 'number') {
            var numVal = String(input.value || '');
            if (text === '.') {
                if (numVal.indexOf('.') !== -1) {
                    return;
                }
                input.value = numVal + '.';
            } else if (text === '00') {
                input.value = numVal + '00';
            } else {
                input.value = numVal + text;
            }
            dispatchInputEvents(input);
            return;
        }

        var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
        var val = input.value || '';
        input.value = val.slice(0, start) + text + val.slice(end);
        var pos = start + text.length;
        try {
            input.setSelectionRange(pos, pos);
        } catch (err) {
            // ignore
        }
        dispatchInputEvents(input);
    }

    function backspaceInput(input) {
        if (!input) {
            return;
        }

        if (input.type === 'number') {
            var n = String(input.value || '');
            input.value = n.slice(0, -1);
            dispatchInputEvents(input);
            return;
        }

        var start = typeof input.selectionStart === 'number' ? input.selectionStart : input.value.length;
        var end = typeof input.selectionEnd === 'number' ? input.selectionEnd : start;
        if (start !== end) {
            var val = input.value || '';
            input.value = val.slice(0, start) + val.slice(end);
            try {
                input.setSelectionRange(start, start);
            } catch (err) {
                // ignore
            }
        } else if (start > 0) {
            var v = input.value || '';
            input.value = v.slice(0, start - 1) + v.slice(start);
            try {
                input.setSelectionRange(start - 1, start - 1);
            } catch (err2) {
                // ignore
            }
        }
        dispatchInputEvents(input);
    }

    function clearInput(input) {
        if (!input) {
            return;
        }
        input.value = '';
        try {
            input.setSelectionRange(0, 0);
        } catch (err) {
            // ignore
        }
        dispatchInputEvents(input);
        input.focus();
    }

    function createKey(label, extraClass, dataValue) {
        var key = document.createElement('span');
        key.className = 'kioskboard-key' + (extraClass ? ' ' + extraClass : '');
        key.setAttribute('role', 'button');
        key.setAttribute('tabindex', '-1');
        if (dataValue != null) {
            key.dataset.value = dataValue;
        }
        key.textContent = label;
        return key;
    }

    function letterDisplay(ch) {
        return capsLock ? ch.toUpperCase() : ch;
    }

    function createRow(keys, rowClass) {
        var row = document.createElement('div');
        row.className = 'kioskboard-row' + (rowClass ? ' ' + rowClass : '');

        keys.forEach(function (item) {
            if (typeof item === 'object' && item !== null && item.label != null) {
                row.appendChild(createKey(item.label, item.className || '', item.value));
                return;
            }
            var ch = String(item);
            var display = letterDisplay(ch);
            row.appendChild(createKey(display, 'kioskboard-key-' + ch.toLowerCase(), display));
        });

        return row;
    }

    function populateKeyboardWrapper(wrapper, mode) {
        wrapper.textContent = '';

        if (mode === 'decimal') {
            DECIMAL_ROWS.forEach(function (row) {
                wrapper.appendChild(createRow(row));
            });
        } else {
            wrapper.appendChild(createRow(NUMBER_ROW));
            if (symbolsMode) {
                SYMBOL_ROWS.forEach(function (row) {
                    wrapper.appendChild(createRow(row));
                });
            } else {
                TEXT_ROWS.forEach(function (row) {
                    var keys = row.map(function (ch) {
                        var display = letterDisplay(ch);
                        return {
                            label: display,
                            value: display,
                            className: 'kioskboard-key-' + ch
                        };
                    });
                    wrapper.appendChild(createRow(keys));
                });
            }
        }

        var clearRow = document.createElement('div');
        clearRow.className = 'kioskboard-row pos-kb-actions-row';
        clearRow.appendChild(createKey('Clear', 'pos-kb-clear-key', '__clear__'));
        wrapper.appendChild(clearRow);

        var bottomKeys;
        if (mode === 'decimal') {
            bottomKeys = [
                { label: '⌫', value: '__backspace__', className: 'kioskboard-key-backspace' },
                { label: 'Done', value: '__done__', className: 'kioskboard-key-enter' }
            ];
        } else if (symbolsMode) {
            bottomKeys = [
                { label: 'ABC', value: '__symbols__', className: 'kioskboard-key-specialcharacter pos-kb-symbols-active' },
                { label: 'Space', value: ' ', className: 'kioskboard-key-space spacebar-allowed' },
                { label: 'Done', value: '__done__', className: 'kioskboard-key-enter' },
                { label: '⌫', value: '__backspace__', className: 'kioskboard-key-backspace' }
            ];
        } else {
            bottomKeys = [
                { label: '⇧', value: '__caps__', className: 'kioskboard-key-capslock' + (capsLock ? ' pos-kb-caps-active' : '') },
                { label: '#+=', value: '__symbols__', className: 'kioskboard-key-specialcharacter' },
                { label: 'Space', value: ' ', className: 'kioskboard-key-space spacebar-allowed' },
                { label: 'Done', value: '__done__', className: 'kioskboard-key-enter' },
                { label: '⌫', value: '__backspace__', className: 'kioskboard-key-backspace' }
            ];
        }

        var bottomRow = document.createElement('div');
        bottomRow.className = 'kioskboard-row kioskboard-row-bottom';
        bottomKeys.forEach(function (k) {
            bottomRow.appendChild(createKey(k.label, k.className, k.value));
        });
        wrapper.appendChild(bottomRow);
    }

    function getActiveKeyboardMode() {
        if (!activeInput) {
            return 'text';
        }
        return isDecimalInput(activeInput) ? 'decimal' : 'text';
    }

    function refreshKeyboardBody() {
        var keyboard = document.getElementById(KB_ID);
        if (!keyboard || !activeInput) {
            return;
        }
        var mode = getActiveKeyboardMode();
        var wrapper = keyboard.querySelector('.kioskboard-wrapper');
        if (!wrapper) {
            return;
        }
        keyboard.classList.toggle('pos-kb-symbols-mode', symbolsMode && mode === 'text');
        populateKeyboardWrapper(wrapper, mode);
    }

    function handleKeyAction(input, value) {
        if (!input) {
            return;
        }

        if (value === '__clear__') {
            clearInput(input);
            return;
        }
        if (value === '__backspace__') {
            backspaceInput(input);
            input.focus();
            return;
        }
        if (value === '__done__') {
            closeKeyboard(true);
            if (input.classList.contains('quantity-input')) {
                input.blur();
            }
            return;
        }
        if (value === '__caps__') {
            capsLock = !capsLock;
            refreshKeyboardBody();
            input.focus();
            return;
        }
        if (value === '__symbols__') {
            symbolsMode = !symbolsMode;
            refreshKeyboardBody();
            input.focus();
            return;
        }

        insertText(input, value);
        input.focus();
    }

    function attachKeyboardEvents(keyboard, input) {
        if (keyboard.dataset.posKbEvents === '1') {
            return;
        }
        keyboard.dataset.posKbEvents = '1';
        keyboard.addEventListener('pointerdown', function (e) {
            var keyEl = e.target.closest('.kioskboard-key, .pos-kb-clear-key');
            if (!keyEl || !keyboard.contains(keyEl)) {
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            var value = keyEl.dataset.value;
            if (value == null) {
                value = keyEl.textContent;
            }
            handleKeyAction(activeInput, value);
        });
    }

    function closeKeyboard(animate) {
        if (closeTimer) {
            clearTimeout(closeTimer);
            closeTimer = null;
        }

        var keyboard = document.getElementById(KB_ID);
        if (!keyboard) {
            clearBodyKbState();
            activeInput = null;
            return;
        }

        if (animate) {
            keyboard.classList.add('kioskboard-fade-remove');
            closeTimer = window.setTimeout(function () {
                if (keyboard.parentNode) {
                    keyboard.parentNode.removeChild(keyboard);
                }
                clearBodyKbState();
                activeInput = null;
                closeTimer = null;
            }, ANIM_MS);
        } else {
            if (keyboard.parentNode) {
                keyboard.parentNode.removeChild(keyboard);
            }
            clearBodyKbState();
            activeInput = null;
        }
    }

    /** Business setting from kioskboard_payment.php; default off when unset. */
    function isTouchKeyboardEnabled() {
        return window.POS_TOUCH_KEYBOARD_ENABLED === true;
    }

    function showKeyboard(input, mode, skipFocus) {
        if (!isTouchKeyboardEnabled()) {
            return;
        }
        if (!input || isMobileDevice()) {
            return;
        }

        if (activeInput !== input) {
            capsLock = false;
            symbolsMode = false;
        }
        activeInput = input;
        var placement = getPlacement(input);

        var existing = document.getElementById(KB_ID);
        if (existing) {
            existing.parentNode.removeChild(existing);
        }

        setBodyKbState(placement);

        var keyboard = document.createElement('div');
        keyboard.id = KB_ID;
        keyboard.className = 'kioskboard-theme-flat kioskboard-fade';
        if (placement === 'bottom') {
            keyboard.classList.add('kioskboard-placement-bottom', 'pos-kb-bottom-panel');
        } else {
            keyboard.classList.add('kioskboard-placement-bottom', 'pos-kb-side-panel');
        }
        if (mode === 'decimal') {
            keyboard.classList.add('pos-kb-decimal-mode');
        }
        if (symbolsMode && mode === 'text') {
            keyboard.classList.add('pos-kb-symbols-mode');
        }

        var wrapper = document.createElement('div');
        wrapper.className = 'kioskboard-wrapper';
        populateKeyboardWrapper(wrapper, mode);
        keyboard.appendChild(wrapper);
        document.documentElement.appendChild(keyboard);
        attachKeyboardEvents(keyboard, input);

        if (!skipFocus) {
            input.focus();
        }
    }

    function bindInput(el, mode, options) {
        options = options || {};
        if (!isTouchKeyboardEnabled() || !el || el.getAttribute('data-pos-kb-bound') || isMobileDevice()) {
            return;
        }

        el.setAttribute('data-pos-kb-bound', mode);
        var placement = options.placement;
        if (!placement) {
            placement = el.getAttribute('data-pos-kb-placement') || el.getAttribute('data-kioskboard-placement');
        }
        el.setAttribute('data-pos-kb-placement', placement === 'bottom' ? 'bottom' : 'side');
        el.classList.add('js-kioskboard-input');
        el.classList.add(mode === 'decimal' ? 'js-kioskboard-decimal' : 'js-kioskboard-text');
        el.setAttribute('autocomplete', 'off');

        if (options.allowRealKeyboard !== false) {
            el.setAttribute('inputmode', mode === 'decimal' ? 'decimal' : 'text');
        }

        el.addEventListener('focus', function () {
            if (isMobileDevice()) {
                return;
            }
            if (!document.getElementById(KB_ID) || activeInput !== el) {
                showKeyboard(el, mode);
            }
        });

        el.addEventListener('pointerdown', function () {
            if (isMobileDevice()) {
                return;
            }
            if (!document.getElementById(KB_ID) || activeInput !== el) {
                showKeyboard(el, mode, true);
            }
        });
    }

    function bindDecimal(selectorOrElements, options) {
        normalizeElements(selectorOrElements).forEach(function (el) {
            bindInput(el, 'decimal', options || {});
        });
    }

    function bindText(selectorOrElements, options) {
        normalizeElements(selectorOrElements).forEach(function (el) {
            bindInput(el, 'text', options || {});
        });
    }

    function initPaymentKeyboards(rootSelector) {
        var root = document.querySelector(rootSelector || '#paymentModal');
        if (!root) {
            return;
        }
        var sideOpts = { placement: 'side' };
        bindDecimal(root.querySelectorAll('.js-kioskboard-decimal'), sideOpts);
        bindText(root.querySelectorAll('.js-kioskboard-text'), sideOpts);
    }

    function bindSwalFields() {
        closeKeyboard(false);
        activeInput = null;

        if (isMobileDevice()) {
            return;
        }

        var popup = document.querySelector('.swal2-popup');
        var root = popup || document;
        var decimalEls = root.querySelectorAll('.js-kioskboard-decimal');
        var textEls = root.querySelectorAll('.js-kioskboard-text');

        var sideOpts = { placement: 'side' };
        bindDecimal(decimalEls, sideOpts);
        bindText(textEls, sideOpts);
    }

    function bindDynamicTextFields() {
        bindSwalFields();
    }

    function attachGlobalHandlers() {
        if (attachGlobalHandlers._done) {
            return;
        }
        attachGlobalHandlers._done = true;

        document.addEventListener('pointerdown', function (e) {
            var keyboard = document.getElementById(KB_ID);
            if (!keyboard) {
                return;
            }
            var target = e.target;
            if (target.closest('#' + KB_ID)) {
                return;
            }
            if (target.closest('.js-kioskboard-input')) {
                return;
            }
            if (target.closest('#paymentModal')) {
                return;
            }
            if (target.closest('#loginForm')) {
                return;
            }
            if (target.closest('#editTabNameModal')) {
                return;
            }
            if (activeInput && target.closest('#cart')) {
                if (getPlacement(activeInput) === 'bottom') {
                    return;
                }
                if (activeInput.classList.contains('quantity-input')) {
                    return;
                }
            }
            if (target.closest('.swal2-popup')) {
                return;
            }
            if (target.closest('label[for]')) {
                var forId = target.getAttribute('for');
                if (forId && document.getElementById(forId) && document.getElementById(forId).classList.contains('js-kioskboard-input')) {
                    return;
                }
            }
            closeKeyboard(true);
        }, true);
    }

    function bootPosKioskBoard() {
        if (!isTouchKeyboardEnabled() || isMobileDevice()) {
            closeKeyboard(false);
            return;
        }

        attachGlobalHandlers();
        initPaymentKeyboards('#paymentModal');
        bindText('#searchBar', { allowRealKeyboard: true });
        bindDecimal('#cashReceived', { placement: 'bottom', allowRealKeyboard: false });
    }

    window.PosKioskBoard = {
        init: initPaymentKeyboards,
        bindText: bindText,
        bindDecimal: bindDecimal,
        bindSwalFields: bindSwalFields,
        bindDynamicTextFields: bindDynamicTextFields,
        openInput: function (inputEl) {
            if (!isTouchKeyboardEnabled() || !inputEl || isMobileDevice()) {
                return;
            }
            var mode = isDecimalInput(inputEl) ? 'decimal' : 'text';
            showKeyboard(inputEl, mode);
        },
        close: function () {
            closeKeyboard(true);
        },
        isMobile: isMobileDevice,
        isEnabled: isTouchKeyboardEnabled
    };

    if (window.matchMedia) {
        var mobileMq = window.matchMedia('(max-width: 767px)');
        var onMobileChange = function () {
            if (isMobileDevice()) {
                closeKeyboard(false);
            }
        };
        if (mobileMq.addEventListener) {
            mobileMq.addEventListener('change', onMobileChange);
        } else if (mobileMq.addListener) {
            mobileMq.addListener(onMobileChange);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootPosKioskBoard);
    } else {
        bootPosKioskBoard();
    }
})(window);
