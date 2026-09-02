<?php
/**
 * MX Cookieless Analytics for Grav.
 *
 * Cookieless, privacy-friendly web analytics. EU-hosted, GDPR-friendly, no
 * consent banner needed. Tracking is strictly opt-in: OFF until you paste
 * your site API key and enable it in the plugin settings.
 *
 * Connect flow (same contract as the WordPress plugin):
 *   1. plugin  → POST /api/integrations/grav/challenge (Bearer api_key)
 *                → stores the token in this plugin's config, served at
 *                  GET /mxcoan/challenge
 *   2. plugin  → POST /api/integrations/grav/verify (Bearer api_key)
 *                → the API fetches /mxcoan/challenge on the site's public
 *                  domain and compares → site.verified = true
 *
 * @package    Grav\Plugin
 * @license    MIT License
 */

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class MxcoanAnalyticsPlugin extends Plugin
{
    /**
     * Tracker script version (cache-bust key on /tracker.js?v=).
     * MUST match TRACKER_VERSION in @metrixs/types — bump together with a
     * tracker release and publish a new plugin release so installs fetch
     * the fresh script.
     */
    const TRACKER_VERSION = '1.1.0';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Initialize: intercept the challenge endpoint, run the connect flow in
     * Admin when needed, and enable output injection when tracking is on.
     *
     * @return void
     */
    public function onPluginsInitialized()
    {
        // ── Challenge endpoint ────────────────────────────────────────────
        // Runs before Grav routing/pages/cache: echo JSON + exit. Available
        // whenever the plugin is active and a challenge token is stored, so
        // verification works even with tracking still disabled. Without a
        // token the endpoint simply 404s (falls through to Grav's router).
        $uri = $this->grav['uri'];
        $path = trim((string) $uri->path(), '/');
        if (rtrim($path, '/') === 'mxcoan/challenge') {
            $token = (string) $this->config->get('plugins.mx-cookieless-analytics.challenge', '');
            if (preg_match('/^[0-9a-f]{64}$/', $token)) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(array('challenge' => $token));
                exit;
            }
        }

        // ── Admin: auto-connect when an API key is set but not verified ───
        // Runs at most once per 5 minutes (cache-throttled) until it
        // succeeds, so the merchant only has to paste the key and save.
        if ($this->isAdmin()) {
            $this->enable(['onAdminSave' => ['onAdminSave', 0]]);
            $this->maybeConnect();
        }

        // ── Tracker injection ─────────────────────────────────────────────
        // Strictly opt-in: nothing is injected until the site is connected
        // AND tracking is enabled. Activation alone loads nothing.
        if (
            $this->config->get('plugins.mx-cookieless-analytics.enabled')
            && $this->config->get('plugins.mx-cookieless-analytics.connected')
        ) {
            $this->enable(['onOutputGenerated' => ['onOutputGenerated', 0]]);
        }
    }

    /**
     * Persist plugin config right after an Admin save so the connect flow
     * (which may have flipped `connected`/`challenge`) is not overwritten
     * by the form post. Nothing else to do — the Admin already wrote the
     * file; saveConfig() re-writes it including our changes.
     *
     * @param Event $event
     * @return void
     */
    public function onAdminSave(Event $event)
    {
        $obj = isset($event['object']) ? $event['object'] : null;
        if (!$obj instanceof Data) {
            return;
        }
        $filename = (string) $obj->blueprints()->getFilename();
        if (strpos($filename, 'mx-cookieless-analytics') === false) {
            return;
        }
        // Give the just-saved settings an immediate connect chance (the
        // cache throttle would otherwise delay it by up to 5 minutes).
        $cache = $this->grav['cache'];
        $cache->delete('mxcoan_connect_lock');
        $this->maybeConnect();
    }

    /**
     * Inject the tracker into the final HTML before </head>.
     *
     * Uses output string injection (not the Assets manager) so it works with
     * every theme, including ones that never emit collected assets. Grav
     * caches the output AFTER this event, so the injected tag is served from
     * the page cache — zero per-request cost.
     *
     * @return void
     */
    public function onOutputGenerated()
    {
        // Opt-in gate (defense-in-depth: this handler is only subscribed when
        // enabled AND connected, but never inject on a stale subscription).
        if (
            !$this->config->get('plugins.mx-cookieless-analytics.enabled')
            || !$this->config->get('plugins.mx-cookieless-analytics.connected')
        ) {
            return;
        }

        $output = $this->grav['output']->getContent();
        if (!is_string($output) || strpos($output, '</head>') === false) {
            return;
        }

        // HTML only — never touch JSON/XML/ATOM outputs.
        $contentType = '';
        $response = $this->grav['output'];
        if (method_exists($response, 'getHeaderLine')) {
            $contentType = (string) $response->getHeaderLine('Content-Type');
        }
        if ($contentType !== '' && stripos($contentType, 'text/html') === false) {
            return;
        }

        // Exclude logged-in admins (default on) so your own visits and
        // preview loads never pollute the stats.
        if ($this->config->get('plugins.mx-cookieless-analytics.exclude_admin', true)) {
            $user = isset($this->grav['user']) ? $this->grav['user'] : null;
            if ($user && !empty($user->authenticated)) {
                return;
            }
        }

        $domain = $this->siteDomain();
        $apiBase = rtrim((string) $this->config->get('plugins.mx-cookieless-analytics.api_base', 'https://app.metrixs.eu'), '/');
        $src = $apiBase . '/tracker.js?v=' . self::TRACKER_VERSION;
        $script = '<script defer data-domain="' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')
            . '" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';

        $updated = preg_replace('/<\/head>/i', addcslashes($script, '\\$') . "\n</head>", $output, 1);
        if ($updated !== null) {
            $this->grav['output']->setContent($updated);
        }
    }

    // ─── Connect flow ─────────────────────────────────────────────────────

    /**
     * Run the challenge → verify handshake if an API key is set but the site
     * is not connected yet. Throttled by the Grav cache (one attempt per
     * 5 minutes) so a failing verification can't hammer the API or slow
     * every Admin page load down.
     *
     * @return void
     */
    private function maybeConnect()
    {
        $apiKey = (string) $this->config->get('plugins.mx-cookieless-analytics.api_key', '');
        if ($apiKey === '') {
            return;
        }
        if ($this->config->get('plugins.mx-cookieless-analytics.connected')) {
            return;
        }

        $cache = $this->grav['cache'];
        if ($cache->get('mxcoan_connect_lock')) {
            return;
        }
        $cache->set('mxcoan_connect_lock', 1, 300);

        // Step 1: request a challenge and store it so /mxcoan/challenge
        // can serve it.
        $challenge = $this->apiPost('/api/integrations/grav/challenge', array());
        if (empty($challenge['ok']) || empty($challenge['data']['challenge'])) {
            $this->adminMessage(
                'MX Cookieless Analytics: could not reach MetriXs to start verification. Check your site API key and try saving the settings again.',
                'error'
            );
            return;
        }
        $token = (string) $challenge['data']['challenge'];
        $this->config->set('plugins.mx-cookieless-analytics.challenge', $token);
        self::saveConfig($this->name);

        // Step 2: ask the API to verify. It fetches the challenge back from
        // the site's public domain — only a party controlling this domain
        // can serve the token.
        $verify = $this->apiPost('/api/integrations/grav/verify', array());
        if (!empty($verify['ok']) && !empty($verify['data']['ok'])) {
            $this->config->set('plugins.mx-cookieless-analytics.connected', true);
            self::saveConfig($this->name);
            $this->adminMessage(
                'MX Cookieless Analytics: site verified. Tracking starts once "Enable tracking" is on.',
                'info'
            );
        } else {
            $this->adminMessage(
                'MX Cookieless Analytics: verification failed. The plugin could not serve the challenge token on your public domain (check HTTPS and caching) — it will retry automatically.',
                'error'
            );
        }
    }

    /**
     * POST JSON to a MetriXs API endpoint with the stored API key.
     *
     * @param string $path API path, e.g. '/api/integrations/grav/verify'.
     * @param array  $body JSON body.
     * @return array{ok: bool, status: int, data: array|null}
     */
    private function apiPost($path, $body = array())
    {
        $apiKey = (string) $this->config->get('plugins.mx-cookieless-analytics.api_key', '');
        $apiBase = rtrim((string) $this->config->get('plugins.mx-cookieless-analytics.api_base', 'https://app.metrixs.eu'), '/');

        $ch = curl_init($apiBase . $path);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ),
        ));
        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);

        if ($response === false || $error !== '') {
            return array('ok' => false, 'status' => 0, 'data' => null);
        }

        $data = json_decode((string) $response, true);

        return array(
            'ok' => $status >= 200 && $status < 300,
            'status' => $status,
            'data' => is_array($data) ? $data : null,
        );
    }

    // ─── helpers ──────────────────────────────────────────────────────────

    /**
     * The site domain for the data-domain attribute: the configured domain,
     * or auto-detected from the request host.
     *
     * @return string
     */
    private function siteDomain()
    {
        $domain = strtolower(trim((string) $this->config->get('plugins.mx-cookieless-analytics.domain', '')));
        if ($domain !== '') {
            return $domain;
        }
        $uri = $this->grav['uri'];

        return (string) $uri->host();
    }

    /**
     * Queue an Admin message (no-op outside Admin).
     *
     * @param string $text
     * @param string $type
     * @return void
     */
    private function adminMessage($text, $type)
    {
        if (!$this->isAdmin()) {
            return;
        }
        $messages = isset($this->grav['messages']) ? $this->grav['messages'] : null;
        if ($messages) {
            $messages->addMessage($text, $type);
        }
    }
}
