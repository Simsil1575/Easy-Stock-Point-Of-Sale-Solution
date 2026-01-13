<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Solution</title>
    <link href="../src/output.css" rel="stylesheet">
    <script src="../navigation.js" async></script>
    <script src="../src/howler.min.js"></script>
    <script src="../src/chart.js"></script>
    <meta name="google" content="notranslate">
    <link rel="icon" href="../favicon.ico" type="image/png">
    <style>
    /* Modern, colorful, and skinny sidebar styles */
    .sidebar {
        width: 2px;
        background: linear-gradient(to bottom, #4F46E5, #7C3AED);
        transition: width 0.3s ease;
    }

    .sidebar:hover {
        width: 12px;
    }

    .sidebar-icon {
        @apply w-6 h-6 text-white opacity-75 transition-all duration-300;
    }

    .sidebar:hover .sidebar-icon {
        @apply opacity-100;
    }

    .sidebar-text {
        @apply ml-3 text-white font-medium opacity-0 transition-opacity duration-300;
    }

    .sidebar:hover .sidebar-text {
        opacity: 1;
    }

    /* Modern, ultra-thin, and visible scrollbar styles */
    .custom-scrollbar {
        scrollbar-width: thin;
        scrollbar-color: #4F46E5 #E5E7EB;
    }

    .custom-scrollbar::-webkit-scrollbar {
        width: 2px;
    }

    .custom-scrollbar::-webkit-scrollbar-track {
        background: #E5E7EB;
        border-radius: 1px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb {
        background-color: #4F46E5;
        border-radius: 1px;
    }

    .custom-scrollbar::-webkit-scrollbar-thumb:hover {
        background-color: #7C3AED;
    }
    </style>

</head>
<body style="background-color:rgb(249 250 251 / var(--tw-bg-opacity, 1))">





    
    <div class="flex">
        <?php include 'sidebar.php'; ?>
        <div class="flex-1 content">
            <div class="container">

        <!-- Main Content Area -->
        <?php
// Database connection
$db = new PDO('sqlite:../pos.db');

// Fetch products from the database
$stmt = $db->query('
    SELECT p.*, COALESCE(SUM(oi.quantity), 0) as total_sold
    FROM products p
    LEFT JOIN order_items oi ON p.name = oi.product_name
    GROUP BY p.id
    ORDER BY total_sold DESC
');

$products = [];
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $products[] = $row;
}
?>

<main class="flex-1 p-6 bg-gray-50 flex">




    <!-- Products Section -->
    <div class="w-3/4 pr-6 max-h-[calc(100%)]">
        <!-- Search Bar -->
        <div class="mb-6 relative">
            <input type="text" id="searchBar" class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-teal-500 focus:border-teal-500 transition-colors duration-200" placeholder="Search for products..." oninput="filterProducts()">
            <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                <svg class="h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                    <path fill-rule="evenodd" d="M8 4a4 4 0 100 8 4 4 0 000-8zM2 8a6 6 0 1110.89 3.476l4.817 4.817a1 1 0 01-1.414 1.414l-4.816-4.816A6 6 0 012 8z" clip-rule="evenodd" />
                </svg>
            </div>
        </div>
        


        <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-8 py-4 <?php echo count($products) > 6 ? 'overflow-y-auto pr-4 custom-scrollbar' : ''; ?>" id="productGrid" style="max-height: calc(100vh - 8rem); height: auto;">
        <?php foreach ($products as $product): ?>
                <div class="bg-white rounded-xl shadow-lg overflow-hidden cursor-pointer transition-transform duration-300 hover:scale-105 product-item select-none" data-price="<?= $product['price'] ?>" data-name="<?= htmlspecialchars($product['name']) ?>" onclick="addToCart(this)" style="height: auto;">
                    <div class="w-full h-48">
                        <img src="../products/<?= htmlspecialchars($product['image_url']) ?>" alt="<?= htmlspecialchars($product['name']) ?>" class="w-full h-full object-cover pointer-events-none loading=lazy">
                    </div>
                    <div class="p-5 flex flex-col">
                         <p class="text-lg font-bold text-gray-900 whitespace-nowrap overflow-hidden text-ellipsis"><?= htmlspecialchars($product['name']) ?></p>
                       <p class="text-2xl font-extrabold text-teal-800">N$<?= number_format($product['price'], 2) ?></p>
                        <p class="text-sm mb-2 <?php 
                            if ($product['quantity'] < 5) {
                                echo 'text-red-600';
                            } elseif ($product['quantity'] < 10) {
                                echo 'text-yellow-600';
                            } else {
                                echo 'text-teal-600';
                            }
                        ?>">Available: <span><?= $product['quantity'] ?></span></p>
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
    <div id="cart" class="w-96 h-full bg-gray-100 shadow-lg rounded-xl p-4 m-2 border border-gray-300 flex flex-col custom-scrollbar" style="height: calc(100vh - 4rem); overflow-y: auto;">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-2xl font-bold text-gray-900 flex items-center">
                <svg class="w-8 h-8 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-1.4 5M17 13l1.4 5M9 21h6M9 21a2 2 0 11-4 0M15 21a2 2 0 104 0"></path></svg>
                Cart
            </h2>
            <p class="text-lg font-bold text-gray-900 flex items-center">
                N$<span id="cartTotal" class="text-teal-700 text-3xl">0.00</span>
            </p>
        </div>
        <ul id="cartItems" class="mb-6 space-y-4 border border-gray-300 rounded-lg p-4 text-base flex-grow">
            <!-- Cart items will be added here dynamically -->
        </ul>

        <div class="mt-4">
            <label for="cashReceived" class="block mb-2 text-gray-900 text-sm">Cash Received:</label>
            <input type="number" id="cashReceived" class="border border-gray-300 rounded-md p-2 w-full mb-4 text-sm" step="0.01" oninput="calculateChange()">
            <div class="flex flex-wrap space-x-2 mb-4">
                <button class="bg-gradient-to-r from-blue-600 to-blue-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(2)">N$2</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(5)">N$5</button>
                <button class="bg-gradient-to-r from-red-600 to-red-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(20)">N$20</button>
                <button class="bg-gradient-to-r from-yellow-600 to-yellow-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-yellow-700 hover:to-yellow-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(30)">N$30</button>
                <button class="bg-gradient-to-r from-purple-600 to-purple-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-purple-700 hover:to-purple-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(50)">N$50</button>
                <button class="bg-gradient-to-r from-gray-600 to-gray-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-gray-700 hover:to-gray-900 transition-colors duration-300 mb-2 text-sm" onclick="addCash(100)">N$100</button>
                <button class="bg-gradient-to-r from-teal-600 to-teal-800 text-white font-bold px-3 py-2 rounded-lg shadow-md hover:from-teal-700 hover:to-teal-900 transition-colors duration-300 mb-2 text-sm"  onclick="addCash(200)">N$200</button>
            </div>
        </div>

        <div class="flex justify-between items-center mb-6">
            <p class="text-lg font-bold text-gray-900 flex items-center">
                <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                Change: N$<span id="changeAmount" class="text-teal-700 text-2xl">0.00</span>
            </p>
        </div>

        <button class="mt-4 bg-gradient-to-r from-blue-600 to-blue-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-blue-700 hover:to-blue-900 transition-colors duration-300 flex justify-center items-center text-lg w-full" onclick="checkout()">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h1l1 2h13l1-2h1m-2 0a2 2 0 100-4 2 2 0 000 4zm-1 0H6m-1 0a2 2 0 100-4 2 2 0 000 4zm-1 0H3m0 0v6a2 2 0 002 2h14a2 2 0 002-2v-6"></path></svg>
            Checkout
        </button>

        <button class="mt-4 bg-gradient-to-r from-red-600 to-red-800 text-white px-4 py-3 rounded-lg shadow-md hover:from-red-700 hover:to-red-900 transition-colors duration-300 flex justify-center items-center text-lg w-full" onclick="clearCart()">
            <svg class="w-6 h-6 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>
            Clear Cart
        </button>

    </div>
    </div>

</main>




<script>
        // Add this at the beginning of your script
        const sound = new Howl({
            src: ['../beep-29.mp3'],
            volume: 0.5
        });

        const cashSound = new Howl({
        src: ['../pay.mp3'],
        volume: 0.5
    });


        let cart = [];
        let cartItemElements = new Map(); // Store references to cart item DOM elements
        let updateCartTimeout = null; // For debouncing cart updates

        function addToCart(element) {
            const price = parseFloat(element.getAttribute('data-price'));
            const name = element.getAttribute('data-name');
            const existingItem = cart.find(item => item.name === name);
            if (existingItem) {
                existingItem.quantity += 1;
                existingItem.price += price;
            } else {
                cart.push({ name, price, quantity: 1 });
            }
            sound.play(); // Play the sound when an item is added
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
                        li.className = 'relative flex justify-between items-center p-4 mb-2 bg-white rounded-lg shadow-md border border-gray-200';
                    }
                    
                    // Update content only if needed
                    const newContent = `
                        <div class="flex items-center gap-2">
                            <span class="text-gray-900 font-medium">${item.name} ${item.quantity > 1 ? `<span class="bg-blue-200 text-blue-800 px-2 py-1 rounded">x${item.quantity}</span>` : ''}</span>
                        </div>
                        <span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>
                        <span class="absolute top-0 right-0 bg-gray-300 hover:bg-gray-400 text-gray-800 rounded-full w-5 h-5 flex items-center justify-center shadow-lg cursor-pointer" onclick="removeFromCart(${index})" style="margin: -5px -5px;">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </span>
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

        function removeFromCart(index) {
            cart.splice(index, 1);
            // Clear the element map since indices have changed
            cartItemElements.clear();
            sound.play(); // Play sound when removing item
            updateCartOptimized();
        }

        function addCash(amount) {
        const cashReceived = document.getElementById('cashReceived');
        cashReceived.value = (parseFloat(cashReceived.value) || 0) + amount;
        calculateChange();
        sound.play(); // Play the cash sound when adding cash
    }

        function calculateChange() {
            const cartTotal = parseFloat(document.getElementById('cartTotal').innerText) || 0; // Ensure cartTotal is a number
            const cashReceived = parseFloat(document.getElementById('cashReceived').value) || 0;
            const change = cashReceived - cartTotal;
            document.getElementById('changeAmount').innerText = change >= 0 ? change.toFixed(2) : '0.00';
        }

        function checkout() {
            const cartTotal = parseFloat(document.getElementById('cartTotal').innerText);
            const cashReceived = parseFloat(document.getElementById('cashReceived').value);
            const change = cashReceived - cartTotal;

            // Check if any item in the cart has zero inventory
            const outOfStockItems = cart.filter(item => {
                const productElement = document.querySelector(`.product-item[data-name="${item.name}"]`);
                if (productElement) {
                    const quantityElement = productElement.querySelector('p:last-child');
                    const availableQuantity = parseInt(quantityElement.textContent.split(': ')[1]);
                    return availableQuantity < item.quantity;
                }
                return false;
            });

            if (outOfStockItems.length > 0) {
                const itemNames = outOfStockItems.map(item => item.name).join(', ');
                Swal.fire({
                    icon: 'error',
                    title: 'Out of Stock',
                    text: `The following items are out of stock or have insufficient quantity: ${itemNames}`,
                });
                return;
            }

            if (change < 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Insufficient Cash',
                    text: 'The cash received is less than the total amount.',
                });
                return;
            }

            if (cart.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Empty Cart',
                    text: 'Please add items to cart before checking out.',
                });
                return;
            }

            // Prepare the data to be sent
            const data = {
                items: cart,
                total: cartTotal,
                cash_received: cashReceived
            };

            // Send AJAX request
            fetch('process_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data),
            })
            .then(response => response.json())
            .then(result => {
                if (result.success) {
                    Swal.fire({
                        icon: 'success',
                        title: `Change: N$${change.toFixed(2)}`,
                        confirmButtonText: 'OK',
                        footer: '<a href="reverse_transaction.php" onclick="return reverseTransaction(event)"><i class="fas fa-undo"></i> Reverse transaction</a>',
                    }).then((result) => {
                        if (result.isConfirmed) {
                            clearCart();
                            refreshProductQuantities(); // Refresh product quantities after checkout
                        }
                    });
                    cashSound.play();
                } else {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'There was an error processing your order. Please try again.',
                    });
                }
            })
            .catch(error => {
                console.error('Error:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: 'There was an error processing your order. Please try again.',
                });
            });
        }




        function refreshProductQuantities() {
        fetch('get_product_quantities.php')
            .then(response => response.json())
            .then(data => {
                data.forEach(product => {
                    const productElement = document.querySelector(`.product-item[data-name="${product.name}"]`);
                    if (productElement) {
                        const quantityElement = productElement.querySelector('p:last-child');
                        quantityElement.textContent = `Available: ${product.quantity}`;
                        quantityElement.className = `text-sm ${
                            product.quantity < 5 ? 'text-red-600' :
                            product.quantity < 10 ? 'text-yellow-600' : 'text-teal-600'
                        }`;
                    }
                });
            })
            .catch(error => console.error('Error refreshing product quantities:', error));
    }

    // Refresh product quantities every 30 seconds
    setInterval(refreshProductQuantities, 30000);

    // Initial refresh when the page loads
    document.addEventListener('DOMContentLoaded', refreshProductQuantities);

        function clearCart() {
            cart = [];
            cartItemElements.clear(); // Clear the element cache
            updateCartOptimized();
            document.getElementById('cashReceived').value = '';
            document.getElementById('changeAmount').innerText = '0.00';
        }

        // Add reverseTransaction function
        function reverseTransaction(e) {
            if (e) e.preventDefault();
            
            Swal.fire({
                title: 'Reverse Transaction?',
                text: "Are you sure you want to reverse this transaction?",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#3085d6',
                cancelButtonColor: '#d33',
                confirmButtonText: 'Yes, reverse it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = 'reverse_transaction.php';
                }
            });
            
            return false;
        }
    </script>
    <script>
        // Add any JavaScript for responsive behavior here
        function toggleSidebar() {
            const sidebar = document.querySelector('aside');
            sidebar.classList.toggle('hidden');
        }
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


    <script>
        function initializeChart() {
            // Prepare data for the chart
            var productNames = <?php echo json_encode(array_column($topSellingProducts, 'product_name')); ?>;
            var productQuantities = <?php echo json_encode(array_column($topSellingProducts, 'total_quantity')); ?>;

            // Create the pie chart
            var ctx = document.getElementById('topProductsChart').getContext('2d');
            var topProductsChart = new Chart(ctx, {
                type: 'pie',
                data: {
                    labels: productNames,
                    datasets: [{
                        data: productQuantities,
                        backgroundColor: [
                            '#FF6384',
                            '#36A2EB',
                            '#FFCE56',
                            '#4BC0C0',
                            '#9966FF'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    title: {
                        display: true,
                        text: 'Top Selling Products'
                    },
                    animation: {
                        duration: 3000 // Set animation duration to 3 seconds
                    }
                }
            });
        }

        function initializePageScripts() {
            setTimeout(function() {
                if (typeof Chart !== 'undefined') {
                    initializeChart();
                } else {
                    console.error('Chart.js is not loaded');
                }
            }, 1000); // Add a delay of 1 second
        }

        // Call initializePageScripts when the page loads
        document.addEventListener('DOMContentLoaded', initializePageScripts);

        // Reinitialize scripts after loading new content
        if (typeof reinitializeScripts === 'function') {
            reinitializeScripts();
        }
    </script>

    
</body>
</html>
