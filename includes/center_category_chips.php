<?php
/**
 * Shared category chips for Reports / Operations centers.
 *
 * Modes via $centerCategoryChipsMode:
 *   - 'markup'  → chip host HTML (place on Customize cards row)
 *   - 'script'  → CSS + JS (requires $centerCategoryChips config)
 *
 * $centerCategoryChips = [
 *   'gridId' => 'operationsGrid',
 *   'cardSelector' => '.operation-card',
 *   'filteredClass' => 'operation-search-filtered-out',
 *   'searchInputId' => 'operationSearchInput',
 *   'searchClearId' => 'operationSearchClear',
 *   'emptyStateId' => 'operationSearchEmpty',
 *   'chipHostId' => 'centerCategoryChips',
 *   'clearBtnId' => 'centerCategoryClear',
 *   'setFilterFn' => 'setCenterCategoryFilter',
 *   'filterFn' => 'filterCenterCards',
 *   'clearSearchFn' => 'clearCenterSearch',
 * ];
 */
if (!isset($centerCategoryChipsMode)) {
    $centerCategoryChipsMode = 'markup';
}

if ($centerCategoryChipsMode === 'markup'):
    $chipHostId = $centerCategoryChips['chipHostId'] ?? 'centerCategoryChips';
    $clearBtnId = $centerCategoryChips['clearBtnId'] ?? 'centerCategoryClear';
    $setFilterFn = $centerCategoryChips['setFilterFn'] ?? 'setCenterCategoryFilter';
?>
<div id="<?= htmlspecialchars($chipHostId, ENT_QUOTES, 'UTF-8') ?>" class="flex flex-wrap items-center gap-2" role="tablist" aria-label="Categories"></div>
<button type="button" id="<?= htmlspecialchars($clearBtnId, ENT_QUOTES, 'UTF-8') ?>" class="hidden text-xs text-teal-700 hover:text-teal-900 font-medium whitespace-nowrap" onclick="<?= htmlspecialchars($setFilterFn, ENT_QUOTES, 'UTF-8') ?>('')">
    Clear
</button>
<?php
elseif ($centerCategoryChipsMode === 'script'):
    $cfg = array_merge([
        'gridId' => 'operationsGrid',
        'cardSelector' => '.operation-card',
        'filteredClass' => 'operation-search-filtered-out',
        'searchInputId' => 'operationSearchInput',
        'searchClearId' => 'operationSearchClear',
        'emptyStateId' => 'operationSearchEmpty',
        'chipHostId' => 'centerCategoryChips',
        'clearBtnId' => 'centerCategoryClear',
        'setFilterFn' => 'setCenterCategoryFilter',
        'filterFn' => 'filterCenterCards',
        'clearSearchFn' => 'clearCenterSearch',
        'chipClass' => 'center-category-chip',
    ], $centerCategoryChips ?? []);
?>
<style>
    .center-category-chip,
    .report-category-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.35rem 0.75rem;
        border-radius: 9999px;
        border: 1px solid #e5e7eb;
        background: #f9fafb;
        color: #374151;
        font-size: 0.75rem;
        font-weight: 500;
        cursor: pointer;
        transition: background 0.15s ease, border-color 0.15s ease, color 0.15s ease;
        user-select: none;
        white-space: nowrap;
    }
    .center-category-chip:hover,
    .report-category-chip:hover {
        background: #f3f4f6;
        border-color: #d1d5db;
    }
    .center-category-chip.active,
    .report-category-chip.active {
        background: #0f766e;
        border-color: #0f766e;
        color: #fff;
    }
    .center-category-chip i,
    .report-category-chip i {
        font-size: 0.7rem;
        opacity: 0.9;
    }
    .center-category-chip .chip-count,
    .report-category-chip .chip-count {
        font-size: 0.7rem;
        opacity: 0.8;
        font-weight: 600;
    }
</style>
<script>
(function() {
    var CFG = <?= json_encode($cfg, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
    var activeCategory = '';
    var preferredCategoryOrder = [
        'Sales', 'Products', 'Payments', 'Invoicing', 'Credit', 'Tabs', 'Lay-bye',
        'Cash', 'Inventory', 'Stock', 'Supplier', 'Procurement', 'Expenses',
        'Tips', 'Refunds', 'Voids', 'Staff', 'Accounting', 'Tax', 'POS', 'System', 'Category'
    ];
    var categoryIcons = {
        '': 'fas fa-th-large',
        'All': 'fas fa-th-large',
        'Sales': 'fas fa-receipt',
        'Products': 'fas fa-barcode',
        'Payments': 'fas fa-credit-card',
        'Invoicing': 'fas fa-file-invoice-dollar',
        'Credit': 'fas fa-hand-holding-usd',
        'Tabs': 'fas fa-clipboard-list',
        'Lay-bye': 'fas fa-calendar-check',
        'Cash': 'fas fa-money-bill-wave',
        'Inventory': 'fas fa-warehouse',
        'Stock': 'fas fa-boxes',
        'Supplier': 'fas fa-truck-loading',
        'Procurement': 'fas fa-file-invoice',
        'Expenses': 'fas fa-file-invoice',
        'Tips': 'fas fa-coins',
        'Refunds': 'fas fa-undo-alt',
        'Voids': 'fas fa-ban',
        'Staff': 'fas fa-user-tie',
        'Accounting': 'fas fa-balance-scale',
        'Tax': 'fas fa-percent',
        'POS': 'fas fa-cash-register',
        'System': 'fas fa-history',
        'Category': 'fas fa-tags'
    };

    function getCardCategory(card) {
        if (card.dataset.reportCategory) return String(card.dataset.reportCategory).trim();
        if (card.dataset.cardCategory) return String(card.dataset.cardCategory).trim();
        var badge = card.querySelector('span.rounded-full');
        return badge ? String(badge.textContent || '').trim() : '';
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function iconFor(category) {
        return categoryIcons[category] || 'fas fa-tag';
    }

    function chipHtml(category, label, count, active) {
        var icon = iconFor(category);
        return '<button type="button" class="' + CFG.chipClass + (active ? ' active' : '') + '" data-category="' + escapeHtml(category) + '" role="tab" aria-selected="' + (active ? 'true' : 'false') + '" onclick="' + CFG.setFilterFn + '(this.getAttribute(\'data-category\'))">'
            + '<i class="' + icon + '" aria-hidden="true"></i>'
            + '<span>' + escapeHtml(label) + '</span>'
            + ' <span class="chip-count">' + count + '</span></button>';
    }

    window[CFG.setFilterFn] = function(category) {
        activeCategory = category || '';
        document.querySelectorAll('.' + CFG.chipClass).forEach(function(chip) {
            var value = chip.getAttribute('data-category') || '';
            chip.classList.toggle('active', value === activeCategory);
            chip.setAttribute('aria-selected', value === activeCategory ? 'true' : 'false');
        });
        var clearBtn = document.getElementById(CFG.clearBtnId);
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', activeCategory === '');
        }
        window[CFG.filterFn]();
    };

    window[CFG.filterFn] = function() {
        var input = document.getElementById(CFG.searchInputId);
        var clearBtn = document.getElementById(CFG.searchClearId);
        var emptyState = document.getElementById(CFG.emptyStateId);
        var grid = document.getElementById(CFG.gridId);
        if (!grid) return;

        var query = input ? input.value.trim().toLowerCase() : '';
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', query === '');
        }

        var cards = grid.querySelectorAll(CFG.cardSelector);
        cards.forEach(function(card) {
            var cardId = (card.dataset.cardId || '').toLowerCase();
            var text = (card.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            var category = getCardCategory(card);
            var matchesSearch = query === '' || text.indexOf(query) !== -1 || cardId.indexOf(query) !== -1;
            var matchesCategory = activeCategory === '' || category.toLowerCase() === activeCategory.toLowerCase();
            card.classList.toggle(CFG.filteredClass, !(matchesSearch && matchesCategory));
        });

        var visibleAfterFilter = Array.from(cards).filter(function(card) {
            return !card.classList.contains(CFG.filteredClass)
                && window.getComputedStyle(card).display !== 'none';
        });

        if (emptyState) {
            emptyState.classList.toggle('hidden', (query === '' && activeCategory === '') || visibleAfterFilter.length > 0);
        }
    };

    window[CFG.clearSearchFn] = function() {
        var input = document.getElementById(CFG.searchInputId);
        if (!input) return;
        input.value = '';
        window[CFG.filterFn]();
        input.focus();
    };

    // Keep legacy names used by search field oninput/onclick attributes
    if (CFG.filterFn === 'filterOperationCards' || CFG.filterFn === 'filterReportCards') {
        // already assigned via window[CFG.filterFn]
    }
    if (CFG.clearSearchFn === 'clearOperationSearch' || CFG.clearSearchFn === 'clearReportSearch') {
        // already assigned via window[CFG.clearSearchFn]
    }
    if (CFG.setFilterFn === 'setReportCategoryFilter' || CFG.setFilterFn === 'setOperationCategoryFilter') {
        // already assigned via window[CFG.setFilterFn]
    }

    function buildChips() {
        var grid = document.getElementById(CFG.gridId);
        var host = document.getElementById(CFG.chipHostId);
        if (!grid || !host) return;

        var counts = {};
        grid.querySelectorAll(CFG.cardSelector).forEach(function(card) {
            var category = getCardCategory(card);
            if (!category) return;
            counts[category] = (counts[category] || 0) + 1;
        });

        var categories = Object.keys(counts);
        categories.sort(function(a, b) {
            var ai = preferredCategoryOrder.indexOf(a);
            var bi = preferredCategoryOrder.indexOf(b);
            if (ai === -1) ai = 999;
            if (bi === -1) bi = 999;
            if (ai !== bi) return ai - bi;
            return a.localeCompare(b);
        });

        var total = grid.querySelectorAll(CFG.cardSelector).length;
        var html = chipHtml('', 'All', total, true);
        categories.forEach(function(category) {
            html += chipHtml(category, category, counts[category], false);
        });
        host.innerHTML = html;
    }

    document.addEventListener('DOMContentLoaded', function() {
        buildChips();

        var params = new URLSearchParams(window.location.search);
        var presetCategory = params.get('category') || params.get('cat') || '';
        if (presetCategory) {
            window[CFG.setFilterFn](presetCategory);
        }

        var input = document.getElementById(CFG.searchInputId);
        if (!input) return;
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                window[CFG.clearSearchFn]();
            }
        });
    });
})();
</script>
<?php endif; ?>
