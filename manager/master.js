        // Add this at the beginning of your script
        const sound = new Audio('beep-29.mp3');
        sound.volume = 0.5;

        const cashSound = new Audio('pay.mp3');
        cashSound.volume = 0.5;


        let cart = [];

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
            updateCart();
        }

        function updateCart() {
            const cartItems = document.getElementById('cartItems');
            const cartTotal = document.getElementById('cartTotal');
            cartItems.innerHTML = '';
            let total = 0;

            cart.forEach(item => {
                const li = document.createElement('li');
                li.className = 'flex justify-between items-center p-4 mb-2 bg-white rounded-lg shadow-md border border-gray-200';
                li.innerHTML = `
                    <span class="text-gray-900 font-medium">${item.name} ${item.quantity > 1 ? `<span class="bg-blue-200 text-blue-800 px-2 py-1 rounded">x${item.quantity}</span>` : ''}</span>
                    <span class="text-teal-700 font-bold">N$${item.price.toFixed(2)}</span>
                `;
                cartItems.appendChild(li);
                total += item.price;
            });

            cartTotal.innerText = total.toFixed(2);
            calculateChange(); // Ensure change is calculated whenever the cart is updated
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
                        confirmButtonText: 'OK'
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
            updateCart();
            document.getElementById('cashReceived').value = '';
            document.getElementById('changeAmount').innerText = '0.00';
        }

        // Add any JavaScript for responsive behavior here
        function toggleSidebar() {
            const sidebar = document.querySelector('aside');
            sidebar.classList.toggle('hidden');
        }




function initializePageScripts() {
    setTimeout(function() {
        if (typeof Chart !== 'undefined') {
            fetchReportData();
        } else {
            console.error('Chart.js is not loaded');
        }
    }, 1000); // Add a delay of 1 second
}

// Call initializePageScripts when the page loads
document.addEventListener('DOMContentLoaded', initializePageScripts);

// Reinitialize scripts after loading new content
function reinitializeScripts() {
    initializePageScripts();
    updateTopProductsChart();
}

// Handle JSON parsing error
function handleJSONError(response) {
    return response.text().then(text => {
        try {
            return JSON.parse(text);
        } catch (error) {
            console.error('Error fetching top selling products:', error);
            return Promise.reject(error);
        }
    });
}

function fetchReportData() {
    fetch('fetch_report.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `start_date=${encodeURIComponent(document.getElementById('start_date').value)}&end_date=${encodeURIComponent(document.getElementById('end_date').value)}`,
    })
    .then(response => response.json())
    .then(response => {
        // Inject the fetched report data into the reportData div
        document.getElementById('reportData').innerHTML = `
            <div class="bg-white shadow-md rounded px-8 pt-6 pb-8 mb-4">
                <h2 class="text-xl font-semibold mb-4">Income Statement</h2>
                <table class="w-full">
                    <tr class="border-b">
                        <td class="py-2">Revenue</td>
                        <td class="py-2 text-right">N$${Number(response.totalSales).toFixed(2)}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 pl-8">Cash Sales</td>
                        <td class="py-2 text-right">N$${(response.totalSales - response.creditCosts).toFixed(2)}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2 pl-8">Credit Sales</td>
                        <td class="py-2 text-right">N$${Number(response.creditCosts).toFixed(2)}</td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2">Cost of Revenue</td>
                        <td class="py-2 text-right">N$${Number(response.costOfGoodsSold).toFixed(2)}</td>
                    </tr>
                    <tr class="font-bold">
                        <td class="py-2">Gross Profit</td>
                        <td class="py-2 text-right">N$${Number(response.grossProfit).toFixed(2)}</td>
                    </tr>
                </table>
            </div>
        `;
        updateTopProductsChart(response.topSellingProducts);
    })
    .catch(error => {
        console.error('Error fetching report data:', error);
    });
}

// Function to update the top-selling products chart
function updateTopProductsChart(products) {
    const productNames = products.map(product => product.product_name);
    const productQuantities = products.map(product => product.total_quantity);

    const ctx = document.getElementById('topProductsChart');
    if (ctx) {
        new Chart(ctx, {
            type: 'pie',
            data: {
                labels: productNames,
                datasets: [{
                    data: productQuantities,
                    backgroundColor: ['#FF6384', '#36A2EB', '#FFCE56', '#4BC0C0', '#9966FF']
                }]
            },
            options: {
                responsive: true,
                title: {
                    display: true,
                    text: 'Top Selling Products'
                },
                animation: {
                    duration: 2000
                }
            }
        });
    }
}

// Fetch report data on form submission
$('#reportForm').on('submit', function(e) {
    e.preventDefault();
    fetchReportData();
});

// Delete all records via AJAX
$('#deleteAllForm').on('submit', function(e) {
    e.preventDefault();
    $.ajax({
        url: 'delete_records.php',
        type: 'POST',
        success: function() {
            alert('All records deleted');
            fetchReportData(); // Reload report data
        }
    });
});

// Fetch the initial report data when the page loads
$(document).ready(function() {
    fetchReportData();
});


