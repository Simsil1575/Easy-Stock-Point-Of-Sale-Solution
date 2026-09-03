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
    const categoryFilter = document.getElementById('categoryFilter');

    function getSearchValue() {
        return (searchInput ? searchInput.value : '').trim();
    }

    function getCategoryValue() {
        return categoryFilter ? categoryFilter.value || '' : '';
    }

    function updatePageDisplay() {
        const pageNumberMobile = document.getElementById('pageNumber');
        const pageNumberDesktop = document.getElementById('pageNumberDesktop');
        const pageInputMobile = document.getElementById('pageInput');
        const pageInputDesktop = document.getElementById('pageInputDesktop');

        if (showAllMode) {
            if (pageNumberMobile) pageNumberMobile.textContent = `All Products (${totalItems})`;
            if (pageNumberDesktop) pageNumberDesktop.textContent = `All Products (${totalItems})`;
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
    }

    function showLoadingRow() {
        tableBody.innerHTML = '<tr><td colspan="8" class="py-8 px-6 text-center text-gray-500">Loading products...</td></tr>';
    }

    async function loadReceivingPage(page) {
        if (isLoading) {
            return;
        }
        if (typeof window.onReceivingTableBeforeLoad === 'function') {
            window.onReceivingTableBeforeLoad();
        }
        isLoading = true;
        currentPage = Math.max(1, page || 1);
        showLoadingRow();

        const params = new URLSearchParams({
            page: String(currentPage),
            per_page: String(rowsPerPage),
            search: getSearchValue(),
            category: getCategoryValue(),
            sort_col: sortState.column,
            sort_dir: sortState.direction,
            view_all: showAllMode ? '1' : '0',
        });

        try {
            const response = await fetch('receiving_list.php?' + params.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Failed to load products');
            }

            tableBody.innerHTML = data.html || '<tr><td colspan="8" class="py-6 px-6 text-center text-gray-500">No products found</td></tr>';
            totalPages = data.total_pages || 1;
            totalItems = data.total || 0;
            currentPage = data.page || currentPage;
            updatePageDisplay();

            if (typeof window.onReceivingTableAfterLoad === 'function') {
                window.onReceivingTableAfterLoad(data);
            }
        } catch (error) {
            console.error(error);
            tableBody.innerHTML = '<tr><td colspan="8" class="py-6 px-6 text-center text-red-500">Failed to load products. Please refresh.</td></tr>';
            if (typeof showToast === 'function') {
                showToast(error.message || 'Failed to load products', 'error');
            }
        } finally {
            isLoading = false;
        }
    }

    function scheduleReload(page) {
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(() => loadReceivingPage(page || 1), 250);
    }

    window.filterRows = function () {
        if (showAllMode) {
            showAllMode = false;
        }
        scheduleReload(1);
    };

    window.showPage = function (page) {
        if (showAllMode) {
            return;
        }
        loadReceivingPage(page);
    };

    window.sortTable = function (columnIndex, isNumeric) {
        const columnMap = { 2: 'name', 3: 'quantity' };
        const nextColumn = columnMap[columnIndex];
        if (!nextColumn) {
            return;
        }
        if (sortState.column === nextColumn) {
            sortState.direction = sortState.direction === 'asc' ? 'desc' : 'asc';
        } else {
            sortState.column = nextColumn;
            sortState.direction = isNumeric ? 'desc' : 'asc';
        }
        loadReceivingPage(1);
    };

    function handlePrevPage() {
        if (!showAllMode && currentPage > 1) {
            loadReceivingPage(currentPage - 1);
        }
    }

    function handleNextPage() {
        if (!showAllMode && currentPage < totalPages) {
            loadReceivingPage(currentPage + 1);
        }
    }

    function handleFirstPage() {
        if (!showAllMode) {
            loadReceivingPage(1);
        }
    }

    function handleLastPage() {
        if (!showAllMode) {
            loadReceivingPage(totalPages);
        }
    }

    function handlePageInput(inputElement) {
        const desiredPage = parseInt(inputElement.value, 10);
        if (!isNaN(desiredPage) && !showAllMode) {
            loadReceivingPage(Math.min(Math.max(1, desiredPage), totalPages));
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', () => scheduleReload(1));
    }
    if (categoryFilter) {
        categoryFilter.addEventListener('change', () => scheduleReload(1));
    }

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
            loadReceivingPage(1);
        }
    });

    window.reloadReceivingTable = function (page) {
        loadReceivingPage(page || currentPage);
    };

    window.initReceivingTable = async function () {
        if (typeof window.bootReceivingPage === 'function') {
            await window.bootReceivingPage();
        } else {
            await loadReceivingPage(1);
        }
    };

    window.initReceivingTable();
})();
