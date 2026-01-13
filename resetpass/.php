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
    $query = mysqli_query($con, "SELECT * FROM users WHERE username = '$emailTo' LIMIT 1");
    if (mysqli_num_rows($query) === 0) {
        $message = 'Email not found!';
    } else {
        $code = uniqid(true); // true for more uniqueness 
        $query = mysqli_query($con, "INSERT INTO resetPasswords (code, email) VALUES ('$code','$emailTo')"); 
        if (!$query) {
            $message = 'Error';
        } else {
            try {
                $mail = new PHPMailer(true);
                //Server settings
                $mail->SMTPDebug = 0;     // Enable verbose debug output, 1 for production, 2 or 3 for debugging in development 
                $mail->isSMTP();                                      // Set mailer to use SMTP
                $mail->Host = 'smtp.gmail.com';  // Specify main and backup SMTP servers
                $mail->SMTPAuth = true;                               // Enable SMTP authentication
                $mail->Username = 'sourcecodedev6@gmail.com'; // Your Google email
				$mail->Password = 'irfvlutirghpfbkl'; // Your email password or app password
                $mail->SMTPSecure = 'tls';                           // Enable TLS encryption, `ssl` also accepted
                $mail->Port = 587;                                   // TCP port to connect to

                //Recipients
				$mail->setFrom('sourcecodedev6@gmail.com', 'Vehicle Service');
                $mail->addAddress($emailTo);     // Add a recipient
                $mail->addReplyTo('no-reply@gmail.com', 'No Reply');

                //Content
                $url = "http://" . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . "/resetPassword.php?code=$code";
                $mail->isHTML(true);                                  // Set email format to HTML
                $mail->Subject = 'Your password reset link';
                $mail->Body = "<h1>You requested a password reset</h1>
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
    }
}
?>




<?php require_once('../config.php') ?>
<!DOCTYPE html>
<html lang="en" class="" style="height: auto;">
<?php require_once('../inc/header.php') ?>
<body class="hold-transition login-page ">
    

<div class="login-box">
    <!-- /.login-logo -->
    <div class="card card-outline card-primary">
        <div class="card-header text-center">
            <h1><b>Forgot Password</b></h1>
        </div>
        <div class="card-body">
            <p class="login-box-msg">Confirm your e-mail address</p>

            <?php if (!empty($message)): ?>
                <p class="<?php echo $isSuccess ? 'text-success' : 'text-danger'; ?>"><?php echo $message; ?></p>
            <?php endif; ?>

            <form method="post">
                <div class="input-group mb-3">
                    <input type="email" class="form-control" name="email" placeholder="Enter your email address" required>
                    <div class="input-group-append">
                        <div class="input-group-text">
                            <span class="fas fa-envelope"></span>
                        </div>
                    </div>
                </div>


                <div class="row">
            <div class="col-12">
              <a href="../">Back to login</a>
            </div>
          </div>
          <div class="row">
            <div class="col-8">
         
            </div>
            <!-- /.col -->
            <div class="col-4">
              <button type="submit" class="btn btn-primary btn-block">Send</button>
            </div>
            <!-- /.col -->
          </div>
                    <!-- /.col -->
                    <!-- /.col -->
            </form>
        </div>
        <!-- /.card-body -->
    </div>
    <!-- /.card -->
</div>


  <!-- /.login-box -->

  <!-- jQuery -->
  <script src="plugins/jquery/jquery.min.js"></script>
  <!-- Bootstrap 4 -->
  <script src="plugins/bootstrap/js/bootstrap.bundle.min.js"></script>
  <!-- AdminLTE App -->
  <script src="dist/js/adminlte.min.js"></script>

  <script>
    $(document).ready(function() {
      end_loader();
    });
  </script>
</body>
</html>








