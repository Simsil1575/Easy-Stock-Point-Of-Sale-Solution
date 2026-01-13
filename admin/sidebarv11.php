<?php
// Session is already started by the main file, no need to start again
// Authentication is also handled by the main file

// Get current page from URL
$currentPage = basename($_SERVER['PHP_SELF'], '.php');

// Get current view parameter from URL or POST
$currentView = isset($_GET['view']) ? $_GET['view'] : (isset($_POST['view']) ? $_POST['view'] : null);

// Define sidebar sections and their pages
$sidebarSections = [
    'dashboard' => ['home', 'dashboard'],
    'sales' => ['sales'],
    'tabs' => ['credit-tabs', 'view-tab'],
    'products' => ['inventory', 'add_product', 'receiving', 'edit', 'stock_tracking', 'stock_taking'],
    'reports' => ['reports', 'daily_stock_report', 'weekly_sales', 'monthly_sales'],
    'credit' => ['credit-book', 'credit-transactions'],
    'cash' => ['cash'],
    'expenses' => ['expenses'],
    'users' => ['users', 'add_user', 'edit_user'],
    'settings' => ['settings', 'business_settings', 'damaged_goods'],
    'messages' => ['inbox', 'chat'],
    'logs' => ['logs']
];

// Determine which section should be expanded
$expandedSection = null;
foreach ($sidebarSections as $sectionId => $pages) {
    if (in_array($currentPage, $pages)) {
        $expandedSection = $sectionId;
        break;
    }
}
?>

<aside id="sidebar" class="bg-[#f3f4f6] text-black w-64 h-screen p-6 shadow-lg fixed top-0 left-0 flex flex-col transform -translate-x-full lg:translate-x-0" style="z-index: 9999; overflow: hidden; transition: transform 0.15s ease-in-out;">
    <style>
        .sidebar-link, .nav-link, .section-header {
            -webkit-user-drag: none;
            -khtml-user-drag: none;
            -moz-user-drag: none;
            -o-user-drag: none;
            user-drag: none;
            -webkit-user-select: none;
            -khtml-user-select: none;
            -moz-user-select: none;
            -o-user-select: none;
            user-select: none;
        }
        
        /* Prevent font-weight layout shifts */
        .sidebar-link, .nav-link, .section-header {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
            text-rendering: optimizeSpeed;
        }
        
        /* Ensure consistent sizing even with font-weight changes */
        .sidebar-link {
            contain: layout style;
        }
        
        /* Prevent color transition glitches */
        .sidebar-link svg {
            transition: none;
        }
        
        /* Specific fix for sales and other subsection links */
        .sidebar-link.hover\:text-gray-700:hover:not(.active) {
            color: #374151;
        }
        
        .sidebar-link.hover\:bg-gray-100:hover:not(.active) {
            background-color: #f3f4f6;
        }
        
        /* Hide scrollbar but keep scroll functionality */
        #sidebar nav::-webkit-scrollbar {
            width: 0px;
            background: transparent;
        }
        
        #sidebar nav {
            scrollbar-width: none;
            -ms-overflow-style: none;
        }
        
        /* Section animations - optimized for speed and smoothness */
        .section-content {
            overflow: hidden;
            transition: max-height 0.2s cubic-bezier(0.4, 0, 0.2, 1);
            contain: layout;
        }
        
        .section-content:not(.expanded) {
            max-height: 0;
            visibility: hidden;
        }
        
        .section-content.expanded {
            max-height: 500px;
            visibility: visible;
        }
        
        /* Section header styling */
        .section-header {
            cursor: pointer;
            transition: background-color 0.15s ease-in-out;
            min-height: 32px;
            contain: layout;
        }
        
        .section-header:hover:not(.text-slate-700):not(.bg-gray-300) {
            background-color: #e5e7eb;
        }
        
        /* Prevent active section header from showing hover state */
        .section-header.text-slate-700,
        .section-header.bg-gray-300 {
            pointer-events: auto;
        }
        
        /* Ensure section header text doesn't shift */
        .section-header span {
            font-weight: 600;
        }
        
        /* Chevron animation - faster and smoother */
        .chevron {
            transition: transform 0.2s ease-in-out;
            transform-origin: center;
            will-change: transform;
        }
        
        .chevron.rotated {
            transform: rotate(90deg);
        }
        
        /* Prevent chevron glitches during page load */
        .chevron svg {
            display: block;
        }
        
        /* Subsection link styling - no transform glitch */
        .sidebar-link {
            transition: background-color 0.15s ease-in-out;
            will-change: auto;
        }
        
        .sidebar-link:hover:not(.active) {
            background-color: #e5e7eb;
        }
        
        .sidebar-link.active {
            background-color: #e5e7eb;
            color: #374151;
            font-weight: 500;
            /* Prevent layout shift from font-weight */
            letter-spacing: -0.01em;
        }
        
        /* Disable transitions on links during page load and optimize rendering */
        .sidebar-link {
            transform: translateZ(0);
            backface-visibility: hidden;
            /* Reserve space for font-weight change */
            text-shadow: 0 0 0 transparent;
        }
        
        /* Prevent any transforms or animations during navigation */
        .sidebar-link:active {
            transform: none !important;
            transition: opacity 0.1s ease-in-out;
        }
        
        /* Faster mobile sidebar transitions */
        @media (max-width: 1023px) {
            #sidebar {
                transition: transform 0.15s ease-in-out;
            }
        }
        
        /* Critical: Override any inline or utility class transitions that cause glitches */
        .sidebar-link,
        .section-header,
        .section-content {
            transition-property: background-color, color, max-height, visibility !important;
            transition-timing-function: ease-in-out !important;
        }
        
        .sidebar-link {
            transition-duration: 0.15s !important;
        }
        
        .section-header {
            transition-duration: 0.15s !important;
        }
        
        .section-content {
            transition-duration: 0.2s !important;
        }
        
        .chevron {
            transition-property: transform !important;
            transition-duration: 0.2s !important;
            transition-timing-function: ease-in-out !important;
        }
        
        /* Compact mode classes for dynamic adjustment */
        #sidebar.medium-compact-mode .nav-link {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
            padding-left: 1rem !important;
            padding-right: 1rem !important;
        }
        
        #sidebar.medium-compact-mode .nav-link svg {
            width: 1.25rem !important;
            height: 1.25rem !important;
            margin-right: 0.75rem !important;
        }
        
        #sidebar.medium-compact-mode .nav-link span {
            font-size: 0.875rem !important;
        }
        
        #sidebar.medium-compact-mode nav ul li {
            margin-bottom: 0.5rem !important;
        }
        
        #sidebar.compact-mode .nav-link {
            padding-top: 0.375rem !important;
            padding-bottom: 0.375rem !important;
            padding-left: 0.75rem !important;
            padding-right: 0.75rem !important;
        }
        
        #sidebar.compact-mode .nav-link svg {
            width: 1.125rem !important;
            height: 1.125rem !important;
            margin-right: 0.5rem !important;
        }
        
        #sidebar.compact-mode .nav-link span {
            font-size: 0.8125rem !important;
        }
        
        #sidebar.compact-mode nav ul li {
            margin-bottom: 0.25rem !important;
        }
        
        #sidebar.compact-mode {
            padding: 0.75rem !important;
        }
        
        #sidebar.compact-mode > div:first-child {
            padding: 0.375rem !important;
            font-size: 0.6875rem !important;
        }
        
        #sidebar.compact-mode > div:nth-child(2) {
            margin-top: 0.375rem !important;
            margin-bottom: 0.375rem !important;
        }
        
        #sidebar.compact-mode > div:nth-child(2) img {
            width: 35px !important;
        }
        
        #sidebar.compact-mode #sidebarUsernameSection {
            padding-top: 0.5rem !important;
            padding-bottom: 0.5rem !important;
        }
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10;
        }
        
        /* Note: Hamburger button z-index is set in the main page (10000), not here */
        
        .hamburger span {
            display: block;
            position: absolute;
            height: 3px;
            width: 100%;
            background:rgb(73, 73, 73);
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
        
        
        /* Mobile sidebar positioning */
        @media (max-width: 1023px) {
            #sidebar {
                position: fixed !important;
                top: 0;
                left: 0;
                z-index: 10000;
                transform: translateX(-100%);
                background-color: #f3f4f6 !important;
                box-shadow: 0 0 20px rgba(0, 0, 0, 0.3);
                /* Account for safe area to ensure username section is always visible */
                height: calc(100vh - env(safe-area-inset-bottom)) !important;
                max-height: calc(100vh - env(safe-area-inset-bottom)) !important;
                overflow: hidden !important;
                display: flex;
                flex-direction: column;
                padding: 1.5rem;
                padding-bottom: 0;
                box-sizing: border-box;
            }
            
            /* Use dvh (dynamic viewport height) if supported - better for mobile */
            @supports (height: 100dvh) {
                #sidebar {
                    height: calc(100dvh - env(safe-area-inset-bottom)) !important;
                    max-height: calc(100dvh - env(safe-area-inset-bottom)) !important;
                }
            }
            
            #sidebar.open {
                transform: translateX(0);
            }
            
            /* Make nav scrollable on mobile while keeping username section fixed at bottom */
            #sidebar nav {
                flex: 1 1 auto;
                overflow-y: auto;
                min-height: 0;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
            
            /* Ensure username section stays at bottom and is always visible on mobile */
            /* Account for Android navigation bar with safe-area-inset-bottom */
            #sidebarUsernameSection {
                flex-shrink: 0;
                background-color: #f3f4f6;
                z-index: 1;
                margin-top: auto;
                padding-top: 1rem;
                /* Use calc to ensure padding accounts for safe area, with minimum 1rem */
                padding-bottom: calc(1rem + env(safe-area-inset-bottom));
                border-top: 1px solid #e5e7eb;
                max-height: fit-content;
                /* Ensure it's always within viewport */
                position: relative;
            }
        }
        
        @media (min-width: 1024px) {
            .hamburger {
                display: none;
            }
            .mobile-overlay {
                display: none;
            }
            
            /* Desktop sidebar positioning - fixed and no overflow */
            #sidebar {
                position: fixed;
                top: 0;
                left: 0;
                height: 100vh;
                max-height: 100vh;
                overflow: hidden;
                display: flex;
                flex-direction: column;
            }
            
            /* Make nav scrollable on desktop while keeping sidebar fixed */
            #sidebar nav {
                flex: 1 1 auto;
                overflow-y: auto;
                min-height: 0;
                -webkit-overflow-scrolling: touch;
                overscroll-behavior: contain;
            }
            
            /* Ensure username section stays at bottom on desktop */
            #sidebarUsernameSection {
                flex-shrink: 0;
                background-color: #f3f4f6;
                margin-top: auto;
                padding-top: 1rem;
                padding-bottom: 1rem;
                border-top: 1px solid #e5e7eb;
            }
            
            /* Add left margin to content area to account for fixed sidebar */
            /* Target content divs that come after sidebar in flex containers */
            div.flex > div.content,
            div.flex > div.flex-1.content,
            body > div.flex > div.content,
            body > div.flex > div.flex-1.content {
                margin-left: 16rem !important; /* 256px = w-64 */
            }
            
            /* Also handle direct body > div.flex structure */
            body > div.flex {
                position: relative;
            }
        }
        
        /* Responsive button sizing to prevent scrollbar */
        /* For smaller desktop screens (1024px - 1280px) */
        @media (min-width: 1024px) and (max-height: 800px) {
            #sidebar nav ul {
                gap: 0.5rem;
            }
            
            #sidebar nav ul li {
                margin-bottom: 0.5rem;
            }
            
            #sidebar nav ul li:last-child {
                margin-bottom: 0;
            }
            
            .nav-link {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
                padding-left: 1rem !important;
                padding-right: 1rem !important;
            }
            
            .nav-link svg {
                width: 1.25rem !important;
                height: 1.25rem !important;
                margin-right: 0.75rem !important;
            }
            
            .nav-link span {
                font-size: 0.875rem !important;
            }
            
            #sidebar {
                padding: 1rem !important;
            }
            
            #sidebar > div:first-child {
                padding: 0.5rem !important;
                font-size: 0.75rem !important;
            }
            
            #sidebar > div:nth-child(2) {
                margin-top: 0.5rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            #sidebar > div:nth-child(2) img {
                width: 40px !important;
            }
        }
        
        /* For very small desktop screens or tablets in landscape */
        @media (min-width: 1024px) and (max-height: 700px) {
            #sidebar nav ul {
                gap: 0.25rem;
            }
            
            #sidebar nav ul li {
                margin-bottom: 0.25rem;
            }
            
            #sidebar nav ul li:last-child {
                margin-bottom: 0;
            }
            
            .nav-link {
                padding-top: 0.375rem !important;
                padding-bottom: 0.375rem !important;
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
            }
            
            .nav-link svg {
                width: 1.125rem !important;
                height: 1.125rem !important;
                margin-right: 0.5rem !important;
            }
            
            .nav-link span {
                font-size: 0.8125rem !important;
            }
            
            #sidebar {
                padding: 0.75rem !important;
            }
            
            #sidebar > div:first-child {
                padding: 0.375rem !important;
                font-size: 0.6875rem !important;
            }
            
            #sidebar > div:nth-child(2) {
                margin-top: 0.375rem !important;
                margin-bottom: 0.375rem !important;
            }
            
            #sidebar > div:nth-child(2) img {
                width: 35px !important;
            }
            
            #sidebarUsernameSection {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
            }
        }
        
        /* Mobile responsive adjustments */
        @media (max-width: 1023px) and (max-height: 600px) {
            #sidebar nav ul {
                gap: 0.25rem;
            }
            
            #sidebar nav ul li {
                margin-bottom: 0.25rem;
            }
            
            #sidebar nav ul li:last-child {
                margin-bottom: 0;
            }
            
            .nav-link {
                padding-top: 0.5rem !important;
                padding-bottom: 0.5rem !important;
                padding-left: 0.75rem !important;
                padding-right: 0.75rem !important;
            }
            
            .nav-link svg {
                width: 1.125rem !important;
                height: 1.125rem !important;
                margin-right: 0.5rem !important;
            }
            
            .nav-link span {
                font-size: 0.8125rem !important;
            }
            
            #sidebar {
                padding: 1rem !important;
            }
            
            #sidebar > div:first-child {
                padding: 0.375rem !important;
                font-size: 0.6875rem !important;
            }
            
            #sidebar > div:nth-child(2) {
                margin-top: 0.5rem !important;
                margin-bottom: 0.5rem !important;
            }
            
            #sidebar > div:nth-child(2) img {
                width: 35px !important;
            }
        }
    </style>
    
    <div class="text-center text-sm text-gray-800 px-2 py-1.5 font-bold" style="z-index: 2; flex-shrink: 0;">
        <?php
            date_default_timezone_set('Africa/Harare');
            echo date('D, M j H:i');
        ?>
    </div>

    <div class="relative mb-4 mt-4" style="flex-shrink: 0;">
        <div class="flex flex-col items-center justify-center">
            <a><img src="../logo.png" style="width: 50px;" alt="POS System Icon"></a>
        </div>
    </div>
    
    <nav class="flex-1 overflow-y-auto" style="min-height: 0;">
        <div class="space-y-1 pb-6">
            
            <!-- Dashboard -->
            <a href="home" class="flex items-center px-3 py-2 transition-all duration-200 ease-in-out rounded-lg <?php echo ($currentPage === 'home' || $currentPage === 'dashboard') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-200'; ?>">
                <span class="text-xs font-semibold uppercase tracking-wider">Dashboard</span>
            </a>

            <!-- Sales -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer rounded-lg <?php echo ($expandedSection === 'sales') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-200'; ?>" onclick="toggleSection('sales')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Sales</span>
                    <svg class="chevron w-4 h-4 <?php echo ($expandedSection === 'sales') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'sales') ? 'expanded' : ''; ?>" id="sales-content">
                    <a href="sales" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm <?php echo ($currentPage === 'sales' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="sales?view=daily" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm <?php echo ($currentPage === 'sales' && $currentView === 'daily') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Daily Sales
                    </a>
                    <a href="sales?view=weekly" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm <?php echo ($currentPage === 'sales' && $currentView === 'weekly') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Weekly Sales
                    </a>
                    <a href="sales?view=monthly" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm <?php echo ($currentPage === 'sales' && $currentView === 'monthly') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Monthly Sales
                    </a>
                </div>
            </div>

            <!-- Customer Tabs -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'tabs') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('tabs')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Tables</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'tabs') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'tabs') ? 'expanded' : ''; ?>" id="tabs-content">
                    <a href="credit-tabs" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-tabs' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="credit-tabs?view=active" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-tabs' && $currentView === 'active') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Active Tabs
                    </a>
                    <a href="credit-tabs?view=closed" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-tabs' && $currentView === 'closed') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Closed Tabs
                    </a>
                    <a href="credit-tabs?view=balance" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-tabs' && $currentView === 'balance') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        With Balance
                    </a>
                    <a href="view-tab" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'view-tab') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Tab Details
                    </a>
                </div>
            </div>

            <!-- Products -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'products') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('products')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Inventory</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'products') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'products') ? 'expanded' : ''; ?>" id="products-content">
                    <a href="inventory" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'inventory' || $currentPage === 'edit') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                        View Products
                    </a>
                    <a href="add_product" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'add_product') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Product
                    </a>
                    <a href="receiving" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'receiving') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4"></path>
                        </svg>
                        Receive Stock
                    </a>
                    <a href="stock_tracking" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'stock_tracking') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                        </svg>
                        Stock Tracking
                    </a>
                    <a href="stock_taking" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'stock_taking') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"></path>
                        </svg>
                        Stock Taking
                    </a>
                </div>
            </div>

            <!-- Reports -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'reports') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('reports')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Reports</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'reports') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'reports') ? 'expanded' : ''; ?>" id="reports-content">
                    <a href="reports" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'reports' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="reports?view=daily" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'reports' && $currentView === 'daily') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Daily Report
                    </a>
                    <a href="weekly_sales" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'weekly_sales') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Weekly Report
                    </a>
                    <a href="monthly_sales" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'monthly_sales') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                        </svg>
                        Monthly Report
                    </a>
                    <a href="daily_stock_report" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'daily_stock_report') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Stock Report
                    </a>
                </div>
            </div>

            <!-- Credit -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'credit') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('credit')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Credit</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'credit') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'credit') ? 'expanded' : ''; ?>" id="credit-content">
                    <a href="credit-book" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-book' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="credit-book?view=outstanding" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-book' && $currentView === 'outstanding') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        Outstanding
                    </a>
                    <a href="credit-book?view=paid" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-book' && $currentView === 'paid') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Paid
                    </a>
                    <a href="credit-transactions" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'credit-transactions') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                        </svg>
                        Transactions
                    </a>
                </div>
            </div>

            <!-- Cash -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'cash') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('cash')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Cash</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'cash') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'cash') ? 'expanded' : ''; ?>" id="cash-content">
                    <a href="cash" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'cash' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="cash?view=inflow" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'cash' && $currentView === 'inflow') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 11l5-5m0 0l5 5m-5-5v12"></path>
                        </svg>
                        Cash In
                    </a>
                    <a href="cash?view=outflow" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'cash' && $currentView === 'outflow') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 13l-5 5m0 0l-5-5m5 5V6"></path>
                        </svg>
                        Cash Out
                    </a>
                    <a href="cash?view=summary" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'cash' && $currentView === 'summary') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Summary
                    </a>
                </div>
            </div>

            <!-- Expenses -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'expenses') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('expenses')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Expenses</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'expenses') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'expenses') ? 'expanded' : ''; ?>" id="expenses-content">
                    <a href="expenses" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'expenses' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="expenses?view=add" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'expenses' && $currentView === 'add') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                        </svg>
                        Add Expense
                    </a>
                    <a href="expenses?view=categories" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'expenses' && $currentView === 'categories') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"></path>
                        </svg>
                        Categories
                    </a>
                    <a href="expenses?view=report" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'expenses' && $currentView === 'report') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Expense Report
                    </a>
                </div>
            </div>

            <!-- Users -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'users') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('users')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Users</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'users') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'users') ? 'expanded' : ''; ?>" id="users-content">
                    <a href="users" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'users' || $currentPage === 'edit_user') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        View Users
                    </a>
                    <a href="add_user" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'add_user') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"></path>
                        </svg>
                        Add User
                    </a>
                </div>
            </div>

            <!-- Settings -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'settings') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('settings')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Settings</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'settings') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'settings') ? 'expanded' : ''; ?>" id="settings-content">
                    <a href="settings" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'settings' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        General
                    </a>
                    <a href="settings?view=receipt" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'settings' && $currentView === 'receipt') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                        </svg>
                        Receipt
                    </a>
                    <a href="settings?view=printer" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'settings' && $currentView === 'printer') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
                        </svg>
                        Printer
                    </a>
                    <a href="business_settings" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'business_settings') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                        </svg>
                        Business
                    </a>
                    <a href="damaged_goods" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'damaged_goods') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        Damaged Goods
                    </a>
                </div>
            </div>

            <!-- Messages -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'messages') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('messages')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Messages</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'messages') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'messages') ? 'expanded' : ''; ?>" id="messages-content">
                    <a href="inbox" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'inbox' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8m0 0v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8m18-2a2 2 0 00-2-2H5a2 2 0 00-2 2"></path>
                        </svg>
                        Inbox
                        <span id="inbox-badge" class="ml-auto px-2 py-0.5 text-xs font-medium bg-red-500 text-white rounded-full opacity-0 transition-opacity duration-200"></span>
                    </a>
                    <a href="inbox?view=unread" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'inbox' && $currentView === 'unread') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                        Unread
                    </a>
                    <a href="inbox?view=sent" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'inbox' && $currentView === 'sent') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"></path>
                        </svg>
                        Sent
                    </a>
                    <a href="chat" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'chat') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
                        </svg>
                        Chat
                    </a>
                </div>
            </div>

            <!-- Logs -->
            <div class="mt-4">
                <div class="section-header flex items-center justify-between px-3 py-2 cursor-pointer transition-all duration-200 ease-in-out rounded-lg <?php echo ($expandedSection === 'logs') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-50'; ?>" onclick="toggleSection('logs')">
                    <span class="text-xs font-semibold uppercase tracking-wider">Logs</span>
                    <svg class="chevron w-4 h-4 transition-transform duration-300 ease-in-out <?php echo ($expandedSection === 'logs') ? 'rotated' : ''; ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                    </svg>
                </div>
                <div class="section-content mt-2 space-y-1 <?php echo ($expandedSection === 'logs') ? 'expanded' : ''; ?>" id="logs-content">
                    <a href="logs" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'logs' && empty($currentView)) ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                        </svg>
                        View All
                    </a>
                    <a href="logs?view=sales" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'logs' && $currentView === 'sales') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                        </svg>
                        Sales Logs
                    </a>
                    <a href="logs?view=inventory" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'logs' && $currentView === 'inventory') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                        </svg>
                        Inventory Logs
                    </a>
                    <a href="logs?view=user" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'logs' && $currentView === 'user') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
                        </svg>
                        User Logs
                    </a>
                    <a href="logs?view=system" class="sidebar-link flex items-center p-2 ml-4 rounded-lg text-sm transition-all duration-200 ease-in-out <?php echo ($currentPage === 'logs' && $currentView === 'system') ? 'active' : 'text-gray-600 hover:text-gray-700 hover:bg-gray-100'; ?>">
                        <svg class="w-4 h-4 mr-2 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                        </svg>
                        System Logs
                    </a>
                </div>
            </div>

            <!-- Logout -->
            <a href="logout" class="flex items-center px-3 py-2 transition-all duration-200 ease-in-out rounded-lg <?php echo ($currentPage === 'logout') ? 'text-slate-700 bg-gray-300 shadow-sm' : 'text-slate-600 hover:text-slate-700 hover:bg-slate-200'; ?>">
                <span class="text-xs font-semibold uppercase tracking-wider">Logout</span>
            </a>

        </div>
    </nav>

    <!-- Move this section to the bottom of the sidebar -->
    <div id="sidebarUsernameSection" class="mt-auto border-t border-gray-200">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 bg-gray-200 flex items-center justify-center text-gray-700 font-medium text-sm">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700 truncate"><?php echo $_SESSION['username']; ?></p>
                <p class="text-xs text-gray-600"><?php echo ucfirst($_SESSION['role']); ?></p>
            </div>
        </div>
    </div>
    <!-- Sidebar is now fixed and properly constrained - no extra padding needed -->

</aside>

<script>
/**
 * Optimized Sidebar Management System
 */
class SidebarManager {
    constructor() {
        this.currentPage = '<?php echo $currentPage; ?>';
        this.expandedSection = '<?php echo $expandedSection ?? "null"; ?>';
        this.sections = <?php echo json_encode($sidebarSections); ?>;
        this.isAnimating = false;
        this.animationTimeout = null;
    }

    init() {
        // Ensure proper initial state without animation - synchronous for speed
        this.setInitialState();
        
        // Add event listeners
        this.attachEventListeners();
        
        // Defer non-critical operations
        requestAnimationFrame(() => {
            this.updateInboxBadge();
            this.optimizeForPerformance();
        });
    }

    setInitialState() {
        const sidebar = document.getElementById('sidebar');
        if (!sidebar) return;

        // Disable ALL transitions instantly for page load
        const style = document.createElement('style');
        style.id = 'sidebar-init-style';
        style.textContent = `
            #sidebar, #sidebar *, #sidebar *::before, #sidebar *::after {
                transition: none !important;
                animation: none !important;
            }
            .sidebar-link {
                transform: none !important;
            }
        `;
        document.head.appendChild(style);
        
        // Set up initial expanded state based on PHP - synchronous
        if (this.expandedSection && this.expandedSection !== 'null') {
            const content = document.getElementById(`${this.expandedSection}-content`);
            const chevron = content?.previousElementSibling?.querySelector('.chevron');
            const header = content?.previousElementSibling;
            
            if (content && chevron) {
                content.classList.add('expanded');
                chevron.classList.add('rotated');
                if (header) {
                    header.classList.add('text-slate-700', 'bg-gray-300', 'shadow-sm');
                    header.classList.remove('text-slate-600', 'hover:text-slate-700', 'hover:bg-slate-50');
                }
            }
        }
        
        // Re-enable transitions after paint - much faster
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                const initStyle = document.getElementById('sidebar-init-style');
                if (initStyle) {
                    initStyle.remove();
                }
            });
        });
    }

    attachEventListeners() {
        // Attach click handlers to section headers
        document.querySelectorAll('.section-header').forEach(header => {
            header.addEventListener('click', (e) => {
                e.preventDefault();
                const sectionId = this.getSectionIdFromHeader(header);
                if (sectionId) {
                    this.toggleSection(sectionId);
                }
            });
        });

        // Handle page navigation - optimized
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.addEventListener('click', (e) => {
                const link = e.target.closest('a[href]');
                if (link) {
                    this.preserveScrollPosition();
                }
            }, { passive: true });
        }

        // Handle browser back/forward - no delay needed
        window.addEventListener('popstate', () => {
            this.init();
        });
    }

    getSectionIdFromHeader(header) {
        const content = header.nextElementSibling;
        return content ? content.id.replace('-content', '') : null;
    }

    toggleSection(sectionId) {
        if (this.isAnimating) return;

        const content = document.getElementById(`${sectionId}-content`);
        const chevron = content?.previousElementSibling?.querySelector('.chevron');
        
        if (!content || !chevron) return;

        this.isAnimating = true;
        
        // Clear any existing timeout
        if (this.animationTimeout) {
            clearTimeout(this.animationTimeout);
        }

        if (content.classList.contains('expanded')) {
            // Collapse current section
            this.collapseSection(sectionId);
            this.expandedSection = null;
        } else {
            // Collapse all other sections and expand target simultaneously for speed
            this.collapseAllSections();
            this.expandSection(sectionId, true);
            this.expandedSection = sectionId;
        }

        // Reset animation flag - reduced time for faster interaction
        this.animationTimeout = setTimeout(() => {
            this.isAnimating = false;
        }, 220);
    }

    expandSection(sectionId, animate = true) {
        const content = document.getElementById(`${sectionId}-content`);
        const chevron = content?.previousElementSibling?.querySelector('.chevron');
        
        if (!content || !chevron) return;

        if (!animate) {
            content.style.transition = 'none';
        }

        content.classList.add('expanded');
        chevron.classList.add('rotated');

        if (!animate) {
            requestAnimationFrame(() => {
                content.style.transition = '';
            });
        }
    }

    collapseSection(sectionId) {
        const content = document.getElementById(`${sectionId}-content`);
        const chevron = content?.previousElementSibling?.querySelector('.chevron');
        
        if (!content || !chevron) return;

        content.classList.remove('expanded');
        chevron.classList.remove('rotated');
    }

    collapseAllSections() {
        Object.keys(this.sections).forEach(sectionId => {
            this.collapseSection(sectionId);
        });
    }

    preserveScrollPosition() {
        const nav = document.querySelector('#sidebar nav');
        if (nav) {
            sessionStorage.setItem('sidebarScrollTop', nav.scrollTop.toString());
        }
    }

    restoreScrollPosition() {
        const nav = document.querySelector('#sidebar nav');
        const savedPosition = sessionStorage.getItem('sidebarScrollTop');
        if (nav && savedPosition) {
            nav.scrollTop = parseInt(savedPosition, 10);
            sessionStorage.removeItem('sidebarScrollTop');
        }
    }

    updateInboxBadge() {
        const badge = document.getElementById('inbox-badge');
        if (!badge || badge.dataset.updating === 'true') return;
        
        badge.dataset.updating = 'true';
        
        // Use faster fetch with abort controller for cleanup
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), 5000);
        
        fetch('chat_api.php?unread_count=1', { 
            signal: controller.signal,
            cache: 'no-cache'
        })
            .then(response => response.json())
            .then(data => {
                clearTimeout(timeoutId);
                if (data.status === 'success' && data.count > 0) {
                    badge.textContent = data.count;
                    badge.classList.remove('opacity-0');
                } else {
                    badge.classList.add('opacity-0');
                }
            })
            .catch(error => {
                clearTimeout(timeoutId);
                if (error.name !== 'AbortError') {
                    console.error('Error fetching unread count:', error);
                }
                badge.classList.add('opacity-0');
            })
            .finally(() => {
                badge.dataset.updating = 'false';
            });
    }

    optimizeForPerformance() {
        // Restore scroll position
        this.restoreScrollPosition();

        // Add passive listeners for better scroll performance
        const nav = document.querySelector('#sidebar nav');
        if (nav) {
            nav.addEventListener('scroll', this.throttle(() => {
                this.preserveScrollPosition();
            }, 100), { passive: true });
        }

        // Update inbox badge every 30 seconds
        setInterval(() => this.updateInboxBadge(), 30000);
    }

    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    }
}

// Global function for onclick handlers (backwards compatibility)
let sidebarManager;

function toggleSection(sectionId) {
    if (sidebarManager) {
        sidebarManager.toggleSection(sectionId);
    }
}

// Initialize immediately for faster load - no need to wait for full DOM
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        sidebarManager = new SidebarManager();
        sidebarManager.init();
    });
} else {
    // DOM already loaded, initialize immediately
    sidebarManager = new SidebarManager();
    sidebarManager.init();
}

// Mobile sidebar functions
function toggleSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    const hamburger = document.querySelector('.hamburger');
    
    sidebar.classList.toggle('open');
    if (overlay) overlay.classList.toggle('active');
    if (hamburger) hamburger.classList.toggle('open');
}

function closeSidebar() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('mobileOverlay');
    const hamburger = document.querySelector('.hamburger');
    
    sidebar.classList.remove('open');
    if (overlay) overlay.classList.remove('active');
    if (hamburger) hamburger.classList.remove('open');
}

// Handle subsection navigation without glitches
let navigationTimeout;
const handleSubsectionClick = (e) => {
    const link = e.target.closest('.sidebar-link, #sidebar > nav > div > a');
    if (!link) return;
    
    // Get the target URL
    let href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
        return;
    }
    
    // Only handle on mobile screens when sidebar is open
    if (window.innerWidth < 1024) {
        const sidebar = document.getElementById('sidebar');
        const isOpen = sidebar && sidebar.classList.contains('open');
        
        if (isOpen) {
            // Prevent default navigation
            e.preventDefault();
            e.stopPropagation();
            
            // Clear any pending navigation
            if (navigationTimeout) clearTimeout(navigationTimeout);
            
            // Add visual feedback immediately
            link.style.opacity = '0.7';
            
            // Close sidebar with faster animation
            closeSidebar();
            
            // Navigate after animation (reduced time)
            navigationTimeout = setTimeout(() => {
                window.location.href = href;
            }, 150);
            return;
        }
    }
    
    // Desktop: instant navigation with visual feedback
    if (navigationTimeout) clearTimeout(navigationTimeout);
    link.style.opacity = '0.7';
    
    // Small delay to show visual feedback, then navigate
    navigationTimeout = setTimeout(() => {
        window.location.href = href;
    }, 50);
    
    e.preventDefault();
};

// Attach event listener after DOM loads
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('sidebar');
        if (sidebar) {
            sidebar.addEventListener('click', handleSubsectionClick);
        }
    });
} else {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.addEventListener('click', handleSubsectionClick);
    }
}

// Close sidebar on window resize if switching to desktop
window.addEventListener('resize', () => {
    if (window.innerWidth >= 1024) {
        closeSidebar();
    }
});
    
    // Fix sidebar height for Android navbar on mobile - optimized
    // Note: CSS now handles height with 100svh/100dvh, but we keep this as fallback
    let resizeTimeout;
    function setSidebarHeight() {
        if (window.innerWidth < 1024) {
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                // Use Visual Viewport API if available (better for mobile browsers)
                // This accounts for browser UI and keyboard
                let vh = window.innerHeight;
                if (window.visualViewport) {
                    vh = window.visualViewport.height;
                }
                
                // Use CSS custom property or direct style, but prefer CSS viewport units
                // Only set if CSS viewport units aren't supported
                const supportsSvh = CSS.supports('height', '100svh');
                const supportsDvh = CSS.supports('height', '100dvh');
                
                if (!supportsSvh && !supportsDvh) {
                    // Fallback for older browsers
                    sidebar.style.height = vh + 'px';
                    sidebar.style.maxHeight = vh + 'px';
                } else {
                    // Let CSS handle it with viewport units
                    sidebar.style.height = '';
                    sidebar.style.maxHeight = '';
                }
            }
        }
    }
    
    // Throttled resize handler
    function throttledSetHeight() {
        if (resizeTimeout) clearTimeout(resizeTimeout);
        resizeTimeout = setTimeout(setSidebarHeight, 50);
    }
    
    // Set height on load and resize - optimized
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', setSidebarHeight);
    } else {
        setSidebarHeight();
    }
    window.addEventListener('resize', throttledSetHeight, { passive: true });
    window.addEventListener('orientationchange', throttledSetHeight, { passive: true });
    
    // Use Visual Viewport API for better mobile support
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', throttledSetHeight);
        window.visualViewport.addEventListener('scroll', throttledSetHeight);
    }
    
    // Function to adjust sidebar button sizes to prevent scrollbar (fallback for edge cases)
    let adjustTimeout;
    function adjustSidebarButtons() {
        const sidebar = document.getElementById('sidebar');
        const nav = sidebar?.querySelector('nav');
        
        if (!sidebar || !nav) return;
        
        // Check if nav is overflowing
        const navScrollHeight = nav.scrollHeight;
        const navClientHeight = nav.clientHeight;
        
        // Only adjust if there's overflow
        if (navScrollHeight > navClientHeight && navClientHeight > 0) {
            const viewportHeight = window.innerHeight;
            
            // Apply compact class based on viewport height
            if (viewportHeight < 700) {
                sidebar.classList.add('compact-mode');
                sidebar.classList.remove('medium-compact-mode');
            } else if (viewportHeight < 800) {
                sidebar.classList.add('medium-compact-mode');
                sidebar.classList.remove('compact-mode');
            } else {
                sidebar.classList.remove('compact-mode', 'medium-compact-mode');
            }
        } else {
            sidebar.classList.remove('compact-mode', 'medium-compact-mode');
        }
    }
    
    // Throttled adjust handler
    function throttledAdjust() {
        if (adjustTimeout) clearTimeout(adjustTimeout);
        adjustTimeout = setTimeout(adjustSidebarButtons, 50);
    }
    
    // Adjust buttons on load and resize - optimized
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', throttledAdjust);
    } else {
        throttledAdjust();
    }
    
    window.addEventListener('resize', throttledAdjust, { passive: true });
    
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', throttledAdjust);
    }
</script>

<script src="3.4.16"></script>
