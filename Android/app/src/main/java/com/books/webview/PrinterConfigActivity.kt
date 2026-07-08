package com.books.webview

import android.Manifest
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.content.IntentFilter
import android.content.SharedPreferences
import android.content.pm.PackageManager
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbManager
import android.net.wifi.WifiManager
import android.os.Build
import android.os.Bundle
import android.text.format.Formatter
import java.net.NetworkInterface
import java.util.Collections
import android.view.View
import android.widget.ArrayAdapter
import android.widget.Button
import android.widget.ProgressBar
import android.widget.Spinner
import android.widget.TextView
import android.widget.Toast
import androidx.appcompat.app.AppCompatActivity
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import androidx.lifecycle.lifecycleScope
import com.google.android.material.textfield.TextInputEditText
import com.khairo.escposprinter.EscPosPrinter
import com.khairo.escposprinter.connection.bluetooth.BluetoothConnection
import com.khairo.escposprinter.connection.bluetooth.BluetoothPrintersConnections
import com.khairo.escposprinter.connection.tcp.TcpConnection
import com.khairo.escposprinter.connection.usb.UsbConnection
import com.khairo.escposprinter.connection.usb.UsbPrintersConnections
import com.khairo.escposprinter.connection.DeviceConnection
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext

class PrinterConfigActivity : AppCompatActivity() {

    private lateinit var bluetoothSpinner: Spinner
    private lateinit var etTcpIp: TextInputEditText
    private lateinit var etTcpPort: TextInputEditText
    private lateinit var etWebViewUrl: TextInputEditText
    private lateinit var btnTestBluetooth: Button
    private lateinit var btnTestTcp: Button
    private lateinit var btnTestUsb: Button
    private lateinit var btnContinue: Button
    private lateinit var tvStatus: TextView
    private lateinit var progressBar: ProgressBar

    private val PERMISSION_BLUETOOTH = 100
    private val PERMISSION_LOCATION = 101
    private val ACTION_USB_PERMISSION = "com.books.webview.USB_PERMISSION"
    
    private lateinit var prefs: SharedPreferences

    private val usbReceiver = object : BroadcastReceiver() {
        override fun onReceive(context: Context, intent: Intent) {
            val action = intent.action
            if (ACTION_USB_PERMISSION == action) {
                synchronized(this) {
                    val usbManager = getSystemService(Context.USB_SERVICE) as UsbManager
                    val usbDevice = intent.getParcelableExtra<UsbDevice>(UsbManager.EXTRA_DEVICE)
                    if (intent.getBooleanExtra(UsbManager.EXTRA_PERMISSION_GRANTED, false)) {
                        if (usbManager != null && usbDevice != null) {
                            testUsbPrinter(usbManager, usbDevice)
                        }
                    } else {
                        runOnUiThread {
                            tvStatus.text = "USB permission denied"
                            progressBar.visibility = View.GONE
                            Toast.makeText(this@PrinterConfigActivity, "USB permission denied", Toast.LENGTH_SHORT).show()
                        }
                    }
                }
            }
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        // Switch from splash theme to main theme
        setTheme(R.style.AppTheme)
        super.onCreate(savedInstanceState)
        
        prefs = getSharedPreferences("PrinterConfig", Context.MODE_PRIVATE)
        
        // Check if printer has been configured before
        val webviewUrl = prefs.getString("webview_url", "")
        val printerConfigured = prefs.getBoolean("printer_configured", false)
        val printerType = prefs.getString("printer_type", "")
        
        // If printer is already configured (has URL and has been marked as configured), skip directly to MainActivity
        if (printerConfigured && !webviewUrl.isNullOrEmpty()) {
            val intent = Intent(this, MainActivity::class.java)
            intent.putExtra("WEBVIEW_URL", webviewUrl)
            startActivity(intent)
            finish()
            return
        }
        
        setContentView(R.layout.activity_printer_config)

        supportActionBar?.hide()

        initializeViews()
        setupBluetoothSpinner()
        setupClickListeners()

        // Register USB receiver
        val filter = IntentFilter(ACTION_USB_PERMISSION)
        registerReceiver(usbReceiver, filter)
    }

    private fun initializeViews() {
        bluetoothSpinner = findViewById(R.id.bluetoothSpinner)
        etTcpIp = findViewById(R.id.etTcpIp)
        etTcpPort = findViewById(R.id.etTcpPort)
        etWebViewUrl = findViewById(R.id.etWebViewUrl)
        btnTestBluetooth = findViewById(R.id.btnTestBluetooth)
        btnTestTcp = findViewById(R.id.btnTestTcp)
        btnTestUsb = findViewById(R.id.btnTestUsb)
        btnContinue = findViewById(R.id.btnContinue)
        tvStatus = findViewById(R.id.tvStatus)
        progressBar = findViewById(R.id.progressBar)
        
        // Set default URL to current IP address
        setDefaultUrl()
    }
    
    private fun setDefaultUrl() {
        val currentIp = getLocalIpAddress()
        if (currentIp.isNotEmpty()) {
            val defaultUrl = "http://$currentIp"
            val savedUrl = prefs.getString("webview_url", "")
            if (savedUrl.isNullOrEmpty()) {
                etWebViewUrl.setText(defaultUrl)
            } else {
                etWebViewUrl.setText(savedUrl)
            }
        } else {
            etWebViewUrl.setText("http://192.168.1.100")
        }
    }
    
    private fun getLocalIpAddress(): String {
        try {
            val interfaces = Collections.list(NetworkInterface.getNetworkInterfaces())
            for (intf in interfaces) {
                val addrs = Collections.list(intf.inetAddresses)
                for (addr in addrs) {
                    if (!addr.isLoopbackAddress) {
                        val sAddr = addr.hostAddress
                        // Check if it's IPv4
                        if (sAddr != null && !sAddr.contains(":")) {
                            // Check if it's a local network IP (192.168.x.x, 10.x.x.x, 172.16-31.x.x)
                            if (sAddr.startsWith("192.168.") || 
                                sAddr.startsWith("10.") || 
                                sAddr.startsWith("172.16.") || 
                                sAddr.startsWith("172.17.") || 
                                sAddr.startsWith("172.18.") || 
                                sAddr.startsWith("172.19.") || 
                                sAddr.startsWith("172.20.") || 
                                sAddr.startsWith("172.21.") || 
                                sAddr.startsWith("172.22.") || 
                                sAddr.startsWith("172.23.") || 
                                sAddr.startsWith("172.24.") || 
                                sAddr.startsWith("172.25.") || 
                                sAddr.startsWith("172.26.") || 
                                sAddr.startsWith("172.27.") || 
                                sAddr.startsWith("172.28.") || 
                                sAddr.startsWith("172.29.") || 
                                sAddr.startsWith("172.30.") || 
                                sAddr.startsWith("172.31.")) {
                                return sAddr
                            }
                        }
                    }
                }
            }
        } catch (e: Exception) {
            e.printStackTrace()
        }
        
        // Fallback: try using WifiManager
        try {
            val wifiManager = applicationContext.getSystemService(Context.WIFI_SERVICE) as WifiManager
            val ipAddress = wifiManager.connectionInfo.ipAddress
            if (ipAddress != 0) {
                return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    // Use InetAddress for Android 10+
                    val ip = (ipAddress and 0xFF).toString() + "." +
                            ((ipAddress shr 8) and 0xFF) + "." +
                            ((ipAddress shr 16) and 0xFF) + "." +
                            ((ipAddress shr 24) and 0xFF)
                    ip
                } else {
                    @Suppress("DEPRECATION")
                    Formatter.formatIpAddress(ipAddress)
                }
            }
        } catch (e: Exception) {
            e.printStackTrace()
        }
        
        return ""
    }
    
    private fun setupBluetoothSpinner() {
        if (checkBluetoothPermission()) {
            loadBluetoothPrinters()
        } else {
            requestBluetoothPermission()
        }
    }

    private fun setupClickListeners() {
        btnTestBluetooth.setOnClickListener {
            if (checkBluetoothPermission() && checkLocationPermission()) {
                testBluetoothPrinter()
            } else {
                requestBluetoothPermission()
                requestLocationPermission()
            }
        }

        btnTestTcp.setOnClickListener {
            testTcpPrinter()
        }

        btnTestUsb.setOnClickListener {
            testUsbPrinter()
        }

        btnContinue.setOnClickListener {
            val url = etWebViewUrl.text?.toString()?.trim()
            if (url.isNullOrEmpty()) {
                Toast.makeText(this, "Please enter a WebView URL", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            
            // Validate URL format
            if (!android.util.Patterns.WEB_URL.matcher(url).matches() && !url.startsWith("http://") && !url.startsWith("https://") && !url.startsWith("file://")) {
                Toast.makeText(this, "Please enter a valid URL", Toast.LENGTH_SHORT).show()
                return@setOnClickListener
            }
            
            // Save URL and mark printer as configured
            prefs.edit()
                .putString("webview_url", url)
                .putBoolean("printer_configured", true)
                .apply()
            
            val intent = Intent(this, MainActivity::class.java)
            intent.putExtra("WEBVIEW_URL", url)
            startActivity(intent)
            finish()
        }
    }

    private fun checkBluetoothPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.BLUETOOTH
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun checkLocationPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            this,
            Manifest.permission.ACCESS_FINE_LOCATION
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun requestBluetoothPermission() {
        ActivityCompat.requestPermissions(
            this,
            arrayOf(Manifest.permission.BLUETOOTH, Manifest.permission.BLUETOOTH_ADMIN),
            PERMISSION_BLUETOOTH
        )
    }

    private fun requestLocationPermission() {
        ActivityCompat.requestPermissions(
            this,
            arrayOf(Manifest.permission.ACCESS_FINE_LOCATION, Manifest.permission.ACCESS_COARSE_LOCATION),
            PERMISSION_LOCATION
        )
    }

    override fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray
    ) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults)
        when (requestCode) {
            PERMISSION_BLUETOOTH, PERMISSION_LOCATION -> {
                if (grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                    loadBluetoothPrinters()
                } else {
                    Toast.makeText(this, "Bluetooth/Location permission required", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private fun loadBluetoothPrinters() {
        try {
            // Use selectFirstPaired as per library documentation
            val firstPrinter = BluetoothPrintersConnections.selectFirstPaired()
            val printerNames = mutableListOf<String>()
            printerNames.add("Select Bluetooth Printer")

            if (firstPrinter != null) {
                printerNames.add(firstPrinter.device?.name ?: "Bluetooth Printer")
            }

            val adapter = ArrayAdapter(this, android.R.layout.simple_spinner_item, printerNames)
            adapter.setDropDownViewResource(android.R.layout.simple_spinner_dropdown_item)
            bluetoothSpinner.adapter = adapter
        } catch (e: Exception) {
            tvStatus.text = "Error loading Bluetooth printers: ${e.message}"
        }
    }

    private fun testBluetoothPrinter() {
        if (bluetoothSpinner.selectedItemPosition == 0) {
            Toast.makeText(this, "Please select a Bluetooth printer", Toast.LENGTH_SHORT).show()
            return
        }

        progressBar.visibility = View.VISIBLE
        tvStatus.text = "Testing Bluetooth printer..."
        btnTestBluetooth.isEnabled = false

        lifecycleScope.launch(Dispatchers.IO) {
            try {
                // Use selectFirstPaired as per library documentation
                val connection = BluetoothPrintersConnections.selectFirstPaired()
                
                if (connection != null) {
                    // Cast to DeviceConnection to resolve constructor ambiguity
                    val printer = EscPosPrinter(connection as DeviceConnection, 203, 48f, 32)

                    val testText = """
                        [C]<u><font size='big'>PRINTER TEST</font></u>
                        [L]
                        [C]================================
                        [L]
                        [C]Bluetooth Printer Test
                        [L]
                        [C]This is a test print
                        [L]
                        [C]================================
                        [L]
                        [L]
                        [L]
                    """.trimIndent()

                    printer.printFormattedText(testText)
                    printer.disconnectPrinter()

                    withContext(Dispatchers.Main) {
                        // Save printer configuration
                        prefs.edit().putString("printer_type", "bluetooth").apply()
                        progressBar.visibility = View.GONE
                        tvStatus.text = "Bluetooth printer test successful!"
                        btnTestBluetooth.isEnabled = true
                        Toast.makeText(this@PrinterConfigActivity, "Print successful!", Toast.LENGTH_SHORT).show()
                    }
                } else {
                    withContext(Dispatchers.Main) {
                        progressBar.visibility = View.GONE
                        tvStatus.text = "No Bluetooth printer found"
                        btnTestBluetooth.isEnabled = true
                        Toast.makeText(this@PrinterConfigActivity, "No Bluetooth printer paired", Toast.LENGTH_SHORT).show()
                    }
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    progressBar.visibility = View.GONE
                    tvStatus.text = "Bluetooth test failed: ${e.message}"
                    btnTestBluetooth.isEnabled = true
                    Toast.makeText(this@PrinterConfigActivity, "Print failed: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private fun testTcpPrinter() {
        val ip = etTcpIp.text?.toString()?.trim()
        val portStr = etTcpPort.text?.toString()?.trim()

        if (ip.isNullOrEmpty()) {
            Toast.makeText(this, "Please enter IP address", Toast.LENGTH_SHORT).show()
            return
        }

        if (portStr.isNullOrEmpty()) {
            Toast.makeText(this, "Please enter port number", Toast.LENGTH_SHORT).show()
            return
        }

        val port = try {
            portStr.toInt()
        } catch (e: NumberFormatException) {
            Toast.makeText(this, "Invalid port number", Toast.LENGTH_SHORT).show()
            return
        }

        progressBar.visibility = View.VISIBLE
        tvStatus.text = "Testing TCP printer..."
        btnTestTcp.isEnabled = false

        lifecycleScope.launch(Dispatchers.IO) {
            try {
                // Use regular EscPosPrinter for TCP
                val connection = TcpConnection(ip, port).apply { connect(this@PrinterConfigActivity) }
                // Cast to DeviceConnection to resolve constructor ambiguity
                val printer = EscPosPrinter(connection as DeviceConnection, 203, 48f, 32)

                val testText = """
                    [C]<u><font size='big'>PRINTER TEST</font></u>
                    [L]
                    [C]================================
                    [L]
                    [C]TCP/IP Printer Test
                    [L]
                    [C]IP: $ip
                    [L]
                    [C]Port: $port
                    [L]
                    [C]This is a test print
                    [L]
                    [C]================================
                    [L]
                    [L]
                    [L]
                """.trimIndent()

                printer.printFormattedText(testText)
                printer.disconnectPrinter()

                withContext(Dispatchers.Main) {
                    // Save printer configuration
                    prefs.edit()
                        .putString("printer_type", "tcp")
                        .putString("tcp_ip", ip)
                        .putInt("tcp_port", port)
                        .apply()
                    progressBar.visibility = View.GONE
                    tvStatus.text = "TCP printer test successful!"
                    btnTestTcp.isEnabled = true
                    Toast.makeText(this@PrinterConfigActivity, "Print successful!", Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    progressBar.visibility = View.GONE
                    tvStatus.text = "TCP test failed: ${e.message}"
                    btnTestTcp.isEnabled = true
                    Toast.makeText(this@PrinterConfigActivity, "Print failed: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private fun testUsbPrinter() {
        val usbConnection = UsbPrintersConnections.selectFirstConnected(this)
        val usbManager = getSystemService(Context.USB_SERVICE) as UsbManager

        if (usbConnection == null || usbManager == null) {
            Toast.makeText(this, "No USB printer found", Toast.LENGTH_SHORT).show()
            return
        }

        val permissionIntent = PendingIntent.getBroadcast(
            this,
            0,
            Intent(ACTION_USB_PERMISSION),
            if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.M) {
                PendingIntent.FLAG_IMMUTABLE
            } else {
                0
            }
        )
        usbManager.requestPermission(usbConnection.device, permissionIntent)
    }

    private fun testUsbPrinter(usbManager: UsbManager, usbDevice: UsbDevice) {
        progressBar.visibility = View.VISIBLE
        tvStatus.text = "Testing USB printer..."
        btnTestUsb.isEnabled = false

        lifecycleScope.launch(Dispatchers.IO) {
            try {
                // Use UsbConnection as per library documentation
                val connection = UsbConnection(usbManager, usbDevice)
                // Cast to DeviceConnection to resolve constructor ambiguity
                val printer = EscPosPrinter(connection as DeviceConnection, 203, 48f, 32)

                val testText = """
                    [C]<u><font size='big'>PRINTER TEST</font></u>
                    [L]
                    [C]================================
                    [L]
                    [C]USB Printer Test
                    [L]
                    [C]This is a test print
                    [L]
                    [C]================================
                    [L]
                    [L]
                    [L]
                """.trimIndent()

                printer.printFormattedText(testText)
                printer.disconnectPrinter()

                withContext(Dispatchers.Main) {
                    // Save printer configuration
                    prefs.edit().putString("printer_type", "usb").apply()
                    progressBar.visibility = View.GONE
                    tvStatus.text = "USB printer test successful!"
                    btnTestUsb.isEnabled = true
                    Toast.makeText(this@PrinterConfigActivity, "Print successful!", Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    progressBar.visibility = View.GONE
                    tvStatus.text = "USB test failed: ${e.message}"
                    btnTestUsb.isEnabled = true
                    Toast.makeText(this@PrinterConfigActivity, "Print failed: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    override fun onDestroy() {
        super.onDestroy()
        try {
            unregisterReceiver(usbReceiver)
        } catch (e: Exception) {
            // Receiver not registered
        }
    }
}
