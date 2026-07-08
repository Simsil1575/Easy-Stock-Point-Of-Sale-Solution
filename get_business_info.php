<?php
// Simple endpoint to return business info from info.db
// This matches exactly how receipt.php gets business info
// Used by Android app to fetch business info reliably

header('Content-Type: application/json');

try {
    // Connect to database and get business info (same as receipt.php)
    $db = new PDO('sqlite:info.db');
    $businessInfo = $db->query("SELECT * FROM business_info LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    // If no business info found, use defaults (same as receipt.php)
    if (!$businessInfo) {
        $businessInfo = [
            'name' => 'POS SOLUTION',
            'location' => 'Your Business Address',
            'phone' => 'Your Phone Number',
            'footer_text' => '',  // No fallback - use empty if not in database
            'printer_port' => 'COM4',
            'vat_inclusive' => 'exclusive',
            'vat_rate' => 15.0
        ];
    }
    
    // Return business info in same format as receipt.php enriches orderData
    echo json_encode([
        'success' => true,
        'business_info' => [
            'name' => $businessInfo['name'] ?? 'POS SOLUTION',
            'location' => $businessInfo['location'] ?? '',
            'phone' => $businessInfo['phone'] ?? '',
            'footer_text' => $businessInfo['footer_text'] ?? '',  // No fallback - use empty if not in database
            'vat_inclusive' => $businessInfo['vat_inclusive'] ?? 'exclusive',
            'vat_rate' => isset($businessInfo['vat_rate']) ? floatval($businessInfo['vat_rate']) : 15.0
        ]
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to fetch business info: ' . $e->getMessage()
    ]);
}
?>
