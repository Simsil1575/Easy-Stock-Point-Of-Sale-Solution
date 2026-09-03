# Shift-Based Report Date/Time Auto-Population

## Overview
This feature automatically populates report date and time ranges based on user login and logout times (shift times) for cashier-specific reports. This ensures accurate reporting based on each user's actual work shift.

## How It Works

### 1. User Login/Logout Tracking
- When a user logs in, the system records the login time in the `user_log` table with `action_type = 'login'`
- When a user logs out, the system records the logout time in the `user_log` table with `action_type = 'logout'`
- This tracking happens automatically in:
  - `index.php` (login handler)
  - `logout.php` (logout handler)
  - `admin/logout.php`, `manager/logout.php`, etc.

### 2. Shift Time Retrieval
The new `user_shift_helper.php` file provides functions to retrieve shift times:

#### `getUserShiftTimes($db, $username)`
Returns the most recent login and logout timestamps for a given username.

#### `getCurrentUserShiftForReports($db)`
Returns formatted date/time data for the currently logged-in user:
```php
[
    'start_date' => '2026-08-28',  // Last login date
    'start_time' => '08:15',       // Last login time
    'end_date' => '2026-08-28',    // Last logout date (or current time if not logged out)
    'end_time' => '17:30',         // Last logout time (or current time if not logged out)
    'has_shift_data' => true       // Whether shift data is available
]
```

#### `getCashierShiftTimes($db, $cashierUsername)`
Same as above but for a specific cashier (useful for admin/manager reports).

### 3. Report Date/Time Auto-Population
When opening a cashier-specific report, the system automatically:
1. Checks if the report is a cashier report (types: 'cashier_sales', 'shift', 'cashup', 'gratuity', 'tips')
2. If shift data is available, uses the user's last login/logout times
3. If no shift data is available, falls back to today's date with business hours

## Files Modified

### 1. `user_shift_helper.php` (NEW)
Contains all the helper functions for retrieving shift times.

### 2. `admin/reports-center.php`
- Added shift helper include
- Passes user shift data to JavaScript
- Modified `openReportModal()` to use shift times for cashier reports

### 3. `manager/reports-center.php`
- Same updates as admin version
- Manager can see their own shift times for reports

### 4. `reports-center.php` (Cashier version)
- Same updates for cashier role
- Auto-populates based on their own login/logout times

## Usage

### For Cashiers
1. Log in to the system (login time is recorded)
2. Navigate to Reports Center
3. Click on any cashier report (e.g., "Cashier Sales Report", "Shift Report", "Cash-Up Report")
4. The date/time fields will automatically be populated with your shift times:
   - **Start Date/Time**: Your last login time
   - **End Date/Time**: Your last logout time (or current time if still logged in)
5. You can manually adjust these times if needed
6. Generate the report

### For Admin/Manager
1. Same process as cashiers
2. The system uses the admin/manager's own shift times
3. They can optionally select a specific cashier from the dropdown to filter results

## Benefits

1. **Accuracy**: Reports automatically reflect the exact shift times, eliminating manual entry errors
2. **Speed**: No need to manually enter dates and times for every report
3. **Consistency**: All cashier reports use the same shift-based logic
4. **Flexibility**: Users can still manually adjust times if needed

## Testing

A test script has been created at `test_shift_helper.php` to verify:
1. User log table structure
2. Recent login/logout entries
3. Shift time retrieval for the current user
4. Formatted output for reports

To test:
1. Log in to the system
2. Navigate to `/test_shift_helper.php`
3. Review the output to ensure shift times are being tracked and retrieved correctly

## Technical Details

### Database Schema
The `user_log` table should have the following structure:
```sql
CREATE TABLE user_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id TEXT NOT NULL,
    action_type TEXT NOT NULL,  -- 'login' or 'logout'
    timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### JavaScript Integration
The shift data is passed from PHP to JavaScript via:
```javascript
window.USER_SHIFT = {
    start_date: '2026-08-28',
    start_time: '08:15',
    end_date: '2026-08-28',
    end_time: '17:30',
    has_shift_data: true
};
```

The `openReportModal()` function checks this data and applies it when opening cashier reports.

## Fallback Behavior

If shift data is not available (new user, data missing, etc.):
1. The system falls back to today's date
2. Uses business hours (opening/closing times) for time ranges
3. No errors are shown to the user
4. Everything works as it did before this feature

## Future Enhancements

Potential improvements:
1. Show multiple shifts if user logged in/out multiple times in a day
2. Add shift selection dropdown for users with multiple shifts
3. Track shift start/end in a separate table for better querying
4. Add "active shift" indicator in the UI
5. Generate shift-based reports automatically at shift end

## Notes

- This feature is backward compatible - existing functionality is preserved
- Manual date/time entry still works
- Quick date selection buttons (Today, Yesterday, This Week, etc.) still work
- The feature is enabled by default for all cashier-specific reports
