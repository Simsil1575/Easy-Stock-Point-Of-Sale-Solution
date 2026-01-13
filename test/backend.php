<?php
$db = new SQLite3('database.db');

// Setup tables if not exists
$db->exec("CREATE TABLE IF NOT EXISTS products (
  id INTEGER PRIMARY KEY,
  name TEXT,
  price REAL
)");
$db->exec("CREATE TABLE IF NOT EXISTS sales (
  id INTEGER PRIMARY KEY,
  product_id INTEGER,
  quantity INTEGER,
  payment_method TEXT,
  total REAL
)");

$data = json_decode(file_get_contents('php://input'), true);
$action = $_GET['action'] ?? $data['action'] ?? '';

if ($action == 'addProduct') {
  $stmt = $db->prepare("INSERT INTO products (name, price) VALUES (?, ?)");
  $stmt->bindValue(1, $data['name']);
  $stmt->bindValue(2, $data['price']);
  $stmt->execute();
}
elseif ($action == 'getProducts') {
  $res = $db->query("SELECT * FROM products");
  $products = [];
  while ($row = $res->fetchArray(SQLITE3_ASSOC)) $products[] = $row;
  echo json_encode($products);
}
elseif ($action == 'makeSale') {
  $productId = $data['productId'];
  $quantity = $data['quantity'];
  $method = $data['paymentMethod'];

  $price = $db->querySingle("SELECT price FROM products WHERE id = $productId");
  $total = $price * $quantity;

  $stmt = $db->prepare("INSERT INTO sales (product_id, quantity, payment_method, total) VALUES (?, ?, ?, ?)");
  $stmt->bindValue(1, $productId);
  $stmt->bindValue(2, $quantity);
  $stmt->bindValue(3, $method);
  $stmt->bindValue(4, $total);
  $stmt->execute();
}
elseif ($action == 'dashboard') {
  $cashTill = $db->querySingle("SELECT SUM(total) FROM sales WHERE payment_method = 'cash'");
  $netProfit = $db->querySingle("SELECT SUM(total) FROM sales");
  echo json_encode([
    'cashTill' => $cashTill ?? 0,
    'netProfit' => $netProfit ?? 0
  ]);
}
