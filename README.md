## EMRIS Inventory & CSV Reporting System

This system provides a high-fidelity interface for managing and auditing inventory levels. It calculates stock positions by reconciling historical "Beginning Stock" with real-time transactional data (Receivings and Issuances).

### 1. Core Logic & Inventory Formula
The system determines the **Ending Stock** and **Ending Cost** for every item using a precise calculation flow:

$$Ending\ Stock = (Beginning\ Stock + Received + Other\ In + WIP\ In + Returns) - (Issued + Other\ Out)$$

* **Beginning Stock:** Derived from the most recent non-zero `beg_stock` entry in the `receivings` table prior to the selected period.
* **Ending Cost:** Calculated as $Ending\ Stock \times Unit\ Price$, where the price is pulled from the latest entry in the `pricelists` table.
* **Zero-Floor Protection:** If the calculated ending stock is negative, the system automatically floors the value at **0** to prevent logical inventory errors.

---

### 2. Functional Modules

| Module | Description | Key File |
| :--- | :--- | :--- |
| **CSV Generator** | A dark-mode web interface for selecting reporting periods (Month/Year) and triggering downloads. | `generate_report.php` |
| **Data Export** | Processes SQL queries to generate a standard CSV file formatted for Excel or ERP integration. | `inventory_download_csv.php` |
| **Validation View** | A comprehensive HTML table used by administrators to verify calculations and trace the source of Beginning Stock. | `inventory_view.php` |
| **Core Config** | Handles high-performance database pooling, error handling, and Manila-specific timezone settings. | `config.php` |

---

### 3. Key Technical Features

* **Precise Beginning Stock Tracking:** Unlike systems that only look at the start of the year, this program searches backward through the `receivings` table to find the last physical count or verified beginning balance.
* **Granular Component Breakdown:** The report doesn't just show "In/Out"; it breaks down movement into specific categories like **WIP (Work in Progress)**, **Returns**, and **Other In/Out** for specialized manufacturing workflows.
* **Search-to-Filter Capability:** The Validation View includes a `LIKE` search for **FA Codes**, allowing users to instantly isolate specific fixed assets or material groups.
* **Performance Optimization:** `config.php` uses static connection reuse and explicit read timeouts (30s) to ensure the system remains responsive even with large datasets.

---

### 4. Infrastructure Requirements

* **Environment:** PHP 7.4+ with `mysqli` extension.
* **Database:** MySQL/MariaDB (configured for port `3308` by default).
* **Timezone:** Hardcoded to `Asia/Manila` to ensure reporting accuracy within Philippine business hours.

> **Note for Nanox IT:** The system is currently configured to connect to the `imfsdb` database using the `root` user. For production security, it is recommended to transition these credentials to a dedicated service account with restricted privileges.
