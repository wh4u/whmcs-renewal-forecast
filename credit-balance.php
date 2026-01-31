<?php
/**
 * Client Credit Balance Sidebar Widget
 * 
 * Displays the client's available credit balance in the sidebar panel
 * on the client area homepage. Theme-agnostic design that works with
 * all WHMCS themes (Six, Twenty-One, and custom themes).
 * 
 * =============================================================================
 * INSTALLATION
 * =============================================================================
 * 
 * Place this file in: /includes/hooks/client_credit_balance.php
 * 
 * =============================================================================
 * LANGUAGE SETUP
 * =============================================================================
 * 
 * Add the following translations to your WHMCS language override files.
 * Create the files if they don't exist.
 * 
 * ENGLISH - /lang/overrides/english.php
 * -----------------------------------------------------------------------------
 * <?php
 * $_LANG['creditBalancePanelTitle'] = 'Available Balance';
 * $_LANG['creditBalanceAvailableForInvoices'] = 'Available for future invoices';
 * $_LANG['creditBalanceAddFunds'] = 'Add Funds';
 * 
 * GREEK - /lang/overrides/greek.php
 * -----------------------------------------------------------------------------
 * <?php
 * $_LANG['creditBalancePanelTitle'] = 'Διαθέσιμο Υπόλοιπο';
 * $_LANG['creditBalanceAvailableForInvoices'] = 'Διαθέσιμο για μελλοντικά τιμολόγια';
 * $_LANG['creditBalanceAddFunds'] = 'Προσθήκη Credits';
 * 
 * GERMAN - /lang/overrides/german.php
 * -----------------------------------------------------------------------------
 * <?php
 * $_LANG['creditBalancePanelTitle'] = 'Verfügbares Guthaben';
 * $_LANG['creditBalanceAvailableForInvoices'] = 'Verfügbar für zukünftige Rechnungen';
 * $_LANG['creditBalanceAddFunds'] = 'Guthaben aufladen';
 * 
 * FRENCH - /lang/overrides/french.php
 * -----------------------------------------------------------------------------
 * <?php
 * $_LANG['creditBalancePanelTitle'] = 'Solde disponible';
 * $_LANG['creditBalanceAvailableForInvoices'] = 'Disponible pour les factures futures';
 * $_LANG['creditBalanceAddFunds'] = 'Ajouter des fonds';
 * 
 * SPANISH - /lang/overrides/spanish.php
 * -----------------------------------------------------------------------------
 * <?php
 * $_LANG['creditBalancePanelTitle'] = 'Saldo disponible';
 * $_LANG['creditBalanceAvailableForInvoices'] = 'Disponible para facturas futuras';
 * $_LANG['creditBalanceAddFunds'] = 'Agregar fondos';
 * 
 * =============================================================================
 * FEATURES
 * =============================================================================
 * 
 * - Displays only when client has credit > 0
 * - Shows only on client area homepage (dashboard)
 * - Theme-agnostic (works with Six, Twenty-One, custom themes)
 * - Multi-language support via WHMCS language system
 * - Secure: XSS prevention, type casting, WHMCS native APIs
 * 
 * =============================================================================
 * 
 * @package    WHMCS
 * @subpackage Hooks
 * @version    2.0.0
 * @license    MIT
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

use WHMCS\View\Menu\Item as MenuItem;

/**
 * Client Credit Balance Sidebar Hook
 * 
 * Adds a credit balance widget to the client area sidebar
 * when the client has a positive credit balance.
 */
add_hook('ClientAreaPrimarySidebar', 1, function (MenuItem $primarySidebar) {

    // Page detection - multiple methods for compatibility across WHMCS versions
    $filename = $GLOBALS['filename'] ?? '';
    $action = $_GET['action'] ?? '';
    $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
    
    // Check if we're on the client area main page (dashboard)
    $isClientAreaHome = (
        ($filename === 'clientarea' && empty($action)) ||
        (strpos($scriptName, 'clientarea.php') !== false && empty($action))
    );
    
    if (!$isClientAreaHome) {
        return;
    }

    // Get client from menu context (WHMCS's secure, authenticated method)
    $client = Menu::context('client');
    
    if (!$client || empty($client->id)) {
        return;
    }

    $clientId = (int) $client->id;
    $credit = (float) ($client->credit ?? 0);

    // Only display if client has positive credit
    if ($credit <= 0) {
        return;
    }

    // Use WHMCS native currency formatting (handles locale and currency symbols)
    $currencyData = getCurrency($clientId);
    $formattedCredit = formatCurrency($credit, $currencyData['id']);

    // Get translated strings with English fallbacks
    $panelTitle = Lang::trans('creditBalancePanelTitle') ?: 'Available Balance';
    
    // Create the sidebar panel
    $primarySidebar->addChild('Credit-Balance', [
        'label' => $panelTitle,
        'uri'   => '#',
        'icon'  => 'fa-wallet',
    ]);

    $panel = $primarySidebar->getChild('Credit-Balance');
    
    if (!$panel) {
        return;
    }

    // Position at the top for visibility
    $panel->moveToFront();

    // Build theme-agnostic display using Bootstrap classes
    $creditHtml = _creditBalanceWidget_buildHtml($formattedCredit);
    
    $panel->addChild('credit-display', [
        'uri'   => 'clientarea.php?action=addfunds',
        'label' => $creditHtml,
    ]);

    // Footer with Add Funds button
    $panel->setFooterHtml(_creditBalanceWidget_buildFooter());
});

/**
 * Build the HTML for credit display
 * 
 * Uses only inline styles and standard Bootstrap classes for theme compatibility.
 * Minimal styling that inherits from the active theme.
 * 
 * @param \WHMCS\View\Formatter\Price $formattedCredit
 * @return string
 */
function _creditBalanceWidget_buildHtml($formattedCredit): string
{
    $creditStr = htmlspecialchars((string) $formattedCredit, ENT_QUOTES, 'UTF-8');
    
    // Get translated subtitle
    $subtitle = Lang::trans('creditBalanceAvailableForInvoices') ?: 'Available for future invoices';
    $subtitleEsc = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    
    // Theme-agnostic HTML using Bootstrap classes and minimal inline styles
    // These styles are basic enough to work with any theme
    return '
    <div style="text-align: center; padding: 15px 10px;">
        <div style="font-size: 1.75em; font-weight: bold; color: #28a745; margin-bottom: 5px;">
            ' . $creditStr . '
        </div>
        <div class="text-muted" style="font-size: 0.85em;">
            ' . $subtitleEsc . '
        </div>
    </div>';
}

/**
 * Build footer HTML with Add Funds button
 * 
 * Uses Bootstrap button classes for theme compatibility.
 * 
 * @return string
 */
function _creditBalanceWidget_buildFooter(): string
{
    // Get translated button text
    $buttonText = Lang::trans('creditBalanceAddFunds') ?: 'Add Funds';
    $buttonTextEsc = htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8');
    
    // Standard Bootstrap button - works with all WHMCS themes
    return '
    <a href="clientarea.php?action=addfunds" class="btn btn-success btn-block">
        <i class="fas fa-plus fa-fw"></i> ' . $buttonTextEsc . '
    </a>';
}
