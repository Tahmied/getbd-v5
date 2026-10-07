<?php
/**
 * GetBD module — dynamic config body.
 *
 * Included (via `return require`) by config.php, which defines
 * $getbd_settings. Scope is shared.
 *
 * WHY THIS EXISTS: WiseCP rewrites config.php to a plain static array export
 * every time the admin saves module settings. A static doc-fields array would
 * leak the verification fields into the checkout (WiseCP renders AND
 * validates them on the cart/configure/checkout flow), but the .bd flow
 * collects documents AFTER the purchase through the domain manager's
 * verification form. This template re-applies the fields dynamically and the
 * module regenerates config.php after every save (see
 * GetBD::controller_settings()).
 */

    $getbd_is_checkout = false;

    if (!empty($_SERVER['REQUEST_METHOD']) && !defined('ADMINISTRATOR')) {

        // Primary signal: the checkout flow loads the module from these core
        // files (configure page render + cart validation). The domain manager
        // (controllers/website/domains.php, ClientDomains.php) must keep the
        // full field set.
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            $file = str_replace('\\', '/', (string) ($frame['file'] ?? ''));
            if (preg_match('~(controllers/website/(configure|checkout)\.php|operations/ClientCart\.php)$~i', $file)) {
                $getbd_is_checkout = true;
                break;
            }
        }

        // Fallback: URL shape, for rich URLs and ?route= style links alike.
        if (!$getbd_is_checkout) {
            $uri = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
            $getbd_is_checkout = (bool) preg_match('~(/|=)(configure|cart|basket|checkout)(/|$|&|\?|=|\.php)~', $uri);
        }
    }

    $docFields = [];
    if (!$getbd_is_checkout) require __DIR__ . '/doc-fields.php';

    return [
        'meta' => [
            'name'    => 'GetBD',
            'version' => '1.2',
            'logo'    => 'logo.png',
        ],
        'settings' => array_merge($getbd_settings, [
            'doc-fields' => $docFields,
        ]),
    ];
