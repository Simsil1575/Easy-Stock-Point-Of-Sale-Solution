<?php 
require 'config.php'; 

//Error Handling for missing GET parameter
if (!isset($_GET['code'])) {
    header("Location: ../error.php?error=missingCode"); // Redirect to an error page
    exit();
}

$code = $_GET['code']; 

//Use prepared statements to prevent SQL injection vulnerabilities.
try {
    $db = new PDO('sqlite:../user.db');
    $stmt = $db->prepare("SELECT * FROM resetPasswords WHERE code = ?");
    $stmt->execute([$code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$row) {
        header("Location: ../error.php?error=invalidCode"); // Redirect to an error page
        exit();
    }
} catch (PDOException $e) {
    header("Location: ../error.php?error=dbError"); // Redirect to an error page
    exit();
}


// handling the form 
if (isset($_POST['password'])) {
    $pw = $_POST['password']; 
    //Use md5 hashing
    $hashed_pw = md5($pw);
    $email = $row['email']; 

    try {
        $db = new PDO('sqlite:../user.db');
        $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$hashed_pw, $email]);

        if ($stmt->rowCount() > 0) {
            $stmt = $db->prepare("DELETE FROM resetPasswords WHERE code = ?");
            $stmt->execute([$code]);
            $message = '<i class="fas fa-check-circle text-teal-500 mr-2"></i> Password updated successfully!';
            $messageClass = 'text-success'; 
        } else {
            $message = '<i class="fas fa-exclamation-circle text-red-500 mr-2"></i> Something went wrong. Password not updated.';
            $messageClass = 'text-danger'; 
        }
    } catch (PDOException $e) {
        $message = '<i class="fas fa-exclamation-circle text-red-500 mr-2"></i> Database error: ' . $e->getMessage();
        $messageClass = 'text-danger'; 
    }
}


?>

<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>POS Solution</title>
<link href="../src/output.css" rel="stylesheet">
<script src="../navigation.js" async></script>
<script src="../src/howler.min.js"></script>
<script src="../src/chart.js"></script>
<meta name="google" content="notranslate">
<link rel="icon" href="../logo.png" type="image/png">
<link rel="stylesheet" href="../src/font-awesome/css/all.min.css">
<script src="../tailwind.16"></script>

</head>
<body class="h-full bg-gray-250 font-sans bg-image">
    <div class="flex min-h-screen items-center justify-center">
        <div class="sm:mx-auto sm:w-full sm:max-w-sm bg-white rounded-xl p-6 shadow-md">
            <h2 class="text-2xl font-bold mb-4 text-center"><i class="fas fa-key mr-2"></i>Set New Password</h2>
            <?php if (isset($message)): ?>
                <div class="mb-4 p-4 rounded <?php echo $messageClass; ?>">
                    <?php echo $message; ?>
                </div>
            <?php endif; ?>
            <form method="post" class="space-y-6">
                <div>
                    <label for="password" class="block text-sm font-medium text-black">New Password</label>
                    <div class="mt-2 relative">
                        <input type="password" class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200" name="password" id="password" required>
                        <i class="fas fa-lock absolute left-3 top-2.5 text-gray-400"></i>
                    </div>
                </div>
                <button type="submit" class="flex w-full items-center justify-center rounded-lg border-2 border-gray-400 bg-transparent px-4 py-2.5 text-sm/6 font-semibold text-gray-700 hover:border-gray-600 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-300 transition duration-200">
                    <i class="fas fa-save mr-2"></i>Update Password
                </button>
                <div class="mt-6 text-center">
                    <a href="../" class="text-sm text-gray-600 hover:text-gray-900"><i class="fas fa-arrow-left mr-2"></i>Back to Login</a>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
