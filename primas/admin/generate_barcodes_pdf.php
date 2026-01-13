<?php
require('../fpdf/fpdf.php');

// Create new PDF document
class PDF extends FPDF {
    function Header() {
        $this->SetFont('Arial', 'B', 20);
        $this->Cell(0, 15, 'Product Barcodes', 0, false, 'C');
        $this->Ln(20);
    }
}

// Function to download and save barcode image
function downloadBarcode($barcode) {
    $url = "https://barcode.tec-it.com/barcode.ashx?data=" . urlencode($barcode) . "&code=Code128&dpi=96";
    $tempFile = tempnam(sys_get_temp_dir(), 'barcode_') . '.png';
    
    // Initialize cURL session
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    // Execute cURL session
    $imageData = curl_exec($ch);
    
    // Check for errors
    if(curl_errno($ch)) {
        throw new Exception('Failed to download barcode: ' . curl_error($ch));
    }
    
    // Close cURL session
    curl_close($ch);
    
    // Save the image data to file
    if(file_put_contents($tempFile, $imageData) === false) {
        throw new Exception('Failed to save barcode image');
    }
    
    return $tempFile;
}

// Create new PDF document
$pdf = new PDF();

// Set document information
$pdf->SetTitle('Product Barcodes');
$pdf->SetAuthor('POS System');

// Add a page
$pdf->AddPage();

// Get all products with barcodes from database
try {
    $pdo = new PDO('sqlite:../pos.db');
    $stmt = $pdo->query("SELECT name, barcode FROM products WHERE barcode IS NOT NULL AND barcode != '' ORDER BY name");
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    die("Error: " . $e->getMessage());
}

// Set up the grid layout
$itemsPerRow = 2;
$itemWidth = 90; // mm
$itemHeight = 40; // mm
$margin = 10; // mm
$currentX = $margin;
$currentY = 40; // Start below header

// Add products to PDF
foreach ($products as $index => $product) {
    try {
        // Check if we need a new page
        if ($currentY + $itemHeight > $pdf->GetPageHeight() - $margin) {
            $pdf->AddPage();
            $currentY = 40;
            $currentX = $margin;
        }
        
        // Check if we need a new row
        if ($currentX + $itemWidth > $pdf->GetPageWidth() - $margin) {
            $currentX = $margin;
            $currentY += $itemHeight + $margin;
            
            // Check if we need a new page after moving to new row
            if ($currentY + $itemHeight > $pdf->GetPageHeight() - $margin) {
                $pdf->AddPage();
                $currentY = 40;
            }
        }
        
        // Add product name
        $pdf->SetFont('Arial', 'B', 10);
        $pdf->SetXY($currentX, $currentY);
        $pdf->Cell($itemWidth, 5, $product['name'], 0, 1, 'L');
        
        // Download and add barcode image
        $tempFile = downloadBarcode($product['barcode']);
        $pdf->Image($tempFile, $currentX, $currentY + 5, $itemWidth - 10, 15, 'PNG');
        unlink($tempFile); // Clean up temporary file
        
        // Add barcode number
        $pdf->SetFont('Arial', '', 8);
        $pdf->SetXY($currentX, $currentY + 20);
        $pdf->Cell($itemWidth, 5, $product['barcode'], 0, 1, 'C');
        
        // Move to next position
        $currentX += $itemWidth + $margin;
    } catch (Exception $e) {
        // Log error but continue with next product
        error_log("Error processing barcode for product {$product['name']}: " . $e->getMessage());
        continue;
    }
}

// Output the PDF
$pdf->Output('D', 'product_barcodes.pdf');
?> 