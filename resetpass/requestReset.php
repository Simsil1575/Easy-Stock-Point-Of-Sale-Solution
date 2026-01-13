<?php
// Import PHPMailer classes into the global namespace
// These must be at the top of your script, not inside a function
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require 'config.php';

if (isset($_POST['email'])) {

    $message = '';
    $isSuccess = false;

    $emailTo = $_POST['email'];
    
    // Perform a database query to check if the email exists
    // Assuming $con is a PDO object now, not a MySQLi object.  This will need to be updated to use SQLite3
    try {
        $db = new PDO('sqlite:../user.db'); //Connect to the SQLite database
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $emailTo]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $message = 'Database error: ' . $e->getMessage();
    }


    if (!$user) {
        $message = 'Email not found!';
    } else {
        $code = uniqid(true); // true for more uniqueness 
        try {
            $db = new PDO('sqlite:../user.db'); //Connect to the SQLite database
            //The error message indicates that the resetPasswords table does not exist.  We need to create it.
            $db->exec("CREATE TABLE IF NOT EXISTS resetPasswords (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                email TEXT NOT NULL,
                expires TIMESTAMP DEFAULT (DATETIME('now', '+1 day'))
            )");
            $stmt = $db->prepare("INSERT INTO resetPasswords (code, email) VALUES (:code, :email)");
            $stmt->execute(['code' => $code, 'email' => $emailTo]);
            if ($stmt->rowCount() == 0) {
                $message = 'Error inserting into resetPasswords table';
            } else {
                try {
                    $mail = new PHPMailer(true);
                    //Server settings
                    $mail->SMTPDebug = 0;     // Enable verbose debug output, 1 for production, 2 or 3 for debugging in development 
                    $mail->isSMTP();                                      // Set mailer to use SMTP
                    $mail->Host = 'smtp.gmail.com';  // Specify main and backup SMTP servers
                    $mail->SMTPAuth = true;                               // Enable SMTP authentication
                    $mail->Username = 'sourcecodedev6@gmail.com';
                    $mail->Password = 'irfvlutirghpfbkl';              // SMTP password
                    $mail->SMTPSecure = 'tls';                           // Enable TLS encryption, `ssl` also accepted
                    $mail->Port = 587;                                   // TCP port to connect to

                    //Recipients
                    $mail->setFrom('sourcecodedev6@gmail.com', 'Stock Management System'); // from whom?
                    $mail->addAddress($emailTo);     // Add a recipient
                    $mail->addReplyTo('no-reply@gmail.com', 'No Reply');

                    //Content
                    $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/resetPassword.php?code=$code";
                    $mail->isHTML(true);                                  // Set email format to HTML
                    $mail->Subject = 'Your password reset link';
                    $mail->Body = "<h1 >You requested a password reset</h1>
                             Click <a href='$url'>this link</a> to reset your password.";
                    $mail->AltBody = 'This is the body in plain text for non-HTML mail clients';

                    // to solve a problem 
                    $mail->SMTPOptions = array(
                        'ssl' => array(
                        'verify_peer' => false,
                        'verify_peer_name' => false,
                        'allow_self_signed' => true
                        )
                    );

                    // Your mail sending code here
                    $mail->send();
                    $message = 'Link has been sent to your email';
                    $isSuccess = true;
                } catch (Exception $e) {
                    $message = 'Message could not be sent. Mailer Error: ' . $mail->ErrorInfo;
                }
            }
        } catch (PDOException $e) {
            $message = 'Database error: ' . $e->getMessage();
        }
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
<body class="h-full bg-gray-100 font-sans">
    <div class="flex justify-center min-h-screen">

        <div class="flex flex-col justify-center px-6 py-12 lg:w-1/2 lg:px-8">
            <div class="sm:mx-auto sm:w-full sm:max-w-sm">
                <img src="../logo.png" alt="Company Logo" class="mx-auto h-16 w-auto">
                <h2 class="mt-6 text-center text-2xl font-bold tracking-tight text-black">
                    <i class="fas fa-key mr-2"></i>Forgot Password
                </h2>
                <p class="mt-2 text-center text-sm text-gray-600">
                    Enter your email address below
                </p>
            </div>

            <div class="mt-10 sm:mx-auto sm:w-full sm:max-w-sm bg-gray-250 rounded-xl p-6">
                <?php if (!empty($message)): ?>
                    <div id="errorAlert" class="mb-4 <?php echo $isSuccess ? 'bg-teal-600' : 'bg-red-600'; ?> text-white px-4 py-3 rounded-lg flex items-center" role="alert">
                        <i class="fas fa-exclamation-triangle mr-3"></i>
                        <span class="flex-1"><?php echo htmlspecialchars($message); ?></span>
                        <i class="fas fa-times cursor-pointer" onclick="document.getElementById('errorAlert').style.display='none'"></i>
                    </div>
                <?php endif; ?>

                <form class="space-y-6" method="post">
                    <div>
                        <label for="email" class="block text-sm font-medium text-black">Email Address</label>
                        <div class="mt-2 relative">
                            <input type="email" id="email" name="email" required
                                   class="block w-full rounded-lg bg-gray-200 px-10 py-2 text-black outline outline-1 -outline-offset-1 outline-gray-300 placeholder:text-gray-400 focus:outline-2 focus:-outline-offset-2 focus:outline-gray-300 transition duration-200">
                            <i class="fas fa-envelope absolute left-3 top-2.5 text-gray-400"></i>
                        </div>
                    </div>

                    <div class="flex items-center justify-between">
                        <button type="submit" 
                                class="flex w-full items-center justify-center rounded-lg border-2 border-gray-400 bg-transparent px-4 py-2.5 text-sm/6 font-semibold text-gray-700 hover:border-gray-600 hover:text-gray-900 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gray-300 transition duration-200">
                            <i class="fas fa-paper-plane mr-2"></i>Send
                        </button>
                    </div>
                </form>

                <div class="mt-6">
                    <a href="../" class="flex items-center justify-center text-sm text-gray-600 hover:text-gray-900">
                        <i class="fas fa-arrow-left mr-2"></i>Back to Login
                    </a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
