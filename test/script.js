document.addEventListener('DOMContentLoaded', loadProducts);

document.getElementById('productForm').addEventListener('submit', function (e) {
  e.preventDefault();
  const name = document.getElementById('productName').value;
  const price = document.getElementById('productPrice').value;
  
  fetch('backend.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ action: 'addProduct', name, price })
  }).then(() => {
    document.getElementById('productForm').reset();
    loadProducts();
  });
});

function loadProducts() {
  fetch('backend.php?action=getProducts')
    .then(res => res.json())
    .then(data => {
      const select = document.getElementById('productSelect');
      select.innerHTML = '';
      data.forEach(product => {
        const opt = document.createElement('option');
        opt.value = product.id;
        opt.textContent = `${product.name} - N$${product.price}`;
        select.appendChild(opt);
      });
    });
  updateDashboard();
}

function makeSale() {
  const productId = document.getElementById('productSelect').value;
  const quantity = document.getElementById('quantity').value;
  const method = document.getElementById('paymentMethod').value;

  fetch('backend.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({
      action: 'makeSale',
      productId,
      quantity,
      paymentMethod: method
    })
  }).then(() => {
    updateDashboard();
  });
}

function updateDashboard() {
  fetch('backend.php?action=dashboard')
    .then(res => res.json())
    .then(data => {
      document.getElementById('cashTill').textContent = data.cashTill.toFixed(2);
      document.getElementById('netProfit').textContent = data.netProfit.toFixed(2);
    });
}
