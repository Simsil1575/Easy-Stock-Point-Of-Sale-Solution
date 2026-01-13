package co.median.android

import android.Manifest
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.Bundle
import android.view.View
import android.widget.*
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.cardview.widget.CardView
import androidx.core.content.ContextCompat
import com.google.android.material.textfield.TextInputEditText
import org.json.JSONObject

class PrinterSettingsActivity : AppCompatActivity() {

    private lateinit var printerManager: PrinterManager
    
    // Views
    private lateinit var btnBack: ImageButton
    private lateinit var bluetoothStatusDot: View
    private lateinit var txtBluetoothStatus: TextView
    private lateinit var btnRequestBluetooth: Button
    private lateinit var rgPrinterType: RadioGroup
    private lateinit var rbAuto: RadioButton
    private lateinit var rbBluetooth: RadioButton
    private lateinit var rbUsb: RadioButton
    private lateinit var rbTcp: RadioButton
    private lateinit var cardTcpConfig: CardView
    private lateinit var etTcpIp: TextInputEditText
    private lateinit var etTcpPort: TextInputEditText
    private lateinit var printerStatusDot: View
    private lateinit var txtPrinterStatus: TextView
    private lateinit var txtPrinterDetails: TextView
    private lateinit var btnRefreshStatus: Button
    private lateinit var btnTestPrint: Button
    private lateinit var btnOpenDrawer: Button
    private lateinit var btnSaveSettings: Button
    private lateinit var cardContinueToApp: CardView
    private lateinit var btnContinueToApp: Button
    
    private var isFirstLaunch = false

    private val bluetoothPermissionLauncher = registerForActivityResult(
        ActivityResultContracts.RequestMultiplePermissions()
    ) { permissions ->
        val allGranted = permissions.values.all { it }
        updateBluetoothPermissionStatus()
        if (allGranted) {
            showToast("Bluetooth permissions granted!")
            refreshPrinterStatus()
        } else {
            showToast("Some permissions were denied")
        }
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_printer_settings)
        
        // Check if this is first launch
        isFirstLaunch = intent.getBooleanExtra("isFirstLaunch", false)
        
        printerManager = PrinterManager(this)
        
        initViews()
        loadCurrentSettings()
        setupListeners()
        updateBluetoothPermissionStatus()
        refreshPrinterStatus()
        
        // Show continue button on first launch
        if (isFirstLaunch) {
            cardContinueToApp.visibility = View.VISIBLE
            btnBack.visibility = View.GONE // Hide back button on first launch
            
            // Prevent back button on first launch
            onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
                override fun handleOnBackPressed() {
                    showToast("Please configure your printer and continue to the app")
                }
            })
        }
    }

    private fun initViews() {
        btnBack = findViewById(R.id.btnBack)
        bluetoothStatusDot = findViewById(R.id.bluetoothStatusDot)
        txtBluetoothStatus = findViewById(R.id.txtBluetoothStatus)
        btnRequestBluetooth = findViewById(R.id.btnRequestBluetooth)
        rgPrinterType = findViewById(R.id.rgPrinterType)
        rbAuto = findViewById(R.id.rbAuto)
        rbBluetooth = findViewById(R.id.rbBluetooth)
        rbUsb = findViewById(R.id.rbUsb)
        rbTcp = findViewById(R.id.rbTcp)
        cardTcpConfig = findViewById(R.id.cardTcpConfig)
        etTcpIp = findViewById(R.id.etTcpIp)
        etTcpPort = findViewById(R.id.etTcpPort)
        printerStatusDot = findViewById(R.id.printerStatusDot)
        txtPrinterStatus = findViewById(R.id.txtPrinterStatus)
        txtPrinterDetails = findViewById(R.id.txtPrinterDetails)
        btnRefreshStatus = findViewById(R.id.btnRefreshStatus)
        btnTestPrint = findViewById(R.id.btnTestPrint)
        btnOpenDrawer = findViewById(R.id.btnOpenDrawer)
        btnSaveSettings = findViewById(R.id.btnSaveSettings)
        cardContinueToApp = findViewById(R.id.cardContinueToApp)
        btnContinueToApp = findViewById(R.id.btnContinueToApp)
    }

    private fun loadCurrentSettings() {
        // Load printer type
        when (printerManager.printerType) {
            PrinterManager.PrinterType.AUTO -> rbAuto.isChecked = true
            PrinterManager.PrinterType.BLUETOOTH -> rbBluetooth.isChecked = true
            PrinterManager.PrinterType.USB -> rbUsb.isChecked = true
            PrinterManager.PrinterType.TCP -> {
                rbTcp.isChecked = true
                cardTcpConfig.visibility = View.VISIBLE
            }
        }
        
        // Load TCP settings
        etTcpIp.setText(printerManager.tcpIp)
        etTcpPort.setText(printerManager.tcpPort.toString())
    }

    private fun setupListeners() {
        btnBack.setOnClickListener {
            finish()
        }

        btnRequestBluetooth.setOnClickListener {
            requestBluetoothPermissions()
        }

        rgPrinterType.setOnCheckedChangeListener { _, checkedId ->
            cardTcpConfig.visibility = if (checkedId == R.id.rbTcp) View.VISIBLE else View.GONE
        }

        btnRefreshStatus.setOnClickListener {
            refreshPrinterStatus()
        }

        btnTestPrint.setOnClickListener {
            testPrint()
        }

        btnOpenDrawer.setOnClickListener {
            openCashDrawer()
        }

        btnSaveSettings.setOnClickListener {
            saveSettings()
        }
        
        btnContinueToApp.setOnClickListener {
            continueToApp()
        }
    }
    
    private fun continueToApp() {
        // Mark printer setup as complete
        val prefs = getSharedPreferences("printer_settings", MODE_PRIVATE)
        prefs.edit().putBoolean("printer_setup_complete", true).apply()
        
        // Launch MainActivity
        val intent = Intent(this, MainActivity::class.java)
        intent.flags = Intent.FLAG_ACTIVITY_NEW_TASK or Intent.FLAG_ACTIVITY_CLEAR_TASK
        startActivity(intent)
        finish()
    }

    private fun hasBluetoothPermission(): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_CONNECT) == PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH_SCAN) == PackageManager.PERMISSION_GRANTED
        } else {
            ContextCompat.checkSelfPermission(this, Manifest.permission.BLUETOOTH) == PackageManager.PERMISSION_GRANTED
        }
    }

    private fun requestBluetoothPermissions() {
        val permissions = if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            arrayOf(
                Manifest.permission.BLUETOOTH_CONNECT,
                Manifest.permission.BLUETOOTH_SCAN
            )
        } else {
            arrayOf(
                Manifest.permission.BLUETOOTH,
                Manifest.permission.BLUETOOTH_ADMIN
            )
        }
        bluetoothPermissionLauncher.launch(permissions)
    }

    private fun updateBluetoothPermissionStatus() {
        val hasPermission = hasBluetoothPermission()
        
        val drawable = GradientDrawable()
        drawable.shape = GradientDrawable.OVAL
        drawable.setColor(if (hasPermission) Color.parseColor("#10B981") else Color.parseColor("#EF4444"))
        bluetoothStatusDot.background = drawable
        
        txtBluetoothStatus.text = if (hasPermission) "Bluetooth: Granted ✓" else "Bluetooth: Not Granted"
        btnRequestBluetooth.visibility = if (hasPermission) View.GONE else View.VISIBLE
    }

    private fun refreshPrinterStatus() {
        txtPrinterStatus.text = "Checking..."
        
        val drawable = GradientDrawable()
        drawable.shape = GradientDrawable.OVAL
        drawable.setColor(Color.parseColor("#F59E0B"))
        printerStatusDot.background = drawable

        Thread {
            try {
                val status = printerManager.getPrinterStatus()
                val available = status.optBoolean("available", false)
                val message = status.optString("message", "Unknown")
                val printerType = status.optString("printerType", "AUTO")
                
                runOnUiThread {
                    val statusDrawable = GradientDrawable()
                    statusDrawable.shape = GradientDrawable.OVAL
                    statusDrawable.setColor(if (available) Color.parseColor("#10B981") else Color.parseColor("#EF4444"))
                    printerStatusDot.background = statusDrawable
                    
                    txtPrinterStatus.text = message
                    txtPrinterDetails.visibility = View.VISIBLE
                    txtPrinterDetails.text = "Mode: $printerType"
                }
            } catch (e: Exception) {
                runOnUiThread {
                    val errorDrawable = GradientDrawable()
                    errorDrawable.shape = GradientDrawable.OVAL
                    errorDrawable.setColor(Color.parseColor("#EF4444"))
                    printerStatusDot.background = errorDrawable
                    
                    txtPrinterStatus.text = "Error: ${e.message}"
                }
            }
        }.start()
    }

    private fun testPrint() {
        showToast("Sending test print...")
        btnTestPrint.isEnabled = false
        
        Thread {
            try {
                val testReceipt = JSONObject().apply {
                    put("business_name", "PRINTER TEST")
                    put("order_id", "TEST-${System.currentTimeMillis() % 1000}")
                    put("cashier_username", "Settings Test")
                    put("payment_method", "cash")
                    put("cash_received", 100.00)
                    put("footer_text", "Test print successful!")
                    
                    val items = org.json.JSONArray()
                    val item = JSONObject().apply {
                        put("name", "Test Item")
                        put("quantity", 2)
                        put("price", 50.00)
                    }
                    items.put(item)
                    put("items", items)
                }
                
                printerManager.printReceipt(testReceipt, object : PrinterManager.PrintCallback {
                    override fun onSuccess(message: String) {
                        runOnUiThread {
                            showToast("✓ Test print successful!")
                            btnTestPrint.isEnabled = true
                        }
                    }

                    override fun onError(error: String) {
                        runOnUiThread {
                            showToast("✗ Print failed: $error")
                            btnTestPrint.isEnabled = true
                        }
                    }
                })
            } catch (e: Exception) {
                runOnUiThread {
                    showToast("Error: ${e.message}")
                    btnTestPrint.isEnabled = true
                }
            }
        }.start()
    }

    private fun openCashDrawer() {
        showToast("Opening cash drawer...")
        btnOpenDrawer.isEnabled = false
        
        Thread {
            try {
                val drawerCommand = JSONObject().apply {
                    put("open_drawer_only", true)
                }
                
                printerManager.printReceipt(drawerCommand, object : PrinterManager.PrintCallback {
                    override fun onSuccess(message: String) {
                        runOnUiThread {
                            showToast("✓ Cash drawer opened!")
                            btnOpenDrawer.isEnabled = true
                        }
                    }

                    override fun onError(error: String) {
                        runOnUiThread {
                            showToast("✗ Failed: $error")
                            btnOpenDrawer.isEnabled = true
                        }
                    }
                })
            } catch (e: Exception) {
                runOnUiThread {
                    showToast("Error: ${e.message}")
                    btnOpenDrawer.isEnabled = true
                }
            }
        }.start()
    }

    private fun saveSettings() {
        val printerType = when (rgPrinterType.checkedRadioButtonId) {
            R.id.rbAuto -> PrinterManager.PrinterType.AUTO
            R.id.rbBluetooth -> PrinterManager.PrinterType.BLUETOOTH
            R.id.rbUsb -> PrinterManager.PrinterType.USB
            R.id.rbTcp -> PrinterManager.PrinterType.TCP
            else -> PrinterManager.PrinterType.AUTO
        }
        
        val tcpIp = etTcpIp.text?.toString() ?: ""
        val tcpPort = etTcpPort.text?.toString()?.toIntOrNull() ?: 9100
        
        // Default to 58mm for small printers (most Android thermal printers are 58mm)
        // User can change this in settings if they have an 80mm printer
        val printerSize = "58mm" // Default to 58mm to match receipt58.php
        
        printerManager.setPrinterConfig(printerType, tcpIp, tcpPort, "", printerSize)
        
        showToast("✓ Settings saved! (58mm printer)")
        refreshPrinterStatus()
        
        // On first launch, show continue button after saving
        if (isFirstLaunch && cardContinueToApp.visibility != View.VISIBLE) {
            cardContinueToApp.visibility = View.VISIBLE
        }
    }

    private fun showToast(message: String) {
        Toast.makeText(this, message, Toast.LENGTH_SHORT).show()
    }

    companion object {
        fun start(context: android.content.Context) {
            val intent = Intent(context, PrinterSettingsActivity::class.java)
            intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
            context.startActivity(intent)
        }
    }
}
