<?php
/**
 * Product Image Download Script (Optimized for Speed)
 * Downloads product images from database using concurrent downloads
 */

// Database configuration
$db_file = 'dylan.db'; // Change this to your SQLite database file path
$image_base_url = 'http://localhost/products/'; // Change this to your image URL base path
$download_folder = 'product_images'; // Folder where images will be saved
$concurrent_downloads = 20; // Number of simultaneous downloads (adjust based on server capacity)

// Create download folder if it doesn't exist
if (!file_exists($download_folder)) {
    mkdir($download_folder, 0755, true);
    echo "Created folder: $download_folder\n";
}

try {
    // Connect to SQLite database
    $db = new PDO("sqlite:$db_file");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Fetch all products with images
    $query = "SELECT id, name, image_url FROM products WHERE image_url IS NOT NULL AND image_url != ''";
    $stmt = $db->query($query);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $success_count = 0;
    $fail_count = 0;
    $total = count($products);
    
    echo "Found $total products with images\n";
    echo "Starting concurrent downloads (batch size: $concurrent_downloads)...\n\n";
    
    $start_time = microtime(true);
    
    // Process products in batches for concurrent downloads
    $batches = array_chunk($products, $concurrent_downloads);
    $batch_num = 0;
    
    foreach ($batches as $batch) {
        $batch_num++;
        echo "Processing batch $batch_num of " . count($batches) . " (" . count($batch) . " images)...\n";
        
        // Initialize cURL multi-handle
        $multi_handle = curl_multi_init();
        $curl_handles = [];
        $file_handles = [];
        
        // Prepare all downloads in this batch
        foreach ($batch as $index => $product) {
            $product_id = $product['id'];
            $product_name = $product['name'];
            $image_filename = $product['image_url'];
            
            // Clean product name for use as filename
            $safe_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', $product_name);
            $safe_name = preg_replace('/_+/', '_', $safe_name);
            
            // Get file extension
            $ext = pathinfo($image_filename, PATHINFO_EXTENSION);
            if (empty($ext)) {
                $ext = 'png';
            }
            
            // Create filename and path
            $new_filename = $safe_name . '.' . $ext;
            $save_path = $download_folder . '/' . $new_filename;
            $image_url = $image_base_url . $image_filename;
            
            // Skip if file already exists
            if (file_exists($save_path)) {
                echo "  [$product_id] Skipped (already exists): $product_name\n";
                $success_count++;
                continue;
            }
            
            // Create temporary file handle for writing
            $file_handle = fopen($save_path, 'wb');
            if (!$file_handle) {
                echo "  [$product_id] ✗ Failed to create file: $product_name\n";
                $fail_count++;
                continue;
            }
            
            // Initialize cURL handle
            $ch = curl_init($image_url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $file_handle,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            ]);
            
            // Store handles with product info
            $curl_handles[$index] = [
                'handle' => $ch,
                'file' => $file_handle,
                'product_id' => $product_id,
                'product_name' => $product_name,
                'save_path' => $save_path
            ];
            
            curl_multi_add_handle($multi_handle, $ch);
        }
        
        // Execute all downloads concurrently
        $running = null;
        do {
            curl_multi_exec($multi_handle, $running);
            curl_multi_select($multi_handle, 0.1);
        } while ($running > 0);
        
        // Process results
        foreach ($curl_handles as $index => $info) {
            $ch = $info['handle'];
            $file_handle = $info['file'];
            $product_id = $info['product_id'];
            $product_name = $info['product_name'];
            $save_path = $info['save_path'];
            
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            
            fclose($file_handle);
            
            if ($http_code == 200 && empty($error)) {
                // Verify file was written and has content
                if (file_exists($save_path) && filesize($save_path) > 0) {
                    echo "  [$product_id] ✓ $product_name\n";
                    $success_count++;
                } else {
                    echo "  [$product_id] ✗ Empty file: $product_name\n";
                    @unlink($save_path);
                    $fail_count++;
                }
            } else {
                echo "  [$product_id] ✗ Failed: $product_name (HTTP $http_code" . ($error ? ": $error" : "") . ")\n";
                @unlink($save_path);
                $fail_count++;
            }
            
            curl_multi_remove_handle($multi_handle, $ch);
            curl_close($ch);
        }
        
        curl_multi_close($multi_handle);
        echo "\n";
    }
    
    $end_time = microtime(true);
    $duration = round($end_time - $start_time, 2);
    
    echo "\n=================================\n";
    echo "Download Complete!\n";
    echo "=================================\n";
    echo "Total products: $total\n";
    echo "Successfully downloaded: $success_count\n";
    echo "Failed: $fail_count\n";
    echo "Time taken: {$duration}s\n";
    echo "Average: " . ($total > 0 ? round($duration / $total, 2) : 0) . "s per image\n";
    echo "Images saved in: $download_folder/\n";
    
} catch (PDOException $e) {
    echo "Database Error: " . $e->getMessage() . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>