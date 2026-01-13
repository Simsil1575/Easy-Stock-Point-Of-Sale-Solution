<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['database'])) {
    $target_dir = "database/";
    $target_file = $target_dir . basename($_FILES["database"]["name"]);
    $fileType = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

    // Check if file is a SQLite database
    if ($fileType != "db" && $fileType != "sqlite" && $fileType != "sqlite3") {
        echo "<p class='text-red-500'>Sorry, only SQLite database files are allowed.</p>";
    } else {
        if (move_uploaded_file($_FILES["database"]["tmp_name"], $target_file)) {
            // Rename to pos.db
            rename($target_file, "pos.db");
            echo "<p class='text-teal-500'>Database uploaded successfully.</p>";
        } else {
            echo "<p class='text-red-500'>Sorry, there was an error uploading your file.</p>";
        }
    }
}
?>

<div class="bg-white p-6 rounded-lg shadow-md">
    <form action="" method="post" enctype="multipart/form-data" class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-gray-700">Upload Database File</label>
            <div class="mt-1">
                <input type="file" name="database" required class="block w-full text-sm text-gray-900 border border-gray-300 rounded-lg cursor-pointer bg-gray-50 focus:outline-none">
            </div>
            <p class="mt-2 text-sm text-gray-500">Only .db, .sqlite, or .sqlite3 files are accepted</p>
        </div>
        <button type="submit" class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500">
            Upload Database
        </button>
    </form>
</div>