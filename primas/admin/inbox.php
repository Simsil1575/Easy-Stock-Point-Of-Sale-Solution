
<?php
session_start();

// Set timezone to Central Africa Time (CAT)
date_default_timezone_set('Africa/Harare');

// Check if user is logged in
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    // Redirect to login page if not logged in
    header("Location: ../");
    exit();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Messages</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../lucide.js"></script>
    <script src="3.4.16"></script>

    <style>
        /* Sidebar and content styles */
        .sidebar {
            position: fixed;
            height: 100%;
            width: 250px;
            z-index: 9999 !important; /* Prevent overlay from overlapping sidebar */
        }
        #sidebar {
            z-index: 9999 !important; /* Ensure sidebar stays above overlay */
        }
        .content {
            margin-left: 250px;
            width: calc(100vw - 250px);
            overflow-x: hidden;
        }
        
        /* Container styles */
        .container {
            max-width: 100vw;
            padding: 0 1rem;
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10000 !important; /* Highest - always accessible */
        }
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background: rgb(0, 0, 0);
            border-radius: 2px;
            opacity: 1;
            left: 0;
            transform: rotate(0deg);
            transition: .25s ease-in-out;
        }
        
        .hamburger span:nth-child(1) {
            top: 0px;
        }
        
        .hamburger span:nth-child(2) {
            top: 10px;
        }
        
        .hamburger span:nth-child(3) {
            top: 20px;
        }
        
        /* Open state - transforms into X */
        .hamburger.open span:nth-child(1) {
            top: 10px;
            transform: rotate(135deg);
        }
        
        .hamburger.open span:nth-child(2) {
            opacity: 0;
            left: -60px;
        }
        
        .hamburger.open span:nth-child(3) {
            top: 10px;
            transform: rotate(-135deg);
        }
        
        /* Mobile sidebar overlay */
        .mobile-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 80 !important; /* MUST be below sidebar (9999) and hamburger (10000) */
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        
        .mobile-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) {
            /* Ensure sidebar wrapper doesn't interfere with flex layout */
            .sidebar {
                position: static !important;
                width: 0 !important;
                overflow: visible !important;
            }
            
            /* Sidebar slide animation - override sidebar.php styles with proper transition */
            #sidebar {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                transform: translateX(-100%) !important;
                transition: transform 0.3s ease-in-out !important;
                z-index: 9999 !important; /* Match reference guide - below hamburger (10000) */
                width: 250px !important;
            }
            
            #sidebar.open {
                transform: translateX(0) !important;
            }
            
            /* Prevent body scroll when sidebar is open */
            body.sidebar-open {
                overflow: hidden !important;
            }
            
            .content {
                margin-left: 0 !important;
                width: 100% !important;
                max-width: 100vw !important;
                overflow-x: hidden;
            }
            
            .container {
                padding: 1rem;
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
            }
            
            /* Fixed header on mobile */
            header {
                position: fixed !important;
                top: 0 !important;
                left: 0 !important;
                right: 0 !important;
                z-index: 50 !important; /* Lower than sidebar/overlay */
                width: 100% !important;
            }
            
            /* Add padding to main content to account for fixed header */
            main {
                padding-top: 4rem !important;
            }
        }
        
        /* Mobile Vertical Table Structure */
        @media (max-width: 768px) {
            /* Remove overflow-x-auto on mobile */
            .overflow-auto {
                overflow-x: visible !important;
                width: 100% !important;
                max-width: 100% !important;
            }
            
            /* Ensure table containers don't overflow */
            .rounded-md.border.bg-white {
                width: 100%;
                max-width: 100%;
                box-sizing: border-box;
                overflow: hidden;
            }
            
            /* Ensure tables fit within container */
            table {
                width: 100% !important;
                max-width: 100% !important;
                table-layout: fixed;
                box-sizing: border-box;
            }
            
            /* Hide table headers on mobile */
            table thead {
                display: none;
            }
            
            /* Convert table rows to compact cards */
            table tbody tr {
                display: block;
                width: 100%;
                max-width: 100%;
                margin-bottom: 0.5rem;
                background: white;
                border: 2px solid #d1d5db;
                border-radius: 0.375rem;
                padding: 0.5rem;
                box-shadow: 0 2px 4px 0 rgba(0, 0, 0, 0.1);
                height: auto !important;
                position: relative;
                box-sizing: border-box;
            }
            
            /* Convert table cells to flex containers */
            table tbody td {
                display: flex;
                align-items: center;
                width: 100% !important;
                max-width: 100% !important;
                padding: 0.375rem 0.25rem !important;
                text-align: left !important;
                border: none !important;
                border-bottom: 1px solid #f3f4f6 !important;
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: unset !important;
                height: auto !important;
                line-height: 1.3 !important;
                gap: 0.5rem;
                font-size: 0.8rem !important;
                color: #111827;
                box-sizing: border-box;
                word-wrap: break-word;
            }
            
            /* Remove border from last cell */
            table tbody td:last-child {
                border-bottom: none !important;
            }
            
            /* Add labels using data-label attribute */
            table tbody td::before {
                content: attr(data-label) ":";
                display: inline-block;
                font-weight: 600;
                font-size: 0.7rem;
                color: #6b7280;
                text-transform: uppercase;
                letter-spacing: 0.025em;
                min-width: 4rem;
                flex-shrink: 0;
            }
            
            /* Hide label if data-label is empty */
            table tbody td[data-label=""]::before {
                display: none;
            }
            
            /* Special handling for action columns */
            table tbody td[data-label="Actions"] {
                justify-content: center;
                padding: 0.5rem !important;
            }
            
            table tbody td[data-label="Actions"]::before {
                display: none; /* Hide label for Actions column */
            }
            
            /* Actions column buttons - wrap and stack */
            table tbody td[data-label="Actions"] > div,
            table tbody td[data-label="Actions"] > button {
                display: flex;
                flex-wrap: wrap;
                gap: 0.5rem;
                justify-content: center;
                align-items: center;
                width: 100%;
            }
            
            /* Remove hover effect on mobile cards */
            table tbody tr:hover {
                background: white;
            }
        }
        
        /* Mobile Pagination - Fit in one row */
        @media (max-width: 768px) {
            .flex.items-center.justify-between.px-2.py-4 {
                padding: 0.5rem 0.375rem !important;
                overflow-x: visible !important;
                flex-wrap: wrap;
                gap: 0.5rem;
            }
            
            .flex.items-center.justify-between.px-2.py-4 > div {
                flex-wrap: nowrap !important;
                gap: 0.25rem !important;
                align-items: center !important;
                width: 100% !important;
                min-width: 0 !important;
                overflow: visible !important;
            }
            
            /* Compact pagination buttons */
            #prev-page,
            #next-page {
                padding: 0.375rem 0.4rem !important;
                font-size: 0.65rem !important;
                min-width: auto !important;
                white-space: nowrap;
                height: 2rem !important;
                min-height: 2rem !important;
            }
            
            /* Pagination info text - smaller */
            #pagination-info {
                font-size: 0.65rem !important;
                white-space: nowrap;
            }
        }
    </style>
</head>
<body class="bg-slate-50 overflow-x-hidden">
    <div class="flex min-h-screen">
        <div class="sidebar">
            <?php include 'sidebar.php'; ?>
        </div>
        <div class="flex-1 content lg:ml-0 ml-0">
            <!-- Mobile Sidebar Overlay -->
            <div id="mobileOverlay" class="mobile-overlay lg:hidden" onclick="closeSidebar()"></div>
            
            <div class="h-full flex flex-col">
                <!-- Header -->
                <header class="border-b bg-white/95 backdrop-blur supports-[backdrop-filter]:bg-white/60">
                    <div class="container mx-auto flex h-16 items-center px-6">
                        <!-- Mobile Hamburger Menu Button -->
                        <div class="hamburger lg:hidden bg-[#f3f4f6] p-2 mr-3" onclick="toggleSidebar()">
                            <span></span>
                            <span></span>
                            <span></span>
                        </div>
                        <a href="home" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 hover:text-slate-900 transition-colors">
                            <i data-lucide="arrow-left" class="h-4 w-4"></i>
                            Back to Dashboard
                        </a>
                        <div class="ml-auto flex items-center gap-4">
                            <button id="refresh-inbox" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-9 px-4">
                                <i data-lucide="refresh-cw" class="h-4 w-4 mr-2"></i>
                                Refresh
                            </button>
                            <button id="clear-inbox" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-destructive text-destructive-foreground hover:bg-destructive/90 h-9 px-4">
                                <i data-lucide="trash-2" class="h-4 w-4 mr-2"></i>
                                Clear All
                            </button>
                        </div>
                    </div>
                </header>

                <!-- Main Content -->
                <main class="flex-1 overflow-y-auto">
                    <div class="container mx-auto p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h1 class="text-2xl font-semibold tracking-tight">Messages</h1>
                            <div class="flex items-center gap-4">
                                <!-- Search Input -->
                                <div class="relative">
                                    <input type="text" id="search-input" placeholder="Search messages..." 
                                        class="h-9 w-[200px] rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                    <i data-lucide="search" class="absolute right-3 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500"></i>
                                </div>
                                <!-- Filter Dropdown -->
                                <select id="filter-status" class="h-9 rounded-md border border-input bg-background px-3 py-1 text-sm shadow-sm transition-colors focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring">
                                    <option value="all">All Messages</option>
                                    <option value="unread">Unread</option>
                                    <option value="read">Read</option>
                                </select>
                            </div>
                        </div>

                        <!-- Table Container -->
                        <div class="rounded-md border bg-white">
                            <div class="relative w-full overflow-auto">
                                <table class="w-full caption-bottom text-sm">
                                    <thead class="[&_tr]:border-b">
                                        <tr class="border-b transition-colors hover:bg-muted/50 data-[state=selected]:bg-muted">
                                            <th class="h-12 px-8 text-left align-middle font-medium text-muted-foreground">From</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Status</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Message</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Time</th>
                                            <th class="h-12 px-4 text-left align-middle font-medium text-muted-foreground">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody id="inbox-list" class="[&_tr:last-child]:border-0">
                                        <!-- Messages will be inserted here -->
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div class="flex items-center justify-between px-2 py-4">
                            <div class="flex items-center gap-2 text-sm text-muted-foreground">
                                <span id="pagination-info">Showing 1-10 of 100</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <button id="prev-page" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-8 px-3">
                                    Previous
                                </button>
                                <button id="next-page" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-8 px-3">
                                    Next
                                </button>
                            </div>
                        </div>

                        <!-- Empty State -->
                        <div id="empty-state" class="hidden text-center py-12">
                            <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-slate-100">
                                <i data-lucide="inbox" class="h-6 w-6 text-slate-600"></i>
                            </div>
                            <h3 class="mt-4 text-sm font-medium text-slate-900">No messages</h3>
                            <p class="mt-1 text-sm text-slate-500">Your inbox is empty. New AI insights will appear here.</p>
                        </div>
                    </div>
                </main>
            </div>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="notification" class="fixed top-4 right-4 z-50 hidden">
        <div class="rounded-lg bg-white p-4 shadow-lg ring-1 ring-slate-200">
            <div class="flex items-center gap-3">
                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-teal-100">
                    <i data-lucide="check" class="h-4 w-4 text-teal-600"></i>
                </div>
                <div class="flex-1">
                    <p class="text-sm font-medium text-slate-900">Success</p>
                    <p class="text-sm text-slate-500 notification-message"></p>
                </div>
                <button class="text-slate-400 hover:text-slate-500">
                    <i data-lucide="x" class="h-4 w-4"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Alert Dialog -->
    <div id="alert-dialog" class="fixed inset-0 z-50 hidden">
        <div class="fixed inset-0 bg-black/50" aria-hidden="true"></div>
        <div class="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 rounded-lg bg-white p-6 shadow-lg">
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-full bg-red-100">
                        <i data-lucide="alert-triangle" class="h-5 w-5 text-red-600"></i>
                    </div>
                    <h3 class="text-lg font-semibold text-slate-900">Clear All Messages</h3>
                </div>
                <p class="text-sm text-slate-500">Are you sure you want to clear all messages? This action cannot be undone.</p>
                <div class="flex justify-end gap-3">
                    <button id="alert-cancel" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 border border-input bg-background hover:bg-accent hover:text-accent-foreground h-9 px-4">
                        Cancel
                    </button>
                    <button id="alert-confirm" class="inline-flex items-center justify-center rounded-md text-sm font-medium ring-offset-background transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50 bg-destructive text-destructive-foreground hover:bg-destructive/90 h-9 px-4">
                        Clear All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Mobile sidebar functions
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            const body = document.body;
            
            if (sidebar && overlay && hamburger) {
                const isOpen = sidebar.classList.contains('open');
                
                if (isOpen) {
                    sidebar.classList.remove('open');
                    overlay.classList.remove('active');
                    hamburger.classList.remove('open');
                    body.classList.remove('sidebar-open');
                } else {
                    sidebar.classList.add('open');
                    overlay.classList.add('active');
                    hamburger.classList.add('open');
                    body.classList.add('sidebar-open');
                }
            }
        }

        function closeSidebar() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('mobileOverlay');
            const hamburger = document.querySelector('.hamburger');
            const body = document.body;
            
            if (sidebar && overlay && hamburger) {
                sidebar.classList.remove('open');
                overlay.classList.remove('active');
                hamburger.classList.remove('open');
                body.classList.remove('sidebar-open');
            }
        }
        
        // Initialize Lucide icons
        lucide.createIcons();

        // Add custom styles for status badges
        const style = document.createElement('style');
        style.textContent = `
            .status-badge.unread {
                background-color: rgb(239 246 255);
                color: rgb(29 78 216);
            }
            .status-badge.read {
                background-color: rgb(241 245 249);
                color: rgb(71 85 105);
            }
        `;
        document.head.appendChild(style);
    </script>
</body>
</html>
