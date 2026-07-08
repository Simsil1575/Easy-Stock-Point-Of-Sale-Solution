package com.books.webview

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.media.AudioAttributes
import android.media.AudioManager
import android.media.MediaPlayer
import android.media.ToneGenerator
import android.os.Handler
import android.os.Looper
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat
import com.journeyapps.barcodescanner.BarcodeCallback
import com.journeyapps.barcodescanner.BarcodeResult
import com.journeyapps.barcodescanner.DecoratedBarcodeView
import com.journeyapps.barcodescanner.DefaultDecoderFactory
import com.journeyapps.barcodescanner.camera.CameraSettings

class BarcodeScannerHelper(
    private val activity: Activity,
    private val barcodeView: DecoratedBarcodeView,
    private val onBarcodeScanned: (String) -> Unit
) {
    private val CAMERA_PERMISSION_REQUEST_CODE = 1001
    private var isScanning = false
    private var lastScannedBarcode: String? = null
    private var lastScanTime: Long = 0
    private val SCAN_DEBOUNCE_MS = 2000L // Prevent duplicate scans within 2 seconds

    init {
        setupBarcodeView()
    }

    private fun setupBarcodeView() {
        // Configure barcode formats
        val formats = listOf(
            com.google.zxing.BarcodeFormat.UPC_A,
            com.google.zxing.BarcodeFormat.UPC_E,
            com.google.zxing.BarcodeFormat.EAN_13,
            com.google.zxing.BarcodeFormat.EAN_8,
            com.google.zxing.BarcodeFormat.CODE_128,
            com.google.zxing.BarcodeFormat.CODE_39,
            com.google.zxing.BarcodeFormat.ITF,
            com.google.zxing.BarcodeFormat.CODABAR,
            com.google.zxing.BarcodeFormat.QR_CODE,
            com.google.zxing.BarcodeFormat.DATA_MATRIX
        )
        
        barcodeView.barcodeView.decoderFactory = DefaultDecoderFactory(formats)
        
        // Configure camera settings for better scanning
        val cameraSettings = CameraSettings()
        cameraSettings.isAutoFocusEnabled = true
        cameraSettings.isContinuousFocusEnabled = true
        barcodeView.barcodeView.cameraSettings = cameraSettings
        
        // Set callback
        barcodeView.decodeContinuous(object : BarcodeCallback {
            override fun barcodeResult(result: BarcodeResult?) {
                result?.let {
                    val barcode = it.text
                    val currentTime = System.currentTimeMillis()
                    
                    // Debounce: ignore if same barcode scanned within debounce period
                    if (barcode == lastScannedBarcode && 
                        (currentTime - lastScanTime) < SCAN_DEBOUNCE_MS) {
                        Log.d("BarcodeScanner", "Ignoring duplicate scan: $barcode")
                        return
                    }
                    
                    lastScannedBarcode = barcode
                    lastScanTime = currentTime
                    Log.d("BarcodeScanner", "Scanned barcode: $barcode")
                    
                    // Play beep sound
                    playBeepSound()
                    
                    // Call the callback
                    onBarcodeScanned(barcode)
                }
            }

            override fun possibleResultPoints(resultPoints: MutableList<com.google.zxing.ResultPoint>?) {
                // Optional: Handle possible result points for UI feedback
            }
        })
    }

    fun startScanning() {
        if (isScanning) return
        
        if (checkCameraPermission()) {
            barcodeView.resume()
            isScanning = true
            Log.d("BarcodeScanner", "Started scanning")
            // Turn on flashlight after a short delay to ensure camera is ready
            Handler(Looper.getMainLooper()).postDelayed({
                setTorchOn(true)
            }, 300) // 300ms delay to allow camera to initialize
        } else {
            requestCameraPermission()
        }
    }

    fun stopScanning() {
        if (!isScanning) return
        
        // Turn off flashlight first
        setTorchOn(false)
        barcodeView.pause()
        isScanning = false
        Log.d("BarcodeScanner", "Stopped scanning and turned off flashlight")
    }

    fun resumeScanning() {
        if (isScanning && checkCameraPermission()) {
            barcodeView.resume()
            // Turn on flashlight when resuming (with delay to ensure camera is ready)
            Handler(Looper.getMainLooper()).postDelayed({
                setTorchOn(true)
            }, 300)
        }
    }

    fun pauseScanning() {
        if (isScanning) {
            // Turn off flashlight when pausing
            setTorchOn(false)
            barcodeView.pause()
        }
    }
    
    private fun setTorchOn(on: Boolean) {
        try {
            // Use the built-in torch control methods from DecoratedBarcodeView
            if (on) {
                barcodeView.setTorchOn()
                Log.d("BarcodeScanner", "Flashlight turned ON")
            } else {
                barcodeView.setTorchOff()
                Log.d("BarcodeScanner", "Flashlight turned OFF")
            }
        } catch (e: Exception) {
            Log.e("BarcodeScanner", "Error controlling flashlight: ${e.message}", e)
        }
    }
    
    private fun playBeepSound() {
        try {
            // Use ToneGenerator for a simple beep sound
            val toneGenerator = ToneGenerator(AudioManager.STREAM_NOTIFICATION, 100)
            toneGenerator.startTone(ToneGenerator.TONE_PROP_BEEP, 150)
            
            // Release after a short delay
            Handler(Looper.getMainLooper()).postDelayed({
                toneGenerator.release()
            }, 200)
            
            Log.d("BarcodeScanner", "Beep sound played")
        } catch (e: Exception) {
            Log.e("BarcodeScanner", "Error playing beep sound: ${e.message}", e)
            // Fallback: try using MediaPlayer with system beep
            try {
                val audioManager = activity.getSystemService(Activity.AUDIO_SERVICE) as AudioManager
                val volume = audioManager.getStreamVolume(AudioManager.STREAM_NOTIFICATION)
                if (volume > 0) {
                    // Use ToneGenerator as fallback
                    val toneGen = ToneGenerator(AudioManager.STREAM_NOTIFICATION, 80)
                    toneGen.startTone(ToneGenerator.TONE_CDMA_ALERT_CALL_GUARD, 100)
                    Handler(Looper.getMainLooper()).postDelayed({
                        toneGen.release()
                    }, 150)
                }
            } catch (e2: Exception) {
                Log.e("BarcodeScanner", "Error with fallback beep: ${e2.message}", e2)
            }
        }
    }

    private fun checkCameraPermission(): Boolean {
        return ContextCompat.checkSelfPermission(
            activity,
            Manifest.permission.CAMERA
        ) == PackageManager.PERMISSION_GRANTED
    }

    private fun requestCameraPermission() {
        ActivityCompat.requestPermissions(
            activity,
            arrayOf(Manifest.permission.CAMERA),
            CAMERA_PERMISSION_REQUEST_CODE
        )
    }

    fun handlePermissionResult(
        requestCode: Int,
        @Suppress("UNUSED_PARAMETER") permissions: Array<out String>,
        grantResults: IntArray
    ): Boolean {
        if (requestCode == CAMERA_PERMISSION_REQUEST_CODE) {
            if (grantResults.isNotEmpty() && grantResults[0] == PackageManager.PERMISSION_GRANTED) {
                startScanning()
                return true
            }
        }
        return false
    }

    fun isScanningActive(): Boolean = isScanning
}
