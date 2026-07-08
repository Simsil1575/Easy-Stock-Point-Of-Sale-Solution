package com.books.webview

import android.content.Context
import android.content.SharedPreferences
import android.widget.Toast
import androidx.lifecycle.LifecycleOwner
import androidx.lifecycle.lifecycleScope
import androidx.appcompat.app.AppCompatActivity
import com.khairo.escposprinter.EscPosPrinter
import com.khairo.escposprinter.connection.DeviceConnection
import com.khairo.escposprinter.connection.bluetooth.BluetoothPrintersConnections
import com.khairo.escposprinter.connection.tcp.TcpConnection
import com.khairo.escposprinter.connection.usb.UsbConnection
import com.khairo.escposprinter.connection.usb.UsbPrintersConnections
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject
import java.text.SimpleDateFormat
import java.util.*
import java.io.OutputStream
import kotlin.ExperimentalStdlibApi

class ReceiptPrintService(private val context: Context, private val lifecycleOwner: AppCompatActivity) {

    private val prefs: SharedPreferences = context.getSharedPreferences("PrinterConfig", Context.MODE_PRIVATE)

    fun printReceipt(orderData: JSONObject) {
        lifecycleOwner.lifecycleScope.launch(Dispatchers.IO) {
            if (!orderData.has("business_name") || orderData.optString("business_name").isEmpty()) {
                android.util.Log.e("ReceiptPrintService", "ERROR: Business info missing from receipt.php response! Order data: ${orderData.toString().take(200)}")
            }
            try {
                val printOnly = orderData.optBoolean("print_only", false)
                val isCashUpReport = orderData.optBoolean("is_cashup_report", false)
                val isBalanceReceipt = orderData.optBoolean("is_balance_receipt", false)
                val isTabBalanceReceipt = orderData.optBoolean("is_tab_balance_receipt", false)
                val isPaymentReceipt = orderData.optBoolean("is_payment_receipt", false)
                
                val shouldPrint = printOnly || isCashUpReport || isBalanceReceipt || isTabBalanceReceipt || isPaymentReceipt
                val openDrawerOnly = orderData.optBoolean("open_drawer_only", false)
                
                if (!shouldPrint && !openDrawerOnly) {
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "Print not requested - receipt skipped", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }
                
                if (openDrawerOnly) {
                    val connection = getPrinterConnection()
                    if (connection == null) {
                        withContext(Dispatchers.Main) {
                            Toast.makeText(context, "No printer configured", Toast.LENGTH_SHORT).show()
                        }
                        return@launch
                    }
                    
                    try {
                        openCashDrawer(connection)
                        closeConnection(connection)
                    } catch (e: Exception) {
                        android.util.Log.e("ReceiptPrint", "Error opening drawer: ${e.message}", e)
                    }
                    
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "Cash drawer opened", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }
                
                val connection = getPrinterConnection()
                if (connection == null) {
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "No printer configured", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }

                val printer = EscPosPrinter(connection as DeviceConnection, 203, 58f, 32)

                val isSpecialReceipt = isCashUpReport || isBalanceReceipt || isTabBalanceReceipt || isPaymentReceipt
                val hasItems = orderData.has("items") && orderData.getJSONArray("items").length() > 0
                
                if (orderData.has("print_only") && !orderData.optBoolean("print_only", false) && !isSpecialReceipt) {
                    printer.disconnectPrinter()
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "Print not requested - receipt skipped", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }
                
                if (!hasItems && !isSpecialReceipt) {
                    printer.disconnectPrinter()
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "No items to print - receipt skipped", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }

                val receiptText = buildReceiptText(orderData)
                
                val strippedText = receiptText
                    .replace(Regex("\\[C\\]|\\[L\\]|\\[R\\]"), "")
                    .replace(Regex("<[^>]+>"), "")
                    .replace(Regex("\\s+"), " ")
                    .trim()
                
                if (strippedText.isBlank()) {
                    printer.disconnectPrinter()
                    withContext(Dispatchers.Main) {
                        Toast.makeText(context, "Receipt is empty - nothing to print", Toast.LENGTH_SHORT).show()
                    }
                    return@launch
                }
                
                // Print receipt with minimal trailing space
                printer.printFormattedText(receiptText)
                
                // Send immediate cut command with NO line feed (cuts right after last printed line)
                sendImmediateCut(connection)
                
                // Open cash drawer after printing (if not a cash-up report)
                if (!isCashUpReport) {
                    openCashDrawer(connection)
                }
                
                printer.disconnectPrinter()

                withContext(Dispatchers.Main) {
                    Toast.makeText(context, "Receipt printed successfully", Toast.LENGTH_SHORT).show()
                }
            } catch (e: Exception) {
                withContext(Dispatchers.Main) {
                    Toast.makeText(context, "Print failed: ${e.message}", Toast.LENGTH_SHORT).show()
                }
            }
        }
    }

    private fun sendImmediateCut(connection: DeviceConnection) {
        try {
            // ESC/POS Full Cut Command: GS V 0 (cuts immediately with 0 line feed)
            val cutCommand = byteArrayOf(
                0x1D.toByte(),  // GS
                0x56.toByte(),  // V  
                0x00.toByte()   // 0 = Full cut with 0 line feed
            )
            
            sendRawBytes(connection, cutCommand, "cut")
        } catch (e: Exception) {
            android.util.Log.w("ReceiptPrint", "Error sending cut command: ${e.message}")
        }
    }

    private fun openCashDrawer(connection: DeviceConnection) {
        try {
            // ESC/POS Cash Drawer Command: ESC p m t1 t2
            val cashDrawerBytes = byteArrayOf(
                0x1B.toByte(),  // ESC
                0x70.toByte(),  // p
                0x00.toByte(),  // m (drawer number, 0 or 1)
                0x19.toByte(),  // t1 (pulse time in 2ms units)
                0x19.toByte()   // t2 (pulse time in 2ms units)
            )
            
            sendRawBytes(connection, cashDrawerBytes, "drawer")
        } catch (e: Exception) {
            android.util.Log.e("ReceiptPrint", "Error opening drawer: ${e.message}", e)
        }
    }

    private fun sendRawBytes(connection: DeviceConnection, bytes: ByteArray, commandName: String): Boolean {
        var commandSent = false
        
        // Method 1: Try OutputStream (most reliable)
        if (!commandSent) {
            try {
                val getOutputStreamMethod = connection.javaClass.getMethod("getOutputStream")
                val outputStream = getOutputStreamMethod.invoke(connection) as? OutputStream
                outputStream?.write(bytes)
                outputStream?.flush()
                commandSent = true
                android.util.Log.d("ReceiptPrint", "Sent $commandName command via OutputStream")
            } catch (e: Exception) {
                // Try next method
            }
        }
        
        // Method 2: Try send method
        if (!commandSent) {
            try {
                val sendMethod = connection.javaClass.getMethod("send", ByteArray::class.java)
                sendMethod.invoke(connection, bytes)
                commandSent = true
                android.util.Log.d("ReceiptPrint", "Sent $commandName command via send()")
            } catch (e: Exception) {
                // Try next method
            }
        }
        
        // Method 3: Try sendBytes method
        if (!commandSent) {
            try {
                val sendBytesMethod = connection.javaClass.getMethod("sendBytes", ByteArray::class.java)
                sendBytesMethod.invoke(connection, bytes)
                commandSent = true
                android.util.Log.d("ReceiptPrint", "Sent $commandName command via sendBytes()")
            } catch (e: Exception) {
                // Try next method
            }
        }
        
        // Method 4: Try write method
        if (!commandSent) {
            try {
                val writeMethod = connection.javaClass.getMethod("write", ByteArray::class.java)
                writeMethod.invoke(connection, bytes)
                commandSent = true
                android.util.Log.d("ReceiptPrint", "Sent $commandName command via write()")
            } catch (e: Exception) {
                // Try next method
            }
        }
        
        // Method 5: Try to access connection fields
        if (!commandSent) {
            try {
                val fields = connection.javaClass.declaredFields
                for (field in fields) {
                    if (field.type == OutputStream::class.java || field.type.name.contains("OutputStream")) {
                        field.isAccessible = true
                        val outputStream = field.get(connection) as? OutputStream
                        outputStream?.write(bytes)
                        outputStream?.flush()
                        commandSent = true
                        android.util.Log.d("ReceiptPrint", "Sent $commandName command via connection field")
                        break
                    }
                }
            } catch (e: Exception) {
                android.util.Log.w("ReceiptPrint", "Could not access connection field: ${e.message}")
            }
        }
        
        if (!commandSent) {
            android.util.Log.w("ReceiptPrint", "Could not send $commandName command via any method")
        }
        
        return commandSent
    }

    private fun closeConnection(connection: DeviceConnection) {
        try {
            val closeMethod = connection.javaClass.getMethod("close")
            closeMethod.invoke(connection)
        } catch (e: Exception) {
            try {
                val disconnectMethod = connection.javaClass.getMethod("disconnect")
                disconnectMethod.invoke(connection)
            } catch (e2: Exception) {
                // Connection will be closed when out of scope
            }
        }
    }

    private suspend fun getPrinterConnection(): DeviceConnection? {
        val printerType = prefs.getString("printer_type", "bluetooth") ?: "bluetooth"
        
        return when (printerType) {
            "bluetooth" -> {
                BluetoothPrintersConnections.selectFirstPaired()
            }
            "tcp" -> {
                val ip = prefs.getString("tcp_ip", "192.168.1.160") ?: "192.168.1.160"
                val port = prefs.getInt("tcp_port", 9100)
                try {
                    val connection = TcpConnection(ip, port)
                    connection.connect(context)
                    connection as DeviceConnection
                } catch (e: Exception) {
                    null
                }
            }
            "usb" -> {
                val usbConnection = UsbPrintersConnections.selectFirstConnected(context)
                val usbManager = context.getSystemService(Context.USB_SERVICE) as android.hardware.usb.UsbManager
                if (usbConnection != null && usbManager != null) {
                    UsbConnection(usbManager, usbConnection.device)
                } else {
                    null
                }
            }
            else -> null
        }
    }

    @OptIn(ExperimentalStdlibApi::class)
    private fun buildReceiptText(orderData: JSONObject): String {
        val sb = StringBuilder()
        
        // Log VAT data from orderData for debugging
        android.util.Log.d("ReceiptPrintService", "OrderData VAT fields - vat_inclusive: '${orderData.optString("vat_inclusive", "NOT_SET")}', vat_rate: ${orderData.optDouble("vat_rate", -1.0)}")
        
        val isCashUpReport = orderData.optBoolean("is_cashup_report", false)
        val isPaymentReceipt = orderData.optBoolean("is_payment_receipt", false)
        val isTabSale = orderData.has("table_id") || orderData.has("tab_id")
        val isCreditSale = orderData.has("sale_id") || orderData.has("creditor_id")
        
        val businessName = orderData.optString("business_name", "")
        val businessLocation = orderData.optString("location", "")
        val businessPhone = orderData.optString("phone", "")
        val footerText = orderData.optString("footer_text", "")
        
        if (businessName.isEmpty()) {
            android.util.Log.w("ReceiptPrintService", "WARNING: business_name missing from receipt.php response")
        }
        
        // Header
        if (!isTabSale || isPaymentReceipt) {
            sb.append("[C]<u><font size='big'>${businessName}</font></u>\n")
            if (businessLocation.isNotEmpty()) {
                sb.append("[C]<b>${businessLocation}</b>\n")
            }
            if (businessPhone.isNotEmpty()) {
                sb.append("[C]Tel: ${businessPhone}\n")
            }
            val cashierUsername = orderData.optString("cashier_username", "")
            if (cashierUsername.isNotEmpty()) {
                sb.append("[C]Cashier: ${cashierUsername}\n")
            }
            sb.append("[L]\n")
        }
        
        // Receipt type header
        when {
            isCashUpReport -> {
                sb.append("[C]<u><font size='big'>Z-REPORT</font></u>\n")
                sb.append("[L]${"-".repeat(32)}\n")
                sb.append("[L]Date: ${orderData.optString("date", getCurrentDate())}\n")
                sb.append("[L]Time: ${getCurrentTime()}\n")
                sb.append("[L]Cashier: ${orderData.optString("cashier_username", "N/A")}\n")
                sb.append("[L]${"-".repeat(32)}\n")
                
                val cashSales = orderData.optDouble("cash_sales", orderData.optDouble("total_cash_sales", 0.0))
                val eftSales = orderData.optDouble("eft_sales", orderData.optDouble("total_eft_sales", 0.0))
                val grandTotal = orderData.optDouble("grand_total", orderData.optDouble("total_income", 0.0))
                
                sb.append("[L]${String.format("%-20s%11s", "CASH SALES:", "N$${String.format("%.2f", cashSales)}")}\n")
                sb.append("[L]${String.format("%-20s%11s", "EFT SALES:", "N$${String.format("%.2f", eftSales)}")}\n")
                sb.append("[L]${"-".repeat(32)}\n")
                sb.append("[L]<b>${String.format("%-20s%11s", "TOTAL SALES:", "N$${String.format("%.2f", grandTotal)}")}</b>\n")
                sb.append("[L]${"=".repeat(32)}\n")
            }
            isTabSale && !isPaymentReceipt -> {
                val receiptNumber = orderData.optString("order_id", 
                    orderData.optString("tab_id", 
                        orderData.optString("table_id", 
                            orderData.optString("receipt_number", 
                                orderData.optString("id", "N/A")))))
                sb.append("[C]<b>ORDER!!  No: $receiptNumber</b>\n")
                val tableName = orderData.optString("table_name", "Table ${orderData.optString("table_id", orderData.optString("tab_id", "N/A"))}")
                val truncatedTableName = if (tableName.length > 30) tableName.substring(0, 27) + "..." else tableName
                sb.append("[L]Table : $truncatedTableName\n")
                sb.append("[L]Time : ${getCurrentDateTime()}\n")
                sb.append("[L]By : ${orderData.optString("cashier_username", "Cashier")}\n")
                sb.append("[L]<b>ITEMS</b>\n")
            }
            else -> {
                sb.append("[L]${"-".repeat(32)}\n")
                val receiptType = if (isCreditSale) "Credit Sale" else "Receipt"
                val receiptNumber = orderData.optString("order_id", 
                    orderData.optString("sale_id", 
                        orderData.optString("tab_id", 
                            orderData.optString("table_id", 
                                orderData.optString("receipt_number", 
                                    orderData.optString("id", "N/A"))))))
                
                if (isPaymentReceipt) {
                    sb.append("[L]<b>Payment Bill Receipt #: $receiptNumber</b>\n")
                } else {
                    sb.append("[L]<b>$receiptType #: $receiptNumber</b>\n")
                }
                
                sb.append("[L]${"-".repeat(32)}\n")
                sb.append("[L]Date: ${getCurrentDateTime()}\n")
                sb.append("[L]\n")
                
                if (!isTabSale || isPaymentReceipt) {
                    sb.append("[L]<b>${String.format("%-18s %3s %8s", "Item", "Qty", "Amount")}</b>\n")
                    sb.append("[L]${"-".repeat(32)}\n")
                }
            }
        }
        
        // Calculate totals
        var subtotal = 0.0
        if (orderData.has("items")) {
            val itemsArray = orderData.getJSONArray("items")
            for (i in 0 until itemsArray.length()) {
                val item = itemsArray.getJSONObject(i)
                subtotal += item.optDouble("price", 0.0)
            }
        }
        
        val vatInclusiveRaw = orderData.optString("vat_inclusive", "exclusive")
        val vatInclusive = vatInclusiveRaw.lowercase().trim()
        val vatRate = orderData.optDouble("vat_rate", 15.0)
        val vatAmount: Double
        val displaySubtotal: Double
        val displayTotal: Double
        
        // Debug logging
        android.util.Log.d("ReceiptPrintService", "VAT Settings - raw: '$vatInclusiveRaw', normalized: '$vatInclusive', rate: $vatRate, subtotal: $subtotal")
        
        if (vatInclusive == "exclusive" || vatInclusive.isEmpty()) {
            vatAmount = subtotal * (vatRate / 100)
            displaySubtotal = subtotal
            displayTotal = subtotal
            android.util.Log.d("ReceiptPrintService", "VAT Exclusive mode - VAT amount: $vatAmount (not shown on receipt)")
        } else {
            // VAT is inclusive - calculate breakdown
            vatAmount = subtotal - (subtotal / (1 + (vatRate / 100)))
            displaySubtotal = subtotal - vatAmount
            displayTotal = subtotal
            android.util.Log.d("ReceiptPrintService", "VAT Inclusive mode - amount: $vatAmount, subtotal (ex VAT): $displaySubtotal, total: $displayTotal")
        }
        
        // Items section
        if (orderData.has("items")) {
            val itemsArray = orderData.getJSONArray("items")
            
            for (i in 0 until itemsArray.length()) {
                val item = itemsArray.getJSONObject(i)
                val name = item.optString("name", "")
                val quantity = item.optInt("quantity", 1)
                val price = item.optDouble("price", 0.0)
                val unitPrice = price / quantity
                
                if (isTabSale && !isPaymentReceipt) {
                    val truncatedName = if (name.length > 35) name.substring(0, 32) + "..." else name
                    sb.append("[L]x$quantity $truncatedName\n")
                } else {
                    val truncatedName = if (name.length > 32) name.substring(0, 29) + "..." else name
                    sb.append("[L]$truncatedName\n")
                    
                    val qtyPrice = "${quantity}x N$${String.format("%.2f", unitPrice)}"
                    val amountText = "N$${String.format("%.2f", price)}"
                    val spaces = 32 - qtyPrice.length - amountText.length
                    val padding = if (spaces >= 1) " ".repeat(spaces) else " "
                    sb.append("[L]$qtyPrice$padding$amountText\n")
                    sb.append("[L]${"-".repeat(32)}\n")
                }
            }
            
            // Totals
            sb.append("[L]\n")
            // Determine if VAT should be shown (inclusive means VAT is included in prices, so show breakdown)
            val shouldShowVAT = vatInclusive != "exclusive" && vatInclusive.isNotEmpty() && vatAmount > 0.0
            
            if (isPaymentReceipt) {
                // Show VAT breakdown for payment receipts if VAT is inclusive
                if (shouldShowVAT) {
                    android.util.Log.d("ReceiptPrintService", "Adding VAT breakdown for payment receipt")
                    val subtotalLabel = String.format("%-20s", "Subtotal (ex VAT):")
                    val subtotalValue = String.format("%11s", "N$${String.format("%.2f", displaySubtotal)}")
                    sb.append("[L]$subtotalLabel$subtotalValue\n")
                    
                    val vatLabel = String.format("%-20s", "VAT (${String.format("%.2f", vatRate)}%):")
                    val vatValue = String.format("%11s", "N$${String.format("%.2f", vatAmount)}")
                    sb.append("[L]$vatLabel$vatValue\n")
                    sb.append("[L]${"-".repeat(32)}\n")
                } else {
                    android.util.Log.d("ReceiptPrintService", "VAT not shown for payment receipt - vatInclusive: '$vatInclusive', vatAmount: $vatAmount")
                }
                
                val totalLabel = String.format("%-20s", "PAID AMOUNT:")
                val totalValue = String.format("%11s", "N$${String.format("%.2f", displayTotal)}")
                sb.append("[L]<b>$totalLabel$totalValue</b>\n")
            } else if (!isTabSale) {
                // Show VAT breakdown for regular receipts if VAT is inclusive
                if (shouldShowVAT) {
                    android.util.Log.d("ReceiptPrintService", "Adding VAT breakdown for regular receipt")
                    val subtotalLabel = String.format("%-20s", "Subtotal (ex VAT):")
                    val subtotalValue = String.format("%11s", "N$${String.format("%.2f", displaySubtotal)}")
                    sb.append("[L]$subtotalLabel$subtotalValue\n")
                    
                    val vatLabel = String.format("%-20s", "VAT (${String.format("%.2f", vatRate)}%):")
                    val vatValue = String.format("%11s", "N$${String.format("%.2f", vatAmount)}")
                    sb.append("[L]$vatLabel$vatValue\n")
                    sb.append("[L]${"-".repeat(32)}\n")
                } else {
                    android.util.Log.d("ReceiptPrintService", "VAT not shown for regular receipt - vatInclusive: '$vatInclusive', vatAmount: $vatAmount")
                }
                
                val totalLabel = String.format("%-20s", "TOTAL:")
                val totalValue = String.format("%11s", "N$${String.format("%.2f", displayTotal)}")
                sb.append("[L]<b>$totalLabel$totalValue</b>\n")
            }
            sb.append("[L]\n")
        }
        
        // Payment information
        if (!isTabSale || isPaymentReceipt) {
            sb.append("[L]${"-".repeat(32)}\n")
            sb.append("[L]<b>PAYMENT INFORMATION</b>\n")
            sb.append("[L]${"-".repeat(32)}\n")
            
            val paymentMethod = orderData.optString("payment_method", "")
            when {
                paymentMethod == "cash" || (paymentMethod.isEmpty() && !isCreditSale && orderData.has("cash_received")) -> {
                    sb.append("[L]Method: Cash\n")
                    val cashReceived = orderData.optDouble("cash_received", displayTotal)
                    sb.append("[L]${String.format("%-20s%11s", "Paid:", "N$${String.format("%.2f", cashReceived)}")}\n")
                    val change = maxOf(0.0, cashReceived - displayTotal)
                    if (change > 0) {
                        sb.append("[L]${String.format("%-20s%11s", "Change:", "N$${String.format("%.2f", change)}")}\n")
                    }
                }
                paymentMethod == "eft" || paymentMethod == "e-wallet" -> {
                    sb.append("[L]Method: EFT\n")
                    val walletProvider = orderData.optString("wallet_provider", "")
                    if (walletProvider.isNotEmpty()) {
                        sb.append("[L]Provider: $walletProvider\n")
                    }
                    val transactionRef = orderData.optString("transaction_ref", "")
                    if (transactionRef.isNotEmpty()) {
                        val ref = if (transactionRef.length > 20) transactionRef.substring(0, 17) + "..." else transactionRef
                        sb.append("[L]Ref: $ref\n")
                    }
                    sb.append("[L]${String.format("%-20s%11s", "Paid:", "N$${String.format("%.2f", displayTotal)}")}\n")
                }
                paymentMethod == "mixed" -> {
                    sb.append("[L]Method: Mixed Payment\n")
                    sb.append("[L]${"-".repeat(32)}\n")
                    val cashAmount = orderData.optDouble("cash_amount", 0.0)
                    val eftAmount = orderData.optDouble("eft_amount", 0.0)
                    if (cashAmount > 0) {
                        sb.append("[L]${String.format("%-20s%11s", "Cash:", "N$${String.format("%.2f", cashAmount)}")}\n")
                    }
                    if (eftAmount > 0) {
                        sb.append("[L]${String.format("%-20s%11s", "EFT:", "N$${String.format("%.2f", eftAmount)}")}\n")
                        val eftProvider = orderData.optString("eft_wallet_provider", orderData.optString("wallet_provider", ""))
                        if (eftProvider.isNotEmpty()) {
                            sb.append("[L]Provider: $eftProvider\n")
                        }
                        val eftRef = orderData.optString("eft_transaction_ref", orderData.optString("transaction_ref", ""))
                        if (eftRef.isNotEmpty()) {
                            val ref = if (eftRef.length > 20) eftRef.substring(0, 17) + "..." else eftRef
                            sb.append("[L]Ref: $ref\n")
                        }
                    }
                    sb.append("[L]${"-".repeat(32)}\n")
                    sb.append("[L]${String.format("%-20s%11s", "Total:", "N$${String.format("%.2f", displayTotal)}")}\n")
                    if (cashAmount > displayTotal) {
                        val change = maxOf(0.0, cashAmount - displayTotal)
                        sb.append("[L]${String.format("%-20s%11s", "Change:", "N$${String.format("%.2f", change)}")}\n")
                    }
                }
                (isTabSale && !isPaymentReceipt) -> {
                    sb.append("[L]Method: Tab\n")
                    val tableName = orderData.optString("table_name", "")
                    if (tableName.isNotEmpty()) {
                        val truncatedTableName = if (tableName.length > 20) tableName.substring(0, 17) + "..." else tableName
                        sb.append("[L]Table: $truncatedTableName\n")
                    }
                    sb.append("[L]${String.format("%-20s%11s", "Total:", "N$${String.format("%.2f", displayTotal)}")}\n")
                }
                else -> {
                    if (!isCreditSale) {
                        sb.append("[L]Method: Cash\n")
                        val cashReceived = orderData.optDouble("cash_received", displayTotal)
                        sb.append("[L]${String.format("%-20s%11s", "Paid:", "N$${String.format("%.2f", cashReceived)}")}\n")
                        val change = maxOf(0.0, cashReceived - displayTotal)
                        if (change > 0) {
                            sb.append("[L]${String.format("%-20s%11s", "Change:", "N$${String.format("%.2f", change)}")}\n")
                        }
                    }
                }
            }
            
            // Credit sale info
            if (isCreditSale) {
                sb.append("[L]Method: Credit\n")
                val creditorId = orderData.optString("creditor_id", "")
                if (creditorId.isNotEmpty()) {
                    sb.append("[L]ID: $creditorId\n")
                }
                val creditorName = orderData.optString("creditor_name", "")
                if (creditorName.isNotEmpty()) {
                    sb.append("[L]Name: $creditorName\n")
                }
                val dueDate = orderData.optString("due_date", "")
                if (dueDate.isNotEmpty()) {
                    sb.append("[L]Due: $dueDate\n")
                }
                
                // Partial payment info
                if (orderData.optString("payment_type", "") == "cash") {
                    val cashReceived = orderData.optDouble("cash_received", 0.0)
                    val totalAmount = orderData.optDouble("total_amount", 0.0)
                    if (cashReceived < totalAmount) {
                        sb.append("[L]<b>Partial Payment</b>\n")
                        sb.append("[L]${String.format("%-20s%11s", "Paid:", "N$${String.format("%.2f", cashReceived)}")}\n")
                        sb.append("[L]${String.format("%-20s%11s", "Balance:", "N$${String.format("%.2f", totalAmount - cashReceived)}")}\n")
                    }
                }
                if (orderData.optString("payment_method", "") == "e-wallet") {
                    val paymentAmount = orderData.optDouble("payment_amount", 0.0)
                    val totalAmount = orderData.optDouble("total_amount", 0.0)
                    if (paymentAmount < totalAmount) {
                        sb.append("[L]<b>Partial Payment (EFT)</b>\n")
                        sb.append("[L]${String.format("%-20s%11s", "Paid:", "N$${String.format("%.2f", paymentAmount)}")}\n")
                        sb.append("[L]${String.format("%-20s%11s", "Balance:", "N$${String.format("%.2f", totalAmount - paymentAmount)}")}\n")
                    }
                }
                
                // Barcode
                if (creditorId.isNotEmpty()) {
                    sb.append("[L]\n")
                    sb.append("[C]Transaction ID:\n")
                    sb.append("[C]<barcode type='39' height='80'>$creditorId</barcode>\n")
                    sb.append("[L]\n")
                }
            }
        }
        
        // Footer - cleaned and optimized
        if (!isTabSale || isPaymentReceipt) {
            val cleanFooterText = footerText.trim()
                .replace("\n", " ")
                .replace("\r", " ")
                .replace(Regex("\\s+"), " ")
            
            if (cleanFooterText.isNotEmpty()) {
                sb.append("[L]${"-".repeat(32)}\n")
                sb.append("[C]$cleanFooterText")
                // NO newline after footer - cut will happen immediately
            }
        }
        
        // Return with NO trailing whitespace
        // The cut command will execute right after the last character
        return sb.toString().trimEnd()
    }

    private fun getCurrentDate(): String {
        val sdf = SimpleDateFormat("yyyy-MM-dd", Locale.getDefault())
        return sdf.format(Date())
    }

    private fun getCurrentTime(): String {
        val sdf = SimpleDateFormat("HH:mm", Locale.getDefault())
        return sdf.format(Date())
    }

    private fun getCurrentDateTime(): String {
        val sdf = SimpleDateFormat("yyyy-MM-dd HH:mm", Locale.getDefault())
        return sdf.format(Date())
    }
}