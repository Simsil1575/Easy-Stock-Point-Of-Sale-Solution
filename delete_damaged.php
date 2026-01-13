<?php
$db = new PDO('sqlite:pos.db');

if (isset($_POST['id'])) {
    try {
        $db->beginTransaction();
        
        // Get the damaged record first
        $stmt = $db->prepare("SELECT * FROM damaged_goods WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Restore product quantity
        $stmt = $db->prepare("UPDATE products SET quantity = quantity + ? WHERE id = ?");
        $stmt->execute([$record['quantity'], $record['product_id']]);
        
        // Delete the record
        $stmt = $db->prepare("DELETE FROM damaged_goods WHERE id = ?");
        $stmt->execute([$_POST['id']]);
        
        $db->commit();
        header("Location: damaged_goods.php?success=1");
    } catch (Exception $e) {
        $db->rollBack();
        header("Location: damaged_goods.php?error=1");
    }
    exit();
} 