<?php
require __DIR__ . '/vendor/autoload.php';

//use Mike42\Escpos\PrintConnectors\FilePrintConnector;

use Mike42\Escpos\Printer;
use Mike42\Escpos\PrintConnectors\WindowsPrintConnector;

try {
    // Enter the share name for your USB printer here
    $connector = new WindowsPrintConnector("XP-58SERIES");
    $printer = new Printer($connector);
    
    // Print hello world
    $printer->text("Hello World\n");
    $printer->feed(10);
    $printer->cut();
    $printer->close();

} catch(Exception $e) {
    echo "Couldn't print to this printer: " . $e -> getMessage() . "\n";
}