# Overnight Shifts Guide

## How Overnight Shifts Work with Auto-Populated Reports

### Scenario: Shift Crosses Midnight

**Example Shift:**
- Login: **Aug 27, 2026 at 11:00 PM**
- Logout: **Aug 28, 2026 at 3:00 AM**

### What Happens:

#### 1. **Shift Time Auto-Population** ✅
When you open a cashier report after this shift, the system will automatically set:
- **Start Date/Time**: Aug 27, 2026 23:00
- **End Date/Time**: Aug 28, 2026 03:00

This correctly captures your entire shift!

#### 2. **Business Day Logic** 📅
Your POS system has special "business day" logic that handles overnight operations. How transactions are categorized depends on your **closing time** setting:

##### If Closing Time is AFTER Midnight (e.g., 02:00 AM - Overnight Business):
Transactions between midnight and closing time are attributed to the **previous** business day.

**Your Example:**
- 11:00 PM - 11:59 PM (Aug 27) → Aug 27 business day
- 12:00 AM - 02:00 AM (Aug 28) → **Still Aug 27 business day** (overnight closing logic)
- 02:00 AM - 03:00 AM (Aug 28) → Aug 28 business day

##### If Closing Time is BEFORE Midnight (e.g., 10:00 PM - Regular Business):
Business day matches the calendar date. Transactions after midnight belong to the new day.

**Your Example:**
- 11:00 PM - 11:59 PM (Aug 27) → Aug 27 business day
- 12:00 AM - 03:00 AM (Aug 28) → Aug 28 business day

### How Reports Handle This:

#### 1. **Shift Reports** (Cashier Sales, Cashup, etc.)
These reports use the **actual time range** from your login to logout:
- ✅ Captures ALL transactions during your shift
- ✅ Works correctly across midnight
- ✅ Shows the complete shift period

**Report Range:** Aug 27, 2026 23:00 → Aug 28, 2026 03:00

#### 2. **Daily Business Reports** (Sales Report, Daily Summary, etc.)
These reports use **business day logic**:
- Groups transactions by business day (not calendar day)
- Respects your closing time settings
- May split your shift across multiple business days

## Visual Indicators

### Current Implementation:
When shift times are auto-populated, you see:
> **Shift Times Applied:** Date/time range set from your last login/logout.

### Recommended Practice:
For overnight shifts:
1. Check the date/time range is correct
2. Verify it covers your complete shift
3. Generate the report - the system handles the rest!

## Common Questions

### Q: Will my overnight shift report be accurate?
**A:** Yes! The system captures all transactions between your login and logout times, regardless of midnight crossing.

### Q: Why do some transactions show different business dates?
**A:** This is normal for businesses with overnight closing times. Transactions are grouped by "business day" rather than calendar day.

### Q: Can I manually adjust the shift times?
**A:** Yes! You can always manually edit the start/end dates and times if needed.

### Q: What if I forget to log out at shift end?
**A:** The system uses the current time as the end time, allowing you to generate a report for an ongoing shift. Remember to log out when your shift actually ends!

### Q: What if I log in multiple times during a shift?
**A:** The system uses your **most recent** login time. Best practice: log in once at shift start, log out once at shift end.

## Best Practices for Overnight Shifts

### 1. **Consistent Login/Logout**
- ✅ Log in at the start of your shift
- ✅ Log out at the end of your shift
- ❌ Don't log in/out multiple times during one shift

### 2. **Verify Date Range**
Before generating reports:
- Check the start date/time matches your actual login
- Check the end date/time matches your actual logout
- Look for the green "Shift Times Applied" notification

### 3. **Understand Business Day Logic**
- Know your business closing time setting
- Understand how overnight transactions are categorized
- Ask your manager if you're unsure

### 4. **Report at Shift End**
For most accurate reporting:
- Generate your shift report AFTER logging out
- This gives you the complete shift time range
- All transactions will be included

## Technical Details

### Database Recording:
```sql
-- Login recorded
INSERT INTO user_log (user_id, action_type, timestamp) 
VALUES ('john_doe', 'login', '2026-08-27 23:00:00');

-- Logout recorded
INSERT INTO user_log (user_id, action_type, timestamp) 
VALUES ('john_doe', 'logout', '2026-08-28 03:00:00');
```

### Report Query:
The system generates reports with:
```sql
WHERE created_at >= '2026-08-27T23:00' 
  AND created_at <= '2026-08-28T03:00'
```

This captures ALL transactions in your shift, regardless of business day logic.

## Troubleshooting

### Issue: Report shows wrong time range
**Solution:**
1. Check you logged in/out at correct times
2. Verify in test_shift_helper.php
3. Manually adjust if needed

### Issue: Transactions seem to be missing
**Solution:**
1. Verify the date/time range is correct
2. Check if transactions were made under a different username
3. Ensure you're looking at the right report type

### Issue: Business day split is confusing
**Solution:**
1. Check your business closing time setting
2. Understand overnight vs regular closing
3. Use shift reports (not daily reports) for your personal shift

## Summary

✅ **Overnight shifts work correctly** with auto-populated reports  
✅ **All transactions are captured** from login to logout  
✅ **Business day logic is separate** from shift time tracking  
✅ **Manual override is always available** if needed  

The shift-based reporting system handles overnight shifts seamlessly, giving you accurate reports every time!
