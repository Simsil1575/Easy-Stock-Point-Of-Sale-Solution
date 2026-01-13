package co.median.android

import android.Manifest
import android.app.Activity
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.app.ActivityCompat
import androidx.core.content.ContextCompat

/**
 * Helper class to manage Bluetooth permissions required for thermal printer connectivity.
 */
object PrinterPermissionHelper {
    private const val TAG = "PrinterPermissionHelper"
    const val REQUEST_BLUETOOTH_PERMISSIONS = 1001
    
    /**
     * Check if all required Bluetooth permissions are granted.
     */
    fun hasBluetoothPermissions(activity: Activity): Boolean {
        return if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.S) {
            // Android 12+ requires BLUETOOTH_CONNECT and BLUETOOTH_SCAN
            ContextCompat.checkSelfPermission(activity, Manifest.permission.BLUETOOTH_CONNECT) == 
                    PackageManager.PERMISSION_GRANTED &&
            ContextCompat.checkSelfPermission(activity, Manifest.permission.BLUETOOTH_SCAN) == 
                    PackageManager.PERMISSION_GRANTED
        } else {
            // Older versions just need BLUETOOTH permission
            ContextCompat.checkSelfPermission(activity, Manifest.permission.BLUETOOTH) == 
                    PackageManager.PERMISSION_GRANTED
        }
    }
    
    /**
     * Request Bluetooth permissions if not already granted.
     * Returns true if permissions are already granted, false if we need to wait for user response.
     */
    fun requestBluetoothPermissions(activity: Activity): Boolean {
        if (hasBluetoothPermissions(activity)) {
            Log.d(TAG, "Bluetooth permissions already granted")
            return true
        }
        
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
        
        Log.d(TAG, "Requesting Bluetooth permissions")
        ActivityCompat.requestPermissions(activity, permissions, REQUEST_BLUETOOTH_PERMISSIONS)
        return false
    }
    
    /**
     * Handle the permission request result.
     * Call this from Activity.onRequestPermissionsResult()
     */
    fun onRequestPermissionsResult(
        requestCode: Int,
        permissions: Array<out String>,
        grantResults: IntArray,
        onGranted: () -> Unit,
        onDenied: () -> Unit
    ) {
        if (requestCode != REQUEST_BLUETOOTH_PERMISSIONS) return
        
        val allGranted = grantResults.isNotEmpty() && 
                grantResults.all { it == PackageManager.PERMISSION_GRANTED }
        
        if (allGranted) {
            Log.d(TAG, "Bluetooth permissions granted")
            onGranted()
        } else {
            Log.w(TAG, "Bluetooth permissions denied")
            onDenied()
        }
    }
}
