(function () {
    const tableBody = document.getElementById('tableBody');
    if (!tableBody) {
        return;
    }

    let rowsPerPage = window.innerWidth < 640 ? 10 : 6;
    let currentPage = 1;
    let totalPages = 1;
    let totalItems = 0;
    let showAllMode = false;
    let isLoading = false;
    let fetchTimer = null;
    const sortState = { column: 'name', direction: 'asc' };

    const searchInput = document.getElementById('searchInput');
    const searchInputDesktop = document.getElementById('searchInputDesktop');
    const categoryFilter = document.getElementById('categoryFilter');
    const categoryFilterDesktop = document.getElementById('categoryFilterDesktop');
    const viewAllMobile = document.getElementById('viewAllMobile');
    const viewAllDesktop = document.getElementById('viewAllDesktop');

    function syncSearchInputs(value) {
        if (searchInput) searchInput.value = value;
        if (searchInputDesktop) searchInputDesktop.value = value;
    }

    function getSearchValue() {
        return ((searchInput && searchInput.value) || (searchInputDesktop && searchInputDesktop.value) || '').trim();
    }

    function getInventoryCategoryValue() {
        if (categoryFilter && categoryFilterDesktop) {
            return categoryFilter.value || categoryFilterDesktop.value || '';
        }
        if (categoryFilter) return categoryFilter.value || '';
        if (categoryFilterDesktop) return categoryFilterDesktop.value || '';
        return '';
    }

    function saveCurrentPage() {
        sessionStorage.setItem('inventoryCurrentPage', String(currentPage));
        sessionStorage.setItem('inventoryCategory', getInventoryCategoryValue());
    }

    function loadCurrentPage() {
        const savedPage = parseInt(sessionStorage.getItem('inventoryCurrentPage') || '1', 10);
        if (!isNaN(savedPage) && savedPage > 0) {
            currentPage = savedPage;
        }
        const savedCategory = sessionStorage.getItem('inventoryCategory');
        if (savedCategory) {
            if (categoryFilter) categoryFilter.value = savedCategory;
            if (categoryFilterDesktop) categoryFilterDesktop.value = savedCategory;
        }
        const urlCategory = new URLSearchParams(window.location.search).get('category');
        if (urlCategory && urlCategory.trim()) {
            const initialCategory = urlCategory.trim();
            if (categoryFilter) categoryFilter.value = initialCategory;
            if (categoryFilterDesktop) categoryFilterDesktop.value = initialCategory;
            try { sessionStorage.setItem('inventoryCategory', initialCategory); } catch (e) {}
        }
    }

    function setPaginationControlsVisible(visible) {
        document.querySelectorAll('#firstPage, #prevPage, #nextPage, #lastPage, #firstPageDesktop, #prevPageDesktop, #nextPageDesktop, #lastPageDesktop, #pageInput, #pageInputDesktop').forEach((control) => {
            if (control) control.style.display = visible ? '' : 'none';
        });
    }

    function updatePageDisplay() {
        const pageNumberMobile = document.getElementById('pageNumber');
        const pageNumberDesktop = document.getElementById('pageNumberDesktop');
        const pageInputMobile = document.getElementById('pageInput');
        const pageInputDesktop = document.getElementById('pageInputDesktop');

        if (showAllMode) {
            if (pageNumberMobile) pageNumberMobile.textContent = `All Products (${totalItems})`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `All Products (${totalItems})`;
            setPaginationControlsVisible(false);
            return;
        }

        if (pageNumberMobile) pageNumberMobile.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pageNumberDesktop) pageNumberDesktop.textContent = `Page ${currentPage} of ${totalPages}`;
        if (pageInputMobile) {
            pageInputMobile.value = currentPage;
            pageInputMobile.placeholder = `Pg (1-${totalPages})`;
        }
        if (pageInputDesktop) {
            pageInputDesktop.value = currentPage;
            pageInputDesktop.placeholder = `Page (1-${totalPages})`;
        }
        setPaginationControlsVisible(true);
    }

    function showLoadingRow() {
        tableBody.innerHTML = '<tr><td colspan="6" class="py-8 px-6 text-center text-gray-500">Loading inventory...</td></tr>';
    }

    async function loadInventoryPage(page) {
        if (isLoading) {
            return;
        }
        isLoading = true;
        currentPage = Math.max(1, page || 1);
        showLoadingRow();

        const params = new URLSearchParams({
            page: String(currentPage),
            per_page: String(rowsPerPage),
            search: getSearchValue(),
            category: getInventoryCategoryValue(),
            sort_col: sortState.column,
            sort_dir: sortState.direction,
            view_all: showAllMode ? '1' : '0',
        });

        try {
            const response = await fetch('inventory_list.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to load inventory');
            }

            tableBody.innerHTML = data.html || '<tr><td colspan="6" class="py-6 px-6 text-center text-gray-500">No products found</td></tr>';
            totalPages = data.total_pages || 1;
            totalItems = data.total || 0;
            currentPage = data.page || currentPage;
            updatePageDisplay();
            saveCurrentPage();
        } catch (error) {
            console.error(error);
            tableBody.innerHTML = '<tr><td colspan="6" class="py-6 px-6 text-center text-red-500">Failed to load inventory. Please refresh.</td></tr>';
            if (typeof showToast === 'function') {
                showToast(error.message || 'Failed to load inventory', 'error');
            }
        } finally {
            isLoading = false;
        }
    }

    function scheduleInventoryReload(page) {
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(() => loadInventoryPage(page || 1), 250);
    }

    window.filterRows = function () {
        if (showAllMode) {
            showAllMode = false;
            setPaginationControlsVisible(true);
        }
        scheduleInventoryReload(1);
    };

    window.showPage = function (page) {
        if (showAllMode) {
            return;
        }
        loadInventoryPage(page);
    };

    window.sortTable = function (columnIndex, isNumeric) {
        const columns = ['name', 'quantity', 'price', 'buying_price'];
        const nextColumn = columns[columnIndex];
        if (!nextColumn) {
            return;
        }
        if (sortState.column === nextColumn) {
            sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
        } else {
            sortState.column = nextColumn;
            sortState.direction = isNumeric ? 'desc' : 'asc';
        }
        loadInventoryPage(1);
    };

    function handleSearchInput(e) {
        syncSearchInputs(e.target.value);
        if (showAllMode) {
            showAllMode = false;
            setPaginationControlsVisible(true);
        }
        scheduleInventoryReload(1);
    }

    function handleCategoryFilter(e) {
        if (showAllMode) {
            showAllMode = false;
            setPaginationControlsVisible(true);
        }
        const source = e && e.target ? e.target : (categoryFilter || categoryFilterDesktop);
        const selectedValue = source ? source.value : '';
        if (categoryFilter) categoryFilter.value = selectedValue;
        if (categoryFilterDesktop) categoryFilterDesktop.value = selectedValue;
        scheduleInventoryReload(1);
    }

    function handleViewAll() {
        showAllMode = !showAllMode;
        if (showAllMode) {
            if (categoryFilter) categoryFilter.value = '';
            if (categoryFilterDesktop) categoryFilterDesktop.value = '';
        }
        loadInventoryPage(1);
    }

    function handlePrevPage() {
        if (!showAllMode && currentPage > 1) {
            loadInventoryPage(currentPage - 1);
        }
    }

    function handleNextPage() {
        if (!showAllMode && currentPage < totalPages) {
            loadInventoryPage(currentPage + 1);
        }
    }

    function handleFirstPage() {
        if (!showAllMode) {
            loadInventoryPage(1);
        }
    }

    function handleLastPage() {
        if (!showAllMode) {
            loadInventoryPage(totalPages);
        }
    }

    function handlePageInput(inputElement) {
        const desiredPage = parseInt(inputElement.value, 10);
        if (!isNaN(desiredPage) && !showAllMode) {
            loadInventoryPage(Math.min(Math.max(1, desiredPage), totalPages));
        }
    }

    if (searchInput) searchInput.addEventListener('input', handleSearchInput);
    if (searchInputDesktop) searchInputDesktop.addEventListener('input', handleSearchInput);
    if (categoryFilter) categoryFilter.addEventListener('change', handleCategoryFilter);
    if (categoryFilterDesktop) categoryFilterDesktop.addEventListener('change', handleCategoryFilter);
    if (viewAllMobile) viewAllMobile.addEventListener('click', handleViewAll);
    if (viewAllDesktop) viewAllDesktop.addEventListener('click', handleViewAll);

    ['prevPage', 'prevPageDesktop'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handlePrevPage);
    });
    ['nextPage', 'nextPageDesktop'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handleNextPage);
    });
    ['firstPage', 'firstPageDesktop'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handleFirstPage);
    });
    ['lastPage', 'lastPageDesktop'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('click', handleLastPage);
    });
    ['pageInput', 'pageInputDesktop'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.addEventListener('change', () => handlePageInput(el));
    });

    window.addEventListener('resize', () => {
        const nextRowsPerPage = window.innerWidth < 640 ? 10 : 6;
        if (nextRowsPerPage !== rowsPerPage && !showAllMode) {
            rowsPerPage = nextRowsPerPage;
            loadInventoryPage(1);
        }
    });

    window.reloadInventoryTable = function (page) {
        loadInventoryPage(page || currentPage);
    };

    loadCurrentPage();
    loadInventoryPage(currentPage);
})();
