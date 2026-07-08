# Cashier ID Fix Implementation Guide

## Overview

This document provides step-by-step instructions to fix cashier_id inconsistencies across the codebase. The goal is to standardize on TEXT username values for consistent storage across all tables.

---

## Step 1: Include Helper Function

Add this to the top of all files that insert cashier_id:

```php
<?php
session_start();
require_once 'cashier_helper.php'; // Add this line
```

---

## Step 2: Update Critical Files

### File: `process_order.php`

**Current Code (Line 40):**
```php
':cashier_id' => $_SESSION['username'] // Add cashier username from session
```

**Fixed Code:**
```php
':cashier_id' => getCashierId($db) // Returns username string
```

**Also update:**
- Line 138: `':cashier_id' => $_SESSION['username']` → `':cashier_id' => getCashierId($db)`
- Line 164: `':cashier_id' => $_SESSION['username']` → `':cashier_id' => getCashierId($db)`

---

### File: `process_credit.php`

**Current Code (Line 40):**
```php
$_SESSION['username'] // Add cashier username from session
```

**Fixed Code:**
```php
getCashierId($db)
```

**Full context:**
```php
$stmt = $db->prepare("INSERT INTO credit_sales (creditor_id, total_amount, due_date, created_at, cashier_id) 
                     VALUES (?, ?, ?, ?, ?)");
$stmt->execute([
    $creditorId, 
    $total, 
    $dueDate,
    date('Y-m-d H:i:s'),
    getCashierId($db) // Changed from $_SESSION['username']
]);
```

---

### File: `process_tab.php`

**Current Code (Line 74):**
```php
$cashierUsername = $_SESSION['username'] ?? 'Unknown';
```

**Fixed Code:**
```php
$cashierUsername = getCashierId($db); // Consistent helper function
```

**Update Line 84:**
```php
// Before:
$createTabStmt = $db->prepare("INSERT INTO tabs (tab_name, opening_balance, current_balance, cashier_id) VALUES (?, 0, 0, ?)");
$createTabStmt->execute([$tableName, $cashierUsername]);

// After (no change needed if using helper):
$createTabStmt = $db->prepare("INSERT INTO tabs (tab_name, opening_balance, current_balance, cashier_id) VALUES (?, 0, 0, ?)");
$createTabStmt->execute([$tableName, $cashierUsername]);
```

**Note:** For `tabs` table, you may need to remove the foreign key constraint since we're using TEXT username instead of INTEGER user_id.

**Update Line 154:**
```php
// Before:
$insertItemStmt->execute([
    $tabId,
    $item['name'],
    $quantity,
    $unitPrice,
    $cashierUsername
]);

// After (no change needed if using helper):
$insertItemStmt->execute([
    $tabId,
    $item['name'],
    $quantity,
    $unitPrice,
    $cashierUsername
]);
```

---

### File: `process_cashback.php`

**Update Lines 33, 45:**
```php
// Before:
':cashier_id' => $_SESSION['username'] ?? 'Unknown'

// After:
':cashier_id' => getCashierId($db)
```

**Update Line 51 (Add cashier_id to cash_transactions):**
```php
// Before:
$stmtCashOut = $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (:type, :amount, :description, :created_at)");

// After:
$stmtCashOut = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (:type, :amount, :description, :cashier_id, :created_at)");
$stmtCashOut->execute([
    ':type' => 'cash-out',
    ':amount' => $cashBack,
    ':description' => 'Cash back from EFT payment',
    ':cashier_id' => getCashierId($db),
    ':created_at' => date('Y-m-d H:i:s')
]);
```

---

### File: `cashrefrence.php`

**Update Line 274 (Add cashier_id to cash_transactions):**
```php
// Before:
$stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, created_at) VALUES (?, ?, ?, datetime('now', '+2 hours'))");
$stmt->execute([$_POST['action'], $_POST['amount'], $_POST['description']]);

// After:
require_once 'cashier_helper.php';
$stmt = $db->prepare("INSERT INTO cash_transactions (type, amount, description, cashier_id, created_at) VALUES (?, ?, ?, ?, datetime('now', '+2 hours'))");
$stmt->execute([$_POST['action'], $_POST['amount'], $_POST['description'], getCashierId($db)]);
```

**Update Line 298:**
```php
// Before:
$stmt->execute([$_POST['return_amount'], $_POST['reason'], $_SESSION['username'] ?? 'Unknown']);

// After:
require_once 'cashier_helper.php';
$stmt->execute([$_POST['return_amount'], $_POST['reason'], getCashierId($db)]);
```

**Update Line 309:**
```php
// Before:
$stmt->execute([$_POST['order_id'] ?? null, $_POST['transaction_ref'], $_POST['wallet_provider'], $_POST['amount'], $_POST['cashier_id'] ?? 1]);

// After:
require_once 'cashier_helper.php';
$stmt->execute([$_POST['order_id'] ?? null, $_POST['transaction_ref'], $_POST['wallet_provider'], $_POST['amount'], getCashierId($db)]);
```

**Update Line 331:**
```php
// Before:
$stmt->execute([$_POST['creditor_id'], $_POST['total_amount'], $_POST['due_date'], $_POST['cashier_id'] ?? 1]);

// After:
require_once 'cashier_helper.php';
$stmt->execute([$_POST['creditor_id'], $_POST['total_amount'], $_POST['due_date'], getCashierId($db)]);
```

---

### File: `void_transaction.php`

**Update Line 63:**
```php
// Before:
':cashier_id' => $_SESSION['username'] ?? 'Unknown'

// After:
require_once 'cashier_helper.php';
':cashier_id' => getCashierId($db)
```

---

### File: `api/process_refund.php`

**Update Line 27:**
```php
// Before:
$cashierId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// After:
require_once '../cashier_helper.php';
$cashierId = getCashierId($db); // Returns username string, defaults to 'Unknown' if not found
```

---

### File: `view-tab.php` (and variants)

**Update Line 726:**
```php
// Before:
$cashierUsername

// After:
require_once 'cashier_helper.php';
getCashierId($db)
```

**Full context:**
```php
// Before:
$stmtMixed->execute([
    $orderId,
    $cashAmount,
    $eftAmount,
    $transactionRef,
    $walletProvider,
    $cashierUsername
]);

// After:
require_once 'cashier_helper.php';
$stmtMixed->execute([
    $orderId,
    $cashAmount,
    $eftAmount,
    $transactionRef,
    $walletProvider,
    getCashierId($db)
]);
```

---

## Step 3: Database Schema Updates

### Update Schema to Use TEXT for All cashier_id Columns

Update `pos.db.sql` to ensure all cashier_id columns are TEXT (no foreign keys):

```sql
-- Example for orders table:
CREATE TABLE IF NOT EXISTS "orders" (
    "id" INTEGER NOT NULL UNIQUE,
    "total" DECIMAL(10, 2) NOT NULL,
    "cash_received" DECIMAL(10, 2) NOT NULL,
    "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    "cashier_id" TEXT,
    PRIMARY KEY("id" AUTOINCREMENT)
);

-- Apply similar changes to ensure TEXT type:
-- - cash_transactions: cashier_id TEXT
-- - credit_sales: cashier_id TEXT  
-- - credit_returns: cashier_id TEXT (already TEXT)
-- - eft_payments: cashier_id TEXT
-- - mixed_payments: cashier_id TEXT (already TEXT)
-- - payments: cashier_id TEXT
-- - refunds: cashier_id TEXT (remove INTEGER FK)
-- - tab_payments: cashier_id TEXT (remove INTEGER FK)
-- - tabs: cashier_id TEXT (remove INTEGER FK)
-- - void_transactions: cashier_id TEXT (already TEXT)
```

### Note on Foreign Keys

Since we're using TEXT username instead of INTEGER user_id:
- **Remove foreign key constraints** from tables that reference users.id
- This means we lose referential integrity enforcement at the database level
- Application code must ensure username validity
- Consider adding application-level validation using `validateCashierId()` helper

---

## Step 5: Testing Checklist

After implementing fixes:

1. **Test Order Processing**
   - [ ] Create a new order
   - [ ] Verify cashier_id is stored as INTEGER
   - [ ] Check foreign key constraint works

2. **Test Credit Sales**
   - [ ] Create credit sale
   - [ ] Verify cashier_id is INTEGER

3. **Test Tab Processing**
   - [ ] Create tab
   - [ ] Add items to tab
   - [ ] Verify cashier_id is INTEGER

4. **Test Refunds**
   - [ ] Process refund
   - [ ] Verify cashier_id is not NULL

5. **Test Cash Transactions**
   - [ ] Record cash-in
   - [ ] Record cash-out
   - [ ] Verify cashier_id is stored

6. **Test Reports**
   - [ ] Verify all reports still work
   - [ ] Check cashier filtering works correctly

---

## Step 4: Remove Foreign Key Constraints

For tables that currently have foreign keys to users.id, you'll need to remove them:

```sql
-- Example: Remove FK from tabs table (if it exists)
-- Note: SQLite doesn't support DROP CONSTRAINT directly
-- You may need to recreate the table or use a migration script
```

**Important:** Since we're using TEXT username, foreign key constraints to users.id won't work. The helper function provides validation instead.

---

## Step 5: Rollback Plan

If issues occur, you can temporarily revert by:

1. Comment out `require_once 'cashier_helper.php';`
2. Replace `getCashierId($db)` with `$_SESSION['username'] ?? 'Unknown'`

---

## Notes

- The helper function `getCashierId()` returns username string, defaults to 'Unknown' if not found
- No exceptions thrown - always returns a string value
- Database validation is optional (pass $db parameter if you want validation)
- Always test in development environment first
- No migration script needed since we're standardizing on TEXT (most data already TEXT)
