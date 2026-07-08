<?php
// Load your private key
$privateKey = file_get_contents(__DIR__ . "/private-key.pem");
$pkeyid = openssl_pkey_get_private($privateKey);

// Get the data from the POST body
$data = file_get_contents("php://input");

// Sign it
openssl_sign($data, $signature, $pkeyid, OPENSSL_ALGO_SHA256);
openssl_free_key($pkeyid);

// Return the signature (Base64 encoded)
echo base64_encode($signature);
?>
