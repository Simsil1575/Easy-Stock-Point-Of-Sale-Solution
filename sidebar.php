<aside id="sidebar" class="bg-[#f3f4f6] text-black w-64 h-screen p-6 shadow-lg fixed top-0 left-0 flex flex-col transform -translate-x-full transition-transform duration-300 ease-in-out lg:translate-x-0" style="z-index: 9999; overflow: hidden;">
    <style>
        .nav-link {
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
        
        /* Mobile hamburger menu styles */
        .hamburger {
            position: relative;
            width: 30px;
            height: 24px;
            cursor: pointer;
            z-index: 10;
        }
        
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
                flex: 1;
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
                flex: 1;
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
    </style>
    
    <div class="text-center text-lg text-teal-800 px-2 py-1 font-bold" style="z-index: 2; flex-shrink: 0;">
        <?php
            date_default_timezone_set('Africa/Harare');
            echo date('D, M j H:i');
        ?>
    </div>

    <div class="relative mb-4 mt-4" style="flex-shrink: 0;">
        <div class="flex flex-col items-center justify-center"><br>
            <a><img src="logo.png" style="width: 60px;" alt="POS System Icon"></a><br>
        </div>
    </div>
    <nav>
        <ul class="space-y-3">
            <li>
                <a href="home" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="./">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Home</span>
                </a>
            </li>

            <li>
                <a href="credit-tabs" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="credit-tabs">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <rect x="2" y="5" width="20" height="14" rx="3" stroke="currentColor" stroke-width="2" fill="none"/>
                        <rect x="2" y="9" width="20" height="2" fill="currentColor"/>
                        <rect x="16" y="15" width="4" height="2" rx="1" fill="currentColor"/>
                    </svg>
                    <span class="text-lg text-gray-700">Tabs</span>
                </a>
            </li>

            <li>
                <a href="reports" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="reports">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 17v-2m3 2v-4m3 4v-6m2 10H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Transactions</span>
                </a>
            </li>

            <li>
                <a href="credit-book" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="credit-book">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v5h.293l6 6V4H6v14h6V10h.293l6 6V4z"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Credit Book</span>
                </a>
            </li>

            <li>
                <a href="cash" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="cash">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Cash In/Out</span>
                </a>
            </li>

            <li>
                <a href="settings" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="settings">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Settings</span>
                </a>
            </li>

            <li>
                <a href="logout" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer text-gray-700" data-href="logout">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    <span class="text-lg text-gray-700">Logout</span>
                </a>
            </li>


        </ul>
    </nav>
    <!-- Move this section to the bottom of the sidebar -->
    <div id="sidebarUsernameSection" class="mt-auto border-t border-gray-200">
        <div class="flex items-center space-x-2">
            <div class="w-8 h-8 bg-teal-100 flex items-center justify-center text-teal-600 font-medium text-sm">
                <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
            </div>
            <div class="flex-1 min-w-0">
                <p class="text-sm font-medium text-gray-700 truncate"><?php echo $_SESSION['username']; ?></p>
                <p class="text-xs text-gray-500"><?php echo ucfirst($_SESSION['role']); ?></p>
            </div>
        </div>
    </div>
    <!-- Sidebar is now fixed and properly constrained - no extra padding needed -->

</aside>

<script>
    function updateActiveLink() {
        // Get current page from URL path
        const path = window.location.pathname;
        const currentPage = path.substring(path.lastIndexOf('/') + 1);
        
        // Remove active class from all links
        const links = document.querySelectorAll('.nav-link');
        links.forEach(link => {
            link.classList.remove('bg-gray-300');
            if (link.getAttribute('data-href') === currentPage || 
                currentPage === '' || 
                currentPage === 'index.php' || 
                (currentPage === 'home' && link.getAttribute('data-href') === './') ||
                (currentPage.startsWith('credit-transactions.php') && link.getAttribute('data-href') === 'credit-book') ||
                (currentPage.startsWith('damaged_goods') && link.getAttribute('data-href') === 'settings') ||
                (currentPage.startsWith('credit-book.php') && link.getAttribute('data-href') === 'credit-book') ||
                (currentPage.startsWith('view-tab.php') && link.getAttribute('data-href') === 'credit-tabs') ||
                (currentPage.startsWith('credit-tabs') && link.getAttribute('data-href') === 'credit-tabs')) {
                link.classList.add('bg-gray-300');
            }
        });
    }

    // Mobile Loader Management
    (function() {
        const mobileLoader = document.getElementById('mobileLoader');
        if (!mobileLoader) return;
        
        let isHiding = false;
        let hideTimeout = null;
        
        // Ensure loader is visible on mobile
        function showLoader() {
            if (window.innerWidth < 1024 && mobileLoader && !isHiding) {
                // Clear any pending hide operations
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                    hideTimeout = null;
                }
                isHiding = false;
                
                // Remove fade-out and hidden classes
                mobileLoader.classList.remove('hidden', 'fade-out');
                
                // Show loader with smooth fade-in
                mobileLoader.style.display = 'flex';
                mobileLoader.style.visibility = 'visible';
                
                // Use requestAnimationFrame for smooth fade-in
                requestAnimationFrame(() => {
                    mobileLoader.style.opacity = '1';
                });
            }
        }
        
        // Hide loader when page is fully loaded
        function hideLoader() {
            if (window.innerWidth < 1024 && mobileLoader && !isHiding) {
                isHiding = true;
                
                // Remove fade-out class first to ensure clean animation
                mobileLoader.classList.remove('fade-out');
                
                // Use requestAnimationFrame to ensure smooth transition
                requestAnimationFrame(() => {
                    mobileLoader.classList.add('fade-out');
                    hideTimeout = setTimeout(() => {
                        mobileLoader.classList.add('hidden');
                        mobileLoader.style.display = 'none';
                        isHiding = false;
                        hideTimeout = null;
                    }, 500);
                });
            }
        }
        
        // Initialize loader state based on page load state
        function initLoader() {
            if (window.innerWidth >= 1024) {
                // Hide on desktop
                mobileLoader.style.display = 'none';
                mobileLoader.style.opacity = '0';
                mobileLoader.style.visibility = 'hidden';
                mobileLoader.classList.add('hidden');
                return;
            }
            
            // On mobile, check if page is already loaded
            if (document.readyState === 'complete') {
                // Page already loaded, ensure loader is hidden
                mobileLoader.style.display = 'none';
                mobileLoader.style.opacity = '0';
                mobileLoader.style.visibility = 'hidden';
                mobileLoader.classList.add('hidden');
            } else {
                // Page still loading, show loader
                showLoader();
                
                // Hide when DOM is ready
                if (document.readyState === 'loading') {
                    document.addEventListener('DOMContentLoaded', function() {
                        // Wait a bit for content to render
                        setTimeout(hideLoader, 300);
                    }, { once: true });
                }
                
                // Also hide on full page load as backup
                window.addEventListener('load', function() {
                    setTimeout(hideLoader, 200);
                }, { once: true });
            }
        }
        
        // Initialize on script load
        initLoader();
        
        // Show loader on navigation (before page unloads)
        window.addEventListener('beforeunload', function() {
            if (window.innerWidth < 1024) {
                showLoader();
            }
        });
        
        // Handle window resize
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 1024) {
                mobileLoader.style.display = 'none';
                isHiding = false;
                if (hideTimeout) {
                    clearTimeout(hideTimeout);
                    hideTimeout = null;
                }
            } else if (document.readyState === 'complete') {
                // Page already loaded, don't show loader
                hideLoader();
            } else {
                // Page still loading, show loader
                showLoader();
            }
        });
    })();

    // Update active link on page load and set default active
    document.addEventListener('DOMContentLoaded', () => {
        updateActiveLink();
        // If no page is selected, activate home by default
        if (!document.querySelector('.nav-link.bg-gray-300')) {
            document.querySelector('[data-href="./"]').classList.add('bg-gray-300');
        }
    });
    
    // Mobile sidebar functions
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        const hamburger = document.querySelector('.hamburger');
        
        sidebar.classList.toggle('open');
        overlay.classList.toggle('active');
        hamburger.classList.toggle('open');
    }
    
    function closeSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('mobileOverlay');
        const hamburger = document.querySelector('.hamburger');
        
        sidebar.classList.remove('open');
        overlay.classList.remove('active');
        hamburger.classList.remove('open');
    }
    
    // Close sidebar with animation before navigating (mobile)
    document.addEventListener('DOMContentLoaded', () => {
        const navLinks = document.querySelectorAll('.nav-link');
        navLinks.forEach(link => {
            link.addEventListener('click', (e) => {
                // Only handle on mobile screens when sidebar is open
                if (window.innerWidth < 1024) {
                    const sidebar = document.getElementById('sidebar');
                    const isOpen = sidebar && sidebar.classList.contains('open');
                    
                    if (isOpen) {
                        // Prevent default navigation
                        e.preventDefault();
                        e.stopPropagation();
                        
                        // Get the target URL - prefer href over data-href
                        let href = link.getAttribute('href') || link.getAttribute('data-href');
                        if (!href || href.startsWith('#') || href.startsWith('javascript:')) {
                            // If no valid href, just close sidebar
                            closeSidebar();
                            return;
                        }
                        
                        // Normalize the href
                        if (href === './') {
                            href = 'home';
                        } else if (href.startsWith('./')) {
                            href = href.substring(2);
                        }
                        
                        // Close sidebar with animation
                        closeSidebar();
                        
                        // Wait for animation to complete (300ms transition + 50ms buffer)
                        setTimeout(() => {
                            // Navigate to the new page
                            if (href.startsWith('http://') || href.startsWith('https://')) {
                                window.location.href = href;
                            } else {
                                // Use the href as-is (it should be relative to current directory)
                                window.location.href = href;
                            }
                        }, 350); // 300ms animation + 50ms buffer
                    }
                }
            });
        });
    });
    
    // Close sidebar on window resize if switching to desktop
    window.addEventListener('resize', () => {
        if (window.innerWidth >= 1024) {
            closeSidebar();
        }
    });
    
    // Fix sidebar height for Android navbar on mobile
    // Note: CSS now handles height with 100svh/100dvh, but we keep this as fallback
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
    
    // Set height on load and resize
    document.addEventListener('DOMContentLoaded', setSidebarHeight);
    window.addEventListener('resize', setSidebarHeight);
    window.addEventListener('orientationchange', () => {
        setTimeout(setSidebarHeight, 100);
    });
    
    // Use Visual Viewport API for better mobile support
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', setSidebarHeight);
        window.visualViewport.addEventListener('scroll', setSidebarHeight);
    }
</script>

<script src="admin/3.4.16"></script>
