package co.median.android

import android.bluetooth.BluetoothAdapter
import android.bluetooth.BluetoothDevice
import android.bluetooth.BluetoothSocket
import android.content.Context
import android.hardware.usb.UsbDevice
import android.hardware.usb.UsbManager
import android.util.Log
import kotlinx.coroutines.*
import org.json.JSONArray
import org.json.JSONObject
import java.io.IOException
import java.io.OutputStream
import java.net.InetSocketAddress
import java.net.Socket
import java.text.SimpleDateFormat
import java.util.*

/**
 * PrinterManager handles ESC/POS thermal printer connectivity and receipt printing.
 * Matches the exact format of receipt.php for consistent output.
 */
class PrinterManager(private val context: Context) {
    
    companion object {
        private const val TAG = "PrinterManager"
        
        // Printer width constants
        private const val PRINTER_WIDTH_48MM = 24 // 48mm thermal printer (smallest)
        private const val PRINTER_WIDTH_58MM = 32 // 58mm thermal printer (matches receipt58.php)
        private const val PRINTER_WIDTH_80MM = 42 // 80mm thermal printer (matches receipt.php)
        
        // Default to 58mm for small printers (most common Android thermal printers)
        @Volatile
        private var currentPrinterWidth = PRINTER_WIDTH_58MM
        
        // ESC/POS Commands
        private val ESC = byteArrayOf(0x1B)
        private val GS = byteArrayOf(0x1D)
        private val LF = byteArrayOf(0x0A)
        
        // Initialize printer
        private val INIT = byteArrayOf(0x1B, 0x40)
        
        // Text formatting
        private val BOLD_ON = byteArrayOf(0x1B, 0x45, 0x01)
        private val BOLD_OFF = byteArrayOf(0x1B, 0x45, 0x00)
        private val DOUBLE_ON = byteArrayOf(0x1B, 0x21, 0x30) // Double width + height + bold
        private val DOUBLE_OFF = byteArrayOf(0x1B, 0x21, 0x00)
        
        // Alignment
        private val ALIGN_LEFT = byteArrayOf(0x1B, 0x61, 0x00)
        private val ALIGN_CENTER = byteArrayOf(0x1B, 0x61, 0x01)
        private val ALIGN_RIGHT = byteArrayOf(0x1B, 0x61, 0x02)
        
        // Paper commands
        private val CUT_PAPER = byteArrayOf(0x1D, 0x56, 0x00) // Full cut
        private val PARTIAL_CUT = byteArrayOf(0x1D, 0x56, 0x01)
        
        // Cash drawer
        private val OPEN_DRAWER = byteArrayOf(0x1B, 0x70, 0x00, 0x19, 0x78) // Pulse pin 2
        private val OPEN_DRAWER_ALT = byteArrayOf(0x1B, 0x70, 0x01, 0x19, 0x78) // Pulse pin 5
        
        // Feed
        private val FEED_LINE = byteArrayOf(0x0A)
    }
    
    enum class PrinterType {
        AUTO, BLUETOOTH, USB, TCP
    }
    
    interface PrintCallback {
        fun onSuccess(message: String)
        fun onError(error: String)
    }
    
    var printerType: PrinterType = PrinterType.AUTO
        private set
    var tcpIp: String = ""
        private set
    var tcpPort: Int = 9100
        private set
    var bluetoothAddress: String = ""
        private set
    
    // Printer size detection (58mm or 80mm)
    var printerSize: String = "58mm" // Default to 58mm for small printers
        private set
    
    private val prefs = context.getSharedPreferences("printer_settings", Context.MODE_PRIVATE)
    
    init {
        loadSettings()
    }
    
    private fun loadSettings() {
        printerType = PrinterType.valueOf(prefs.getString("printer_type", "AUTO") ?: "AUTO")
        tcpIp = prefs.getString("tcp_ip", "") ?: ""
        tcpPort = prefs.getInt("tcp_port", 9100)
        bluetoothAddress = prefs.getString("bluetooth_address", "") ?: ""
        printerSize = prefs.getString("printer_size", "58mm") ?: "58mm"
        
        // Update printer width based on size
        currentPrinterWidth = when (printerSize) {
            "48mm" -> PRINTER_WIDTH_48MM
            "80mm" -> PRINTER_WIDTH_80MM
            else -> PRINTER_WIDTH_58MM // Default to 58mm
        }
        Log.d(TAG, "Printer size: $printerSize, Width: $currentPrinterWidth")
    }
    
    fun setPrinterConfig(type: PrinterType, ip: String, port: Int, btAddress: String, size: String = "58mm") {
        printerType = type
        tcpIp = ip
        tcpPort = port
        bluetoothAddress = btAddress
        printerSize = size
        
        // Update printer width based on size
        currentPrinterWidth = if (printerSize == "80mm") PRINTER_WIDTH_80MM else PRINTER_WIDTH_58MM
        
        prefs.edit().apply {
            putString("printer_type", type.name)
            putString("tcp_ip", ip)
            putInt("tcp_port", port)
            putString("bluetooth_address", btAddress)
            putString("printer_size", size)
            apply()
        }
        
        Log.d(TAG, "Printer config updated: type=$type, size=$size, width=$currentPrinterWidth")
    }
    
    fun getPrinterStatus(): JSONObject {
        return JSONObject().apply {
            put("type", printerType.name)
            put("tcp_ip", tcpIp)
            put("tcp_port", tcpPort)
            put("bluetooth_address", bluetoothAddress)
            put("configured", isConfigured())
        }
    }
    
    fun getAvailablePrinters(): JSONObject {
        val result = JSONObject()
        val bluetoothPrinters = JSONArray()
        val usbPrinters = JSONArray()
        
        // Get Bluetooth devices
        try {
            val bluetoothAdapter = BluetoothAdapter.getDefaultAdapter()
            if (bluetoothAdapter != null && bluetoothAdapter.isEnabled) {
                val pairedDevices = bluetoothAdapter.bondedDevices
                for (device in pairedDevices) {
                    val deviceInfo = JSONObject()
                    deviceInfo.put("name", device.name ?: "Unknown")
                    deviceInfo.put("address", device.address)
                    bluetoothPrinters.put(deviceInfo)
                }
            }
        } catch (e: SecurityException) {
            Log.e(TAG, "Bluetooth permission denied", e)
        }
        
        // Get USB devices
        try {
            val usbManager = context.getSystemService(Context.USB_SERVICE) as UsbManager
            for (device in usbManager.deviceList.values) {
                val deviceInfo = JSONObject()
                deviceInfo.put("name", device.productName ?: "USB Device")
                deviceInfo.put("vendorId", device.vendorId)
                deviceInfo.put("productId", device.productId)
                usbPrinters.put(deviceInfo)
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error getting USB devices", e)
        }
        
        result.put("bluetooth", bluetoothPrinters)
        result.put("usb", usbPrinters)
        return result
    }
    
    private fun isConfigured(): Boolean {
        return when (printerType) {
            PrinterType.TCP -> tcpIp.isNotEmpty()
            PrinterType.BLUETOOTH -> bluetoothAddress.isNotEmpty()
            PrinterType.USB -> true
            PrinterType.AUTO -> true
        }
    }
    
    /**
     * Main print function - routes to appropriate handler based on receipt type
     */
    fun printReceipt(receiptData: JSONObject, callback: PrintCallback) {
        CoroutineScope(Dispatchers.IO).launch {
            try {
                Log.d(TAG, "printReceipt called with data: ${receiptData.toString().take(500)}")
                
                // Check for open drawer only command
                if (receiptData.optBoolean("open_drawer_only", false)) {
                    openCashDrawer(callback)
                    return@launch
                }
                
                // Log business name for debugging
                val businessNameRaw = receiptData.opt("business_name")
                val extractedName = getBusinessName(receiptData)
                val currentWidth = getPrinterWidth()
                Log.d(TAG, "=== PRINT RECEIPT DEBUG ===")
                Log.d(TAG, "Business name raw type: ${businessNameRaw?.javaClass?.simpleName}, value: $businessNameRaw")
                Log.d(TAG, "Business name extracted: '$extractedName'")
                Log.d(TAG, "Printer width: $currentWidth (${if (currentWidth == PRINTER_WIDTH_48MM) "48mm" else if (currentWidth == PRINTER_WIDTH_58MM) "58mm" else "80mm"})")
                Log.d(TAG, "Receipt data keys: ${receiptData.keys().asSequence().joinToString()}")
                
                // Log items array for debugging
                val items = receiptData.optJSONArray("items")
                Log.d(TAG, "Items array: ${items?.length() ?: 0} items")
                Log.d(TAG, "Has items key: ${receiptData.has("items")}")
                
                if (items != null && items.length() > 0) {
                    for (i in 0 until items.length()) {
                        try {
                            val item = items.getJSONObject(i)
                            Log.d(TAG, "Item $i: name=${item.optString("name")}, qty=${item.optInt("quantity")}, price=${item.optDouble("price")}")
                        } catch (e: Exception) {
                            Log.e(TAG, "Error reading item $i", e)
                        }
                    }
                } else {
                    Log.w(TAG, "Items array is null, empty, or missing!")
                    // Try to log the raw JSON to see what we're getting
                    Log.d(TAG, "Raw receipt data (first 1000 chars): ${receiptData.toString().take(1000)}")
                }
                Log.d(TAG, "===========================")
                
                // Generate receipt bytes based on type
                val receiptBytes = when {
                    receiptData.optBoolean("is_cashup_report", false) -> 
                        formatCashUpReceipt(receiptData)
                    receiptData.optBoolean("is_balance_receipt", false) -> 
                        formatBalanceReceipt(receiptData)
                    receiptData.optBoolean("is_tab_balance_receipt", false) -> 
                        formatTabBalanceReceipt(receiptData)
                    receiptData.has("table_id") || receiptData.has("tab_id") -> 
                        if (receiptData.optBoolean("is_payment_receipt", false))
                            formatPaymentReceipt(receiptData)
                        else
                            formatKitchenTicket(receiptData)
                    else -> 
                        formatRegularReceipt(receiptData)
                }
                
                // Send to printer
                val success = sendToPrinter(receiptBytes)
                
                withContext(Dispatchers.Main) {
                    if (success) {
                        callback.onSuccess("Receipt printed successfully")
                    } else {
                        callback.onError("Failed to send to printer")
                    }
                }
                
            } catch (e: Exception) {
                Log.e(TAG, "Print error", e)
                withContext(Dispatchers.Main) {
                    callback.onError("Print error: ${e.message}")
                }
            }
        }
    }
    
    /**
     * Open cash drawer without printing
     */
    private suspend fun openCashDrawer(callback: PrintCallback) {
        try {
            val commands = ByteArray(0) + INIT + OPEN_DRAWER + OPEN_DRAWER_ALT
            val success = sendToPrinter(commands)
            
            withContext(Dispatchers.Main) {
                if (success) {
                    callback.onSuccess("Cash drawer opened")
                } else {
                    callback.onError("Failed to open cash drawer")
                }
            }
        } catch (e: Exception) {
            withContext(Dispatchers.Main) {
                callback.onError("Drawer error: ${e.message}")
            }
        }
    }
    
    /**
     * Format Cash-Up (Z-Report) Receipt - matches receipt58.php for 58mm, receipt.php for 80mm
     */
    private fun formatCashUpReceipt(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        val width = getPrinterWidth()
        val businessName = getBusinessName(data)
        
        // Initialize
        output.addAll(INIT.toList())
        
        // Business name - large centered
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(DOUBLE_ON.toList())
        output.addAll(formatBusinessNameForPrint(businessName, width))
        output.addAll(DOUBLE_OFF.toList())
        
        // Z-REPORT header
        output.addAll(BOLD_ON.toList())
        output.addAll("Z-REPORT\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("-", getPrinterWidth()) + "\n").toByteArray().toList())
        
        // Left align for details
        output.addAll(ALIGN_LEFT.toList())
        output.addAll("Date: ${data.optString("date", getCurrentDate())}\n".toByteArray().toList())
        output.addAll("Time: ${getCurrentTime()}\n".toByteArray().toList())
        output.addAll("Cashier: ${data.optString("cashier_username", "N/A")}\n".toByteArray().toList())
        output.addAll((repeat("-", getPrinterWidth()) + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        
        // Sales totals
        val cashSales = data.optDouble("cash_sales", data.optDouble("total_cash_sales", 0.0))
        val eftSales = data.optDouble("eft_sales", data.optDouble("total_eft_sales", 0.0))
        val grandTotal = data.optDouble("grand_total", data.optDouble("total_income", cashSales + eftSales))
        
        output.addAll(formatLine("CASH SALES:", formatCurrency(cashSales)).toByteArray().toList())
        output.addAll(formatLine("EFT SALES:", formatCurrency(eftSales)).toByteArray().toList())
        output.addAll((repeat("-", getPrinterWidth()) + "\n").toByteArray().toList())
        
        output.addAll(BOLD_ON.toList())
        output.addAll(formatLine("TOTAL SALES:", formatCurrency(grandTotal)).toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("=", getPrinterWidth()) + "\n").toByteArray().toList())
        
        // Shortage/Surplus
        if (data.has("cash_difference")) {
            val difference = data.optDouble("cash_difference", 0.0)
            if (difference != 0.0) {
                output.addAll(FEED_LINE.toList())
                output.addAll(BOLD_ON.toList())
                val label = if (difference > 0) "SURPLUS:" else "SHORTAGE:"
                output.addAll(formatLine(label, formatCurrency(kotlin.math.abs(difference))).toByteArray().toList())
                output.addAll(BOLD_OFF.toList())
            }
        }
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll(ALIGN_CENTER.toList())
        output.addAll("End of Report\n".toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        // Cut and open drawer
        output.addAll(CUT_PAPER.toList())
        output.addAll(OPEN_DRAWER.toList())
        
        return output.toByteArray()
    }
    
    /**
     * Format Tab Balance Receipt - matches receipt58.php for 58mm
     */
    private fun formatTabBalanceReceipt(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        val businessName = getBusinessName(data)
        val width = getPrinterWidth()
        
        output.addAll(INIT.toList())
        
        // Header
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(DOUBLE_ON.toList())
        output.addAll(formatBusinessNameForPrint(businessName, width))
        output.addAll(DOUBLE_OFF.toList())
        
        output.addAll(BOLD_ON.toList())
        output.addAll("TAB BALANCE RECEIPT\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(ALIGN_LEFT.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll("Tab: ${data.optString("tab_name", "N/A")}\n".toByteArray().toList())
        
        val creditorName = data.optString("creditor_name", "")
        if (creditorName.isNotEmpty() && creditorName != "N/A") {
            output.addAll("Client: $creditorName\n".toByteArray().toList())
        }
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        
        // Items
        val items = data.optJSONArray("items")
        if (items != null && items.length() > 0) {
            output.addAll((repeat("-", width) + "\n").toByteArray().toList())
            
            var itemsTotal = 0.0
            for (i in 0 until items.length()) {
                val item = items.getJSONObject(i)
                // Truncate name based on printer width (32 for 58mm, 42 for 80mm)
                val maxNameLength = if (width == PRINTER_WIDTH_58MM) 32 else 42
                val name = truncate(item.optString("name", "Item"), maxNameLength)
                val qty = item.optInt("quantity", 1)
                val unitPrice = item.optDouble("unit_price", item.optDouble("price", 0.0) / qty)
                val itemTotal = item.optDouble("price", 0.0)
                itemsTotal += itemTotal
                
                output.addAll("$name\n".toByteArray().toList())
                // Match receipt58.php format: sprintf(" %dx N$%.2f = N$%.2f\n", $qty, $unitPrice, $itemTotal)
                output.addAll(" ${qty}x ${formatCurrency(unitPrice)} = ${formatCurrency(itemTotal)}\n".toByteArray().toList())
            }
            
            output.addAll((repeat("-", width) + "\n").toByteArray().toList())
            output.addAll(BOLD_ON.toList())
            output.addAll(formatLine("ITEMS TOTAL:", formatCurrency(itemsTotal)).toByteArray().toList())
            output.addAll(BOLD_OFF.toList())
            output.addAll((repeat("-", width) + "\n").toByteArray().toList())
            output.addAll(FEED_LINE.toList())
        }
        
        // Total balance - match receipt58.php format
        output.addAll(BOLD_ON.toList())
        output.addAll((repeat("=", width) + "\n").toByteArray().toList())
        if (width == PRINTER_WIDTH_58MM) {
            // 58mm format: sprintf("%-20s%11s\n", "OUTSTANDING:", "N$" . number_format($orderData['total_balance'], 2))
            val label = String.format("%-20s", "OUTSTANDING:")
            val value = String.format("%11s", formatCurrency(data.optDouble("total_balance", 0.0)))
            output.addAll("$label$value\n".toByteArray().toList())
        } else {
            output.addAll(formatLine("OUTSTANDING BALANCE:", formatCurrency(data.optDouble("total_balance", 0.0))).toByteArray().toList())
        }
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("=", width) + "\n").toByteArray().toList())
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll(ALIGN_CENTER.toList())
        output.addAll((data.optString("footer_text", "Thank you!") + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(CUT_PAPER.toList())
        
        return output.toByteArray()
    }
    
    /**
     * Format Credit Sale Balance Receipt
     */
    private fun formatBalanceReceipt(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        val businessName = getBusinessName(data)
        val width = getPrinterWidth()
        
        output.addAll(INIT.toList())
        
        // Header
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(DOUBLE_ON.toList())
        output.addAll(formatBusinessNameForPrint(businessName, width))
        output.addAll(DOUBLE_OFF.toList())
        
        output.addAll(BOLD_ON.toList())
        output.addAll("BALANCE RECEIPT\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(ALIGN_LEFT.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll("Client: ${data.optString("creditor_name", "")}\n".toByteArray().toList())
        output.addAll(formatLine("Balance:", formatCurrency(data.optDouble("total_balance", 0.0))).toByteArray().toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll(ALIGN_CENTER.toList())
        output.addAll((data.optString("footer_text", "Thank you!") + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(CUT_PAPER.toList())
        
        return output.toByteArray()
    }
    
    /**
     * Format Kitchen Ticket (Tab/Table orders) - matches receipt.php kitchen ticket format
     */
    private fun formatKitchenTicket(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        
        output.addAll(INIT.toList())
        
        // Get receipt number
        val receiptNumber = data.optString("order_id", 
            data.optString("tab_id", 
            data.optString("table_id", "N/A")))
        
        // Header - ORDER!! format
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(BOLD_ON.toList())
        output.addAll("ORDER!!  No: $receiptNumber\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(ALIGN_LEFT.toList())
        
        // Table name
        val tableName = truncate(data.optString("table_name", "Table ${data.optString("table_id", data.optString("tab_id", "N/A"))}"), 30)
        output.addAll("Table : $tableName\n".toByteArray().toList())
        
        // Time
        output.addAll("Time : ${getCurrentDateTime()}\n".toByteArray().toList())
        
        // Cashier
        output.addAll("By : ${data.optString("cashier_username", "Cashier")}\n".toByteArray().toList())
        
        output.addAll(FEED_LINE.toList())
        
        // Items header
        output.addAll(BOLD_ON.toList())
        output.addAll("ITEMS\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        
        // Items - simple format: "x1 Item Name"
        val width = getPrinterWidth()
        val items = data.optJSONArray("items")
        if (items != null) {
            for (i in 0 until items.length()) {
                val item = items.getJSONObject(i)
                // Truncate name based on printer width (35 for 58mm, 42 for 80mm)
                val maxNameLength = if (width == PRINTER_WIDTH_58MM) 35 else 42
                val name = truncate(item.optString("name", "Item"), maxNameLength)
                val qty = item.optInt("quantity", 1)
                output.addAll("x$qty $name\n".toByteArray().toList())
            }
        }
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(CUT_PAPER.toList())
        
        return output.toByteArray()
    }
    
    /**
     * Format Payment Receipt - matches receipt58.php for 58mm, receipt.php for 80mm
     */
    private fun formatPaymentReceipt(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        val businessName = getBusinessName(data)
        val location = data.optString("location", "")
        val phone = data.optString("phone", "")
        val width = getPrinterWidth()
        
        output.addAll(INIT.toList())
        
        // Open drawer for cash/mixed payments
        val paymentMethod = data.optString("payment_method", "cash")
        if (paymentMethod != "e-wallet" && paymentMethod != "credit") {
            output.addAll(OPEN_DRAWER.toList())
        }
        
        // Header
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(DOUBLE_ON.toList())
        output.addAll(formatBusinessNameForPrint(businessName, width))
        output.addAll(DOUBLE_OFF.toList())
        
        output.addAll(BOLD_ON.toList())
        output.addAll((location + "\n").toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll("Tel: $phone\n".toByteArray().toList())
        output.addAll("Cashier: ${data.optString("cashier_username", "Cashier")}\n".toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(ALIGN_LEFT.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        val receiptNumber = data.optString("order_id", data.optString("tab_id", data.optString("table_id", "N/A")))
        output.addAll(BOLD_ON.toList())
        output.addAll("Payment Bill Receipt #: $receiptNumber\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll("Date: ${getCurrentDateTime()}\n".toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        
        // Items header - adjust format based on printer width
        output.addAll(BOLD_ON.toList())
        if (width == PRINTER_WIDTH_58MM) {
            // 58mm format: sprintf("%-18s %3s %8s\n", "Item", "Qty", "Amount")
            output.addAll(String.format("%-18s %3s %8s\n", "Item", "Qty", "Amount").toByteArray().toList())
        } else {
            // 80mm format
            output.addAll(String.format("%-20s %3s %9s\n", "Item", "Qty", "Amount").toByteArray().toList())
        }
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        // Items
        var subtotal = 0.0
        val items = data.optJSONArray("items")
        if (items != null) {
            for (i in 0 until items.length()) {
                val item = items.getJSONObject(i)
                // Truncate name based on printer width (32 for 58mm, 42 for 80mm)
                val maxNameLength = if (width == PRINTER_WIDTH_58MM) 32 else 42
                val name = truncate(item.optString("name", "Item"), maxNameLength)
                val qty = item.optInt("quantity", 1)
                val price = item.optDouble("price", 0.0)
                val unitPrice = price / qty
                subtotal += price
                
                output.addAll("$name\n".toByteArray().toList())
                val qtyPrice = "$qty x ${formatCurrency(unitPrice)}"
                val amountText = formatCurrency(price)
                val spaces = width - qtyPrice.length - amountText.length
                output.addAll("$qtyPrice${" ".repeat(maxOf(1, spaces))}$amountText\n".toByteArray().toList())
                output.addAll((repeat("-", width) + "\n").toByteArray().toList())
            }
        }
        
        // Total - match receipt58.php format for 58mm
        output.addAll(FEED_LINE.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(BOLD_ON.toList())
        if (width == PRINTER_WIDTH_58MM) {
            // 58mm format: sprintf("%-20s%11s\n", "PAID AMOUNT:", "N$" . number_format($subtotal, 2))
            val totalLabel = String.format("%-20s", "PAID AMOUNT:")
            val totalValue = String.format("%11s", formatCurrency(subtotal))
            output.addAll("$totalLabel$totalValue\n".toByteArray().toList())
        } else {
            val totalText = "PAID AMOUNT: ${formatCurrency(subtotal)}"
            val spaces = width - totalText.length
            output.addAll((" ".repeat(maxOf(0, spaces)) + totalText + "\n").toByteArray().toList())
        }
        output.addAll(BOLD_OFF.toList())
        output.addAll(FEED_LINE.toList())
        
        // Payment information
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(BOLD_ON.toList())
        output.addAll("PAYMENT INFORMATION\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        when (paymentMethod) {
            "cash" -> {
                output.addAll("Method: Cash\n".toByteArray().toList())
                val cashReceived = data.optDouble("cash_received", subtotal)
                output.addAll(formatLine("Paid:", formatCurrency(cashReceived)).toByteArray().toList())
                val change = maxOf(0.0, cashReceived - subtotal)
                if (change > 0) {
                    output.addAll(formatLine("Change:", formatCurrency(change)).toByteArray().toList())
                }
            }
            "eft", "e-wallet" -> {
                output.addAll("Method: EFT\n".toByteArray().toList())
                val provider = data.optString("wallet_provider", "")
                if (provider.isNotEmpty()) {
                    if (width == PRINTER_WIDTH_58MM) {
                        output.addAll("Provider: $provider\n".toByteArray().toList())
                    } else {
                        output.addAll(formatLine("Provider:", provider).toByteArray().toList())
                    }
                }
                val ref = truncate(data.optString("transaction_ref", ""), if (width == PRINTER_WIDTH_58MM) 20 else 30)
                if (ref.isNotEmpty()) {
                    if (width == PRINTER_WIDTH_58MM) {
                        output.addAll("Ref: $ref\n".toByteArray().toList())
                    } else {
                        output.addAll(formatLine("Ref:", ref).toByteArray().toList())
                    }
                }
                output.addAll(formatLine("Paid:", formatCurrency(subtotal)).toByteArray().toList())
            }
            "mixed" -> {
                output.addAll("Method: Mixed Payment\n".toByteArray().toList())
                output.addAll((repeat("-", width) + "\n").toByteArray().toList())
                
                val cashAmount = data.optDouble("cash_amount", 0.0)
                if (cashAmount > 0) {
                    output.addAll(formatLine("Cash:", formatCurrency(cashAmount)).toByteArray().toList())
                }
                
                val eftAmount = data.optDouble("eft_amount", 0.0)
                if (eftAmount > 0) {
                    output.addAll(formatLine("EFT:", formatCurrency(eftAmount)).toByteArray().toList())
                    val provider = data.optString("eft_wallet_provider", data.optString("wallet_provider", ""))
                    if (provider.isNotEmpty()) {
                        if (width == PRINTER_WIDTH_58MM) {
                            output.addAll("Provider: $provider\n".toByteArray().toList())
                        } else {
                            output.addAll(formatLine("Provider:", provider).toByteArray().toList())
                        }
                    }
                    val ref = truncate(data.optString("transaction_ref", ""), if (width == PRINTER_WIDTH_58MM) 20 else 30)
                    if (ref.isNotEmpty()) {
                        if (width == PRINTER_WIDTH_58MM) {
                            output.addAll("Ref: $ref\n".toByteArray().toList())
                        } else {
                            output.addAll(formatLine("Ref:", ref).toByteArray().toList())
                        }
                    }
                }
                
                output.addAll((repeat("-", width) + "\n").toByteArray().toList())
                output.addAll(formatLine("Total:", formatCurrency(subtotal)).toByteArray().toList())
            }
        }
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(ALIGN_CENTER.toList())
        output.addAll((data.optString("footer_text", "Thank you for your purchase!") + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(CUT_PAPER.toList())
        
        return output.toByteArray()
    }
    
    /**
     * Format Regular Receipt - matches receipt.php regular receipt format
     */
    private fun formatRegularReceipt(data: JSONObject): ByteArray {
        val output = mutableListOf<Byte>()
        val businessName = getBusinessName(data)
        val location = data.optString("location", "")
        val phone = data.optString("phone", "")
        val width = getPrinterWidth()
        
        Log.d(TAG, "formatRegularReceipt - Business name: '$businessName'")
        
        output.addAll(INIT.toList())
        
        // Open drawer for cash payments
        val paymentMethod = data.optString("payment_method", "cash")
        if (paymentMethod != "e-wallet" && paymentMethod != "credit") {
            output.addAll(OPEN_DRAWER.toList())
        }
        
        // Header
        output.addAll(ALIGN_CENTER.toList())
        output.addAll(DOUBLE_ON.toList())
        output.addAll(formatBusinessNameForPrint(businessName, width))
        output.addAll(DOUBLE_OFF.toList())
        
        output.addAll(BOLD_ON.toList())
        output.addAll((location + "\n").toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll("Tel: $phone\n".toByteArray().toList())
        output.addAll("Cashier: ${data.optString("cashier_username", "Cashier")}\n".toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(ALIGN_LEFT.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        // Receipt number
        val receiptNumber = data.optString("order_id", data.optString("sale_id", UUID.randomUUID().toString().take(8)))
        val receiptType = if (data.has("sale_id")) "Credit Sale" else "Receipt"
        output.addAll(BOLD_ON.toList())
        output.addAll("$receiptType #: $receiptNumber\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll("Date: ${getCurrentDateTime()}\n".toByteArray().toList())
        
        // Order type if present
        if (data.has("order_type")) {
            val typeLabel = if (data.optString("order_type").lowercase() == "takeaway") "Takeaway" else "Dine-in"
            output.addAll("Order: $typeLabel\n".toByteArray().toList())
        }
        output.addAll(FEED_LINE.toList())
        
        // Items header - adjust format based on printer width
        output.addAll(BOLD_ON.toList())
        if (width == PRINTER_WIDTH_58MM) {
            // 58mm format: sprintf("%-18s %3s %8s\n", "Item", "Qty", "Amount")
            output.addAll(String.format("%-18s %3s %8s\n", "Item", "Qty", "Amount").toByteArray().toList())
        } else {
            // 80mm format
            output.addAll(String.format("%-20s %3s %9s\n", "Item", "Qty", "Amount").toByteArray().toList())
        }
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        // Items
        var subtotal = 0.0
        val items = data.optJSONArray("items")
        Log.d(TAG, "formatRegularReceipt - Items array: ${items?.length() ?: 0} items, Width: $width")
        
        if (items != null && items.length() > 0) {
            for (i in 0 until items.length()) {
                try {
                    val item = items.getJSONObject(i)
                    val name = item.optString("name", "Item")
                    val qty = item.optInt("quantity", 1)
                    val price = item.optDouble("price", 0.0)
                    
                    Log.d(TAG, "Processing item $i: name=$name, qty=$qty, price=$price")
                    
                    if (name.isNotEmpty() && price > 0) {
                        // Truncate name based on printer width (32 for 58mm, 42 for 80mm)
                        val maxNameLength = if (width == PRINTER_WIDTH_58MM) 32 else 42
                        val truncatedName = truncate(name, maxNameLength)
                        val unitPrice = if (qty > 0) price / qty else price
                        subtotal += price
                        
                        output.addAll("$truncatedName\n".toByteArray().toList())
                        val qtyPrice = "$qty x ${formatCurrency(unitPrice)}"
                        val amountText = formatCurrency(price)
                        val spaces = width - qtyPrice.length - amountText.length
                        output.addAll("$qtyPrice${" ".repeat(maxOf(1, spaces))}$amountText\n".toByteArray().toList())
                        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
                    } else {
                        Log.w(TAG, "Skipping invalid item $i: name=$name, price=$price")
                    }
                } catch (e: Exception) {
                    Log.e(TAG, "Error processing item $i", e)
                }
            }
        } else {
            Log.w(TAG, "No items found in receipt data!")
            // Still show header but indicate no items
            output.addAll("(No items)\n".toByteArray().toList())
        }
        
        Log.d(TAG, "formatRegularReceipt - Subtotal calculated: $subtotal")
        
        // Use total from data if available and items were missing/empty
        if (subtotal == 0.0 && data.has("total")) {
            subtotal = data.optDouble("total", 0.0)
            Log.d(TAG, "Using total from data: $subtotal")
        }
        if (subtotal == 0.0 && data.has("cash_received")) {
            subtotal = data.optDouble("cash_received", 0.0)
            Log.d(TAG, "Using cash_received as total: $subtotal")
        }
        
        // Totals
        output.addAll(FEED_LINE.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        // VAT handling
        val vatInclusive = data.optString("vat_inclusive", "exclusive")
        val vatRate = data.optDouble("vat_rate", 15.0)
        
        if (vatInclusive == "inclusive") {
            val vatAmount = subtotal - (subtotal / (1 + (vatRate / 100)))
            val displaySubtotal = subtotal - vatAmount
            output.addAll(formatLine("Subtotal (ex VAT):", formatCurrency(displaySubtotal)).toByteArray().toList())
            output.addAll(formatLine("VAT (${String.format("%.2f", vatRate)}%):", formatCurrency(vatAmount)).toByteArray().toList())
            output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        }
        
        // Total - use 58mm format if applicable
        output.addAll(BOLD_ON.toList())
        if (width == PRINTER_WIDTH_58MM) {
            // 58mm format: sprintf("%-20s%11s\n", "TOTAL:", "N$" . number_format($subtotal, 2))
            val totalLabel = String.format("%-20s", "TOTAL:")
            val totalValue = String.format("%11s", formatCurrency(subtotal))
            output.addAll("$totalLabel$totalValue\n".toByteArray().toList())
        } else {
            // 80mm format
            val totalText = "TOTAL: ${formatCurrency(subtotal)}"
            val spaces = width - totalText.length
            output.addAll((" ".repeat(maxOf(0, spaces)) + totalText + "\n").toByteArray().toList())
        }
        output.addAll(BOLD_OFF.toList())
        output.addAll(FEED_LINE.toList())
        
        // Payment information
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(BOLD_ON.toList())
        output.addAll("PAYMENT INFORMATION\n".toByteArray().toList())
        output.addAll(BOLD_OFF.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        
        when {
            data.has("creditor_id") -> {
                output.addAll("Method: Credit\n".toByteArray().toList())
                output.addAll(formatLine("ID:", data.optString("creditor_id", "")).toByteArray().toList())
                val creditorName = data.optString("creditor_name", "")
                if (creditorName.isNotEmpty()) {
                    if (width == PRINTER_WIDTH_58MM) {
                        output.addAll("Name: $creditorName\n".toByteArray().toList())
                    } else {
                        output.addAll(formatLine("Name:", creditorName).toByteArray().toList())
                    }
                }
                val dueDate = data.optString("due_date", "")
                if (dueDate.isNotEmpty()) {
                    if (width == PRINTER_WIDTH_58MM) {
                        output.addAll("Due: $dueDate\n".toByteArray().toList())
                    } else {
                        output.addAll(formatLine("Due:", dueDate).toByteArray().toList())
                    }
                }
            }
            paymentMethod == "e-wallet" -> {
                output.addAll("Method: EFT\n".toByteArray().toList())
                val provider = data.optString("wallet_provider", "")
                if (width == PRINTER_WIDTH_58MM) {
                    output.addAll("Provider: $provider\n".toByteArray().toList())
                } else {
                    output.addAll(formatLine("Provider:", provider).toByteArray().toList())
                }
                val ref = truncate(data.optString("transaction_ref", ""), if (width == PRINTER_WIDTH_58MM) 20 else 30)
                if (width == PRINTER_WIDTH_58MM) {
                    output.addAll("Ref: $ref\n".toByteArray().toList())
                } else {
                    output.addAll(formatLine("Ref:", ref).toByteArray().toList())
                }
                output.addAll(formatLine("Paid:", formatCurrency(subtotal)).toByteArray().toList())
            }
            paymentMethod == "mixed" -> {
                output.addAll("Method: Mixed Payment\n".toByteArray().toList())
                output.addAll((repeat("-", width) + "\n").toByteArray().toList())
                
                val cashAmount = data.optDouble("cash_amount", 0.0)
                if (cashAmount > 0) {
                    output.addAll(formatLine("Cash:", formatCurrency(cashAmount)).toByteArray().toList())
                }
                
                val eftAmount = data.optDouble("eft_amount", 0.0)
                if (eftAmount > 0) {
                    output.addAll(formatLine("EFT:", formatCurrency(eftAmount)).toByteArray().toList())
                    val provider = data.optString("wallet_provider", "")
                    if (provider.isNotEmpty()) {
                        if (width == PRINTER_WIDTH_58MM) {
                            output.addAll("Provider: $provider\n".toByteArray().toList())
                        } else {
                            output.addAll(formatLine("Provider:", provider).toByteArray().toList())
                        }
                    }
                    val ref = truncate(data.optString("transaction_ref", ""), if (width == PRINTER_WIDTH_58MM) 20 else 30)
                    if (ref.isNotEmpty()) {
                        if (width == PRINTER_WIDTH_58MM) {
                            output.addAll("Ref: $ref\n".toByteArray().toList())
                        } else {
                            output.addAll(formatLine("Ref:", ref).toByteArray().toList())
                        }
                    }
                }
                
                output.addAll((repeat("-", width) + "\n").toByteArray().toList())
                output.addAll(formatLine("Total:", formatCurrency(subtotal)).toByteArray().toList())
                
                if (cashAmount > subtotal) {
                    val change = cashAmount - subtotal
                    output.addAll(formatLine("Change:", formatCurrency(change)).toByteArray().toList())
                }
            }
            else -> {
                // Cash payment - match receipt58.php format
                output.addAll("Method: Cash\n".toByteArray().toList())
                val cashReceived = data.optDouble("cash_received", subtotal)
                if (width == PRINTER_WIDTH_58MM) {
                    // 58mm format: sprintf("%-20s%11s\n", "Paid:", "N$" . number_format($orderData['cash_received'], 2))
                    output.addAll(formatLine("Paid:", formatCurrency(cashReceived)).toByteArray().toList())
                } else {
                    output.addAll("Paid: ${formatCurrency(cashReceived)}\n".toByteArray().toList())
                }
                val change = maxOf(0.0, cashReceived - subtotal)
                if (width == PRINTER_WIDTH_58MM) {
                    // 58mm format: sprintf("%-20s%11s\n", "Change:", "N$" . number_format($change, 2))
                    output.addAll(formatLine("Change:", formatCurrency(change)).toByteArray().toList())
                } else {
                    output.addAll("Change: ${formatCurrency(change)}\n".toByteArray().toList())
                }
            }
        }
        
        // Footer
        output.addAll(FEED_LINE.toList())
        output.addAll((repeat("-", width) + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(ALIGN_CENTER.toList())
        output.addAll((data.optString("footer_text", "Thank you for your purchase!") + "\n").toByteArray().toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        output.addAll(FEED_LINE.toList())
        
        output.addAll(CUT_PAPER.toList())
        
        return output.toByteArray()
    }
    
    // ==================== Helper Functions ====================
    
    private fun formatCurrency(amount: Double): String {
        return "N$${String.format("%,.2f", amount)}"
    }
    
    private fun getPrinterWidth(): Int {
        return currentPrinterWidth
    }
    
    /**
     * Format business name for printing with proper encoding and truncation
     */
    private fun formatBusinessNameForPrint(businessName: String, width: Int): List<Byte> {
        // Truncate business name based on printer width
        // For 48mm (24 chars), truncate to 20 chars to account for double width
        // For 58mm (32 chars), truncate to 28 chars
        // For 80mm (42 chars), truncate to 38 chars
        val maxLength = when (width) {
            PRINTER_WIDTH_48MM -> 20
            PRINTER_WIDTH_58MM -> 28
            else -> 38
        }
        
        val truncatedName = if (businessName.length > maxLength) {
            businessName.take(maxLength - 3) + "..."
        } else {
            businessName
        }
        
        Log.d(TAG, "formatBusinessNameForPrint - Original: '$businessName', Truncated: '$truncatedName', Width: $width")
        
        // Encode as UTF-8 and add newline
        val nameBytes = truncatedName.toByteArray(Charsets.UTF_8).toMutableList()
        nameBytes.addAll(LF.toList())
        return nameBytes
    }
    
    /**
     * Safely extract business name as string, handling cases where it might be a number
     */
    private fun getBusinessName(data: JSONObject): String {
        return try {
            // Try multiple field names in case of naming variations
            val value = data.opt("business_name") 
                ?: data.opt("businessName")
                ?: data.opt("name")
                ?: data.opt("business_info")
            
            when (value) {
                is String -> {
                    val trimmed = value.trim()
                    if (trimmed.isEmpty() || trimmed.matches(Regex("^\\d+$"))) {
                        Log.w(TAG, "Business name is empty or numeric: '$trimmed', using default")
                        "POS SOLUTION"
                    } else {
                        Log.d(TAG, "Business name extracted: '$trimmed'")
                        trimmed
                    }
                }
                is Number -> {
                    Log.w(TAG, "Business name is a number: $value, using default")
                    "POS SOLUTION"
                }
                null -> {
                    Log.w(TAG, "Business name is null, checking alternative fields")
                    // Try to get from nested object
                    val businessInfo = data.optJSONObject("business_info")
                    if (businessInfo != null) {
                        val name = businessInfo.optString("name", "")
                        if (name.isNotEmpty() && !name.matches(Regex("^\\d+$"))) {
                            Log.d(TAG, "Business name from business_info: '$name'")
                            return name
                        }
                    }
                    "POS SOLUTION"
                }
                else -> {
                    val str = value.toString().trim()
                    if (str.isEmpty() || str.matches(Regex("^\\d+$"))) {
                        Log.w(TAG, "Business name appears to be empty or numeric: '$str', using default")
                        "POS SOLUTION"
                    } else {
                        Log.d(TAG, "Business name converted from ${value.javaClass.simpleName}: '$str'")
                        str
                    }
                }
            }
        } catch (e: Exception) {
            Log.e(TAG, "Error extracting business name", e)
            "POS SOLUTION"
        }
    }
    
    private fun formatLine(label: String, value: String): String {
        val width = getPrinterWidth()
        // For 58mm: use sprintf("%-20s%11s\n") format like receipt58.php
        if (width == PRINTER_WIDTH_58MM) {
            val labelPadded = String.format("%-20s", label)
            val valuePadded = String.format("%11s", value)
            return "$labelPadded$valuePadded\n"
        } else {
            // 80mm format: flexible spacing
            val spacing = width - label.length - value.length
            return "$label${" ".repeat(maxOf(1, spacing))}$value\n"
        }
    }
    
    private fun truncate(text: String, maxLength: Int): String {
        val width = getPrinterWidth()
        val actualMaxLength = if (maxLength > width) width else maxLength
        return if (text.length > actualMaxLength) text.take(actualMaxLength - 3) + "..." else text
    }
    
    private fun repeat(char: String, times: Int): String {
        val width = getPrinterWidth()
        val actualTimes = if (times > width) width else times
        return char.repeat(actualTimes)
    }
    
    private fun getCurrentDate(): String {
        return SimpleDateFormat("yyyy-MM-dd", Locale.getDefault()).format(Date())
    }
    
    private fun getCurrentTime(): String {
        return SimpleDateFormat("HH:mm", Locale.getDefault()).format(Date())
    }
    
    private fun getCurrentDateTime(): String {
        return SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault()).format(Date())
    }
    
    // ==================== Printer Communication ====================
    
    private suspend fun sendToPrinter(data: ByteArray): Boolean {
        return when (printerType) {
            PrinterType.TCP -> sendViaTcp(data)
            PrinterType.BLUETOOTH -> sendViaBluetooth(data)
            PrinterType.USB -> sendViaUsb(data)
            PrinterType.AUTO -> autoSend(data)
        }
    }
    
    private suspend fun autoSend(data: ByteArray): Boolean {
        // Try Bluetooth first
        if (sendViaBluetooth(data)) return true
        
        // Try USB
        if (sendViaUsb(data)) return true
        
        // Try TCP if configured
        if (tcpIp.isNotEmpty() && sendViaTcp(data)) return true
        
        Log.e(TAG, "No printer available")
        return false
    }
    
    private suspend fun sendViaTcp(data: ByteArray): Boolean {
        if (tcpIp.isEmpty()) return false
        
        return try {
            withContext(Dispatchers.IO) {
                Socket().use { socket ->
                    socket.connect(InetSocketAddress(tcpIp, tcpPort), 5000)
                    socket.getOutputStream().apply {
                        write(data)
                        flush()
                    }
                }
            }
            Log.d(TAG, "TCP print successful")
            true
        } catch (e: Exception) {
            Log.e(TAG, "TCP print failed", e)
            false
        }
    }
    
    private suspend fun sendViaBluetooth(data: ByteArray): Boolean {
        return try {
            val adapter = BluetoothAdapter.getDefaultAdapter() ?: return false
            if (!adapter.isEnabled) return false
            
            val device: BluetoothDevice? = if (bluetoothAddress.isNotEmpty()) {
                adapter.getRemoteDevice(bluetoothAddress)
            } else {
                // Find first printer-like device
                adapter.bondedDevices.firstOrNull { 
                    it.name?.contains("printer", ignoreCase = true) == true ||
                    it.name?.contains("pos", ignoreCase = true) == true ||
                    it.name?.contains("thermal", ignoreCase = true) == true ||
                    it.name?.contains("xp-", ignoreCase = true) == true
                }
            }
            
            if (device == null) {
                Log.w(TAG, "No Bluetooth printer found")
                return false
            }
            
            withContext(Dispatchers.IO) {
                val uuid = UUID.fromString("00001101-0000-1000-8000-00805F9B34FB")
                val socket = device.createRfcommSocketToServiceRecord(uuid)
                socket.connect()
                socket.outputStream.write(data)
                socket.outputStream.flush()
                socket.close()
            }
            Log.d(TAG, "Bluetooth print successful")
            true
        } catch (e: SecurityException) {
            Log.e(TAG, "Bluetooth permission denied", e)
            false
        } catch (e: Exception) {
            Log.e(TAG, "Bluetooth print failed", e)
            false
        }
    }
    
    private suspend fun sendViaUsb(data: ByteArray): Boolean {
        return try {
            val usbManager = context.getSystemService(Context.USB_SERVICE) as UsbManager
            val device = usbManager.deviceList.values.firstOrNull { 
                it.productName?.contains("printer", ignoreCase = true) == true ||
                it.vendorId == 0x0483 || // Common thermal printer vendor
                it.vendorId == 0x0416 ||
                it.vendorId == 0x04B8    // Epson
            } ?: return false
            
            if (!usbManager.hasPermission(device)) {
                Log.w(TAG, "No USB permission for device")
                return false
            }
            
            val connection = usbManager.openDevice(device) ?: return false
            val intf = device.getInterface(0)
            val endpoint = intf.getEndpoint(0)
            
            connection.claimInterface(intf, true)
            connection.bulkTransfer(endpoint, data, data.size, 5000)
            connection.releaseInterface(intf)
            connection.close()
            
            Log.d(TAG, "USB print successful")
            true
        } catch (e: Exception) {
            Log.e(TAG, "USB print failed", e)
            false
        }
    }
}
