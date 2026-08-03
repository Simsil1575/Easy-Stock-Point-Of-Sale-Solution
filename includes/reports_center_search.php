<?php
/**
 * Reports Center search — include with $reportsSearchInclude = 'field' | 'chips' | 'empty' | 'script'
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
elseif ($reportsSearchInclude === 'chips'):
    $centerCategoryChips = [
        'chipHostId' => 'reportCategoryChips',
        'clearBtnId' => 'reportCategoryClear',
        'setFilterFn' => 'setReportCategoryFilter',
    ];
    $centerCategoryChipsMode = 'markup';
    include __DIR__ . '/center_category_chips.php';
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
<?php
    $centerCategoryChips = [
        'gridId' => 'reportsGrid',
        'cardSelector' => '.report-card',
        'filteredClass' => 'report-search-filtered-out',
        'searchInputId' => 'reportSearchInput',
        'searchClearId' => 'reportSearchClear',
        'emptyStateId' => 'reportSearchEmpty',
        'chipHostId' => 'reportCategoryChips',
        'clearBtnId' => 'reportCategoryClear',
        'setFilterFn' => 'setReportCategoryFilter',
        'filterFn' => 'filterReportCards',
        'clearSearchFn' => 'clearReportSearch',
        'chipClass' => 'report-category-chip',
    ];
    $centerCategoryChipsMode = 'script';
    include __DIR__ . '/center_category_chips.php';
endif;
?>
