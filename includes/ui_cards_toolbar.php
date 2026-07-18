<?php
/** @var string $uiCardScope */
/** @var list<string> $hiddenUiCards */
/** @var list<string> $orderedUiCards */
/** @var bool $showHiddenUiCards */
/** @var bool $uiCardsCustomizeMode */
/** @var string $uiCardsApiUrl */
$hiddenUiCardsJson = json_encode($hiddenUiCards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
$orderedUiCards = $orderedUiCards ?? [];
$orderedUiCardsJson = json_encode($orderedUiCards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
$hiddenCount = count($hiddenUiCards);
$uiCardsCustomizeMode = $uiCardsCustomizeMode ?? ($showHiddenUiCards || isset($_GET['customize']));
?>
<style>
    .ui-selectable-card { position: relative; }
    .ui-selectable-card.ui-card-is-hidden { opacity: 0.55; }
    .ui-selectable-card.ui-card-selected { box-shadow: 0 0 0 2px #0d9488; }
    .ui-card-checkbox-wrap {
        position: absolute;
        top: 0.65rem;
        left: 0.65rem;
        z-index: 2;
        display: none !important;
    }
    .ui-cards-customize-mode .ui-card-checkbox-wrap { display: block !important; }
    .ui-card-checkbox {
        width: 1rem;
        height: 1rem;
        cursor: pointer;
    }
    .ui-cards-bulk-bar { transition: all 0.2s ease; }
    .ui-cards-customize-only { display: none !important; }
    .ui-cards-customize-mode .ui-cards-customize-only { display: flex !important; }
    .ui-cards-normal-only { display: flex; }
    .ui-cards-customize-mode .ui-cards-normal-only { display: none !important; }
    .ui-cards-customize-mode .ui-selectable-card {
        cursor: grab;
        user-select: none;
        touch-action: none;
    }
    .ui-cards-customize-mode .ui-selectable-card.ui-card-dragging {
        cursor: grabbing;
        opacity: 0.7;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.18);
        transform: scale(1.02);
        z-index: 5;
    }
    .ui-cards-customize-mode .ui-selectable-card.ui-card-drop-target {
        box-shadow: 0 0 0 2px #14b8a6;
    }
    .ui-card-drag-hint {
        display: none;
        align-items: center;
        gap: 0.4rem;
        font-size: 0.875rem;
        color: #64748b;
    }
    .ui-cards-customize-mode .ui-card-drag-hint { display: inline-flex; }
</style>

<div class="flex flex-wrap items-center justify-between gap-3 mb-4">
  <!-- Normal view: single unobtrusive link -->
  <div class="ui-cards-normal-only flex-wrap items-center gap-3 ml-auto w-full justify-end">
    <?php if ($hiddenCount > 0): ?>
      <a href="?show_hidden=1" class="text-sm text-gray-500 hover:text-gray-800">
        <i class="fas fa-eye-slash mr-1"></i> Hidden cards (<?= $hiddenCount ?>)
      </a>
    <?php endif; ?>
    <a href="?customize=1" class="text-sm text-gray-500 hover:text-gray-800">
      <i class="fas fa-sliders-h mr-1"></i> Customize cards
    </a>
  </div>

  <!-- Customize / hide-unhide mode -->
  <div class="ui-cards-customize-only flex-wrap items-center justify-between gap-3 w-full">
    <div class="flex flex-wrap items-center gap-3">
      <span class="ui-card-drag-hint">
        <i class="fas fa-arrows-alt"></i> Drag cards to change position
      </span>
      <div id="uiCardsBulkBar" class="ui-cards-bulk-bar hidden flex flex-wrap items-center gap-2 px-3 py-2 bg-teal-50 border border-teal-100 rounded-lg">
        <span id="uiCardsSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
        <button type="button" class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium" onclick="uiCardsBulkAction('hide')">
          <i class="fas fa-eye-slash mr-2 text-gray-500"></i> Hide selected
        </button>
        <button type="button" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="uiCardsBulkAction('show')">
          <i class="fas fa-eye mr-2"></i> Show selected
        </button>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-3 ml-auto">
      <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
        <input type="checkbox" id="uiCardsSelectAll" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        Select all
      </label>
      <button type="button" id="uiCardsResetDefault" class="text-sm text-gray-600 hover:text-gray-900 font-medium">
        <i class="fas fa-undo mr-1"></i> Reset to default
      </button>
      <?php if ($showHiddenUiCards): ?>
        <a href="?customize=1" class="text-sm text-gray-600 hover:text-gray-900 font-medium">Hide hidden cards</a>
      <?php elseif ($hiddenCount > 0): ?>
        <a href="?customize=1&amp;show_hidden=1" class="text-sm text-gray-600 hover:text-gray-900 font-medium">Show hidden cards (<?= $hiddenCount ?>)</a>
      <?php endif; ?>
      <a href="?" class="text-sm text-teal-700 hover:text-teal-900 font-medium">Done</a>
    </div>
  </div>
</div>

<script src="../js/pos-confirm.js"></script>
<script>
(function() {
    const UI_CARD_SCOPE = <?= json_encode($uiCardScope, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const UI_CARDS_API = <?= json_encode($uiCardsApiUrl, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const UI_HIDDEN_CARDS = <?= $hiddenUiCardsJson ?>;
    const UI_CARD_ORDER = <?= $orderedUiCardsJson ?>;
    const UI_SHOW_HIDDEN = <?= $showHiddenUiCards ? 'true' : 'false' ?>;
    const UI_CUSTOMIZE_MODE = <?= $uiCardsCustomizeMode ? 'true' : 'false' ?>;

    let dragCard = null;
    let didDrag = false;
    let saveTimer = null;

    function uiNotify(opts) {
        if (typeof window.posAlert === 'function') {
            return window.posAlert(opts);
        }
        window.alert((opts.title ? opts.title + '\n' : '') + (opts.text || ''));
        return Promise.resolve();
    }

    function uiAsk(opts) {
        if (typeof window.posConfirm === 'function') {
            return window.posConfirm(opts);
        }
        const ok = window.confirm((opts.title ? opts.title + '\n\n' : '') + (opts.text || ''));
        return Promise.resolve({ isConfirmed: ok });
    }

    function getCardEls() {
        return Array.from(document.querySelectorAll('.ui-selectable-card[data-card-id]'));
    }

    function getCheckboxes() {
        return Array.from(document.querySelectorAll('.ui-card-checkbox'));
    }

    function getCardsParent() {
        const cards = getCardEls();
        return cards.length ? cards[0].parentElement : null;
    }

    function applyCardOrder(order) {
        if (!Array.isArray(order) || !order.length) return;
        const parent = getCardsParent();
        if (!parent) return;
        const byId = {};
        getCardEls().forEach(card => {
            byId[card.dataset.cardId] = card;
        });
        order.forEach(id => {
            if (byId[id]) {
                parent.appendChild(byId[id]);
                delete byId[id];
            }
        });
        Object.keys(byId).forEach(id => parent.appendChild(byId[id]));
    }

    function currentCardOrder() {
        return getCardEls().map(card => card.dataset.cardId).filter(Boolean);
    }

    function applyHiddenState() {
        getCardEls().forEach(card => {
            const id = card.dataset.cardId;
            const hidden = UI_HIDDEN_CARDS.includes(id);
            card.classList.toggle('ui-card-is-hidden', hidden && UI_SHOW_HIDDEN);
            if (hidden && !UI_SHOW_HIDDEN) {
                card.style.display = 'none';
            } else {
                card.style.display = '';
            }
        });
    }

    function updateBulkUI() {
        if (!UI_CUSTOMIZE_MODE) return;
        const boxes = getCheckboxes();
        const checked = boxes.filter(cb => cb.checked);
        const bar = document.getElementById('uiCardsBulkBar');
        const countEl = document.getElementById('uiCardsSelectedCount');
        const selectAll = document.getElementById('uiCardsSelectAll');
        if (bar) {
            bar.classList.toggle('hidden', checked.length === 0);
        }
        if (countEl) {
            countEl.textContent = checked.length + ' selected';
        }
        boxes.forEach(cb => {
            const card = cb.closest('.ui-selectable-card');
            if (card) {
                card.classList.toggle('ui-card-selected', cb.checked);
            }
        });
        if (selectAll && boxes.length) {
            selectAll.checked = checked.length === boxes.length;
            selectAll.indeterminate = checked.length > 0 && checked.length < boxes.length;
        }
    }

    function uiCardsRedirectUrl() {
        if (UI_SHOW_HIDDEN) return '?customize=1&show_hidden=1';
        if (UI_CUSTOMIZE_MODE) return '?customize=1';
        return window.location.pathname;
    }

    async function saveCardOrder() {
        const ids = currentCardOrder();
        if (!ids.length) return;
        const fd = new FormData();
        fd.append('action', 'reorder');
        fd.append('scope', UI_CARD_SCOPE);
        ids.forEach(id => fd.append('card_ids[]', id));
        try {
            const res = await fetch(UI_CARDS_API, { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) {
                throw new Error(json.message || 'Could not save order.');
            }
        } catch (err) {
            uiNotify({ icon: 'error', title: 'Could not save order', text: err.message || 'Please try again.' });
        }
    }

    function scheduleSaveCardOrder() {
        if (saveTimer) clearTimeout(saveTimer);
        saveTimer = setTimeout(saveCardOrder, 250);
    }

    window.uiCardsBulkAction = async function(action) {
        const ids = getCheckboxes()
            .filter(cb => cb.checked)
            .map(cb => cb.closest('.ui-selectable-card')?.dataset.cardId)
            .filter(Boolean);
        if (!ids.length) {
            uiNotify({ icon: 'warning', title: 'No cards selected', text: 'Select at least one card first.' });
            return;
        }
        const verb = action === 'hide' ? 'hide' : 'show';
        const result = await uiAsk({
            title: verb === 'hide' ? 'Hide selected cards?' : 'Show selected cards?',
            text: (verb === 'hide' ? 'Hide ' : 'Show ') + ids.length + ' card(s).',
            confirmButtonText: verb === 'hide' ? 'Hide cards' : 'Show cards',
            variant: verb === 'hide' ? 'warning' : 'primary',
            icon: 'question'
        });
        if (!result.isConfirmed) {
            return;
        }
        const fd = new FormData();
        fd.append('action', verb);
        fd.append('scope', UI_CARD_SCOPE);
        ids.forEach(id => fd.append('card_ids[]', id));
        try {
            const res = await fetch(UI_CARDS_API, { method: 'POST', body: fd, credentials: 'same-origin' });
            const json = await res.json();
            if (!json.ok) {
                throw new Error(json.message || 'Request failed.');
            }
            window.location.href = uiCardsRedirectUrl();
        } catch (err) {
            uiNotify({ icon: 'error', title: 'Update failed', text: err.message || 'Could not update cards.' });
        }
    };

    function enableDragAndDrop() {
        const cards = getCardEls();
        cards.forEach(card => {
            card.setAttribute('draggable', 'true');

            card.addEventListener('dragstart', (e) => {
                if (e.target.closest('.ui-card-checkbox-wrap')) {
                    e.preventDefault();
                    return;
                }
                dragCard = card;
                didDrag = false;
                card.classList.add('ui-card-dragging');
                try {
                    e.dataTransfer.effectAllowed = 'move';
                    e.dataTransfer.setData('text/plain', card.dataset.cardId || '');
                } catch (err) {}
            });

            card.addEventListener('dragover', (e) => {
                e.preventDefault();
                if (!dragCard || dragCard === card) return;
                const parent = card.parentElement;
                if (!parent) return;
                const rect = card.getBoundingClientRect();
                const before = (e.clientY - rect.top) < (rect.height / 2)
                    || (e.clientX - rect.left) < (rect.width / 2);
                if (before) {
                    parent.insertBefore(dragCard, card);
                } else {
                    parent.insertBefore(dragCard, card.nextSibling);
                }
                didDrag = true;
                card.classList.add('ui-card-drop-target');
            });

            card.addEventListener('dragleave', () => {
                card.classList.remove('ui-card-drop-target');
            });

            card.addEventListener('drop', (e) => {
                e.preventDefault();
                card.classList.remove('ui-card-drop-target');
            });

            card.addEventListener('dragend', () => {
                card.classList.remove('ui-card-dragging');
                getCardEls().forEach(c => c.classList.remove('ui-card-drop-target'));
                if (didDrag) {
                    scheduleSaveCardOrder();
                }
                // Keep didDrag true briefly so click handlers ignore navigation.
                setTimeout(() => { didDrag = false; }, 50);
                dragCard = null;
            });

            // In customize mode, block navigation; click toggles selection instead.
            card.addEventListener('click', (e) => {
                if (!UI_CUSTOMIZE_MODE) return;
                if (e.target.closest('.ui-card-checkbox-wrap')) return;
                e.preventDefault();
                e.stopImmediatePropagation();
                if (didDrag) return;
                const cb = card.querySelector('.ui-card-checkbox');
                if (cb) {
                    cb.checked = !cb.checked;
                    updateBulkUI();
                }
            }, true);
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        applyCardOrder(UI_CARD_ORDER);
        applyHiddenState();

        if (!UI_CUSTOMIZE_MODE) return;

        enableDragAndDrop();

        const selectAll = document.getElementById('uiCardsSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', () => {
                getCheckboxes().forEach(cb => { cb.checked = selectAll.checked; });
                updateBulkUI();
            });
        }

        const resetBtn = document.getElementById('uiCardsResetDefault');
        if (resetBtn) {
            resetBtn.addEventListener('click', async () => {
                const result = await uiAsk({
                    title: 'Reset to default?',
                    text: 'This restores the original card order and shows every card again.',
                    confirmButtonText: 'Reset to default',
                    variant: 'warning',
                    icon: 'warning'
                });
                if (!result.isConfirmed) {
                    return;
                }
                const fd = new FormData();
                fd.append('action', 'reset');
                fd.append('scope', UI_CARD_SCOPE);
                try {
                    const res = await fetch(UI_CARDS_API, { method: 'POST', body: fd, credentials: 'same-origin' });
                    const json = await res.json();
                    if (!json.ok) {
                        throw new Error(json.message || 'Reset failed.');
                    }
                    window.location.href = '?customize=1';
                } catch (err) {
                    uiNotify({ icon: 'error', title: 'Reset failed', text: err.message || 'Could not reset cards.' });
                }
            });
        }

        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('ui-card-checkbox')) {
                updateBulkUI();
            }
        });
    });
})();
</script>
