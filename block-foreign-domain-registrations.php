<?php
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {

    // Runs before order & invoice creation; returning string/array shows an error and blocks checkout.
    // :contentReference[oaicite:1]{index=1}

    // 1) Check if cart contains ANY foreign domain registration (not .gr family)
    $cartDomains = $_SESSION['cart']['domains'] ?? [];
    if (!is_array($cartDomains) || empty($cartDomains)) {
        return;
    }

    $allowedZones = ['gr','com.gr','edu.gr','net.gr','org.gr','gov.gr'];
    $hasForeignRegistration = false;

    foreach ($cartDomains as $d) {
        if (($d['type'] ?? '') !== 'register') continue;
        $domain = strtolower(trim((string)($d['domain'] ?? '')));
        if ($domain === '' || strpos($domain, '.') === false) continue;

        $parts = explode('.', $domain, 2);
        $zone = $parts[1] ?? '';

        if ($zone !== '' && !in_array($zone, $allowedZones, true)) {
            $hasForeignRegistration = true;
            break;
        }
    }

    if (!$hasForeignRegistration) {
        return; // only .gr family registrations => ignore
    }

    // Greek character detector
    $containsGreek = function ($value) use (&$containsGreek): bool {
        if ($value === null) return false;
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($containsGreek($v)) return true;
            }
            return false;
        }
        $s = (string)$value;
        return $s !== '' && preg_match('/\p{Greek}/u', $s) === 1;
    };

    // 2) Determine client and selected registrant contact
    $clientId = (int) ($vars['clientId'] ?? 0);
    if ($clientId <= 0) {
        return [
            'Foreign domain registrations require an authenticated client and Latin (Greeklish) Domain Registrant Information.'
        ];
    }

    $selectedContact = $_REQUEST['contact'] ?? ''; // Standard Cart uses name="contact" for registrant selector (template-side)

    // 3) Get registrant data based on selection
    $registrantData = null;

    if ($selectedContact === '' || $selectedContact === null) {
        // "Use Default Contact"
        $registrantData = (array) Capsule::table('tblclients')
            ->where('id', $clientId)
            ->first(['firstname','lastname','companyname','address1','address2','city','state']);
    } elseif (ctype_digit((string)$selectedContact)) {
        // Existing saved contact
        $registrantData = (array) Capsule::table('tblcontacts')
            ->where('userid', $clientId)
            ->where('id', (int)$selectedContact)
            ->first(['firstname','lastname','companyname','address1','address2','city','state']);

        if (empty($registrantData)) {
            return ['Το επιλεγμένο Domain Registrant Information δεν είναι έγκυρο.'];
        }
    } else {
        /**
         * "Add New Contact" case:
         * WHMCS will POST new-contact fields, but the exact field names depend on the order-form template.
         * We cannot rely on a single fixed key without seeing your POST payload.
         *
         * Strategy:
         *  - First check common containers (arrays)
         *  - If not found, scan only request keys that look like registrant/contact fields
         *    (so billing info in Greek doesn't wrongly block)
         */

        // Try common array containers (best if present)
        $candidateContainers = [
            'domaincontact',
            'domainContact',
            'registrant',
            'registrantcontact',
            'contactdetails',
            'contactDetails',
            'domainregistrant',
        ];

        foreach ($candidateContainers as $k) {
            if (isset($_REQUEST[$k]) && is_array($_REQUEST[$k]) && !empty($_REQUEST[$k])) {
                $registrantData = $_REQUEST[$k];
                break;
            }
        }

        // Fallback: build a subset of request values that look like "new registrant contact fields"
        if ($registrantData === null) {
            $registrantData = [];
            foreach ($_REQUEST as $k => $v) {
                $key = strtolower((string)$k);

                // include only keys that likely belong to registrant/contact creation
                // (avoid blocking because billing address/name is Greek)
                $looksRegistrant =
                    (strpos($key, 'domain') !== false && strpos($key, 'contact') !== false)
                    || (strpos($key, 'registrant') !== false)
                    || (strpos($key, 'whois') !== false);

                if ($looksRegistrant) {
                    $registrantData[$k] = $v;
                }
            }

            // If we still found nothing, we can't validate the "add new contact" payload reliably.
            // In that case, block with a diagnostic message (deterministic) rather than silently allowing.
            if (empty($registrantData)) {
                if (function_exists('logActivity')) {
                    logActivity('Registrant validation: Add New Contact selected, but no registrant/contact POST fields detected. Keys=' . implode(',', array_keys($_REQUEST)));
                }
                return [
                    'Δεν μπορώ να επαληθεύσω τα στοιχεία Κατόχου για εγγραφή ξένου domain.',
                    'Παρακαλώ αποθηκεύστε πρώτα ένα Domain Registrant Information contact (λατινικά/Greeklish) και επιλέξτε το από τη λίστα.'
                ];
            }
        }
    }

    // 4) Validate registrant data for Greek characters
    if ($containsGreek($registrantData)) {
        return [
            'Η εγγραφή ξένου domain δεν μπορεί να ολοκληρωθεί. Τα στοιχεία του ιδιοκτήτη πρέπει να είναι στα λατινικά για domains πλην των Ελληνικών.',
            'Παρακαλώ δημιουργήστε νέο προφιλ ιδιοκτήτη από την επιλογή παρακάτω με λατινικά/Greeklish και επιλέξτε το από τη λίστα.'
        ];
    }

    return; // OK
});
