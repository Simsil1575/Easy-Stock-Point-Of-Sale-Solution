# Printer-ktx Integration for Android WebView App

## ✅ INTEGRATION COMPLETE

The Printer-ktx library has been fully integrated into the Android WebView app. Below is the summary of changes made and how to use the printing functionality.

## Files Modified/Created

### Modified Files:
1. **`Android/android/build.gradle`** - Added JitPack repository
2. **`Android/android/app/build.gradle`** - Added Printer-ktx and Coroutines dependencies
3. **`Android/android/app/src/main/AndroidManifest.xml`** - Added Bluetooth and USB permissions
4. **`Android/android/app/src/normal/java/co/median/android/WebViewSetup.java`** - Registered PrinterInterface
5. **`Android/android/app/src/normal/java/co/median/android/GoNativeWebviewClient.java`** - Inject JS interceptor on page load
6. **`Android/android/app/src/main/java/co/median/android/MainActivity.java`** - Request Bluetooth permissions on startup

### New Files Created:
1. **`PrinterManager.kt`** - Core printing logic (Bluetooth/TCP/USB connections, receipt formatting)
2. **`PrinterInterface.java`** - JavaScript interface exposed to WebView as `window.AndroidPrinter`
3. **`PrinterPermissionHelper.kt`** - Bluetooth permission handling utility

## How It Works

### Architecture Flow
```
Web App (JavaScript)
    ↓ fetch('receipt.php', {...})
JavaScript Interceptor (injected on every page load)
    ↓ Intercepts POST requests to receipt.php
AndroidPrinter JavaScript Interface
    ↓ Calls native Java/Kotlin code
PrinterManager.kt
    ↓ Formats receipt to ESC/POS format
Printer-ktx Library
    ↓ Connects via Bluetooth/TCP/USB
Thermal Printer (prints receipt)
```

### Automatic Interception
When the WebView loads any page, JavaScript is automatically injected that:
1. Overrides the `fetch()` function
2. Detects POST requests to `receipt.php`
3. Extracts the receipt JSON data
4. Calls `window.AndroidPrinter.printReceipt(data)`
5. Returns a mock success response to the web app

This means **no changes are needed to your web app code** - it continues to call `fetch('receipt.php', {...})` as normal.

## Supported Receipt Types

The integration automatically handles all receipt types from your `receipt.php`:
- ✅ Regular sales receipts
- ✅ Kitchen tickets (tab/table orders)
- ✅ Cash-up/Z-reports
- ✅ Tab balance receipts
- ✅ Credit balance receipts
- ✅ Payment receipts
- ✅ Mixed payment receipts (Cash + EFT)

## Printer Connection Options

### 1. Bluetooth Printer (Recommended)
- Automatically connects to paired Bluetooth printers
- App requests Bluetooth permission on startup
- Pair your printer in Android Bluetooth settings first

### 2. TCP/Network Printer
- Configure via JavaScript: 
```javascript
AndroidPrinter.configurePrinter(JSON.stringify({
    type: "TCP",
    ip: "192.168.1.100",
    port: 9100
}));
```

### 3. USB Printer
- Automatically detects USB thermal printers
- Requires USB OTG support on device

### 4. Auto-detect (Default)
- Tries Bluetooth first, then USB, then TCP
- Best for flexibility

## JavaScript API

The following methods are available via `window.AndroidPrinter`:

### Print Receipt
```javascript
// Print a receipt (data is automatically intercepted from receipt.php)
AndroidPrinter.printReceipt(JSON.stringify(receiptData));
```

### Configure Printer
```javascript
// Set printer type and connection details
AndroidPrinter.configurePrinter(JSON.stringify({
    type: "BLUETOOTH",  // or "TCP", "USB", "AUTO"
    ip: "",             // for TCP
    port: 9100,         // for TCP
    bluetoothAddress: "" // optional specific device
}));
```

### Get Printer Status
```javascript
const status = JSON.parse(AndroidPrinter.getPrinterStatus());
console.log(status.printerType);  // "BLUETOOTH", "TCP", etc.
console.log(status.available);    // true/false
console.log(status.message);      // Status message
```

### Get Available Printers
```javascript
const printers = JSON.parse(AndroidPrinter.getAvailablePrinters());
console.log(printers.bluetooth);  // Array of Bluetooth printers
console.log(printers.usb);        // Array of USB printers
```

### Test Print
```javascript
// Print a test receipt
AndroidPrinter.testPrint();
```

### Check Bluetooth Permission
```javascript
const hasPermission = AndroidPrinter.hasBluetoothPermission();
```

## Print Event Callbacks

Listen for print events in JavaScript:
```javascript
window.AndroidPrinterCallback = function(data) {
    if (data.event === 'onPrintSuccess') {
        console.log('Print successful:', data.message);
    } else if (data.event === 'onPrintError') {
        console.error('Print error:', data.error);
    }
};
```

## Building the App

1. Open `Android/android` folder in Android Studio
2. Sync Gradle files (the new dependencies will download)
3. Build and run on a physical Android device (emulator won't have Bluetooth)
4. Grant Bluetooth permission when prompted
5. Pair your Bluetooth thermal printer in device settings
6. Open your web app and test printing!

## Troubleshooting

### Printer not found
- Ensure Bluetooth is enabled on the device
- Check that the printer is paired in Android Bluetooth settings
- Grant Bluetooth permissions when prompted

### Print quality issues
- The printer is configured for 203 DPI, 48mm width, 42 chars per line
- Modify `PrinterManager.kt` constants if your printer differs

### Receipt format issues
- The `formatReceipt()` method in `PrinterManager.kt` handles formatting
- Customize the formatting logic if needed for your specific requirements

### TCP connection fails
- Verify the printer IP and port are correct
- Ensure the device and printer are on the same network
- Check firewall settings

## Support

For Printer-ktx library documentation:
- GitHub: https://github.com/KhairoHumsi/Printer-ktx
- JitPack: https://jitpack.io/#KhairoHumsi/Printer-ktx
