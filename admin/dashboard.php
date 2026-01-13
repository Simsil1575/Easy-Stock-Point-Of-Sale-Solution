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


$pdo = new PDO('sqlite:../active.db'); // Re-establish the database connection
$activationStatus = $pdo->query("SELECT COUNT(*) FROM software_keys WHERE is_used = 1")->fetchColumn();
if ($activationStatus == 0) {
    header('Location: settings');
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Point of Sale System</title>
      <link href="src/output.css" rel="stylesheet">    <style>
        .cart-item {
            @apply flex justify-between mb-2 bg-white border border-gray-300;
        }
    </style>
</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">

    <div class="flex">
        <!-- Sidebar -->

        <!-- Main Content Area -->
<?php
$db = new PDO('mysql:host=localhost;dbname=pos', 'root', '');
?>

<main class="flex-1 p-6 bg-gray-50 flex">
    <!-- Products Section -->
    <div class="w-3/4 pr-6">
        <!-- Search Bar -->
        <div class="mb-6">
            <input type="text" id="searchBar" class="border border-gray-300 rounded-md p-2 w-full" placeholder="Search for products..." oninput="filterProducts()">
        </div>
        
        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8" id="productGrid">
            <?php foreach ($products as $product): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item" data-price="<?= $product['price'] ?>" data-name="<?= htmlspecialchars($product['name']) ?>" onclick="addToCart(this)">
                    <img src="<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-full h-48 object-cover">
                    <div class="p-5">
                        <h2 class="text-2xl font-semibold mb-3 text-gray-900"><?= htmlspecialchars($product['name']) ?></h2>
                        <p class="text-lg font-bold text-teal-700">$<?= number_format($product['price'], 2) ?></p>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        function filterProducts() {
            const searchTerm = document.getElementById('searchBar').value.toLowerCase();
            const products = document.querySelectorAll('.product-item');

            products.forEach(product => {
                const name = product.getAttribute('data-name').toLowerCase();
                if (name.includes(searchTerm)) {
                    product.style.display = 'block';
                } else {
                    product.style.display = 'none';
                }
            });
        }
    </script>

    <!-- Cart Sidebar -->
    <div id="cart" class="w-80 h-full bg-gray-100 shadow-lg rounded-xl p-4 m-2 border border-gray-300">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                <svg class="w-8 h-8 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.4 5M17 13l1.4 5M9 21h6M9 21a2 2 0 11-4 0M15 21a2 2 0 104 0"></path></svg>
                Cart
            </h2>
            <p class="text-lg font-bold text-gray-900 flex items-center">
                N$<span id="cartTotal" class="text-teal-700 text-3xl">0.00</span>
            </p>
        </div>
        <ul id="cartItems" class="mb-6 space-y-4 border border-gray-300 rounded-lg p-4 text-base">
            <!-- Cart items will be added here dynamically -->
        </ul>

        <div class="mt-4">
            <label for="cashReceived" class="block mb-2 text-gray-900 text-sm">Cash Received:</label>
            <input type="number" id="cashReceived" class="border border-gray-300 rounded-md p-2 w-full mb-4 text-sm" step="0.01" oninput="calculateChange()">
            <div class="flex flex-wrap space-x-2 mb-4">
                <button class="bg-gradient-to-r from-blue-600 to-blue-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(2)">N$2</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(5)">N$5</button>
                <button class="bg-gradient-to-r from-red-600 to-red-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(20)">N$20</button>
                <button class="bg-gradient-to-r from-yellow-600 to-yellow-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-yellow-700 hover:to-yellow-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(30)">N$30</button>
                <button class="bg-gradient-to-r from-purple-600 to-purple-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-purple-700 hover:to-purple-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(50)">N$50</button>
                <button class="bg-gradient-to-r from-gray-600 to-gray-800 text-white px-3 py-2 rounded-lg shadow-md hover:from-gray-700 hover:to-gray-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(100)">N$100</button>
            </div>
        </div>

        <div class="flex justify-between items-center mb-6">

            <p class="text-lg font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Change: N$<span id="changeAmount" class="text-teal-700 text-2xl">0.00</span>
            </p>
        </div>

        <button class="mt-4 bg-gradient-to-r from-blue-600 to-blue-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 flex items-center text-lg w-full" onclick="checkout()">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h1l1 2h13l1-2h1m-2 0a2 2 0 100-4 2 2 0 000 4zm-1 0H6m-1 0a2 2 0 100-4 2 2 0 000 4zm-1 0H3m0 0v6a2 2 0 002 2h14a2 2 0 002-2v-6"></path></svg>
            Checkout
        </button>

        <button class="mt-4 bg-gradient-to-r from-red-600 to-red-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 flex items-center text-lg w-full" onclick="clearCart()">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            Clear Cart
        </button>

    </div>
</main>

<script>
        let cart = [];
        let cartItemElements = new Map(); // Store references to cart item DOM elements
        let updateCartTimeout = null; // For debouncing cart updates

        function addToCart(element) {
            const price = parseFloat(element.getAttribute('data-price'));
            const name = element.getAttribute('data-name');
            const item = { name, price };
            cart.push(item);
            updateCartOptimized();
        }

        function updateCartOptimized() {
            // Debounce rapid updates
            if (updateCartTimeout) {
                clearTimeout(updateCartTimeout);
            }
            
            updateCartTimeout = setTimeout(() => {
                const cartItems = document.getElementById('cartItems');
                const cartTotal = document.getElementById('cartTotal');
                let total = 0;

                // Create a fragment for batch DOM operations
                const fragment = document.createDocumentFragment();
                const newCartItemElements = new Map();

                cart.forEach((item, index) => {
                    const itemKey = `${item.name}_${index}`;
                    let li = cartItemElements.get(itemKey);
                    
                    // Only create new element if it doesn't exist
                    if (!li) {
                        li = document.createElement('li');
                        li.className = 'flex justify-between items-center p-4 mb-2 bg-white rounded-lg shadow-md border border-gray-200';
                    }
                    
                    // Update content only if needed
                    const newContent = `
                        <span class="text-gray-900 font-medium">${item.name}</span>
                        <span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>
                    `;
                    
                    if (li.innerHTML !== newContent) {
                        li.innerHTML = newContent;
                    }
                    
                    newCartItemElements.set(itemKey, li);
                    fragment.appendChild(li);
                    total += item.price;
                });

                // Only update DOM if there are changes
                if (cartItems.children.length !== cart.length) {
                    cartItems.innerHTML = '';
                    cartItems.appendChild(fragment);
                }

                cartItemElements = newCartItemElements;
                cartTotal.innerText = total.toFixed(2);
                calculateChange();
            }, 16); // ~60fps debouncing
        }

        // Keep the original updateCart function for backward compatibility
        function updateCart() {
            updateCartOptimized();
        }

        function addCash(amount) {
            const cashReceived = document.getElementById('cashReceived');
            cashReceived.value = (parseFloat(cashReceived.value) || 0) + amount;
            calculateChange();
        }

        function calculateChange() {
            const cartTotal = parseFloat(document.getElementById('cartTotal').innerText) || 0; // Ensure cartTotal is a number
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            const change = cashReceived - cartTotal;
            document.getElementById('changeAmount').innerText = change >= 0 ? change.toFixed(2) : '0.00';
        }

        function checkout() {
            alert('Proceeding to checkout');
            // Implement checkout functionality here
        }

        function clearCart() {
            cart = [];
            cartItemElements.clear(); // Clear the element cache
            updateCartOptimized();
            document.getElementById('cashReceived').value = '';
            document.getElementById('changeAmount').innerText = '0.00';
        }
    </script>
    <script>
        // Add any JavaScript for responsive behavior here
        function toggleSidebar() {
            const sidebar = document.querySelector('aside');
            sidebar.classList.toggle('hidden');
        }
    </script>