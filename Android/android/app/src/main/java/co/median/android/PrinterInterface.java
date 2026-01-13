package co.median.android;

import android.Manifest;
import android.content.Context;
import android.content.pm.PackageManager;
import android.os.Build;
import android.os.Handler;
import android.os.Looper;
import android.util.Log;
import android.webkit.JavascriptInterface;
import android.webkit.WebView;

import androidx.core.content.ContextCompat;

import org.json.JSONException;
import org.json.JSONObject;

/**
 * JavaScript interface for thermal printer functionality.
 * This interface allows the web app to print receipts directly to 
 * Bluetooth, USB, or TCP thermal printers connected to the Android device.
 */
public class PrinterInterface {
    private static final String TAG = "PrinterInterface";
    
    private final Context context;
    private final PrinterManager printerManager;
    private final Handler mainHandler;
    private WebView webView;

    public PrinterInterface(Context context) {
        this.context = context;
        this.printerManager = new PrinterManager(context);
        this.mainHandler = new Handler(Looper.getMainLooper());
        Log.d(TAG, "PrinterInterface created");
    }

    public void setWebView(WebView webView) {
        this.webView = webView;
        Log.d(TAG, "WebView set");
    }

    /**
     * Simple ping to test if the interface is working.
     * Call from JavaScript: AndroidPrinter.ping()
     * 
     * @return "pong" if working
     */
    @JavascriptInterface
    public String ping() {
        Log.d(TAG, "ping() called from JavaScript");
        // Show a toast to confirm the call
        mainHandler.post(() -> {
            android.widget.Toast.makeText(context, "Ping received!", android.widget.Toast.LENGTH_SHORT).show();
        });
        return "pong";
    }
    
    /**
     * Check if printer is ready.
     * Call from JavaScript: AndroidPrinter.isReady()
     * 
     * @return true if printer interface is ready
     */
    @JavascriptInterface
    public boolean isReady() {
        Log.d(TAG, "isReady() called from JavaScript");
        // Show a toast to confirm
        mainHandler.post(() -> {
            android.widget.Toast.makeText(context, "isReady called!", android.widget.Toast.LENGTH_SHORT).show();
        });
        return true;
    }

    /**
     * Print a receipt from JSON data.
     * This is called from JavaScript when intercepting receipt.php requests.
     * 
     * @param receiptJson JSON string containing receipt data
     */
    @JavascriptInterface
    public void printReceipt(String receiptJson) {
        Log.d(TAG, "printReceipt called with: " + receiptJson);
        
        try {
            JSONObject receiptData = new JSONObject(receiptJson);
            
            printerManager.printReceipt(receiptData, new PrinterManager.PrintCallback() {
                @Override
                public void onSuccess(String message) {
                    Log.d(TAG, "Print success: " + message);
                    sendCallback("onPrintSuccess", message, null);
                }

                @Override
                public void onError(String error) {
                    Log.e(TAG, "Print error: " + error);
                    sendCallback("onPrintError", null, error);
                }
            });
            
        } catch (JSONException e) {
            Log.e(TAG, "Error parsing receipt JSON", e);
            sendCallback("onPrintError", null, "Invalid receipt data: " + e.getMessage());
        }
    }

    /**
     * Print a receipt and return result via callback.
     * 
     * @param receiptJson JSON string containing receipt data
     * @param callback JavaScript callback function name
     */
    @JavascriptInterface
    public void printReceiptWithCallback(String receiptJson, String callback) {
        Log.d(TAG, "printReceiptWithCallback called");
        
        try {
            JSONObject receiptData = new JSONObject(receiptJson);
            
            printerManager.printReceipt(receiptData, new PrinterManager.PrintCallback() {
                @Override
                public void onSuccess(String message) {
                    invokeJsCallback(callback, true, message);
                }

                @Override
                public void onError(String error) {
                    invokeJsCallback(callback, false, error);
                }
            });
            
        } catch (JSONException e) {
            Log.e(TAG, "Error parsing receipt JSON", e);
            invokeJsCallback(callback, false, "Invalid receipt data: " + e.getMessage());
        }
    }

    /**
     * Get the current printer status.
     * 
     * @return JSON string with printer status information
     */
    @JavascriptInterface
    public String getPrinterStatus() {
        try {
            return printerManager.getPrinterStatus().toString();
        } catch (Exception e) {
            Log.e(TAG, "Error getting printer status", e);
            JSONObject error = new JSONObject();
            try {
                error.put("error", e.getMessage());
            } catch (JSONException ignored) {}
            return error.toString();
        }
    }

    /**
     * Get list of available printers.
     * 
     * @return JSON string with available Bluetooth and USB printers
     */
    @JavascriptInterface
    public String getAvailablePrinters() {
        try {
            return printerManager.getAvailablePrinters().toString();
        } catch (Exception e) {
            Log.e(TAG, "Error getting available printers", e);
            JSONObject error = new JSONObject();
            try {
                error.put("error", e.getMessage());
            } catch (JSONException ignored) {}
            return error.toString();
        }
    }

    /**
     * Configure the printer connection.
     * 
     * @param configJson JSON string with printer configuration:
     *   - type: "BLUETOOTH", "TCP", "USB", or "AUTO"
     *   - ip: TCP IP address (for TCP type)
     *   - port: TCP port (for TCP type, default 9100)
     *   - bluetoothAddress: Bluetooth MAC address (for BLUETOOTH type)
     */
    @JavascriptInterface
    public void configurePrinter(String configJson) {
        Log.d(TAG, "configurePrinter called: " + configJson);
        
        try {
            JSONObject config = new JSONObject(configJson);
            
            String typeStr = config.optString("type", "AUTO");
            PrinterManager.PrinterType type = PrinterManager.PrinterType.valueOf(typeStr.toUpperCase());
            
            String ip = config.optString("ip", "");
            int port = config.optInt("port", 9100);
            String bluetoothAddress = config.optString("bluetoothAddress", "");
            
            printerManager.setPrinterConfig(type, ip, port, bluetoothAddress, "58mm");
            
            Log.d(TAG, "Printer configured: type=" + type + ", ip=" + ip + ", port=" + port);
            
        } catch (Exception e) {
            Log.e(TAG, "Error configuring printer", e);
        }
    }

    /**
     * Check if Bluetooth permissions are granted.
     * 
     * @return true if Bluetooth permissions are granted
     */
    @JavascriptInterface
    public boolean hasBluetoothPermission() {
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            return ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_CONNECT) 
                    == PackageManager.PERMISSION_GRANTED &&
                   ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH_SCAN) 
                    == PackageManager.PERMISSION_GRANTED;
        } else {
            return ContextCompat.checkSelfPermission(context, Manifest.permission.BLUETOOTH) 
                    == PackageManager.PERMISSION_GRANTED;
        }
    }

    /**
     * Test print functionality with a simple test receipt.
     */
    @JavascriptInterface
    public void testPrint() {
        Log.d(TAG, "testPrint called");
        
        try {
            JSONObject testReceipt = new JSONObject();
            testReceipt.put("business_name", "PRINTER TEST");
            testReceipt.put("order_id", "TEST-001");
            testReceipt.put("cashier_username", "Test User");
            testReceipt.put("payment_method", "cash");
            testReceipt.put("cash_received", 100.00);
            testReceipt.put("footer_text", "Test print successful!");
            
            org.json.JSONArray items = new org.json.JSONArray();
            JSONObject item = new JSONObject();
            item.put("name", "Test Item");
            item.put("quantity", 1);
            item.put("price", 50.00);
            items.put(item);
            testReceipt.put("items", items);
            
            printerManager.printReceipt(testReceipt, new PrinterManager.PrintCallback() {
                @Override
                public void onSuccess(String message) {
                    Log.d(TAG, "Test print success: " + message);
                    sendCallback("onPrintSuccess", "Test print completed!", null);
                }

                @Override
                public void onError(String error) {
                    Log.e(TAG, "Test print error: " + error);
                    sendCallback("onPrintError", null, error);
                }
            });
            
        } catch (JSONException e) {
            Log.e(TAG, "Error creating test receipt", e);
            sendCallback("onPrintError", null, "Failed to create test receipt");
        }
    }

    /**
     * Open the printer settings activity.
     * This allows users to configure printer connection and test printing.
     */
    @JavascriptInterface
    public void openSettings() {
        Log.d(TAG, "openSettings called");
        mainHandler.post(() -> {
            PrinterSettingsActivity.Companion.start(context);
        });
    }

    /**
     * Open cash drawer (if connected to receipt printer).
     */
    @JavascriptInterface
    public void openCashDrawer() {
        Log.d(TAG, "openCashDrawer called");
        
        try {
            JSONObject drawerCommand = new JSONObject();
            drawerCommand.put("open_drawer_only", true);
            
            printerManager.printReceipt(drawerCommand, new PrinterManager.PrintCallback() {
                @Override
                public void onSuccess(String message) {
                    Log.d(TAG, "Cash drawer opened");
                    sendCallback("onDrawerOpened", "Cash drawer opened", null);
                }

                @Override
                public void onError(String error) {
                    Log.e(TAG, "Failed to open cash drawer: " + error);
                    sendCallback("onDrawerError", null, error);
                }
            });
            
        } catch (JSONException e) {
            Log.e(TAG, "Error opening cash drawer", e);
        }
    }

    private void sendCallback(String event, String message, String error) {
        if (webView == null) return;
        
        mainHandler.post(() -> {
            try {
                JSONObject data = new JSONObject();
                data.put("event", event);
                if (message != null) data.put("message", message);
                if (error != null) data.put("error", error);
                
                String js = "if (window.AndroidPrinterCallback) { window.AndroidPrinterCallback(" + data.toString() + "); }";
                webView.evaluateJavascript(js, null);
            } catch (Exception e) {
                Log.e(TAG, "Error sending callback", e);
            }
        });
    }

    private void invokeJsCallback(String callback, boolean success, String message) {
        if (webView == null || callback == null || callback.isEmpty()) return;
        
        mainHandler.post(() -> {
            try {
                JSONObject result = new JSONObject();
                result.put("success", success);
                result.put("message", message);
                
                String js = callback + "(" + result.toString() + ");";
                webView.evaluateJavascript(js, null);
            } catch (Exception e) {
                Log.e(TAG, "Error invoking callback", e);
            }
        });
    }

    /**
     * Get the JavaScript code to inject for intercepting receipt.php requests.
     * This code overrides fetch() to intercept calls to receipt.php and redirect them
     * to the native Android printing functionality.
     * 
     * @return JavaScript code to inject
     */
    public static String getInterceptorJavaScript() {
        return "(function() {\n" +
                "  'use strict';\n" +
                "  \n" +
                "  // Prevent multiple injections\n" +
                "  if (window._androidPrinterInterceptorInstalled) {\n" +
                "    return;\n" +
                "  }\n" +
                "  window._androidPrinterInterceptorInstalled = true;\n" +
                "  \n" +
                "  // Store original fetch immediately\n" +
                "  var originalFetch = window.fetch.bind(window);\n" +
                "  \n" +
                "  // Helper function to print receipt via Android\n" +
                "  function printViaAndroid(receiptObj) {\n" +
                "    try {\n" +
                "      // Merge with businessInfo if available\n" +
                "      if (window.businessInfo) {\n" +
                "        receiptObj.business_name = receiptObj.business_name || window.businessInfo.business_name;\n" +
                "        receiptObj.location = receiptObj.location || window.businessInfo.location;\n" +
                "        receiptObj.phone = receiptObj.phone || window.businessInfo.phone;\n" +
                "        receiptObj.footer_text = receiptObj.footer_text || window.businessInfo.footer_text;\n" +
                "        receiptObj.vat_inclusive = receiptObj.vat_inclusive || window.businessInfo.vat_inclusive;\n" +
                "        receiptObj.vat_rate = receiptObj.vat_rate || window.businessInfo.vat_rate;\n" +
                "      }\n" +
                "      var jsonStr = JSON.stringify(receiptObj);\n" +
                "      console.log('[AndroidPrint] Printing:', jsonStr.substring(0, 100));\n" +
                "      AndroidPrinter.printReceipt(jsonStr);\n" +
                "      return true;\n" +
                "    } catch(e) {\n" +
                "      console.error('[AndroidPrint] Error:', e);\n" +
                "      return false;\n" +
                "    }\n" +
                "  }\n" +
                "  \n" +
                "  // Callback handler for print events\n" +
                "  window.AndroidPrinterCallback = function(data) {\n" +
                "    console.log('[AndroidPrint] Event:', data);\n" +
                "  };\n" +
                "  \n" +
                "  // Override fetch\n" +
                "  window.fetch = function(url, options) {\n" +
                "    var urlStr = (typeof url === 'string') ? url : (url && url.url ? url.url : String(url));\n" +
                "    \n" +
                "    // Check if receipt.php POST request\n" +
                "    if (urlStr.indexOf('receipt.php') !== -1 && options && options.body) {\n" +
                "      var method = options.method ? options.method.toUpperCase() : 'GET';\n" +
                "      if (method === 'POST') {\n" +
                "        console.log('[AndroidPrint] Intercepted receipt.php POST');\n" +
                "        \n" +
                "        // Check if AndroidPrinter is available\n" +
                "        if (typeof AndroidPrinter !== 'undefined' && AndroidPrinter.printReceipt) {\n" +
                "          try {\n" +
                "            var bodyStr = typeof options.body === 'string' ? options.body : JSON.stringify(options.body);\n" +
                "            var receiptObj = JSON.parse(bodyStr);\n" +
                "            \n" +
                "            if (printViaAndroid(receiptObj)) {\n" +
                "              // Return mock success response\n" +
                "              return Promise.resolve(new Response(\n" +
                "                JSON.stringify({success: true, message: 'Printed via Android', printer_type: 'android_native'}),\n" +
                "                {status: 200, headers: {'Content-Type': 'application/json'}}\n" +
                "              ));\n" +
                "            }\n" +
                "          } catch(e) {\n" +
                "            console.error('[AndroidPrint] Parse error:', e);\n" +
                "          }\n" +
                "        } else {\n" +
                "          console.log('[AndroidPrint] AndroidPrinter not available');\n" +
                "        }\n" +
                "      }\n" +
                "    }\n" +
                "    \n" +
                "    // Fall through to original fetch\n" +
                "    return originalFetch(url, options);\n" +
                "  };\n" +
                "  \n" +
                "  console.log('[AndroidPrint] Interceptor ready. AndroidPrinter:', typeof AndroidPrinter !== 'undefined');\n" +
                "})();\n";
    }
}
