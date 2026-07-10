<?php
session_start();
date_default_timezone_set('Africa/Harare');

if (!isset($_SESSION['user_id'], $_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../');
    exit;
}

$pdo = new PDO('sqlite:' . __DIR__ . '/../active.db');
if ($pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn() == 0) {
    header('Location: settings');
    exit;
}

$db = new PDO('sqlite:' . __DIR__ . '/../pos.db');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
require_once __DIR__ . '/../ensure_laybye_schema.php';
require_once __DIR__ . '/../laybye_list_helpers.php';
ensureLaybyeSchema($db);

function getUsernameByIdLaybye($userId) {
    if (empty($userId)) {
        return 'Unknown';
    }
    if (!is_numeric($userId)) {
        return $userId;
    }
    try {
        $userDb = new PDO('sqlite:' . __DIR__ . '/../user.db');
        $stmt = $userDb->prepare('SELECT username FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $u = $stmt->fetch(PDO::FETCH_ASSOC);
        return $u ? $u['username'] : 'User #' . $userId;
    } catch (Exception $e) {
        return 'User #' . $userId;
    }
}

$currentUsername = $_SESSION['username'] ?? '';
$currentUserId = $_SESSION['user_id'] ?? '';
$role = strtolower($_SESSION['role'] ?? '');

$list = laybyeFetchListForClientTable($db, $role, $currentUsername, (string) $currentUserId);
$rows = $list['rows'];
$totalLoaded = $list['totalLoaded'];
$perPage = $list['perPage'];
$statusVal = $list['status'];

foreach ($rows as &$r) {
    $r['opened_by_username'] = getUsernameByIdLaybye($r['cashier_id']);
}
unset($r);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lay-byes</title>
    <script src="../navigation.js" async></script>
    <link href="../src/output.css" rel="stylesheet">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
    <script src="../sweetalert2@11.js"></script>
</head>
<body class="bg-gray-50 min-h-screen">
    <?php include 'sidebar.php'; ?>
    <div class="mobile-overlay lg:hidden fixed inset-0 bg-black/50 z-[80] hidden" id="mobileOverlay" onclick="closeSidebar()"></div>
    <div class="content flex-1 lg:ml-64 p-4 lg:p-6">
        <?php if (!empty($_SESSION['success'])): ?>
            <div class="mb-4 p-3 rounded-lg bg-teal-50 text-teal-800 text-sm"><?= htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (!empty($_SESSION['error'])): ?>
            <div class="mb-4 p-3 rounded-lg bg-red-50 text-red-800 text-sm"><?= htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div class="flex items-start gap-2 sm:gap-3 min-w-0 flex-1">
                <a href="admin-center" class="inline-flex items-center px-3 py-2 sm:px-4 bg-gray-200 hover:bg-gray-300 text-gray-800 rounded-lg transition-colors text-sm font-medium flex-shrink-0" title="Back to Admin Center">
                    <svg class="w-5 h-5 sm:mr-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                    </svg>
                    <span class="hidden sm:inline">back</span>
                </a>
                <button type="button" class="hamburger lg:hidden p-2 flex-shrink-0 -ml-1" onclick="toggleSidebar()" aria-label="Open menu"><span></span><span></span><span></span></button>
                <div class="min-w-0 flex-1">
                    <h1 class="text-xl sm:text-2xl font-bold text-gray-900">Lay-bye accounts</h1>
                    <p class="text-gray-600 text-sm">Open and manage customer lay-byes</p>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden mb-4">
            <div class="p-4 flex flex-col lg:flex-row lg:flex-wrap gap-3 lg:items-end border-b border-gray-100">
                <div class="flex-1 min-w-[200px]">
                    <label for="laybye_q" class="block text-xs font-medium text-gray-600 mb-1">Search <span class="text-gray-400 font-normal">(this list only)</span></label>
                    <input type="text" id="laybye_q" value="" autocomplete="off" spellcheck="false"
                           data-lpignore="true" data-1p-ignore data-form-type="other"
                           readonly
                           onfocus="this.removeAttribute('readonly');"
                           onmousedown="this.removeAttribute('readonly');"
                           placeholder="Reference, customer, phone, ID…"
                           class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500 focus:border-teal-500">
                </div>
                <div class="w-full sm:w-40">
                    <label for="laybye_status" class="block text-xs font-medium text-gray-600 mb-1">Status</label>
                    <select name="status" id="laybye_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500">
                        <option value="all" <?= $statusVal === 'all' ? 'selected' : '' ?>>All</option>
                        <option value="active" <?= $statusVal === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="completed" <?= $statusVal === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $statusVal === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                <div class="w-full sm:w-32">
                    <label for="laybye_per_page" class="block text-xs font-medium text-gray-600 mb-1">Per page</label>
                    <select name="per_page" id="laybye_per_page" class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-teal-500">
                        <?php foreach ([10, 15, 25, 50, 100] as $pp): ?>
                            <option value="<?= $pp ?>" <?= $perPage === $pp ? 'selected' : '' ?>><?= $pp ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="flex items-center gap-2">
                    <a href="laybye.php" class="px-4 py-2 border border-gray-300 rounded-lg text-sm text-gray-700 hover:bg-gray-50">Clear filters</a>
                </div>
            </div>
            <div class="px-4 py-2 text-sm text-gray-600 border-b border-gray-100">
                <span id="laybyeListMeta"><?= (int) $totalLoaded === 0 ? htmlspecialchars($statusVal === 'all' ? 'No lay-bye accounts yet' : 'No lay-byes for this filter') : '' ?></span>
            </div>
        </div>

        <div class="bg-white rounded-xl shadow border border-gray-100 overflow-hidden">
            <div class="overflow-x-auto">
                <table id="laybyeTable" class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Ref</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Customer</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Total</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Balance</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Plan</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($rows)): ?>
                            <tr><td colspan="7" class="px-4 py-8 text-center text-gray-500 text-sm"><?= (int) $totalLoaded === 0 && $statusVal === 'all' ? 'No lay-bye accounts yet' : 'No lay-byes for this filter' ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $searchBlob = strtolower(trim(
                                    (string) ($row['reference'] ?? '') . ' '
                                    . (string) ($row['creditor_name'] ?? '') . ' '
                                    . (string) ($row['creditor_phone'] ?? '') . ' '
                                    . (string) (int) ($row['id'] ?? 0)
                                ));
                                ?>
                                <tr class="hover:bg-gray-50 cursor-pointer laybye-row transition-colors focus:outline-none focus:ring-2 focus:ring-teal-500 focus:ring-inset" tabindex="0" role="link" data-href="view-laybye.php?id=<?= (int) $row['id'] ?>" data-search="<?= htmlspecialchars($searchBlob, ENT_QUOTES) ?>" aria-label="View lay-bye <?= htmlspecialchars($row['reference'] ?? '', ENT_QUOTES) ?>">
                                    <td class="px-4 py-3 text-sm font-medium text-gray-900"><?= htmlspecialchars($row['reference'] ?? '') ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-700"><?= htmlspecialchars($row['creditor_name'] ?? '') ?></td>
                                    <td class="px-4 py-3 text-sm text-right">N$<?= number_format((float) $row['total_amount'], 2) ?></td>
                                    <td class="px-4 py-3 text-sm text-right font-medium <?= (float) $row['balance_due'] > 0.01 ? 'text-orange-600' : 'text-teal-600' ?>">N$<?= number_format((float) $row['balance_due'], 2) ?></td>
                                    <td class="px-4 py-3 text-sm text-gray-600"><?= htmlspecialchars($row['plan_frequency']) ?>, <?= (int) laybyeEffectivePlanPeriod($row) ?> payments · N$<?= number_format((float) $row['installment_amount'], 2) ?></td>
                                    <td class="px-4 py-3">
                                        <span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium <?= $row['status'] === 'active' ? 'bg-teal-100 text-teal-800' : ($row['status'] === 'completed' ? 'bg-gray-100 text-gray-700' : 'bg-red-100 text-red-800') ?>"><?= htmlspecialchars($row['status']) ?></span>
                                    </td>
                                    <td class="px-4 py-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <a href="view-laybye.php?id=<?= (int) $row['id'] ?>" class="text-teal-600 hover:text-teal-800 text-sm font-medium">View</a>
                                            <?php
                                            $canDeleteRow = in_array($role, ['admin', 'manager'], true)
                                                || (string) ($row['cashier_id'] ?? '') === (string) $currentUserId
                                                || (string) ($row['cashier_id'] ?? '') === $currentUsername;
                                            ?>
                                            <?php if ($canDeleteRow): ?>
                                                <button type="button"
                                                        class="btn-delete-laybye text-red-600 hover:text-red-800 text-sm font-medium"
                                                        data-id="<?= (int) $row['id'] ?>"
                                                        data-ref="<?= htmlspecialchars($row['reference'] ?? ('#' . $row['id']), ENT_QUOTES) ?>"
                                                        data-status="<?= htmlspecialchars($row['status'] ?? '', ENT_QUOTES) ?>">
                                                    Delete
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <nav id="laybyeClientPagination" class="px-4 py-3 flex flex-wrap items-center justify-between gap-2 border-t border-gray-100 text-sm hidden" aria-label="Pagination">
                <div class="text-gray-600"><span id="laybyePgLabel">Page 1 of 1</span></div>
                <div class="flex flex-wrap gap-1">
                    <button type="button" id="laybyePgFirst" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">First</button>
                    <button type="button" id="laybyePgPrev" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Prev</button>
                    <button type="button" id="laybyePgNext" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Next</button>
                    <button type="button" id="laybyePgLast" class="px-3 py-1.5 rounded-lg border border-gray-200 bg-white hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed">Last</button>
                </div>
            </nav>
        </div>
    </div>
    <script>
        (function () {
            var base = 'laybye.php';
            var stEl = document.getElementById('laybye_status');
            var ppEl = document.getElementById('laybye_per_page');
            function buildQs() {
                var status = stEl ? stEl.value : 'all';
                var perPage = ppEl ? parseInt(ppEl.value, 10) || 15 : 15;
                var parts = [];
                if (status !== 'all') parts.push('status=' + encodeURIComponent(status));
                if (perPage !== 15) parts.push('per_page=' + perPage);
                return parts.length ? ('?' + parts.join('&')) : '';
            }
            function goFilters() {
                window.location.href = base + buildQs();
            }
            if (stEl) stEl.addEventListener('change', goFilters);
            if (ppEl) ppEl.addEventListener('change', goFilters);
        })();
        (function () {
            var allRows = [];
            var filteredRows = [];
            var currentPage = 1;
            var rowsPerPage = <?= (int) $perPage ?>;
            var qEl = document.getElementById('laybye_q');
            var metaEl = document.getElementById('laybyeListMeta');

            function laybyeUpdatePaginationUi(page, maxPage) {
                var nav = document.getElementById('laybyeClientPagination');
                var label = document.getElementById('laybyePgLabel');
                if (label) label.textContent = 'Page ' + page + ' of ' + maxPage;
                if (nav) {
                    if (allRows.length === 0 || filteredRows.length === 0) nav.classList.add('hidden');
                    else nav.classList.toggle('hidden', maxPage <= 1);
                }
                var f = document.getElementById('laybyePgFirst');
                var p = document.getElementById('laybyePgPrev');
                var n = document.getElementById('laybyePgNext');
                var l = document.getElementById('laybyePgLast');
                if (f) f.disabled = page <= 1;
                if (p) p.disabled = page <= 1;
                if (n) n.disabled = page >= maxPage;
                if (l) l.disabled = page >= maxPage;
            }

            function updateMeta() {
                if (!metaEl) return;
                var n = filteredRows.length;
                var total = allRows.length;
                if (total === 0) return;
                if (n === 0) {
                    metaEl.textContent = 'No matches for search (' + total + ' loaded)';
                    return;
                }
                var maxPage = Math.ceil(n / rowsPerPage) || 1;
                var start = (currentPage - 1) * rowsPerPage + 1;
                var end = Math.min(n, currentPage * rowsPerPage);
                var extra = n !== total ? ' (' + total + ' loaded)' : '';
                metaEl.textContent = 'Showing ' + start + '–' + end + ' of ' + n + extra + ' · Page ' + currentPage + '/' + maxPage;
            }

            function updateTable() {
                var tbody = document.querySelector('#laybyeTable tbody');
                if (!tbody) return;
                if (allRows.length === 0) return;

                while (tbody.firstChild) tbody.removeChild(tbody.firstChild);

                if (filteredRows.length === 0) {
                    var tr = document.createElement('tr');
                    var td = document.createElement('td');
                    td.colSpan = 7;
                    td.className = 'px-4 py-8 text-center text-gray-500 text-sm';
                    td.textContent = 'No matching lay-byes';
                    tr.appendChild(td);
                    tbody.appendChild(tr);
                    return;
                }
                filteredRows.forEach(function (row) { tbody.appendChild(row); });
            }

            function showPage(page) {
                var tbody = document.querySelector('#laybyeTable tbody');
                if (!tbody) return;
                var rows = Array.from(tbody.querySelectorAll('tr')).filter(function (r) { return !r.querySelector('td[colspan]'); });
                if (rows.length === 0) {
                    currentPage = 1;
                    laybyeUpdatePaginationUi(1, 1);
                    updateMeta();
                    return;
                }
                var maxPage = Math.ceil(filteredRows.length / rowsPerPage) || 1;
                page = Math.max(1, Math.min(page, maxPage));
                rows.forEach(function (r) { r.style.display = 'none'; });
                var start = (page - 1) * rowsPerPage;
                var end = start + rowsPerPage;
                rows.slice(start, end).forEach(function (r) { r.style.display = ''; });
                currentPage = page;
                laybyeUpdatePaginationUi(page, maxPage);
                updateMeta();
            }

            function filterTable(term) {
                term = term.toLowerCase().trim();
                if (!term) {
                    filteredRows = allRows.slice();
                } else {
                    filteredRows = allRows.filter(function (tr) {
                        var blob = tr.getAttribute('data-search') || '';
                        return blob.indexOf(term) !== -1;
                    });
                }
                updateTable();
                showPage(1);
            }

            function init() {
                var tbody = document.querySelector('#laybyeTable tbody');
                if (!tbody) return;
                if (sessionStorage.getItem('laybye_clear_search_on_show')) {
                    sessionStorage.removeItem('laybye_clear_search_on_show');
                    var qFresh = document.getElementById('laybye_q');
                    if (qFresh) qFresh.value = '';
                }
                allRows = Array.from(tbody.querySelectorAll('tr.laybye-row'));
                var ppSel = document.getElementById('laybye_per_page');
                if (ppSel) rowsPerPage = parseInt(ppSel.value, 10) || rowsPerPage;

                if (allRows.length === 0) {
                    laybyeUpdatePaginationUi(1, 1);
                    return;
                }
                filteredRows = allRows.slice();
                if (qEl) qEl.addEventListener('input', function () { filterTable(this.value); });

                document.getElementById('laybyePgFirst')?.addEventListener('click', function () { showPage(1); });
                document.getElementById('laybyePgPrev')?.addEventListener('click', function () { if (currentPage > 1) showPage(currentPage - 1); });
                document.getElementById('laybyePgNext')?.addEventListener('click', function () {
                    var maxPage = Math.ceil(filteredRows.length / rowsPerPage) || 1;
                    if (currentPage < maxPage) showPage(currentPage + 1);
                });
                document.getElementById('laybyePgLast')?.addEventListener('click', function () {
                    var maxPage = Math.ceil(filteredRows.length / rowsPerPage) || 1;
                    showPage(maxPage);
                });

                updateTable();
                showPage(1);
            }

            if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
            else init();
            window.addEventListener('pageshow', function (ev) {
                var el = document.getElementById('laybye_q');
                if (!el || !ev.persisted) return;
                el.value = '';
                if (allRows.length) {
                    filteredRows = allRows.slice();
                    updateTable();
                    showPage(1);
                }
            });
        })();
        (function () {
            document.querySelectorAll('tr.laybye-row').forEach(function (tr) {
                tr.addEventListener('click', function (e) {
                    if (e.target.closest('a, button, input, select, textarea, label')) return;
                    var url = tr.getAttribute('data-href');
                    if (url) window.location.href = url;
                });
                tr.addEventListener('keydown', function (e) {
                    if (e.key !== 'Enter' && e.key !== ' ') return;
                    e.preventDefault();
                    var url = tr.getAttribute('data-href');
                    if (url) window.location.href = url;
                });
            });
        })();
        function toggleSidebar() {
            document.getElementById('sidebar')?.classList.toggle('open');
            document.querySelector('.hamburger')?.classList.toggle('open');
            document.getElementById('mobileOverlay')?.classList.toggle('hidden');
        }
        function closeSidebar() {
            document.getElementById('sidebar')?.classList.remove('open');
            document.querySelector('.hamburger')?.classList.remove('open');
            document.getElementById('mobileOverlay')?.classList.add('hidden');
        }
        document.querySelectorAll('.btn-delete-laybye').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var id = parseInt(btn.getAttribute('data-id'), 10);
                var ref = btn.getAttribute('data-ref') || '';
                var st = btn.getAttribute('data-status') || '';
                var extra = st === 'active'
                    ? ' Linked sales orders will be removed, stock restored, and the lay-bye deleted. No cash-out is recorded.'
                    : ' Linked payment orders will be removed from reports.';
                Swal.fire({
                    title: 'Delete lay-bye?',
                    text: 'Permanently remove ' + ref + ' from the list?' + extra + ' Enter manager void PIN.',
                    icon: 'warning',
                    input: 'password',
                    inputLabel: 'Manager void PIN',
                    inputAttributes: { autocapitalize: 'off', autocomplete: 'off' },
                    showCancelButton: true,
                    confirmButtonText: 'Delete',
                    confirmButtonColor: '#dc2626',
                }).then(function (res) {
                    if (!res.isConfirmed) return;
                    fetch('../process_laybye_delete.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ laybye_id: id, manager_pin: res.value || '' }),
                    }).then(function (r) { return r.json(); }).then(function (d) {
                        if (d.success) {
                            Swal.fire({ icon: 'success', title: 'Deleted', timer: 900, showConfirmButton: false }).then(function () {
                                try { sessionStorage.setItem('laybye_clear_search_on_show', '1'); } catch (e) {}
                                window.location.assign(window.location.pathname + window.location.search);
                            });
                        } else {
                            Swal.fire('Error', d.message || 'Failed', 'error');
                        }
                    }).catch(function () { Swal.fire('Error', 'Request failed', 'error'); });
                });
            });
        });
    </script>
</body>
</html>
