<?php
$con = new PDO('sqlite:../user.db');
if (!$con) {
    echo "Connection failed: " . $con->errorInfo()[2];
}
