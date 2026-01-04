<?php
/**
 * Renewals Forecast + Unpaid Invoices Due (WHMCS-native UI)
 *
 * Includes:
 * - Active Services renewals (tblhosting.nextduedate)
 * - Active Addons renewals (tblhostingaddons.nextduedate)
 * - Active Domains renewals (tbldomains.expirydate)
 * - Unpaid Invoices due in range (tblinvoices.duedate, status=Unpaid)
 *
 * Filters:
 * - Start Date
 * - End Date
 * - Type: All / Service / Addon / Domain / Invoice
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

use WHMCS\Database\Capsule;

$reportdata["title"] = "Renewals & Unpaid Invoices Forecast";
$reportdata["description"] = "Renewals forecast (Active Services/Addons/Domains) plus Unpaid invoices due within the selected period.";

// ----------------------
// Helpers
// ----------------------
function validDateOrNull($date)
{
    if (!is_string($date) || $date === '') {
        return null;
    }
    $d = DateTime::createFromFormat("Y-m-d", $date);
    return ($d && $d->format("Y-m-d") === $date) ? $date : null;
}

function h($s)
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function moneyFloat($v)
{
    if ($v === null || $v === '') return 0.0;
    return (float)$v;
}

// ----------------------
// Read params
// ----------------------
$startdate = validDateOrNull($_REQUEST['startdate'] ?? null);
$enddate   = validDateOrNull($_REQUEST['enddate'] ?? null);

$itemType  = isset($_REQUEST['itemtype']) ? (string)$_REQUEST['itemtype'] : "All";
$allowedTypes = ["All", "Service", "Addon", "Domain", "Invoice"];
if (!in_array($itemType, $allowedTypes, true)) {
    $itemType = "All";
}

// ----------------------
// Default date window (current month)
// ----------------------
if (!$startdate || !$enddate) {
    $first = new DateTime("first day of this month");
    $last  = new DateTime("last day of this month");
    $windowStart = $first->format("Y-m-d");
    $windowEnd   = $last->format("Y-m-d");
} else {
    $windowStart = $startdate;
    $windowEnd   = $enddate;
}

// ----------------------
// URLs for filter actions
// ----------------------
$currentReport = $_GET['report'] ?? 'renewals_forecast';
$baseUrl = "reports.php?report=" . urlencode($currentReport);

$startValue = $_REQUEST["startdate"] ?? $windowStart;
$endValue   = $_REQUEST["enddate"] ?? $windowEnd;

// ----------------------
// Compact Filter Bar (WHMCS-native Bootstrap)
// ----------------------
$filterBarHtml = '
<style>
  /* Small spacing tweaks only (keeps WHMCS look) */
  .rf-filterbar { margin: 10px 0 12px; padding: 8px 10px; }
  .rf-filterbar .form-group { margin-right: 10px; margin-bottom: 0; }
  .rf-filterbar label { margin-right: 6px; margin-bottom: 0; font-weight: 600; }
  .rf-filterbar .form-control { height: 30px; padding: 4px 8px; }
  .rf-filterbar .btn { padding: 5px 10px; }
  .rf-filterbar .help-block { margin: 6px 0 0; font-size: 12px; }
  .rf-summary { margin: 8px 0 12px; padding: 8px 10px; }
  .rf-date { white-space: nowrap; font-variant-numeric: tabular-nums; }
  .rf-amount { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>

<div class="well well-sm rf-filterbar">
  <form method="get" action="reports.php" class="form-inline">
    <input type="hidden" name="report" value="' . h($currentReport) . '"/>

    <div class="form-group">
      <label for="rf_startdate">Start</label>
      <input id="rf_startdate" type="date" name="startdate" value="' . h($startValue) . '" class="form-control" />
    </div>

    <div class="form-group">
      <label for="rf_enddate">End</label>
      <input id="rf_enddate" type="date" name="enddate" value="' . h($endValue) . '" class="form-control" />
    </div>

    <div class="form-group">
      <label for="rf_itemtype">Type</label>
      <select id="rf_itemtype" name="itemtype" class="form-control">
        <option value="All"' . ($itemType === "All" ? " selected" : "") . '>All</option>
        <option value="Service"' . ($itemType === "Service" ? " selected" : "") . '>Services</option>
        <option value="Addon"' . ($itemType === "Addon" ? " selected" : "") . '>Addons</option>
        <option value="Domain"' . ($itemType === "Domain" ? " selected" : "") . '>Domains</option>
        <option value="Invoice"' . ($itemType === "Invoice" ? " selected" : "") . '>Invoices (Unpaid)</option>
      </select>
    </div>

    <button type="submit" class="btn btn-primary">Apply</button>

    <a href="' . h($baseUrl) . '" class="btn btn-default" style="margin-left:6px;">Reset</a>

    <span class="help-block" style="display:inline-block;margin-left:10px;">
      <strong>Renewals:</strong> Active only • <strong>Invoices:</strong> Unpaid only
    </span>
  </form>
</div>
';

$reportdata["description"] .= "<br><strong>Date Window:</strong> {$windowStart} to {$windowEnd}";
$reportdata["description"] .= "<br><strong>Type:</strong> " . h($itemType);
$reportdata["description"] .= $filterBarHtml;

// ----------------------
// Table headings
// ----------------------
$reportdata["tableheadings"] = [
    "Type",
    "Client",
    "Email",
    "Item",
    "Status",
    "Billing Cycle",
    "Due/Expiry Date",
    "Amount",
];

// ----------------------
// Data rows + totals
// ----------------------
$rows = [];
$totalCount = 0;
$totalAmount = 0.0;

// ----------------------
// SERVICES (Active)
// ----------------------
if ($itemType === "All" || $itemType === "Service") {

    $services = Capsule::table('tblhosting')
        ->join('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->leftJoin('tblproducts', 'tblproducts.id', '=', 'tblhosting.packageid')
        ->where('tblhosting.domainstatus', '=', 'Active')
        ->whereBetween('tblhosting.nextduedate', [$windowStart, $windowEnd])
        ->select([
            'tblhosting.id as serviceid',
            'tblclients.id as clientid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            'tblproducts.name as productname',
            'tblhosting.domain',
            'tblhosting.domainstatus',
            'tblhosting.billingcycle',
            'tblhosting.nextduedate',
            'tblhosting.amount',
        ])
        ->orderBy('tblhosting.nextduedate', 'asc')
        ->get();

    foreach ($services as $s) {
        $clientName = trim($s->firstname . " " . $s->lastname);
        $product = $s->productname ?: "Service";
        $domain = $s->domain ?: "";
        $item = $domain ? ($product . " - " . $domain) : $product;

        $amount = moneyFloat($s->amount);

        $rows[] = [
            "Service",
            "<a href=\"clientssummary.php?userid={$s->clientid}\">" . h($clientName) . "</a>",
            h($s->email),
            "<a href=\"clientsservices.php?userid={$s->clientid}&id={$s->serviceid}\">" . h($item) . "</a>",
            h($s->domainstatus),
            h($s->billingcycle),
            "<span class=\"rf-date\">" . h($s->nextduedate) . "</span>",
            "<span class=\"rf-amount\">" . h(number_format($amount, 2)) . "</span>",
        ];

        $totalCount++;
        $totalAmount += $amount;
    }
}

// ----------------------
// ADDONS (Active)
// ----------------------
if ($itemType === "All" || $itemType === "Addon") {

    $addons = Capsule::table('tblhostingaddons')
        ->join('tblhosting', 'tblhosting.id', '=', 'tblhostingaddons.hostingid')
        ->join('tblclients', 'tblclients.id', '=', 'tblhosting.userid')
        ->leftJoin('tbladdons', 'tbladdons.id', '=', 'tblhostingaddons.addonid')
        ->where('tblhostingaddons.status', '=', 'Active')
        ->whereBetween('tblhostingaddons.nextduedate', [$windowStart, $windowEnd])
        ->select([
            'tblhosting.id as hostingid',
            'tblclients.id as clientid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            'tbladdons.name as addonname',
            'tblhosting.domain as hostingdomain',
            'tblhostingaddons.status',
            'tblhostingaddons.billingcycle',
            'tblhostingaddons.nextduedate',
            'tblhostingaddons.recurring',
        ])
        ->orderBy('tblhostingaddons.nextduedate', 'asc')
        ->get();

    foreach ($addons as $a) {
        $clientName = trim($a->firstname . " " . $a->lastname);
        $addonName = $a->addonname ?: "Addon";
        $domain = $a->hostingdomain ?: "";
        $item = $domain ? ($addonName . " - " . $domain) : $addonName;

        $amount = moneyFloat($a->recurring);

        $rows[] = [
            "Addon",
            "<a href=\"clientssummary.php?userid={$a->clientid}\">" . h($clientName) . "</a>",
            h($a->email),
            "<a href=\"clientsservices.php?userid={$a->clientid}&id={$a->hostingid}\">" . h($item) . "</a>",
            h($a->status),
            h($a->billingcycle),
            "<span class=\"rf-date\">" . h($a->nextduedate) . "</span>",
            "<span class=\"rf-amount\">" . h(number_format($amount, 2)) . "</span>",
        ];

        $totalCount++;
        $totalAmount += $amount;
    }
}

// ----------------------
// DOMAINS (Active)
// ----------------------
if ($itemType === "All" || $itemType === "Domain") {

    $domains = Capsule::table('tbldomains')
        ->join('tblclients', 'tblclients.id', '=', 'tbldomains.userid')
        ->where('tbldomains.status', '=', 'Active')
        ->whereBetween('tbldomains.expirydate', [$windowStart, $windowEnd])
        ->select([
            'tbldomains.id as domainid',
            'tblclients.id as clientid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            'tbldomains.domain',
            'tbldomains.status',
            'tbldomains.registrationperiod',
            'tbldomains.expirydate',
            'tbldomains.recurringamount',
        ])
        ->orderBy('tbldomains.expirydate', 'asc')
        ->get();

    foreach ($domains as $d) {
        $clientName = trim($d->firstname . " " . $d->lastname);
        $cycle = ((int)$d->registrationperiod) . " Year(s)";
        $amount = moneyFloat($d->recurringamount);

        $rows[] = [
            "Domain",
            "<a href=\"clientssummary.php?userid={$d->clientid}\">" . h($clientName) . "</a>",
            h($d->email),
            "<a href=\"clientsdomains.php?userid={$d->clientid}&domainid={$d->domainid}\">" . h($d->domain) . "</a>",
            h($d->status),
            h($cycle),
            "<span class=\"rf-date\">" . h($d->expirydate) . "</span>",
            "<span class=\"rf-amount\">" . h(number_format($amount, 2)) . "</span>",
        ];

        $totalCount++;
        $totalAmount += $amount;
    }
}

// ----------------------
// UNPAID INVOICES due in range
// ----------------------
if ($itemType === "All" || $itemType === "Invoice") {

    $invoices = Capsule::table('tblinvoices')
        ->join('tblclients', 'tblclients.id', '=', 'tblinvoices.userid')
        ->where('tblinvoices.status', '=', 'Unpaid')
        ->whereBetween('tblinvoices.duedate', [$windowStart, $windowEnd])
        ->select([
            'tblinvoices.id as invoiceid',
            'tblclients.id as clientid',
            'tblclients.firstname',
            'tblclients.lastname',
            'tblclients.email',
            'tblinvoices.status',
            'tblinvoices.duedate',
            'tblinvoices.total',
        ])
        ->orderBy('tblinvoices.duedate', 'asc')
        ->get();

    foreach ($invoices as $inv) {
        $clientName = trim($inv->firstname . " " . $inv->lastname);
        $amount = moneyFloat($inv->total);

        $rows[] = [
            "Invoice",
            "<a href=\"clientssummary.php?userid={$inv->clientid}\">" . h($clientName) . "</a>",
            h($inv->email),
            "<a href=\"invoices.php?action=edit&id={$inv->invoiceid}\">Invoice #" . h($inv->invoiceid) . "</a>",
            h($inv->status),
            "-",
            "<span class=\"rf-date\">" . h($inv->duedate) . "</span>",
            "<span class=\"rf-amount\">" . h(number_format($amount, 2)) . "</span>",
        ];

        $totalCount++;
        $totalAmount += $amount;
    }
}

// Sort by date column (index 6)
usort($rows, function ($a, $b) {
    $da = strip_tags($a[6]);
    $db = strip_tags($b[6]);
    return strcmp($da, $db);
});

// Summary bar
$typeLabelMap = [
    "All" => "All",
    "Service" => "Services",
    "Addon" => "Addons",
    "Domain" => "Domains",
    "Invoice" => "Invoices (Unpaid)",
];
$typeLabel = $typeLabelMap[$itemType] ?? $itemType;

$reportdata["description"] .= '
<div class="alert alert-info rf-summary">
  <strong>' . h($totalCount) . '</strong> items •
  <strong>Total:</strong> ' . h(number_format($totalAmount, 2)) . ' •
  <strong>Window:</strong> ' . h($windowStart) . ' → ' . h($windowEnd) . ' •
  <strong>Type:</strong> ' . h($typeLabel) . '
</div>
';

// Totals row at bottom
$rows[] = [
    "<strong>Total</strong>",
    "<strong>" . h($totalCount) . " items</strong>",
    "",
    "",
    "",
    "",
    "",
    "<span class=\"rf-amount\"><strong>" . h(number_format($totalAmount, 2)) . "</strong></span>",
];

$reportdata["tablevalues"] = $rows;
$reportdata["exportdata"]  = $rows;
