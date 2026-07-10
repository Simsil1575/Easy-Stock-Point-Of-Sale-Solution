package com.books.webview;

import androidx.appcompat.app.AppCompatActivity;
import androidx.swiperefreshlayout.widget.SwipeRefreshLayout;

import android.annotation.SuppressLint;
import android.app.DownloadManager;
import android.graphics.Bitmap;
import android.net.Uri;
import android.net.http.SslError;
import android.os.Bundle;
import android.os.Environment;
import android.view.KeyEvent;
import android.view.View;
import android.webkit.DownloadListener;
import android.webkit.MimeTypeMap;
import android.webkit.SslErrorHandler;
import android.webkit.URLUtil;
import android.webkit.ConsoleMessage;
import android.webkit.WebChromeClient;
import android.webkit.WebResourceRequest;
import android.webkit.WebResourceResponse;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import android.widget.ProgressBar;
import android.widget.Toast;
import android.os.Handler;
import android.os.Looper;

import org.json.JSONObject;
import com.journeyapps.barcodescanner.DecoratedBarcodeView;
import android.content.pm.PackageManager;
import androidx.core.app.ActivityCompat;
import androidx.core.content.ContextCompat;
import android.Manifest;
import kotlin.jvm.functions.Function1;
import kotlin.Unit;


public class MainActivity extends AppCompatActivity {

    WebView webView;
    ProgressBar progressBar;
    SwipeRefreshLayout swipeRefreshLayout;

    String url = "http://google.com"; // Default URL
    private ReceiptPrintService receiptPrintService;
    private BarcodeScannerHelper barcodeScannerHelper;
    private DecoratedBarcodeView barcodeView;
    private static final int CAMERA_PERMISSION_REQUEST_CODE = 1001;

//    final String filename= URLUtil.guessFileName(URLUtil.guessUrl(url));

    @SuppressLint("SetJavaScriptEnabled")
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        setContentView(R.layout.activity_main);

        if (getSupportActionBar() != null) {
            getSupportActionBar().hide();
        }

        // Get URL from intent (set in PrinterConfigActivity)
        String intentUrl = getIntent().getStringExtra("WEBVIEW_URL");
        if (intentUrl != null && !intentUrl.isEmpty()) {
            url = intentUrl;
        }

        webView = findViewById(R.id.web);
        progressBar = findViewById(R.id.progress);
        swipeRefreshLayout = findViewById(R.id.swipe);
        barcodeView = findViewById(R.id.barcode_scanner);

        webView.getSettings().setJavaScriptEnabled(true);
        webView.getSettings().setSupportZoom(false);
        webView.getSettings().setDomStorageEnabled(true);
        
        // Initialize receipt print service
        receiptPrintService = new ReceiptPrintService(this, this);
        
        // Initialize barcode scanner
        barcodeScannerHelper = new BarcodeScannerHelper(
            this,
            barcodeView,
            new kotlin.jvm.functions.Function1<String, kotlin.Unit>() {
                @Override
                public kotlin.Unit invoke(String barcode) {
                    // Handle scanned barcode
                    android.util.Log.d("MainActivity", "Barcode scanned: " + barcode);
                    runOnUiThread(new Runnable() {
                        @Override
                        public void run() {
                            // Send barcode to JavaScript
                            String jsCode = String.format(
                                "if (window.handleBarcodeScan) { window.handleBarcodeScan('%s'); } else { " +
                                "const searchBar = document.getElementById('searchBar'); " +
                                "if (searchBar) { searchBar.value = '%s'; " +
                                "const event = new Event('input', { bubbles: true }); " +
                                "searchBar.dispatchEvent(event); " +
                                "setTimeout(() => { " +
                                "const enterEvent = new KeyboardEvent('keydown', { key: 'Enter', code: 'Enter', keyCode: 13, bubbles: true }); " +
                                "searchBar.dispatchEvent(enterEvent); }, 100); } }",
                                barcode, barcode
                            );
                            webView.evaluateJavascript(jsCode, null);
                            
                            // Keep scanning active for continuous scanning (Sunmi devices)
                            // Scanner remains active after successful scan
                        }
                    });
                    return kotlin.Unit.INSTANCE;
                }
            }
        );
        
        // Add JavaScript interface for receipt handling
        webView.addJavascriptInterface(new ReceiptJavaScriptInterface(), "AndroidReceiptHandler");
        
        // Add JavaScript interface for barcode scanning
        webView.addJavascriptInterface(new BarcodeJavaScriptInterface(), "AndroidBarcodeScanner");
        
        // Enable console logging for debugging
        webView.setWebChromeClient(new WebChromeClient() {
            @Override
            public boolean onConsoleMessage(ConsoleMessage consoleMessage) {
                android.util.Log.d("WebView", consoleMessage.message() + " -- From line "
                        + consoleMessage.lineNumber() + " of "
                        + consoleMessage.sourceId());
                return true;
            }
        });
        
        webView.setWebViewClient(new myWebViewclient());
        webView.loadUrl(url);

        // Disable swipe to refresh
        swipeRefreshLayout.setEnabled(false);

        //  ==================== START HERE: THIS CODE BLOCK IS TO ENABLE FILE DOWNLOAD FROM THE WEB. YOU CAN COMMENT IT OUT IF YOUR APPLICATION DOES NOT REQUIRE FILE DOWNLOAD. IT WAS ADDED ON REQUEST ======//

        webView.setDownloadListener(new DownloadListener() {
            String fileName = MimeTypeMap.getFileExtensionFromUrl(url);
            @Override
            public void onDownloadStart(String url, String userAgent,
                                        String contentDisposition, String mimetype,
                                        long contentLength) {

                DownloadManager.Request request = new DownloadManager.Request(
                        Uri.parse(url));

                request.allowScanningByMediaScanner();
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED); //Notify client once download is completed!
                request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, fileName);
                DownloadManager dm = (DownloadManager) getSystemService(DOWNLOAD_SERVICE);
                dm.enqueue(request);
                Toast.makeText(getApplicationContext(), "Downloading File", //To notify the Client that the file is being downloaded
                        Toast.LENGTH_LONG).show();

            }
        });
        //  ==================== END HERE: THIS CODE BLOCK IS TO ENABLE FILE DOWNLOAD FROM THE WEB. YOU CAN COMMENT IT OUT IF YOUR APPLICATION DOES NOT REQUIRE FILE DOWNLOAD. IT WAS ADDED ON REQUEST ======//



    }
    

    public class myWebViewclient extends WebViewClient{

        @Override
        public boolean shouldOverrideUrlLoading(WebView view, String url) {
            view.loadUrl(url);
            return true;
        }

        @Override
        public WebResourceResponse shouldInterceptRequest(WebView view, WebResourceRequest request) {
            // Don't intercept receipt.php requests - let them go through normally
            // The JavaScript interceptor will handle capturing the response data
            return super.shouldInterceptRequest(view, request);
        }

        @Override
        public void onReceivedError(WebView view, int errorCode, String description, String failingUrl) {
            Toast.makeText(getApplicationContext(), "No internet connection", Toast.LENGTH_LONG).show();
            webView.loadUrl("file:///android_asset/lost.html");
        }

        @Override
        public void onReceivedSslError(WebView view, SslErrorHandler handler, SslError error) {
            super.onReceivedSslError(view, handler, error);
            handler.cancel();
        }

        @Override
        public void onPageStarted(WebView view, String url, Bitmap favicon) {
            super.onPageStarted(view, url, favicon);
            progressBar.setVisibility(View.VISIBLE);
            
            // Inject JavaScript to intercept fetch and XMLHttpRequest calls to receipt.php
            injectReceiptInterceptor();
        }

        @Override
        public void onPageFinished(WebView view, String url) {
            super.onPageFinished(view, url);
            progressBar.setVisibility(View.GONE);
            
                    // Reinject interceptor and debug panel after page load with a slight delay to ensure page is ready
            new Handler(Looper.getMainLooper()).postDelayed(new Runnable() {
                @Override
                public void run() {
                    injectReceiptInterceptor();
                    // Debug panel is injected inside injectReceiptInterceptor, but ensure it's there
                    injectDebugPanel();
                    
                    // Inject barcode scanner if on home.php
                    if (url.contains("home.php")) {
                        injectBarcodeScanner();
                        // Scanner is ready - user can activate with physical scan button
                        android.util.Log.d("MainActivity", "Barcode scanner ready for home.php - use physical scan button to activate");
                    }
                }
            }, 500);
        }

        private void injectReceiptInterceptor() {
            String jsCode = 
                "(function() {" +
                "  // Test Android interface connection" +
                "  if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.testConnection) {" +
                "    try {" +
                "      window.AndroidReceiptHandler.testConnection();" +
                "      console.log('Receipt interceptor: Android interface is accessible');" +
                "    } catch(e) {" +
                "      console.error('Receipt interceptor: Android interface test failed', e);" +
                "    }" +
                "  } else {" +
                "    console.error('Receipt interceptor: AndroidReceiptHandler not found');" +
                "  }" +
                "  " +
                "  // Always reinject fetch interceptor to ensure it works on all pages" +
                "  // IMPORTANT: This interceptor ONLY listens to receipt.php, not home.php or other files" +
                "  const originalFetch = window.fetch;" +
                "  window.fetch = function(url, options) {" +
                "    const requestUrl = typeof url === 'string' ? url : (url && url.url ? url.url : '');" +
                "    // Only intercept calls to receipt.php - ignore all other files" +
                "    if (requestUrl && requestUrl.includes('receipt.php')) {" +
                "      console.log('Receipt interceptor: Intercepted fetch request to receipt.php');" +
                "      if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "        window.AndroidReceiptHandler.logDebug('Intercepted fetch to receipt.php');" +
                "      }" +
                "      " +
                "      // Intercept the response to get enriched data" +
                "      const originalFetchCall = originalFetch.apply(this, arguments);" +
                "      originalFetchCall.then(response => {" +
                "        if (response.ok) {" +
                "          response.clone().json().then(result => {" +
                "            console.log('Receipt interceptor: Got response from receipt.php', result);" +
                "            if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "              window.AndroidReceiptHandler.logDebug('Got response: ' + JSON.stringify(result).substring(0, 200));" +
                "            }" +
                "            // Use enriched order_data from response if available" +
                "            const enrichedData = result.order_data || result;" +
                "            " +
                "            // Only print if print_only is true or it's a special receipt type" +
                "            const shouldPrint = enrichedData.print_only === true || " +
                "                                enrichedData.is_cashup_report === true || " +
                "                                enrichedData.is_balance_receipt === true || " +
                "                                enrichedData.is_tab_balance_receipt === true || " +
                "                                enrichedData.is_tab_copy_receipt === true || " +
                "                                enrichedData.is_payment_receipt === true || " +
                "                                enrichedData.open_drawer_only === true;" +
                "            " +
                "            if (!shouldPrint) {" +
                "              console.log('Receipt interceptor: Print not requested, skipping');" +
                "              if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                window.AndroidReceiptHandler.logDebug('Print skipped - print_only not set');" +
                "              }" +
                "              return;" +
                "            }" +
                "            " +
                "            if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.handleReceipt) {" +
                "              console.log('Receipt interceptor: Calling Android handler with data');" +
                "              if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                window.AndroidReceiptHandler.logDebug('Calling handleReceipt with enriched data');" +
                "              }" +
                "              try {" +
                "                window.AndroidReceiptHandler.handleReceipt(JSON.stringify(enrichedData));" +
                "              } catch(e) {" +
                "                console.error('Receipt interceptor: Error calling Android handler', e);" +
                "                if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                  window.AndroidReceiptHandler.logDebug('Error: ' + e.toString());" +
                "                }" +
                "              }" +
                "            } else {" +
                "              console.error('Receipt interceptor: AndroidReceiptHandler not available');" +
                "            }" +
                "          }).catch(e => {" +
                "            console.error('Receipt interceptor: Error parsing JSON response', e);" +
                "          });" +
                "        } else {" +
                "          console.error('Receipt interceptor: Response not ok', response.status);" +
                "        }" +
                "      }).catch(e => {" +
                "        console.error('Receipt interceptor: Fetch error', e);" +
                "      });" +
                "    }" +
                "    return originalFetch.apply(this, arguments);" +
                "  };" +
                "  " +
                "  // Intercept XMLHttpRequest (only set up once)" +
                "  // IMPORTANT: This interceptor ONLY listens to receipt.php, not home.php or other files" +
                "  if (!window._xhrIntercepted) {" +
                "    window._xhrIntercepted = true;" +
                "    const originalXHROpen = XMLHttpRequest.prototype.open;" +
                "    const originalXHRSend = XMLHttpRequest.prototype.send;" +
                "    " +
                "    XMLHttpRequest.prototype.open = function(method, url, ...args) {" +
                "      this._receiptUrl = url;" +
                "      this._receiptMethod = method;" +
                "      return originalXHROpen.apply(this, [method, url, ...args]);" +
                "    };" +
                "    " +
                "    XMLHttpRequest.prototype.send = function(data) {" +
                "      // Only intercept calls to receipt.php - ignore all other files" +
                "      if (this._receiptUrl && this._receiptUrl.includes('receipt.php') && this._receiptMethod === 'POST') {" +
                "        console.log('Receipt interceptor: Intercepted XHR request to receipt.php');" +
                "        if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "          window.AndroidReceiptHandler.logDebug('Intercepted XHR to receipt.php');" +
                "        }" +
                "        const xhr = this;" +
                "        " +
                "        // Intercept response" +
                "        const originalOnReadyStateChange = xhr.onreadystatechange;" +
                "        xhr.onreadystatechange = function() {" +
                "          if (xhr.readyState === 4 && xhr.status === 200) {" +
                "            try {" +
                "              const response = JSON.parse(xhr.responseText);" +
                "              console.log('Receipt interceptor: Got XHR response from receipt.php', response);" +
                "              if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                window.AndroidReceiptHandler.logDebug('Got XHR response: ' + JSON.stringify(response).substring(0, 200));" +
                "              }" +
                "              // Use enriched order_data from response if available" +
                "              const enrichedData = response.order_data || response;" +
                "              " +
                "              // Only print if print_only is true or it's a special receipt type" +
                "              const shouldPrint = enrichedData.print_only === true || " +
                "                                  enrichedData.is_cashup_report === true || " +
                "                                  enrichedData.is_balance_receipt === true || " +
                "                                  enrichedData.is_tab_balance_receipt === true || " +
                "                                  enrichedData.is_tab_copy_receipt === true || " +
                "                                  enrichedData.is_payment_receipt === true || " +
                "                                  enrichedData.open_drawer_only === true;" +
                "              " +
                "              if (!shouldPrint) {" +
                "                console.log('Receipt interceptor: Print not requested, skipping');" +
                "                if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                  window.AndroidReceiptHandler.logDebug('Print skipped - print_only not set');" +
                "                }" +
                "                return;" +
                "              }" +
                "              " +
                "              if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.handleReceipt) {" +
                "                console.log('Receipt interceptor: Calling Android handler with XHR data');" +
                "                if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                  window.AndroidReceiptHandler.logDebug('Calling handleReceipt with XHR data');" +
                "                }" +
                "                try {" +
                "                  window.AndroidReceiptHandler.handleReceipt(JSON.stringify(enrichedData));" +
                "                } catch(e) {" +
                "                  console.error('Receipt interceptor: Error calling Android handler', e);" +
                "                  if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "                    window.AndroidReceiptHandler.logDebug('Error: ' + e.toString());" +
                "                  }" +
                "                }" +
                "              } else {" +
                "                console.error('Receipt interceptor: AndroidReceiptHandler not available');" +
                "              }" +
                "            } catch(e) {" +
                "              console.error('Receipt interceptor: Error parsing XHR response', e);" +
                "            }" +
                "          }" +
                "          if (originalOnReadyStateChange) {" +
                "            originalOnReadyStateChange.apply(this, arguments);" +
                "          }" +
                "        };" +
                "      }" +
                "      return originalXHRSend.apply(this, arguments);" +
                "    };" +
                "  }" +
                "  " +
                "  console.log('Receipt interceptor injected successfully');" +
                "})();";
            
            webView.evaluateJavascript(jsCode, null);
            
            // Inject debug panel
            injectDebugPanel();
        }
        
        private void injectDebugPanel() {
            String debugPanelJs = 
                "(function() {" +
                "  if (window._debugPanelInjected) return;" +
                "  window._debugPanelInjected = true;" +
                "  " +
                "  // Create debug button" +
                "  const debugBtn = document.createElement('button');" +
                "  debugBtn.innerHTML = 'DEBUG';" +
                "  debugBtn.style.cssText = 'position:fixed;bottom:10px;right:10px;z-index:99999;background:#ff4444;color:white;border:none;padding:10px 15px;border-radius:5px;font-weight:bold;box-shadow:0 2px 5px rgba(0,0,0,0.3);';" +
                "  " +
                "  // Create debug panel" +
                "  const debugPanel = document.createElement('div');" +
                "  debugPanel.id = 'androidDebugPanel';" +
                "  debugPanel.style.cssText = 'display:none;position:fixed;bottom:60px;right:10px;width:300px;background:white;border:2px solid #ff4444;border-radius:5px;padding:15px;z-index:99998;box-shadow:0 4px 10px rgba(0,0,0,0.3);max-height:400px;overflow-y:auto;';" +
                "  " +
                "  const panelTitle = document.createElement('h3');" +
                "  panelTitle.innerHTML = 'Receipt Debug Panel';" +
                "  panelTitle.style.cssText = 'margin:0 0 10px 0;color:#ff4444;font-size:16px;';" +
                "  debugPanel.appendChild(panelTitle);" +
                "  " +
                "  // Test Connection Button" +
                "  const testConnBtn = document.createElement('button');" +
                "  testConnBtn.innerHTML = 'Test Android Connection';" +
                "  testConnBtn.style.cssText = 'width:100%;padding:10px;margin:5px 0;background:#4CAF50;color:white;border:none;border-radius:3px;cursor:pointer;';" +
                "  testConnBtn.onclick = function() {" +
                "    if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.testConnection) {" +
                "      window.AndroidReceiptHandler.testConnection();" +
                "      addLog('Test connection called');" +
                "    } else {" +
                "      addLog('ERROR: AndroidReceiptHandler not found!');" +
                "    }" +
                "  };" +
                "  debugPanel.appendChild(testConnBtn);" +
                "  " +
                "  // Test Receipt Button" +
                "  const testReceiptBtn = document.createElement('button');" +
                "  testReceiptBtn.innerHTML = 'Print Test Receipt';" +
                "  testReceiptBtn.style.cssText = 'width:100%;padding:10px;margin:5px 0;background:#2196F3;color:white;border:none;border-radius:3px;cursor:pointer;';" +
                "  testReceiptBtn.onclick = function() {" +
                "    if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.testReceipt) {" +
                "      window.AndroidReceiptHandler.testReceipt();" +
                "      addLog('Test receipt called');" +
                "    } else {" +
                "      addLog('ERROR: testReceipt method not found!');" +
                "    }" +
                "  };" +
                "  debugPanel.appendChild(testReceiptBtn);" +
                "  " +
                "  // Simulate receipt.php call" +
                "  const simReceiptBtn = document.createElement('button');" +
                "  simReceiptBtn.innerHTML = 'Simulate receipt.php Call';" +
                "  simReceiptBtn.style.cssText = 'width:100%;padding:10px;margin:5px 0;background:#FF9800;color:white;border:none;border-radius:3px;cursor:pointer;';" +
                "  simReceiptBtn.onclick = function() {" +
                "    addLog('Simulating receipt.php call...');" +
                "    const testData = {" +
                "      print_only: true," +
                "      order_id: 'SIM-001'," +
                "      items: [{" +
                "        name: 'Simulated Item 1'," +
                "        quantity: 1," +
                "        price: 25.00" +
                "      }]," +
                "      payment_method: 'cash'," +
                "      cash_received: 25.00" +
                "    };" +
                "    " +
                "    // Try fetch" +
                "    fetch('receipt.php', {" +
                "      method: 'POST'," +
                "      headers: {'Content-Type': 'application/json'}," +
                "      body: JSON.stringify(testData)" +
                "    }).then(r => r.json()).then(data => {" +
                "      addLog('Fetch response: ' + JSON.stringify(data).substring(0, 100));" +
                "    }).catch(e => {" +
                "      addLog('Fetch error: ' + e.toString());" +
                "    });" +
                "  };" +
                "  debugPanel.appendChild(simReceiptBtn);" +
                "  " +
                "  // Log area" +
                "  const logArea = document.createElement('div');" +
                "  logArea.id = 'debugLogArea';" +
                "  logArea.style.cssText = 'margin-top:10px;padding:10px;background:#f5f5f5;border-radius:3px;font-size:12px;max-height:200px;overflow-y:auto;';" +
                "  debugPanel.appendChild(logArea);" +
                "  " +
                "  function addLog(message) {" +
                "    const logEntry = document.createElement('div');" +
                "    logEntry.style.cssText = 'padding:5px;margin:2px 0;border-bottom:1px solid #ddd;';" +
                "    logEntry.innerHTML = new Date().toLocaleTimeString() + ': ' + message;" +
                "    logArea.appendChild(logEntry);" +
                "    logArea.scrollTop = logArea.scrollHeight;" +
                "    if (window.AndroidReceiptHandler && window.AndroidReceiptHandler.logDebug) {" +
                "      window.AndroidReceiptHandler.logDebug(message);" +
                "    }" +
                "  }" +
                "  " +
                "  // Toggle panel" +
                "  debugBtn.onclick = function() {" +
                "    if (debugPanel.style.display === 'none') {" +
                "      debugPanel.style.display = 'block';" +
                "      addLog('Debug panel opened');" +
                "    } else {" +
                "      debugPanel.style.display = 'none';" +
                "    }" +
                "  };" +
                "  " +
                "  document.body.appendChild(debugBtn);" +
                "  document.body.appendChild(debugPanel);" +
                "  " +
                "  // Expose addLog globally for interceptor use" +
                "  window.addDebugLog = addLog;" +
                "  " +
                "  console.log('Debug panel injected');" +
                "})();";
            
            webView.evaluateJavascript(debugPanelJs, null);
        }
        
        private void injectBarcodeScanner() {
            String barcodeScannerJs = 
                "(function() {" +
                "  if (window._barcodeScannerInjected) return;" +
                "  window._barcodeScannerInjected = true;" +
                "  " +
                "  console.log('Barcode scanner injected');" +
                "  " +
                "  // Function to handle scanned barcode" +
                "  window.handleBarcodeScan = function(barcode) {" +
                "    console.log('Barcode scanned:', barcode);" +
                "    " +
                "    // Get the search bar" +
                "    const searchBar = document.getElementById('searchBar');" +
                "    if (!searchBar) {" +
                "      console.error('Search bar not found');" +
                "      return;" +
                "    }" +
                "    " +
                "    // Set the barcode value" +
                "    searchBar.value = barcode;" +
                "    " +
                "    // Trigger input event to filter products" +
                "    const inputEvent = new Event('input', { bubbles: true });" +
                "    searchBar.dispatchEvent(inputEvent);" +
                "    " +
                "    // Look for product with matching barcode" +
                "    setTimeout(() => {" +
                "      const products = document.querySelectorAll('.product-item[data-barcode=\"' + barcode + '\"]');" +
                "      " +
                "      if (products.length > 0) {" +
                "        // Product found - add to cart" +
                "        if (typeof addToCart === 'function') {" +
                "          addToCart(products[0]);" +
                "          console.log('Product added to cart via barcode scan');" +
                "        } else {" +
                "          console.error('addToCart function not found');" +
                "        }" +
                "        " +
                "        // Clear search bar" +
                "        if (typeof clearSearch === 'function') {" +
                "          clearSearch();" +
                "        } else {" +
                "          searchBar.value = '';" +
                "        }" +
                "      } else {" +
                "        // Product not found - trigger Enter key to show notification" +
                "        const enterEvent = new KeyboardEvent('keydown', {" +
                "          key: 'Enter'," +
                "          code: 'Enter'," +
                "          keyCode: 13," +
                "          bubbles: true" +
                "        });" +
                "        searchBar.dispatchEvent(enterEvent);" +
                "      }" +
                "    }, 100);" +
                "  };" +
                "  " +
                "  // Scanner is ready - use physical scan button on Sunmi devices" +
                "  if (window.AndroidBarcodeScanner) {" +
                "    console.log('Android barcode scanner interface found - ready for physical scan button');" +
                "    " +
                "    // Don't auto-start - wait for physical button press" +
                "    // The physical scan button on Sunmi devices will trigger scanning" +
                "  } else {" +
                "    console.warn('Android barcode scanner interface not found');" +
                "  }" +
                "  " +
                "  console.log('Barcode scanner setup complete');" +
                "})();";
            
            webView.evaluateJavascript(barcodeScannerJs, null);
        }
    }


    @Override
    public boolean onKeyDown(int keyCode, KeyEvent event) {
        // Log all key presses for debugging (helps identify Sunmi scan button codes)
        android.util.Log.d("MainActivity", "Key pressed: " + keyCode + " (KEYCODE_F1=" + KeyEvent.KEYCODE_F1 + ", KEYCODE_F2=" + KeyEvent.KEYCODE_F2 + ")");
        
        // Handle Sunmi scan button presses
        // Sunmi devices typically use:
        // - KEYCODE_F1 (131) - Left scan button
        // - KEYCODE_F2 (132) - Right scan button  
        // - 280, 281 - Custom scan button codes on some models
        // - 293, 294 - Additional scan button codes
        // - 290, 291 - Some newer models
        boolean isScanButton = (keyCode == KeyEvent.KEYCODE_F1 || 
                                keyCode == KeyEvent.KEYCODE_F2 || 
                                keyCode == 280 || 
                                keyCode == 281 ||
                                keyCode == 290 ||
                                keyCode == 291 ||
                                keyCode == 293 || 
                                keyCode == 294);
        
        if (isScanButton) {
            android.util.Log.d("MainActivity", "Sunmi scan button detected: " + keyCode);
            
            if (barcodeScannerHelper != null) {
                // Toggle scanning on button press
                if (barcodeScannerHelper.isScanningActive()) {
                    barcodeScannerHelper.stopScanning();
                    android.util.Log.d("MainActivity", "Scanning stopped via physical button");
                    Toast.makeText(this, "Scanner stopped", Toast.LENGTH_SHORT).show();
                    // Update button state in webview
                    String jsCode = "if (typeof updateCameraButton === 'function') { updateCameraButton(false); } if (typeof isCameraScanning !== 'undefined') { isCameraScanning = false; }";
                    webView.evaluateJavascript(jsCode, null);
                } else {
                    barcodeScannerHelper.startScanning();
                    android.util.Log.d("MainActivity", "Scanning started via physical button");
                    Toast.makeText(this, "Scanner started", Toast.LENGTH_SHORT).show();
                    // Update button state in webview
                    String jsCode = "if (typeof updateCameraButton === 'function') { updateCameraButton(true); } if (typeof isCameraScanning !== 'undefined') { isCameraScanning = true; }";
                    webView.evaluateJavascript(jsCode, null);
                }
            } else {
                android.util.Log.w("MainActivity", "Barcode scanner helper not initialized");
            }
            return true; // Consume the event
        }

        if ((keyCode == KeyEvent.KEYCODE_BACK) && webView.canGoBack()) {
            webView.goBack();
            return true;
        }
        return super.onKeyDown(keyCode, event);
    }

    // JavaScript interface to receive receipt data
    public class ReceiptJavaScriptInterface {
        @android.webkit.JavascriptInterface
        public void handleReceipt(String receiptJson) {
            android.util.Log.d("ReceiptHandler", "Received receipt data: " + receiptJson);
            runOnUiThread(() -> {
                try {
                    JSONObject orderData = new JSONObject(receiptJson);
                    android.util.Log.d("ReceiptHandler", "Parsed order data, calling printReceipt");
                    receiptPrintService.printReceipt(orderData);
                } catch (Exception e) {
                    android.util.Log.e("ReceiptHandler", "Error processing receipt", e);
                    e.printStackTrace();
                    Toast.makeText(MainActivity.this, "Error processing receipt: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                }
            });
        }
        
        @android.webkit.JavascriptInterface
        public void testConnection() {
            android.util.Log.d("ReceiptHandler", "Test connection called - Android interface is working");
            runOnUiThread(() -> {
                Toast.makeText(MainActivity.this, "Android interface is working", Toast.LENGTH_SHORT).show();
            });
        }
        
        @android.webkit.JavascriptInterface
        public void testReceipt() {
            android.util.Log.d("ReceiptHandler", "Test receipt called");
            runOnUiThread(() -> {
                try {
                    // Create a test receipt JSON
                    JSONObject testReceipt = new JSONObject();
                    testReceipt.put("print_only", true);
                    testReceipt.put("order_id", "TEST-001");
                    testReceipt.put("date", new java.text.SimpleDateFormat("yyyy-MM-dd HH:mm", java.util.Locale.getDefault()).format(new java.util.Date()));
                    testReceipt.put("cashier_username", "Test User");
                    
                    // Add test items
                    org.json.JSONArray items = new org.json.JSONArray();
                    JSONObject item1 = new JSONObject();
                    item1.put("name", "Test Item 1");
                    item1.put("quantity", 2);
                    item1.put("price", 50.00);
                    items.put(item1);
                    
                    JSONObject item2 = new JSONObject();
                    item2.put("name", "Test Item 2");
                    item2.put("quantity", 1);
                    item2.put("price", 30.00);
                    items.put(item2);
                    
                    testReceipt.put("items", items);
                    testReceipt.put("payment_method", "cash");
                    testReceipt.put("cash_received", 130.00);
                    testReceipt.put("vat_inclusive", "exclusive");
                    testReceipt.put("vat_rate", 15.0);
                    
                    android.util.Log.d("ReceiptHandler", "Test receipt data: " + testReceipt.toString());
                    receiptPrintService.printReceipt(testReceipt);
                    Toast.makeText(MainActivity.this, "Test receipt sent to printer", Toast.LENGTH_SHORT).show();
                } catch (Exception e) {
                    android.util.Log.e("ReceiptHandler", "Error creating test receipt", e);
                    Toast.makeText(MainActivity.this, "Error: " + e.getMessage(), Toast.LENGTH_SHORT).show();
                }
            });
        }
        
        @android.webkit.JavascriptInterface
        public void logDebug(String message) {
            android.util.Log.d("WebViewDebug", message);
        }
        
        @android.webkit.JavascriptInterface
        public void showToast(String message) {
            runOnUiThread(() -> {
                Toast.makeText(MainActivity.this, message, Toast.LENGTH_SHORT).show();
            });
        }
    }

    // JavaScript interface for barcode scanning
    public class BarcodeJavaScriptInterface {
        @android.webkit.JavascriptInterface
        public void startScanning() {
            android.util.Log.d("BarcodeScanner", "Start scanning requested from JavaScript");
            runOnUiThread(() -> {
                if (barcodeScannerHelper != null) {
                    barcodeScannerHelper.startScanning();
                    // Update button state in webview
                    String jsCode = "if (typeof updateCameraButton === 'function') { updateCameraButton(true); } if (typeof isCameraScanning !== 'undefined') { isCameraScanning = true; }";
                    webView.evaluateJavascript(jsCode, null);
                }
            });
        }
        
        @android.webkit.JavascriptInterface
        public void stopScanning() {
            android.util.Log.d("BarcodeScanner", "Stop scanning requested from JavaScript");
            runOnUiThread(() -> {
                if (barcodeScannerHelper != null) {
                    barcodeScannerHelper.stopScanning();
                    // Update button state in webview
                    String jsCode = "if (typeof updateCameraButton === 'function') { updateCameraButton(false); } if (typeof isCameraScanning !== 'undefined') { isCameraScanning = false; }";
                    webView.evaluateJavascript(jsCode, null);
                }
            });
        }
        
        @android.webkit.JavascriptInterface
        public boolean isScanning() {
            return barcodeScannerHelper != null && barcodeScannerHelper.isScanningActive();
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        if (barcodeScannerHelper != null) {
            barcodeScannerHelper.resumeScanning();
        }
    }

    @Override
    protected void onPause() {
        super.onPause();
        if (barcodeScannerHelper != null) {
            barcodeScannerHelper.pauseScanning();
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        if (barcodeScannerHelper != null) {
            barcodeScannerHelper.handlePermissionResult(requestCode, permissions, grantResults);
        }
    }

}
