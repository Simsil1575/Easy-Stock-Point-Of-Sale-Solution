package co.median.android;

import android.content.Intent;
import android.content.SharedPreferences;
import android.os.Bundle;

public class LaunchActivity extends MainActivity {
    private static final String PREFS_NAME = "printer_settings";
    private static final String PREF_PRINTER_SETUP_COMPLETE = "printer_setup_complete";
    
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        // Check if printer setup has been completed
        SharedPreferences prefs = getSharedPreferences(PREFS_NAME, MODE_PRIVATE);
        boolean setupComplete = prefs.getBoolean(PREF_PRINTER_SETUP_COMPLETE, false);
        
        // If setup not complete, show printer settings first
        if (!setupComplete) {
            Intent printerIntent = new Intent(this, PrinterSettingsActivity.class);
            printerIntent.putExtra("isFirstLaunch", true);
            startActivity(printerIntent);
            finish(); // Don't show MainActivity yet
            return;
        }
        
        // Otherwise, proceed with normal MainActivity flow
        super.onCreate(savedInstanceState);
    }
}
