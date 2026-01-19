<?php
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

/**
 * For foreign TLD registrations (anything except .gr family),
 * block checkout if Domain Registrant Information contains Greek characters.
 *
 * Allows "Add New Contact" during checkout and validates the entered registrant fields.
 */
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {

    /* -------------------------------------------------
     * 1) Detect foreign domain registration in cart
     * ------------------------------------------------- */
    $cartDomains = $_SESSION['cart']['domains'] ?? [];
    if (!is_array($cartDomains) || empty($cartDomains)) {
        return;
    }

    $allowedZones = ['gr','com.gr','edu.gr','net.gr','org.gr','gov.gr'];
    $hasForeignRegistration = false;

    foreach ($cartDomains as $d) {
        if (($d['type'] ?? '') !== 'register') {
            continue;
        }

        $domain = strtolower(trim((string)($d['domain'] ?? '')));
        if ($domain === '' || strpos($domain, '.') === false) {
            continue;
        }

        $parts = explode('.', $domain, 2);
        $zone  = $parts[1] ?? '';

        if ($zone !== '' && !in_array($zone, $allowedZones, true)) {
            $hasForeignRegistration = true;
            break;
        }
    }

    if (!$hasForeignRegistration) {
        return; // Only GR-family registrations
    }

    /* -------------------------------------------------
     * 2) Helpers
     * ------------------------------------------------- */
    $containsGreek = function ($value) use (&$containsGreek): bool {
        if ($value === null) {
            return false;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($containsGreek($v)) {
                    return true;
                }
            }
            return false;
        }
        $s = (string) $value;
        return $s !== '' && preg_match('/\p{Greek}/u', $s) === 1;
    };

    // Collect only likely registrant/contact fields from request for "Add New Contact"
    $extractNewRegistrantFields = function (): array {
        // 1) Best case: template posts a dedicated array container
        $candidateContainers = [
            'domaincontact', 'domainContact',
            'registrant', 'registrantcontact', 'registrantContact',
            'domainregistrant', 'domainRegistrant',
            'contactdetails', 'contactDetails',
        ];
        foreach ($candidateContainers as $k) {
            if (isset($_REQUEST[$k]) && is_array($_REQUEST[$k]) && !empty($_REQUEST[$k])) {
                return $_REQUEST[$k];
            }
        }

        // 2) Fallback: pick only keys that strongly look like domain registrant/contact fields
        $picked = [];
        foreach ($_REQUEST as $k => $v) {
            $key = strtolower((string)$k);

            $looksLikeRegistrantContext =
                (strpos($key, 'domain') !== false && strpos($key, 'contact') !== false) ||
                (strpos($key, 'registrant') !== false) ||
                (strpos($key, 'whois') !== false);

            if (!$looksLikeRegistrantContext) {
                continue;
            }

            $isFieldWeCareAbout =
                strpos($key, 'firstname') !== false ||
                strpos($key, 'lastname') !== false ||
                strpos($key, 'company') !== false ||
                strpos($key, 'address') !== false ||
                strpos($key, 'city') !== false ||
                strpos($key, 'state') !== false ||
                strpos($key, 'postcode') !== false;

            if ($isFieldWeCareAbout) {
                $picked[$k] = $v;
            }
        }

        return $picked;
    };

    /* -------------------------------------------------
     * 3) Determine selected Domain Registrant Information option
     * ------------------------------------------------- */
    $clientId = (int) ($vars['clientId'] ?? 0);
    if ($clientId <= 0) {
        // Creating/using contacts is a client-area feature; keep it explicit.
        return [
            \Lang::trans('ForeignDomainRegistrantLoginToUseRegistrantInfo'),
        ];
    }

    // Standard Cart dropdown uses name="contact"
    $selectedContact = $_REQUEST['contact'] ?? '';

    /* -------------------------------------------------
     * 4) Load / build registrant data based on selection
     * ------------------------------------------------- */
    $registrantData = [];

    if ($selectedContact === '' || $selectedContact === null) {
        // "Use Default Contact (Details Above)" → validate client's saved details
        $registrantData = (array) Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first([
                'firstname','lastname','companyname',
                'address1','address2','city','state','postcode'
            ]);
    } elseif (ctype_digit((string)$selectedContact)) {
        // Existing contact → validate contact record
        $registrantData = (array) Capsule::table('tblcontacts')
            ->where('userid', $clientId)
            ->where('id', (int)$selectedContact)
            ->first([
                'firstname','lastname','companyname',
                'address1','address2','city','state','postcode'
            ]);

        if (empty($registrantData)) {
            return [
                \Lang::trans('ForeignDomainRegistrantInvalidContact'),
            ];
        }
    } else {
        // "Add New Contact" (or any non-numeric sentinel) → validate the entered registrant fields
        $registrantData = $extractNewRegistrantFields();

        if (empty($registrantData)) {
            // We can't reliably see the entered registrant fields; block with a deterministic instruction.
            return [
                \Lang::trans('ForeignDomainRegistrantAddNewContactFillLatin'),
            ];
        }
    }

    /* -------------------------------------------------
     * 5) Enforce Latin-only (no Greek) for foreign domains
     * ------------------------------------------------- */
    if ($containsGreek($registrantData)) {
        return [
            \Lang::trans('ForeignDomainRegistrantGreekCharacters'),
            \Lang::trans('ForeignDomainRegistrantUseLatin'),
        ];
    }

    return; // OK
});
