# EFT On Hand Feature - Implementation Guide

## Overview
This update adds "EFT on hand" functionality to the cash-up process, similar to the existing "Cash on hand" feature. This allows tracking of electronic fund transfer (EFT) amounts during daily reconciliation.

## Changes Made

### 1. Database Changes
- Added `eft_on_hand` column to `cashup_records` table (DECIMAL 10,2, default 0.00)
- Added `eft_over_short` column to `cashup_records` table (DECIMAL 10,2, default 0.00)
- Updated `pos.db.sql` schema file

### 2. Backend Changes
- Updated `save_cashup.php` to handle EFT on hand and EFT over/short amounts
- Added EFT data extraction and validation

### 3. Frontend Changes (manager/home.php)
- Updated cash-up modal from 5 steps to 6 steps
- Added Step 5: "Enter EFT On Hand" with input field and real-time over/short calculation
- Updated review section (now Step 6) to display EFT expected, on hand, and over/short
- Added EFT fields to all relevant JavaScript functions:
  - `updateCashUpReview()` - includes EFT calculations
  - `submitCashUp()` - includes EFT data in submission
  - Real-time EFT over/short calculation on input
- Updated step indicators to show 6 steps
- Updated date range display for Step 5

## Installation Instructions

### Step 1: Run the Setup Script
1. Open your browser and navigate to:
   ```
   http://localhost/manager/setup_eft_on_hand.php
   ```
2. The script will:
   - Create the `cashup_records` table if it doesn't exist (with EFT columns included)
   - Add EFT columns to existing table if needed
   - Create necessary database indexes
3. You should see a success message showing the table structure

### Step 2: Verify the Changes
1. Check that the setup completed successfully
2. The table should include these EFT-related columns:
   - `eft_on_hand` (DECIMAL 10,2, default 0.00)
   - `eft_over_short` (DECIMAL 10,2, default 0.00)
3. All existing cashup records will have these fields set to 0.00 by default

### Step 3: Test the Feature
1. Log in as a manager or admin
2. Navigate to the Manager Dashboard
3. Click "Cash Up" button
4. Go through the new 6-step process:
   - Step 1: Select Date Range & Staff
   - Step 2: Deductions & Sources
   - Step 3: Expenses
   - Step 4: Enter Cash On Hand
   - **Step 5: Enter EFT On Hand** (NEW)
   - Step 6: Review & Print

## How It Works

### Cash-Up Process Flow
1. **Step 4**: Enter actual cash on hand
   - System shows expected cash sales
   - Calculate over/short for cash
   
2. **Step 5**: Enter actual EFT on hand (NEW)
   - System shows expected EFT sales (from `card_sales_expected`)
   - Calculate over/short for EFT
   - Real-time calculation updates as you type

3. **Step 6**: Review summary
   - Shows both Cash and EFT sections separately
   - Displays expected vs actual amounts
   - Shows over/short for both payment methods

### Data Storage
All EFT data is stored in the `cashup_records` table:
- `card_sales_expected`: Expected EFT amount (from system)
- `eft_on_hand`: Actual EFT amount entered by user
- `eft_over_short`: Calculated difference (eft_on_hand - card_sales_expected)

## Files Modified/Created
1. `pos.db.sql` - Database schema
2. `manager/save_cashup.php` - Backend processing
3. `manager/home.php` - Frontend UI and JavaScript
4. `manager/setup_eft_on_hand.php` - Setup/migration script (NEW)
5. `EFT_ON_HAND_CHANGES.md` - This documentation (NEW)

## Rollback (if needed)
If you need to remove these changes:
```sql
-- Run this in your SQLite database
ALTER TABLE cashup_records DROP COLUMN eft_on_hand;
ALTER TABLE cashup_records DROP COLUMN eft_over_short;
```

## Support
If you encounter any issues:
1. Check that the migration completed successfully
2. Clear your browser cache
3. Verify that the `cashup_records` table has the new columns
4. Check browser console for JavaScript errors

## Future Enhancements
- Add EFT reconciliation reports
- Include EFT data in printed receipts
- Add EFT variance alerts for large discrepancies
