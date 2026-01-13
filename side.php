


<aside class="bg-gray-250 text-black w-64 min-h-screen p-6 shadow-lg relative flex flex-col">
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
    </style>
    <div class="absolute top-0 left-0 right-0 text-center text-lg text-teal-800 px-2 py-1 font-bold">
        <?php
            date_default_timezone_set('Africa/Harare');
            echo date('D, M j H:i');
        ?>
    </div>

    <div class="relative mb-6">
        <div class="flex flex-col items-center justify-center"><br>
            <a><img src="logo.png" style="width: 60px;" alt="POS System Icon"></a><br>
            <h1 class="text-3xl font-bold">POS System</h1>
        </div>
    </div>
    <nav>
        <ul class="space-y-3">
            <li>
                <a href="home" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer" data-href="./">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                    </svg>
                    <span class="text-lg">Home</span>
                </a>
            </li>

    

            <li>
                <a href="logout" class="nav-link flex items-center py-3 px-5 rounded hover:bg-gray-200 transition-colors duration-200 cursor-pointer" data-href="logout">
                    <svg class="w-6 h-6 mr-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                    </svg>
                    <span class="text-lg">Logout</span>
                </a>
            </li>


        </ul>
    </nav>
    <!-- Move this section to the bottom of the sidebar -->
    <div class="mt-auto pl-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-3">
                <div class="w-10 h-10 bg-teal-600 rounded-full flex items-center justify-center text-white font-bold">
                    <?php echo strtoupper(substr($_SESSION['username'], 0, 1)); ?>
                </div>
                <div>
                    <p class="text-sm font-medium"><?php echo $_SESSION['username']; ?></p>
                    <p class="text-xs text-gray-600"><?php echo ucfirst($_SESSION['role']); ?></p>
                </div>
            </div>
        </div>
    </div>

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
                (currentPage.startsWith('credit-book.php') && link.getAttribute('data-href') === 'credit-book')) {
                link.classList.add('bg-gray-300');
            }
        });
    }

    // Update active link on page load and set default active
    document.addEventListener('DOMContentLoaded', () => {
        updateActiveLink();
        // If no page is selected, activate home by default
        if (!document.querySelector('.nav-link.bg-gray-300')) {
            document.querySelector('[data-href="./"]').classList.add('bg-gray-300');
        }
    });
</script>

<script src="admin/3.4.16"></script>
