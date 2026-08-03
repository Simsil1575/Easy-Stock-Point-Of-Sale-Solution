<?php
/**
 * Operations Center search — include with $operationsSearchInclude = 'field' | 'chips' | 'empty' | 'script'
 */
if (!isset($operationsSearchInclude)) {
    $operationsSearchInclude = 'field';
}

if ($operationsSearchInclude === 'field'):
?>
<div class="w-full sm:w-72 lg:w-80 shrink-0">
    <label for="operationSearchInput" class="sr-only">Search operations</label>
    <div class="relative">
        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" aria-hidden="true"></i>
        <input
            type="search"
            id="operationSearchInput"
            class="w-full pl-9 pr-9 py-2.5 text-sm border border-gray-300 rounded-lg bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500"
            placeholder="Search operations..."
            autocomplete="off"
            oninput="filterOperationCards()"
        >
        <button
            type="button"
            id="operationSearchClear"
            class="hidden absolute right-2 top-1/2 -translate-y-1/2 w-7 h-7 rounded-full text-gray-400 hover:text-gray-600 hover:bg-gray-100"
            aria-label="Clear search"
            onclick="clearOperationSearch()"
        >
            <i class="fas fa-times text-xs"></i>
        </button>
    </div>
</div>
<?php
elseif ($operationsSearchInclude === 'chips'):
    $centerCategoryChips = [
        'chipHostId' => 'operationCategoryChips',
        'clearBtnId' => 'operationCategoryClear',
        'setFilterFn' => 'setOperationCategoryFilter',
    ];
    $centerCategoryChipsMode = 'markup';
    include __DIR__ . '/center_category_chips.php';
elseif ($operationsSearchInclude === 'empty'):
?>
<div id="operationSearchEmpty" class="hidden col-span-full text-center py-10 text-gray-500">
    <i class="fas fa-search text-3xl mb-3 text-gray-300"></i>
    <p class="font-medium text-gray-600">No operations match your search</p>
    <p class="text-sm mt-1">Try a different name, category, or keyword.</p>
</div>
<?php
elseif ($operationsSearchInclude === 'script'):
?>
<style>
    .operation-card.operation-search-filtered-out {
        display: none !important;
    }
</style>
<?php
    $centerCategoryChips = [
        'gridId' => 'operationsGrid',
        'cardSelector' => '.operation-card',
        'filteredClass' => 'operation-search-filtered-out',
        'searchInputId' => 'operationSearchInput',
        'searchClearId' => 'operationSearchClear',
        'emptyStateId' => 'operationSearchEmpty',
        'chipHostId' => 'operationCategoryChips',
        'clearBtnId' => 'operationCategoryClear',
        'setFilterFn' => 'setOperationCategoryFilter',
        'filterFn' => 'filterOperationCards',
        'clearSearchFn' => 'clearOperationSearch',
        'chipClass' => 'center-category-chip',
    ];
    $centerCategoryChipsMode = 'script';
    include __DIR__ . '/center_category_chips.php';
endif;
?>
