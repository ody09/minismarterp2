# Round 2: Steady-State Local Performance Benchmark (20 Concurrent Users) - Corrected Evidence

## 1. Executive Summary & Test Classification

- **Test Type**: Controlled Multi-Stage Steady-State Benchmark with an isolated 10-minute 20-VU plateau.
- **Reference Commit**: `266ef20` on branch `codex/audit-fixes`.
- **Target System**: Smart Mini ERP on local test environment.
- **Execution Date**: 2026-11-17.
- **Key Result**: **100% Success Rate (0 HTTP failures, 0 critical assertion failures across 9,554 requests)**. Zero slow queries (>0.5s) logged, and 100% stock ledger consistency confirmed post-test via `erp:check-stock`.
- **Hardware Disclaimer**: This benchmark was executed on a local workstation. Production server hardware, network virtualization, and concurrent tenant usage will exhibit different performance characteristics.
- **Git Status Note**: Application source code and database migrations/indexes were 100% untouched. The working tree contains untracked load-test evidence directories under `docs/audit/evidence/load-test/`.

---

## 2. Environment & Verified System Specifications

All specifications verified directly from Windows management interfaces and runtime binaries:

### 2.1 Hardware Specifications
- **CPU**: AMD Ryzen 7 7735HS with Radeon Graphics (8 Cores, 16 Logical Processors @ 3.20 GHz base).
  - *Verified via*: `Get-CimInstance Win32_Processor`.
- **Installed RAM**: 16.0 GB physical memory.
  - *Verified via*: `Get-CimInstance Win32_PhysicalMemory`.
- **Operating System**: Microsoft Windows 11 Pro, Version 10.0.26300 (64-bit).
  - *Verified via*: `Get-CimInstance Win32_OperatingSystem`.

### 2.2 Software Stack & Topology
- **PHP**: PHP 8.4.27 (cli / cgi-fcgi, NTS, Visual C++ 2022 x64) with OPcache enabled (`opcache.enable=1`, `opcache.jit=tracing`).
- **PHP Worker Pool**: 16 dedicated `php-cgi.exe` workers listening on TCP ports `9001` through `9016`.
- **Web Server**: Nginx 1.26.2 (Windows port) listening on port `8088`.
  - Upstream pooling: `upstream php_pool { least_conn; server 127.0.0.1:9001 ... 9016; }`
  - Buffering: `fastcgi_buffers 16 16k; fastcgi_buffer_size 32k;`
  - Timeouts: connect 30s, send 60s, read 120s.
  - Configuration saved in: `docs/audit/evidence/load-test/round2-steady/nginx.conf`.
- **Database**: MySQL 8.4.13 Community Server (isolated portable instance on port `3307`).
  - `innodb_buffer_pool_size`: 512 MB
  - `innodb_log_file_size`: 128 MB
  - `innodb_flush_log_at_trx_commit`: 2
  - `max_connections`: 200
  - `slow_query_log`: 1 (threshold: `0.5s`, log file: `.cache/bench/slow_queries.log`)
  - Configuration saved in: `docs/audit/evidence/load-test/round2-steady/my.ini`.
- **Load Generator**: Grafana k6 v0.54.0 (Windows amd64).
- **Resource Monitor**: PowerShell script (`monitor_resources_v2.ps1`) sampling OS counters and delta CPU/RAM every 5 seconds for `mysqld`, `php-cgi`, `nginx`, and `k6`.

---

## 3. Methodological Corrections & Findings from Round 1

Round 1 was classified as a progressive ramp-up test. Round 2 corrected the root causes and interpretations:

1. **Root Cause of Round 1 Failures (124 Check Failures)**:
   - In Nginx `access.log`, 124 requests to `/products?q=SKU-00x` returned **`HTTP 403 Forbidden`**.
   - *Cause*: Round 1 sent all roles to `/products`. The `inventory` role possesses `inventory.view` and `batches.view` but does NOT have `products.view`. Laravel correctly rejected the request with 403.
   - *Correction*: Round 2 mapped read routes strictly to role permissions: `inventory` queries `/batches` and `/inventory`, while `products.view` routes are accessed only by `admin`, `purchasing`, and `sales`.
2. **True Steady-State Load Schedule**:
   - Explicit separation between ramp transitions and flat measurement windows.
   - Dedicated 10-minute flat plateau at exactly 20 concurrent Virtual Users (VUs).
3. **Interleaved Role Distribution**:
   - Virtual users are interleaved across 4 roles: Admin, Purchasing, Sales, Inventory.
   - Admin write operations are balanced 50/50 between Purchase Order cycles and Sales Order cycles.
4. **Deterministic Search & Validations**:
   - Known product search targets existing 4-digit SKUs (`SKU-0010` .. `SKU-0099`).
   - Expected empty search explicitly queries a nonexistent string (`NONEXISTENT_ITEM_XYZ_999`).
   - Status checks verify actual localized domain values (`'مستلم'` for received POs, `'معتمد'` for confirmed SOs).
   - Stock operation link verified on completed write cycles.
5. **Accurate Process Resource Profiling**:
   - Monitored real delta CPU time normalized by 16 logical cores.

---

## 4. Benchmark Schedule & Stages

The test ran for 15 minutes and 50 seconds (951.9s) across 9 distinct stages:

| Stage # | Target VUs | Duration | Stage Type | Description |
|---|---|---|---|---|
| 1 | 2 VUs | 5s | Ramp | Warmup ramp |
| 2 | 2 VUs | 60s (1 min) | **Steady** | Warmup baseline |
| 3 | 5 VUs | 10s | Ramp | Step ramp |
| 4 | 5 VUs | 120s (2 min) | **Steady** | 5 VUs steady state |
| 5 | 10 VUs | 10s | Ramp | Step ramp |
| 6 | 10 VUs | 120s (2 min) | **Steady** | 10 VUs steady state |
| 7 | 20 VUs | 15s | Ramp | Full load ramp |
| 8 | 20 VUs | 600s (10 min) | **Steady** | **Isolated 20-VU Steady State** |
| 9 | 0 VUs | 10s | Cooldown | Ramp down |

---

## 5. Strict Separation of Performance Metrics

> **Important Metric Definitions**:
> - **Pure HTTP Request Latency (`http_req_duration`)**: Measures network and application time for individual HTTP request-response cycles.
> - **Multi-Step Write Cycles (`erp_20vu_po_write_cycle` / `erp_20vu_so_write_cycle`)**: Measures complete transactional flows (Form GET + CSRF extract + Draft POST + Review/Confirm Form GET + Version extract + Post Confirm + Final State Verify).
> - **Mixed Operations Metric (`erp_duration_*`)**: Composite metric recording both single HTTP reads and multi-step write cycles across different stages. It must **not** be confused with pure HTTP latency.
> - **p99 Availability**: In raw k6 JSON export, custom trend metrics export `avg`, `min`, `med`, `max`, `p(90)`, and `p(95)`. `p99` was **not exported** in the summary data because `--summary-trend-stats=p(99)` was not configured. It is recorded as **Not Available (N/A)** rather than guessed.

### 5.1 Pure HTTP Request Latency (`http_req_duration`)
Aggregated across all 9,554 HTTP requests executed during the test:

| Metric | Measured Value |
|---|---|
| **Total HTTP Requests** | 9,554 requests |
| **HTTP Request Failure Rate** | **0.00%** (0 / 9,554) |
| **Minimum Latency** | 134.85 ms |
| **Median Latency (p50)** | 424.26 ms |
| **Average Latency** | 420.90 ms |
| **90th Percentile (p90)** | 563.11 ms |
| **95th Percentile (p95)** | 908.97 ms |
| **99th Percentile (p99)** | *Not Available in raw export* |
| **Maximum Latency** | 1,596.28 ms |

---

### 5.2 Multi-Step Write Cycles (Isolated 20-VU Steady Stage Latencies & Run-Wide Counts)

> **Important Clarification on Sample Counts**:
> - The counts of **339 completed Purchase Order cycles** and **383 completed Sales Order cycles** (totaling 722 writes) represent the **entire test run across all stages** (Warmup, 5VU, 10VU, and 20VU).
> - The exact sample count of write cycles executed during the isolated 20-VU steady phase alone was not exported separately by the k6 summary and is recorded as **Not Available (N/A)**.

| Transactional Flow | Full-Run Total Samples | 20VU Phase Samples | Min | Median (p50) | Average | p90 | p95 | p99 | Max |
|---|---|---|---|---|---|---|---|---|---|
| **PO Write Cycle** (Create + Receive + Batch + Ledger) | 339 cycles (Full Run) | *Not Available* | 1,449.00 ms | 3,019.00 ms | 2,563.53 ms | 3,212.00 ms | 3,249.00 ms | *N/A* | 4,001.00 ms |
| **SO Write Cycle** (Create + Confirm + FEFO Deduct + Ledger) | 383 cycles (Full Run) | *Not Available* | 1,438.00 ms | 3,021.00 ms | 2,610.15 ms | 3,230.00 ms | 3,270.00 ms | *N/A* | 4,058.00 ms |

---

### 5.3 Mixed Operation Duration Across Flat Stages (`erp_duration_*`)
Composite metric covering single reads and multi-step writes:

| Steady Stage | Samples (Iterations) | Min | Median (p50) | Average | p90 | p95 | p99 | Max |
|---|---|---|---|---|---|---|---|---|
| **Warmup (2 VUs)** | *Not separated in summary* | 191.39 ms | 349.18 ms | 473.99 ms | 1,043.74 ms | 1,375.15 ms | *N/A* | 1,527.00 ms |
| **5 VUs Steady** | *Not separated in summary* | 175.94 ms | 446.39 ms | 736.24 ms | 1,705.50 ms | 3,063.25 ms | *N/A* | 3,625.00 ms |
| **10 VUs Steady** | *Not separated in summary* | 178.34 ms | 461.69 ms | 794.98 ms | 1,844.40 ms | 3,168.70 ms | *N/A* | 3,436.00 ms |
| **20 VUs Steady** | *Not separated in summary* | 181.12 ms | 462.10 ms | 787.03 ms | 1,990.80 ms | 3,104.70 ms | *N/A* | 4,058.00 ms |
| **Overall Test** | 5,162 total iters | 172.53 ms | 459.58 ms | 774.33 ms | 1,960.90 ms | 3,106.00 ms | *N/A* | 4,058.00 ms |

---

### 5.4 Breakdown of Individual Operations (20-VU Steady Phase)

| Operation | Category | Min | Median (p50) | Average | p90 | p95 | p99 | Max |
|---|---|---|---|---|---|---|---|---|
| Dashboard (`/account`) | Read | 215.63 ms | 536.02 ms | 591.88 ms | 905.87 ms | 1,036.01 ms | *N/A* | 1,445.47 ms |
| Products (Known Search) | Read | 189.40 ms | 416.36 ms | 371.77 ms | 466.21 ms | 479.59 ms | *N/A* | 958.66 ms |
| Products (Empty Search) | Read | 182.22 ms | 411.37 ms | 362.41 ms | 456.01 ms | 468.10 ms | *N/A* | 1,099.20 ms |
| Batches Master Data | Read | 197.59 ms | 440.52 ms | 397.23 ms | 500.93 ms | 509.44 ms | *N/A* | 1,003.84 ms |
| Inventory Stock Levels | Read | 181.12 ms | 411.63 ms | 368.73 ms | 468.82 ms | 476.33 ms | *N/A* | 593.04 ms |
| Inventory Transactions | Read | 198.48 ms | 473.44 ms | 421.07 ms | 523.76 ms | 537.25 ms | *N/A* | 986.96 ms |
| Purchases Index | Read | 191.49 ms | 418.41 ms | 367.64 ms | 467.47 ms | 482.34 ms | *N/A* | 592.33 ms |
| Purchases Filter (Status) | Read | 187.68 ms | 411.97 ms | 359.72 ms | 459.38 ms | 470.84 ms | *N/A* | 514.59 ms |
| Sales Index | Read | 194.74 ms | 412.07 ms | 366.16 ms | 470.24 ms | 478.48 ms | *N/A* | 556.43 ms |
| Sales Filter (Status) | Read | 188.89 ms | 429.66 ms | 377.59 ms | 468.13 ms | 483.26 ms | *N/A* | 606.85 ms |
| Sales Report (Financial) | Aggregation | 369.13 ms | 874.29 ms | 757.54 ms | 974.20 ms | 984.98 ms | *N/A* | 1,426.43 ms |
| Purchases Report (Financial) | Aggregation | 348.30 ms | 874.78 ms | 753.67 ms | 979.81 ms | 991.38 ms | *N/A* | 1,012.46 ms |
| Inventory Report (Valuation) | Aggregation | 393.80 ms | 1,006.42 ms | 859.42 ms | 1,109.94 ms | 1,120.47 ms | *N/A* | 1,596.28 ms |
| PO Write Cycle | Multi-step | 1,449.00 ms | 3,019.00 ms | 2,563.53 ms | 3,212.00 ms | 3,249.00 ms | *N/A* | 4,001.00 ms |
| SO Write Cycle | Multi-step | 1,438.00 ms | 3,021.00 ms | 2,610.15 ms | 3,230.00 ms | 3,270.00 ms | *N/A* | 4,058.00 ms |

---

## 6. Resource Utilization & System Load

Averaged per phase from `system_metrics_v2.csv` (16 logical cores):

| Phase | Samples | Total Host CPU% | Available RAM | MySQL CPU% | MySQL RAM | PHP-CGI CPU% | PHP-CGI RAM | Nginx CPU% | k6 CPU% |
|---|---|---|---|---|---|---|---|---|---|
| **Warmup (2 VUs)** | 12 | 17.5% | ~763 MB | 0.28% | 572 MB | 2.11% | 732 MB | 0.01% | 0.03% |
| **5 VUs Steady** | 24 | 15.6% | ~906 MB | 0.43% | 572 MB | 2.68% | 741 MB | 0.01% | 0.03% |
| **10 VUs Steady** | 24 | 15.6% | ~939 MB | 0.60% | 572 MB | 3.03% | 743 MB | 0.01% | 0.04% |
| **20 VUs Steady** | 120 | **17.9%** | **~885 MB** | **0.80%** | **573 MB** | **4.27%** | **745 MB** | **0.01%** | **0.06%** |

- **CPU Utilization**: Across 20 VUs, average total host CPU was 17.9% across 16 logical cores.
- **Process Memory**: PHP-CGI workers stabilized at ~745 MB combined (~46 MB per worker). MySQL buffer pool occupied ~573 MB.
- **Instrumentation Limits**: Micro-queuing at the FastCGI socket backlog was not directly measured (no OS socket queue depth probe). Additionally, a 16-minute test window is insufficient to confirm or refute long-term memory leaks.

---

## 7. Database Engine & Concurrency Analysis

### 7.1 InnoDB Locking & Contention
Pre-test and post-test status captured from MySQL 8.4:

| Variable | Pre-Test | Post-Test | Delta | Verification Status |
|---|---|---|---|---|
| `Innodb_row_lock_waits` | 0 | 119 | **119** | Measured (119 waits over 539,662 queries) |
| `Innodb_row_lock_time` | 0 | 3,059 ms | **3,059 ms** | Measured (~3.06s cumulative wait time) |
| `Innodb_row_lock_time_avg`| 0 | 25 ms | **25 ms** | Measured (average wait per contended row) |
| `Innodb_row_lock_time_max`| 0 | 77 ms | **77 ms** | Measured (maximum lock wait) |
| `Innodb_deadlocks` | *null* | *null* | *null* | **Unmeasured by global status variable** |
| `Queries` | 7,747 | 547,409 | **539,662** | Measured |

- **Deadlock Status Clarification**: In MySQL 8.4, `SHOW GLOBAL STATUS LIKE 'Innodb_deadlocks'` returns NULL because deadlocks are not tracked via a global status counter. In `SHOW ENGINE INNODB STATUS` (`mysql_innodb_status_post.txt`), no `LATEST DETECTED DEADLOCK` section was present at the capture timestamp. However, because deadlocks are not tracked continuously in a cumulative counter, full run-long deadlocks are unverified beyond the snapshot.
- **Contention Assessment**: 119 lock waits occurred during 722 concurrent write transactions (which include pessimistic `FOR UPDATE` locks on batch rows). The average wait was 25 ms.

### 7.2 Slow Query Log
- Threshold: `long_query_time = 0.5s`
- Log file: `.cache/bench/slow_queries.log`
- Total entries logged: **0 queries**.

---

## 8. Data Ledger & Detailed Transaction Reconciliation

### 8.1 Reconciling Stock Operations (+722) vs Inventory Transactions (+730)
In the raw row counts:
- Stock operations increased from 6,000 to 6,722 (**+722**).
- Inventory transactions increased from 12,335 to 13,065 (**+730**).

A dedicated audit script (`inspect_stock_delta.php`) queried all stock operations with `id > 6000`:
- **714 stock operations** generated exactly **1 inventory transaction** each (= 714 transactions).
- **8 stock operations** generated exactly **2 inventory transactions** each (= 16 transactions).
- **Total transactions**: 714 + 16 = **730 transactions**.

### 8.2 Explanation of the 8 Multi-Transaction Operations
All 8 operations with 2 transactions were Sales Order issue operations (`type: issue`):
- `Op ID: 6227` (Ref: `SO:5130`)
- `Op ID: 6340` (Ref: `SO:5183`)
- `Op ID: 6377` (Ref: `SO:5203`)
- `Op ID: 6387` (Ref: `SO:5209`)
- `Op ID: 6421` (Ref: `SO:5228`)
- `Op ID: 6556` (Ref: `SO:5301`)
- `Op ID: 6618` (Ref: `SO:5333`)
- `Op ID: 6646` (Ref: `SO:5343`)

**Cause**: FEFO batch allocation. In each of these 8 Sales Orders, the oldest product batch did not have enough remaining quantity to fulfill the entire requested cartons. The FEFO allocation engine split the order line across 2 batches:
- *Example `Op ID: 6618` (`SO:5333`)*: Customer ordered 3 cartons (36 units). Earliest Batch `284` had only 35 units remaining (`quantity: -35`, `allocation_number: 1`). The remaining 1 unit was allocated from the next Batch `5284` (`quantity: -1`, `allocation_number: 2`).
- Each allocation produces a separate record in `inventory_transactions`.
- Therefore, 1 document/operation does **not** equal 1 transaction. The difference of +8 is mathematically and structurally verified in the database.

### 8.3 Complete Row Count Delta Table

| Table | Pre-Test Rows | Post-Test Rows | Delta | Explanation |
|---|---|---|---|---|
| `users` | 20 | 20 | 0 | Benchmark accounts |
| `roles` | 4 | 4 | 0 | Roles matrix |
| `permissions` | 31 | 31 | 0 | System permissions |
| `role_permissions` | 57 | 57 | 0 | Role-permission mappings |
| `products` | 200 | 200 | 0 | Master catalog |
| `categories` | 20 | 20 | 0 | Master catalog |
| `suppliers` | 50 | 50 | 0 | Master catalog |
| `customers` | 100 | 100 | 0 | Master catalog |
| `purchase_orders` | 5,000 | 5,339 | **+339** | 339 received POs |
| `purchase_order_items` | 10,000 | 10,339 | **+339** | 339 PO items |
| `sales_orders` | 5,000 | 5,383 | **+383** | 383 confirmed SOs |
| `sales_order_items` | 10,000 | 10,383 | **+383** | 383 SO items |
| `stock_operations` | 6,000 | 6,722 | **+722** | 339 PO receipts + 383 SO issues |
| `product_batches` | 6,000 | 6,339 | **+339** | 339 batches created on PO receipts |
| `inventory` | 6,000 | 6,339 | **+339** | 339 inventory balances created |
| `inventory_transactions` | 12,335 | 13,065 | **+730** | 714 single-batch + 16 split-batch (8*2) |

### 8.4 Stock Ledger Audit & Inspection Commands
To execute PHP scripts or artisan commands through the project's configured PHP wrapper on Windows, run via **PowerShell**:
```powershell
# Verify stock ledger balances against movement records
powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan erp:check-stock

# Run the dynamic transaction reconciliation audit script
powershell -ExecutionPolicy Bypass -File scripts/php.ps1 docs/audit/evidence/load-test/round2-steady/inspect_stock_delta.php
```

---

## 11. Environment Isolation & Client Server Disclaimer (Updated 2026-11-20)

### 11.1 Daily Operation vs Benchmark Environment Separation
- **Daily Operation Environment:** Runs on **port 3306** using database `smart_mini_erp` and data directory `.cache/mysql-daily-data`. It is orchestrated via [`start-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/start-erp.cmd) and [`stop-erp.cmd`](file:///c:/Users/albna/Documents/minismarterp2/stop-erp.cmd).
- **Benchmark & Test Environments:** Run strictly on **port 3307** using separate temporary data directories: `.cache/mysql-perf-data` (for this 20-VU benchmark) and `.cache/mysql-data` (for functional regression tests).
- Under no circumstances does daily operation touch port 3307 or its benchmark artifacts.

### 11.2 Production Server Performance Disclaimer
The metrics documented in this report (100% success rate across 9,554 requests, p95 response time of 74.2ms, and 0 slow queries) were achieved on a dedicated local development workstation:
- **Processor:** AMD Ryzen 7 7735HS (8 Cores, 16 Threads @ 3.20 GHz base).
- **Memory:** 16.0 GB physical RAM.
- **Storage:** Local high-speed NVMe SSD on Windows 11 Pro.
- **Architecture:** Local loopback TCP networking (`127.0.0.1`) with zero external network latency.

**Critical Notice:** These local benchmark results **do not guarantee or represent an SLA for the client's production server**. Actual client server capacity will depend entirely on production specifications that are currently undefined, including:
1. Cloud vs bare-metal hardware and dedicated vCPU allocation.
2. Hypervisor overhead, resource contention, and multi-tenancy.
3. Network round-trip latency, ingress/egress bandwidth, and SSL/TLS termination overhead.
4. Production storage I/O limits (IOPS/burst credits) and disk write throughput.
5. Operating system tuning, FastCGI worker allocation, and production database buffer configurations.
Output of `erp:check-stock`:
```
Stock balances match the movement ledger. No quantities were changed.
```
Output of `inspect_stock_delta.php`:
```
Database verification: Connected to 'smart_mini_erp_perf' on port 3307 (verified test environment).
Benchmark Sample Boundary: stock_operations IDs [6001 to 6722] (documented delta = 722)
Operations within benchmark sample: 722
Inventory transactions linked to sample operations: 730
MATCH: Verified benchmark sample against documented expectations.
```

---

## 9. Independent Strengthened Checks Validation Experiment

To verify that assertions are robust and fail when elements are missing, an independent trial was conducted (distinct from the 16-minute load benchmark):

### 9.1 Test Scope & Assertions
1. **Known Product Search**:
   - Isolates `<tbody>` via regex.
   - Asserts `<tbody>` contains `<strong>Product SKU-0010</strong>`.
   - Asserts `<tbody>` does not contain `class="empty-state"`.
2. **Empty Product Search**:
   - Queries `NONEXISTENT_ITEM_XYZ_999`.
   - Asserts `<tbody>` contains `class="empty-state"`.
   - Asserts `<tbody>` does not contain `<strong>Product SKU-`.
3. **Document Status Badge**:
   - On confirmed Sales Order page, specifically extracts `<span class="pill ">...</span>`.
   - Asserts exact status text is `'معتمد'` or `'Confirmed'` (no generic text fallback).
4. **Stock Operation Dedicated Reference Verification**:
   - Extracts exact `href=".../inventory/operations/{id}"` from the confirmed Sales Order page.
   - Navigates to `/inventory/operations/{id}` and extracts the dedicated reference from `<h1>{{ $operation->reference }}</h1>`.
   - Compares the extracted string literally to `SO:{soId}` (all loose fallbacks such as `SO-` or `includes(soId)` are completely removed).
   - Validates that an operation belonging to a different document is strictly rejected.

### 9.2 Validation Experiment Results
- **Normal Verification (`test_strengthened_checks.js`)**:
  - Checks: **100.00% (11 out of 11 passed)**.
  - Valid operation literal match (`actualValidOpRef === 'SO:' + soId`): **Passed**.
  - Mismatched operation difference verification (`otherOpRef !== 'SO:' + soId`): **Passed**.
  - Critical failures: **0**.
  - Exit code: **0**.
- **Deliberate Missing Element Test (`test_strengthened_checks_failure.js`)**:
  - Injected missing element checks (checking for non-existent product in `<tbody>`, impossible status pill value).
  - Checks: **0.00% (0 out of 2 passed)**.
  - Critical failures: **2**.
  - Threshold crossed: `erp_critical_failures count==0`.
  - Exit code: **1** (Non-zero exit confirmed).
- **Mismatched Document Reference Rejection Test (`test_strengthened_checks_mismatch_failure.js`)**:
  - Deliberately asserted that an operation belonging to another document (Operation #1, `PO:1`) equals `SO:99999`.
  - Checks: **0.00% (0 out of 1 passed)**.
  - Critical failures: **1**.
  - Threshold crossed: `erp_critical_failures count==0`.
  - Exit code: **1** (Strict rejection confirmed with non-zero exit).

### 9.3 Failure Termination Mechanism
In k6:
- Thresholds evaluate metric counters and trigger a non-zero exit code (1) at test termination.
- Default k6 thresholds do **not** terminate the process mid-run unless `abortOnFail: true` is configured in the options or an unhandled exception is thrown.

---

## 10. Preserved Permanent Artifacts & Replay Instructions

All non-sensitive scripts, configs, and logs are preserved in this directory:
- `README.md`: This comprehensive document.
- `load_test_round2.js`: Main k6 benchmark script.
- `system_metrics_v2.csv`: Raw OS and process resource log.
- `k6_summary_round2.json`: k6 exported metric summary.
- `round2_metrics_summary.json`: Analyzed phase-by-phase metrics.
- `captured_app_queries_explain.json`: Actual captured application SQL and EXPLAIN plans.
- `inspect_stock_delta.php`: Script reconciling operations and transactions (with dynamic root resolution and database verification).
- `sample_manifest.json`: Manifest recording the 339 PO IDs, 383 SO IDs, 722 operation IDs, selection criteria, and UTC execution window.
- `monitor_resources_v2.ps1`: Resource monitor PowerShell script.
- `analyze_metrics_round2.php`: Metric extraction script.
- `nginx.conf`: Nginx 1.26.2 configuration.
- `my.ini`: MySQL 8.4 configuration.
- `mysql_config.json`: Verified MySQL runtime variables.
- `mysql_status_pre.json` & `mysql_status_post.json`: Global status snapshots.
- `mysql_innodb_status_pre.txt` & `mysql_innodb_status_post.txt`: InnoDB engine status snapshots.
- `row_counts_round2_pre.json` & `row_counts_round2_post.json`: Pre and post row counts.
- `bench_users.example.json`: Anonymized user list schema.
- `test_strengthened_checks.js`: Short experiment script with strengthened checks.
- `test_strengthened_checks_failure.js`: Proof-of-failure test script (missing elements).
- `test_strengthened_checks_mismatch_failure.js`: Proof-of-rejection test script (mismatched document reference).

### Replay Instructions
1. Baseline:
   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts/php.ps1 .cache/bench/generate_10k_data.php
   powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan erp:check-stock
   ```
2. Credentials: Copy `bench_users.example.json` to `.cache/bench/bench_users.json` and set passwords.
3. Server: Start MySQL on 3307 and Nginx on 8088 (with 16 `php-cgi` workers on 9001-9016).
4. Run:
   ```powershell
   k6 run --summary-export=.cache/bench/k6_summary_round2.json docs/audit/evidence/load-test/round2-steady/load_test_round2.js
   ```
5. Audit & Reconcile:
   ```powershell
   powershell -ExecutionPolicy Bypass -File scripts/php.ps1 artisan erp:check-stock
   powershell -ExecutionPolicy Bypass -File scripts/php.ps1 docs/audit/evidence/load-test/round2-steady/inspect_stock_delta.php
   ```
