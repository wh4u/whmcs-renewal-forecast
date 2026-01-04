# WHMCS Renewals Forecast Report (Services + Addons + Domains)

This is a custom WHMCS admin report (`/modules/reports/`) that helps you see **what will renew in a selected date range**, including:

- **Active Services / Hosting renewals**
- **Active Addons renewals**
- **Active Domain renewals**

The goal is to provide a clear **renewals pipeline** so you can estimate expected revenue and workload for a given month (for example, “next month renewals”).

---

## What this report shows

For the selected date range, the report lists only **Active** items:

### Services (Hosting)
- Table: `tblhosting`
- Uses: `nextduedate`
- Status filter: `domainstatus = Active`

### Addons
- Table: `tblhostingaddons`
- Uses: `nextduedate`
- Status filter: `status = Active`

### Domains
- Table: `tbldomains`
- Uses: `expirydate` (domain expires within the date range)
- Status filter: `status = Active`

All results are shown in one combined table and sorted by date.

---

## Filters

This report includes a compact filter bar (works with WHMCS admin templates even when `$reportdata['filters']` is not rendered).

### Available filters
- **Start Date**
- **End Date**
- **Type**
  - All
  - Services
  - Addons
  - Domains

### Default behavior
If no Start/End date is provided, the report defaults to the **current month**.

---

## Why use this report?

WHMCS includes standard reports, but many users want a simple way to answer:

✅ “Which customers have renewals next month?”  
✅ “How much is expected to renew next month?”  
✅ “Which renewals are coming regardless of billing cycle?”  
✅ “Show me domains expiring in a specific month”  

This report provides all of that in a single view.

---

## Installation

1. Upload the file:
2. In WHMCS Admin, go to:
**Reports → Other → Renewals Forecast**  
(the exact category depends on your admin theme)

3. Select your Start Date, End Date, and Type, then click **Apply**.

---

## Compatibility

- Tested on: **WHMCS 8.13**
- Uses: **WHMCS Capsule DB (Laravel)**
- No external dependencies

---

## Notes / Important behavior

### Domains are based on `expirydate`
Domains appear in the report based on their **expiry date**, not necessarily the invoice due date.

This is intentional so the report answers “domains expiring in this period”.

If you prefer domains to be based on `nextduedate` (billing schedule), you can modify the query in the domains section.

---

## What this report does NOT do

This is a **renewals forecast**, not an invoice forecast.

It does **not** include:
- Invoices that are already generated (paid/unpaid)
- One-time invoice items
- Recurring billable items (`tblbillableitems`)
- Cancelled/Suspended/Terminated services (only Active)
- Grace/Redemption/Expired domains (only Active)

If your goal is purely cash-flow (what invoices are due and unpaid), you should use an **invoice forecast report** instead.

---

## Security

- Uses WHMCS internal admin reporting environment.
- Output is available only to logged-in WHMCS admins who have access to reports.
- All displayed values are escaped with `htmlspecialchars()`.

---

## Support / Contributions

Issues and pull requests are welcome.

If you contribute improvements:
- Keep compatibility with WHMCS 8.x
- Avoid heavy UI frameworks (use WHMCS admin/Bootstrap styling)
- Ensure queries stay efficient (large WHMCS databases)


