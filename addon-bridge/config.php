<?php

/**
 * GetBD Verify — Addons bridge configuration.
 *
 * This tiny addon exists for exactly one purpose: it hosts the client-area
 * POST endpoint (operation=use_addon_method, method=verify_submit) that the
 * GetBD registrar module's "Verify to Active" modal submits to. The registrar
 * module auto-copies this folder into coremio/modules/Addons/GetBDVerify/.
 *
 * It ships enabled (status) and client-visible (show_on_clientArea) so no
 * manual admin step is required. Do not disable or delete it while the GetBD
 * registrar module is in use.
 */

    return array (
        'meta' =>
        array (
            'name' => 'GetBD Verify',
            'version' => '1.0.0',
            'description' => 'Internal bridge for the GetBD registrar module: hosts the domain document verification endpoint. Do not disable while GetBD is in use.',
            'logo' => 'default-logo.svg',
        ),
        'status' => true,
        'show_on_clientArea' => true,
    );
