<?php
/**
 * Reports Center search — include with $reportsSearchInclude = 'field' | 'empty' | 'script'
 */
if (!isset($reportsSearchInclude)) {
    $reportsSearchInclude = 'field';
}

if ($reportsSearchInclude === 'field'):
?>
<div class="w-full sm:w-72 lg:w-80 shrink-0">
    <label for="reportSearchInput" class="sr-only">Search reports</label>
    <div class="relative">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" aria-hidden="true"></i>
        <input
            type="search"
            id="reportSearchInput"
            class="w-full pl-9 pr-9 py-2.5 text-sm border border-gray-300 rounded-lg bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
            placeholder="Search reports..."
            autocomplete="off"
            oninput="filterReportCards()"
        >
        <button
            type="button"
            id="reportSearchClear"
            class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100"
            aria-label="Clear search"
            onclick="clearReportSearch()"
        >
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>
</div>
<?php
elseif ($reportsSearchInclude === 'empty'):
?>
<div id="reportSearchEmpty" class="hidden col-span-full text-center py-10 text-gray-500">
    <i class="fas fa-search text-3xl mb-3 text-gray-300"></i>
    <p class="font-medium text-gray-600">No reports match your search</p>
    <p class="text-sm mt-1">Try a different name, category, or keyword.</p>
</div>
<?php
elseif ($reportsSearchInclude === 'script'):
?>
<style>
    .report-card.report-search-filtered-out {
        display: none !important;
    }
</style>
<script>
    function filterReportCards() {
        const input = document.getElementById('reportSearchInput');
        const clearBtn = document.getElementById('reportSearchClear');
        const emptyState = document.getElementById('reportSearchEmpty');
        const grid = document.getElementById('reportsGrid');
        if (!input || !grid) return;

        const query = input.value.trim().toLowerCase();
        if (clearBtn) {
            clearBtn.classList.toggle('hidden', query === '');
        }

        const cards = grid.querySelectorAll('.report-card');

        cards.forEach(function(card) {
            const cardId = (card.dataset.cardId || '').toLowerCase();
            const text = (card.textContent || '').replace(/\s+/g, ' ').trim().toLowerCase();
            const matches = query === '' || text.indexOf(query) !== -1 || cardId.indexOf(query) !== -1;
            card.classList.toggle('report-search-filtered-out', !matches);
        });

        const visibleAfterFilter = Array.from(cards).filter(function(card) {
            return !card.classList.contains('report-search-filtered-out')
                && window.getComputedStyle(card).display !== 'none';
        });

        if (emptyState) {
            emptyState.classList.toggle('hidden', query === '' || visibleAfterFilter.length > 0);
        }
    }

    function clearReportSearch() {
        const input = document.getElementById('reportSearchInput');
        if (!input) return;
        input.value = '';
        filterReportCards();
        input.focus();
    }

    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('reportSearchInput');
        if (!input) return;
        input.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                clearReportSearch();
            }
        });
    });
</script>
<?php endif; ?>
