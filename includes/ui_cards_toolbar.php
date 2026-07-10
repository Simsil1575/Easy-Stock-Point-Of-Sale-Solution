<?php
/** @var string $uiCardScope */
/** @var list<string> $hiddenUiCards */
/** @var bool $showHiddenUiCards */
/** @var bool $uiCardsCustomizeMode */
/** @var string $uiCardsApiUrl */
$hiddenUiCardsJson = json_encode($hiddenUiCards, JSON_HEX_TAG | JSON_HEX_APOS | JSON_UNESCAPED_UNICODE);
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
        display: none;
    }
    .ui-cards-customize-mode .ui-card-checkbox-wrap { display: block; }
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
    <div id="uiCardsBulkBar" class="ui-cards-bulk-bar hidden flex flex-wrap items-center gap-2 px-3 py-2 bg-teal-50 border border-teal-100 rounded-lg">
      <span id="uiCardsSelectedCount" class="text-sm font-medium text-gray-700">0 selected</span>
      <button type="button" class="inline-flex items-center px-3 py-1.5 bg-white border border-gray-300 hover:bg-gray-50 text-gray-800 rounded-lg text-sm font-medium" onclick="uiCardsBulkAction('hide')">
        <i class="fas fa-eye-slash mr-2 text-gray-500"></i> Hide selected
      </button>
      <button type="button" class="inline-flex items-center px-3 py-1.5 bg-teal-600 hover:bg-teal-700 text-white rounded-lg text-sm font-medium" onclick="uiCardsBulkAction('show')">
        <i class="fas fa-eye mr-2"></i> Show selected
      </button>
    </div>
    <div class="flex flex-wrap items-center gap-3 ml-auto">
      <label class="inline-flex items-center gap-2 text-sm text-gray-600 cursor-pointer">
        <input type="checkbox" id="uiCardsSelectAll" class="rounded border-gray-300 text-teal-600 focus:ring-teal-500">
        Select all
      </label>
      <?php if ($showHiddenUiCards): ?>
        <a href="?customize=1" class="text-sm text-gray-600 hover:text-gray-900 font-medium">Hide hidden cards</a>
      <?php elseif ($hiddenCount > 0): ?>
        <a href="?customize=1&amp;show_hidden=1" class="text-sm text-gray-600 hover:text-gray-900 font-medium">Show hidden cards (<?= $hiddenCount ?>)</a>
      <?php endif; ?>
      <a href="?" class="text-sm text-teal-700 hover:text-teal-900 font-medium">Done</a>
    </div>
  </div>
</div>

<script>
(function() {
    const UI_CARD_SCOPE = <?= json_encode($uiCardScope, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const UI_CARDS_API = <?= json_encode($uiCardsApiUrl, JSON_HEX_TAG | JSON_HEX_APOS) ?>;
    const UI_HIDDEN_CARDS = <?= $hiddenUiCardsJson ?>;
    const UI_SHOW_HIDDEN = <?= $showHiddenUiCards ? 'true' : 'false' ?>;
    const UI_CUSTOMIZE_MODE = <?= $uiCardsCustomizeMode ? 'true' : 'false' ?>;

    function getCardEls() {
        return Array.from(document.querySelectorAll('.ui-selectable-card[data-card-id]'));
    }

    function getCheckboxes() {
        return Array.from(document.querySelectorAll('.ui-card-checkbox'));
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

    window.uiCardsBulkAction = async function(action) {
        const ids = getCheckboxes()
            .filter(cb => cb.checked)
            .map(cb => cb.closest('.ui-selectable-card')?.dataset.cardId)
            .filter(Boolean);
        if (!ids.length) {
            alert('Select at least one card.');
            return;
        }
        const verb = action === 'hide' ? 'hide' : 'show';
        if (!confirm((verb === 'hide' ? 'Hide ' : 'Show ') + ids.length + ' card(s)?')) {
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
            alert(err.message || 'Could not update cards.');
        }
    };

    document.addEventListener('DOMContentLoaded', () => {
        applyHiddenState();

        if (!UI_CUSTOMIZE_MODE) return;

        const selectAll = document.getElementById('uiCardsSelectAll');
        if (selectAll) {
            selectAll.addEventListener('change', () => {
                getCheckboxes().forEach(cb => { cb.checked = selectAll.checked; });
                updateBulkUI();
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
