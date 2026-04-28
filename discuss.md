# EMRIS Inventory Report System — Feature Summary

> **Context:** The Nanox's previous inventory generator stopped/slow working.
> This system was built from scratch as a replacement, reading directly
> from their existing `imfsdb` database with no changes to their data structure.

---

## The Problem with the Old System

- The old generator could no longer produce reports/ it takes too long to provide data and sometimes can go on timeout wasting time
- No fallback or alternative existed
- Staff had no way to export or view current inventory figures
- Data was still being entered daily into the database — it just couldn't be read out

---

## What Was Built

Three PHP files that plug directly into the existing database:

| File | Purpose |
|------|---------|
| `generate_report.php` | Main UI — staff pick year/month and trigger actions |
| `inventory_view.php` | Browser-based inventory table for the selected period |
| `inventory_download_csv.php` | Generates and downloads a CSV file for the same period |

---

## Core Features

### 1. Live Database Reading
- Every report is generated fresh from the database at the moment it is requested
- No cached files, no pre-generated snapshots
- If a receiving entry was added this morning, it appears in today's report

### 2. Daily Accuracy
- Data is accurate as of the day it is run — not frozen at month-end
- Beginning stock is automatically calculated from the last recorded receiving entry before the selected period
- No manual monthly rollover is required by any staff member

### 3. Deleted Item Exclusion
- Items that have been soft-deleted in the system (`deleted_at` is set) are fully excluded from all reports
- Deleted receiving entries are also excluded from all quantity calculations
- This means the report reflects only what is currently active in the system

### 4. Correct Inventory Formula
For each item the ending stock is calculated as:

```
End Stock = Beg Stock + Received + Other In + WIP + Returns
          − Issued − Other Out
```

If the result is negative (data entry error), it is clamped to zero rather than showing a negative figure.

### 5. Period Filtering
- Filter by any specific **month and year**
- Or select **Full Year** to see the entire year aggregated
- The FA Code field allows filtering to a specific item group (e.g. `FS-CUT` shows all cutter wheels)

### 6. Include Zero-Balance Toggle
Two modes:

| Mode | Behaviour |
|------|-----------|
| **Off (default)** | Hides items with zero opening balance AND zero movement in the period. Depleted items (used up during the period) still appear. |
| **On** | Shows every item that has ever had warehouse activity, even if all values are zero. Useful for physical stock counts. |

### 7. Browser View (`inventory_view.php`)
- Clean table layout with sticky header
- Columns: FA Code, Material Type, PR Mode, Description, Price, Beg, Rec, OI, WIP, Ret, Iss, OO, End Stock, End Cost
- Zero values displayed as a dash `—` for easy reading
- End stock of zero is highlighted in red so depleted items are immediately visible
- Summary cards show: period, total items, total inventory value, and today's date
- "Beg Stock as of [date]" shown under each opening balance so auditors can trace the source

### 8. CSV Download (`inventory_download_csv.php`)
- One-click download from `generate_report.php`
- Filename is automatically named: `InventoryReport_April-2026.csv`
- Includes a `Beg Stock Date` column (the date of the snapshot used for opening balance)
- UTF-8 BOM included so Excel opens Filipino/Japanese characters without garbling
- Numbers are plain decimals (no thousand separators) so Excel can sum them directly

---

## What the System Does NOT Do

- It does not modify any data — read-only access only
- It does not replace the client's existing data entry system
- It does not require any changes to the database schema
- It does not depend on any third-party libraries or frameworks

---

## Database Tables Used

| Table | How It Is Used |
|-------|---------------|
| `items` | Master list of inventory items (filtered: `deleted_at IS NULL`) |
| `pricelists` | Latest price per item (most recently inserted price record) |
| `receivings` | Source of all stock movements: beg stock, received, other in/out, WIP, returns (filtered: `deleted_at IS NULL`) |
| `issuances` | Quantities issued out per item per period |

---

## Key Integrity Points to Raise in Discussion

1. **Numbers match the database exactly** — there is no intermediate calculation layer that could introduce rounding or logic errors.

2. **Soft deletes are respected** — if the client removed a receiving entry or an item in their system, it will not appear in any report.

3. **The old system and this system use the same data** — the database was not touched, only the reading layer was replaced.

4. **Auditability** — the Beg Stock Date column in the CSV tells the client exactly which snapshot date was used for the opening balance on every single row, so any figure can be traced back to a specific database record.

5. **Daily updates are automatic** — there is no end-of-month job or manual step required. The report is always current.
