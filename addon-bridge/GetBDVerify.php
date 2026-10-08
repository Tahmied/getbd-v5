<?php
/**
 * GetBD Verify — Addons bridge for the GetBD registrar module (WiseCP v5).
 *
 * WiseCP registrar modules cannot register client-area endpoints, and hook-
 * registered routes are not reliable on every install (module files load after
 * route collection). Addons modules are different: the website addon controller
 * loads the addon ITSELF on request, so an addon method is reachable on every
 * install without any routing-time registration.
 *
 * The registrar module auto-copies this file (with config.php) into
 * coremio/modules/Addons/GetBDVerify/. The client modal then posts
 * operation=use_addon_method&method=verify_submit to the addon URL, which
 * lands here and delegates to the registrar's verify handler.
 */

    class GetBDVerify extends AddonModule
    {
        /**
         * The addon's client-area page. The actual workflow lives in the
         * domains list/detail pages (the modal), this is just a signpost.
         */
        public function clientArea()
        {
            $domainsUrl = \LinkGenerator::client('services-type', ['domain']);
            return '<div class="alert alert-info mb-0">'
                . 'Domain verification happens from your domains page. '
                . '<a href="' . htmlspecialchars($domainsUrl, ENT_QUOTES, 'UTF-8') . '" class="alert-link">Open my domains</a>.'
                . '</div>';
        }

        /**
         * Live submission-state for the logged-in client's pending GetBD
         * domains (method=verify_state). The domains page may be served from
         * cache, so the modal JS asks for the real state here on every load
         * instead of trusting server-rendered markup.
         */
        public function use_verify_state()
        {
            if (!\UserManager::LoginData('member')) {
                $this->error = 'Please log in to continue.';
                return false;
            }

            $registrarDir = MODULE_DIR . 'Registrars' . DS . 'GetBD' . DS;
            if (!is_file($registrarDir . 'GetBD.php')) {
                $this->error = 'The GetBD registrar module is not installed.';
                return false;
            }

            $module = \Modules::getInstance('Registrars', 'GetBD');
            if (!$module || !method_exists($module, 'respondPendingState')) {
                $this->error = 'The GetBD registrar module is unavailable.';
                return false;
            }

            $module->respondPendingState('2'); // emits JSON and exits; '2' = bridge revision marker

            return true;
        }

        /**
         * POST target of the Verify-to-Active modal (method=verify_submit).
         * Returns true on success; the registrar handler emits the JSON
         * response and exits before this returns.
         */
        public function use_verify_submit()
        {
            if (!\UserManager::LoginData('member')) {
                $this->error = 'Please log in to continue.';
                return false;
            }

            if (!\Validation::verify_csrf_token((string) \Filter::init('POST/token', 'hclear'), 'domains')) {
                $this->error = 'Your session has expired. Please reload the page and try again.';
                return false;
            }

            $registrarDir = MODULE_DIR . 'Registrars' . DS . 'GetBD' . DS;
            if (!is_file($registrarDir . 'GetBD.php')) {
                $this->error = 'The GetBD registrar module is not installed.';
                return false;
            }

            $module = \Modules::getInstance('Registrars', 'GetBD');
            if (!$module || !method_exists($module, 'handleVerifySubmission')) {
                $this->error = 'The GetBD registrar module is unavailable.';
                return false;
            }

            // Delegates all auth/validation/API work; responds with JSON and exits.
            $module->handleVerifySubmission();

            // Unreachable in practice (the handler exits), kept for safety:
            return true;
        }
    }
