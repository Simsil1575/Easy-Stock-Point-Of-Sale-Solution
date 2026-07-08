# Cash Up Logic Fix - Summary

## Date: February 10, 2026

## Issue
The manager's cash up functionality was incorrectly calculating:
1. **Expenses** - Were including cash back values, which is incorrect
2. **Cash Sales Expected** - Was being reduced by ALL cash-out transactions including cash back
3. **Hansa Amount** - Needed verification to ensure it's calculated correctly

## Root Cause
In `manager/get_cashup_data.php`, the `$totalCashOut` calculation (line 221-230) was summing ALL cash-out transactions, including:
- Actual business expenses (correct)
- Tips (incorrect - should be tracked separately)
- Cash back (incorrect - should be tracked separately)

This caused the expected cash to be incorrectly reduced by tips and cash back amounts, leading to inaccurate cash up reports.

## Solution Applied

### File Modified: `manager/get_cashup_data.php`

#### Change 1: Cash Out Calculation (Lines 220-238)
**Before:**
```php
// Cash Out
$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-out' AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
```

**After:**
```php
// Cash Out (ONLY expenses - excluding tips and cash back)
// This follows the same logic as the expenses query to ensure consistency
$cashOutQuery = $db->prepare("
    SELECT COALESCE(SUM(amount), 0) 
    FROM cash_transactions 
    WHERE type='cash-out' 
    AND (description NOT LIKE '%Tips%' AND description NOT LIKE '%Cash Back%' AND description NOT LIKE '%tip%' AND description NOT LIKE '%cash back%')
    AND (" . getRangeWhere('created_at') . ")" . getCashierFilter('cashier_id') . "
");
```

**Impact:** Now `$totalCashOut` only includes actual business expenses, excluding tips and cash back. This ensures the cash sales expected calculation is accurate.

#### Change 2: Enhanced Expenses Comment (Lines 295-309)
Added clear comments to explain that expenses should NOT include tips or cash back:
```php
// 6. Expenses (excluding tips and cash back)
// This matches the same logic used in totalCashOut above to ensure consistency
// Expenses are ONLY actual business expenses, NOT tips or cash back transactions
```

**Impact:** Developers will clearly understand that expenses exclude tips and cash back.

## Verification

### Cash Sales Expected Formula
```
Cash Sales Expected = Cash In + Total Cash Sales + Credit Payments - Expenses (excluding tips/cash back)
```

### Expenses Calculation
Expenses now correctly exclude:
- Tips (description contains 'Tips' or 'tip')
- Cash Back (description contains 'Cash Back' or 'cash back')

### Hansa Amount Calculation
Verified as correct - calculates total revenue from "Hansa Draught" products:
```sql
SELECT COALESCE(SUM(oi.quantity * oi.price), 0)
FROM order_items oi
JOIN orders o ON oi.order_id = o.id
WHERE ... AND (LOWER(TRIM(oi.product_name)) LIKE '%hansa draught%' OR LOWER(TRIM(oi.product_name)) = 'hansa draught')
```

## Testing Recommendations

1. **Test Cash Up with Cash Back**
   - Create a cash back transaction
   - Perform cash up
   - Verify expenses do NOT include the cash back amount
   - Verify expected cash is NOT reduced by cash back amount

2. **Test Cash Up with Tips**
   - Create a tips transaction
   - Perform cash up
   - Verify expenses do NOT include the tips amount
   - Verify expected cash is NOT reduced by tips amount

3. **Test Cash Up with Regular Expenses**
   - Create regular expense (e.g., "Electricity Bill")
   - Perform cash up
   - Verify expenses DO include this amount
   - Verify expected cash IS reduced by this expense

4. **Test Hansa Amount**
   - Sell Hansa Draught products
   - Perform cash up
   - Verify Hansa amount correctly shows total revenue from Hansa Draught sales

## Files Modified
- `manager/get_cashup_data.php` - Updated cash out and expenses calculations to exclude tips and cash back

## No Changes Required
The following were verified and found to be correct:
- Cash back tracking (separate from expenses)
- Tips tracking (separate from expenses)
- Hansa amount calculation
- Credit returns calculation
- All other cash up calculations
