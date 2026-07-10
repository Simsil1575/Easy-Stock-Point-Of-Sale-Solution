<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // =======================
    // UPLOAD DATABASE
    // =======================
    $uploadDir = __DIR__ . "/temp/";
    if (!file_exists($uploadDir)) mkdir($uploadDir, 0777, true);

    $dbPath = $uploadDir . basename($_FILES['db_file']['name']);
    move_uploaded_file($_FILES['db_file']['tmp_name'], $dbPath);

    // =======================
    // OUTPUT FOLDER
    // =======================
    $outputDir = $_POST['output_dir'] ?? 'exported_images';
    if (!file_exists($outputDir)) mkdir($outputDir, 0777, true);

    // =======================
    // IMAGE SOURCE FOLDER (OPTIONAL)
    // =======================
    $imageFolder = $_POST['image_folder'] ?? '';

    // =======================
    // CONNECT DB
    // =======================
    $db = new PDO("sqlite:" . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $query = $db->query("SELECT id, name, image FROM products");

    $count = 0;

    while ($row = $query->fetch(PDO::FETCH_ASSOC)) {

        $id   = $row['id'];
        $name = $row['name'];
        $imageData = $row['image'];

        if (empty($imageData)) continue;

        $cleanName = preg_replace('/[^A-Za-z0-9\-]/', '_', $name);
        $fileName  = $id . "_" . $cleanName;

        $filePath = "";

        // =======================
        // CASE 1: IMAGE IS PATH
        // =======================
        if ($imageFolder && file_exists($imageFolder . "/" . $imageData)) {

            $sourcePath = $imageFolder . "/" . $imageData;
            $ext = pathinfo($sourcePath, PATHINFO_EXTENSION);

            $filePath = rtrim($outputDir, '/') . '/' . $fileName . "." . $ext;

            copy($sourcePath, $filePath);
        }

        // =======================
        // CASE 2: IMAGE IS BASE64 / BLOB
        // =======================
        else {

            // decode base64 if needed
            if (base64_encode(base64_decode($imageData, true)) === $imageData) {
                $imageData = base64_decode($imageData);
            }

            $finfo = finfo_open();
            $mime  = finfo_buffer($finfo, $imageData, FILEINFO_MIME_TYPE);
            finfo_close($finfo);

            switch ($mime) {
                case 'image/jpeg': $ext = 'jpg'; break;
                case 'image/png':  $ext = 'png'; break;
                case 'image/webp': $ext = 'webp'; break;
                default: $ext = 'png';
            }

            $filePath = rtrim($outputDir, '/') . '/' . $fileName . "." . $ext;

            file_put_contents($filePath, $imageData);
        }

        echo "✅ Saved: " . basename($filePath) . "<br>";
        $count++;
    }

    echo "<br><strong>DONE: $count images exported.</strong>";
    exit;
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Export Images</title>
</head>
<body style="font-family: Arial; padding:20px;">

<h2>📦 Export Product Images (Advanced)</h2>

<form method="post" enctype="multipart/form-data">

    <label><strong>Database File:</strong></label><br>
    <input type="file" name="db_file" required><br><br>

    <label><strong>Image Source Folder (if DB stores paths):</strong></label><br>
    <input type="text" name="image_folder" placeholder="e.g. C:/xampp/htdocs/project/images" style="width:400px;"><br><br>

    <label><strong>Output Folder:</strong></label><br>
    <input type="text" name="output_dir" value="exported_images" style="width:400px;"><br><br>

    <button type="submit">🚀 Export</button>

</form>

</body>
</html>