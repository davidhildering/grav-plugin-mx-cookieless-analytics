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
 * The handshake runs ONLY from onAdminAfterSave (the save handler), never on
 * the page-render path — a failing verification can never slow a page load.
 *
 * @package    Grav\Plugin
 * @license    MIT License
 */

namespace Grav\Plugin;

use Grav\Common\Data\Data;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;

class MxCookielessAnalyticsPlugin extends Plugin
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
            // Subscribed UNCONDITIONALLY: Plugin::isAdmin() is just
            // isset($grav['admin']), and on the Grav 2.0 admin2/API stack that
            // key only registers at route dispatch — long after
            // onPluginsInitialized. Gating the subscription on admin context
            // would make the connect flow a silent no-op on 2.0. The guard
            // happens at dispatch time inside the handler (both the classic
            // admin plugin and the 2.0 API plugin fire onAdminAfterSave for
            // plugin config saves).
            'onAdminAfterSave' => ['onAdminAfterSave', 0],
        ];
    }

    /**
     * Initialize: intercept the challenge endpoint and enable output
     * injection when tracking is on.
     *
     * @return void
     */
    public function onPluginsInitialized()
    {
        // ── Challenge endpoint ────────────────────────────────────────────
        // Runs before Grav routing/pages/cache: echo JSON + exit. Serves the
        // token only while a handshake is in flight (it is cleared once the
        // handshake completes), so no verification token sits on a public
        // endpoint longer than needed. Without a stored token the endpoint
        // simply 404s (falls through to Grav's router).
        $uri = $this->grav['uri'];
        $path = trim((string) $uri->path(), '/');
        if (rtrim($path, '/') === 'mxcoan/challenge') {
            $token = (string) $this->config->get('plugins.' . $this->name . '.challenge', '');
            if (preg_match('/^[0-9a-f]{64}$/', $token)) {
                header('Content-Type: application/json; charset=utf-8');
                header('Cache-Control: no-store');
                echo json_encode(array('challenge' => $token));
                exit;
            }
        }

        // ── Tracker injection ─────────────────────────────────────────────
        // Strictly opt-in: nothing is injected until the site is connected
        // AND tracking is enabled. Activation alone loads nothing.
        if (
            $this->config->get('plugins.' . $this->name . '.enabled')
            && $this->config->get('plugins.' . $this->name . '.connected')
        ) {
            $this->enable(['onOutputGenerated' => ['onOutputGenerated', 0]]);
        }
    }

    /**
     * Run the connect handshake after a plugin-config save.
     *
     * onAdminAfterSave fires AFTER the config file is written on both hosts
     * (classic admin: fireEvent() then save(); 2.0 API: fireAdminEvent() then
     * writeConfigFile()) — so state persisted here is not clobbered by the
     * form post. The form values are read from the saved object, not from
     * $this->config: the classic admin reloads the config container after
     * this event, so the event object is the freshest source.
     *
     * @param Event $event
     * @return void
     */
    public function onAdminAfterSave($event = null)
    {
        $obj = $event !== null && isset($event['object']) ? $event['object'] : null;
        if (!$obj instanceof Data) {
            return;
        }
        $filename = (string) $obj->blueprints()->getFilename();
        if (strpos($filename, 'mx-cookieless-analytics') === false) {
            return;
        }

        // Merge the form post into the config container so saveConfig()
        // writes exactly what the admin submitted (the container may not yet
        // reflect the save on every host).
        foreach (array('enabled', 'api_key', 'api_base', 'domain', 'exclude_admin') as $key) {
            $this->config->set('plugins.' . $this->name . '.' . $key, $obj->get($key));
        }

        $apiKey = trim((string) $obj->get('api_key'));
        if ($apiKey === '') {
            return;
        }
        if (!empty($obj->get('connected')) || $this->config->get('plugins.' . $this->name . '.connected')) {
            // Already connected — nothing to do. (State is not in the form;
            // this reads whatever is on file.)
            return;
        }

        $this->runHandshake($apiKey);
    }

    /**
     * Inject the tracker into the final HTML before </head>.
     *
     * Uses output string injection (not the Assets manager) so it works with
     * every theme, including ones that never emit collected assets. Note:
     * Grav caches page CONTENT before render, so onOutputGenerated runs on
     * every request — the injection itself is a cheap single preg_replace,
     * but it is not literally free.
     *
     * @param Event $event
     * @return void
     */
    public function onOutputGenerated($event = null)
    {
        // Opt-in gate (defense-in-depth: this handler is only subscribed when
        // enabled AND connected, but never inject on a stale subscription).
        if (
            !$this->config->get('plugins.' . $this->name . '.enabled')
            || !$this->config->get('plugins.' . $this->name . '.connected')
        ) {
            return;
        }

        // Grav passes the rendered output as a STRING, by reference on the
        // event (RenderProcessor: Event(['output' => &$grav->output])).
        $output = is_string($this->grav['output']) ? $this->grav['output'] : '';
        if ($output === '' || strpos($output, '</head>') === false) {
            return;
        }

        // Exclude admins only — NOT every authenticated user, or any site
        // with front-end logins would silently lose all its members from the
        // stats. authorize() is role/permission-based and false for regular
        // logged-in users.
        if ($this->config->get('plugins.' . $this->name . '.exclude_admin', true)) {
            $user = isset($this->grav['user']) ? $this->grav['user'] : null;
            if ($user && method_exists($user, 'authorize')
                && ($user->authorize('admin.login') || $user->authorize('api.access'))) {
                return;
            }
        }

        $domain = $this->siteDomain();
        $apiBase = $this->apiBase();
        $src = $apiBase . '/tracker.js?v=' . self::TRACKER_VERSION;
        $script = '<script defer data-domain="' . htmlspecialchars($domain, ENT_QUOTES, 'UTF-8')
            . '" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '"></script>';

        $updated = preg_replace('/<\/head>/i', addcslashes($script, '\\$') . "\n</head>", $output, 1);
        if ($updated !== null && $updated !== $output) {
            // Write through the event's by-reference output slot (the
            // RenderProcessor echoes exactly that variable); property
            // assignment as fallback when no event was passed.
            if ($event !== null && isset($event['output'])) {
                $event['output'] = $updated;
            } else {
                $this->grav->output = $updated;
            }
        }
    }

    // ─── Connect flow ─────────────────────────────────────────────────────

    /**
     * Challenge → verify handshake, triggered from onAdminAfterSave only.
     * Bounded: one request at a time, ~5s total timeout, a short cache lock
     * against rapid double-saves.
     *
     * @param string $apiKey
     * @return void
     */
    private function runHandshake($apiKey)
    {
        $cache = $this->grav['cache'];
        if ($cache->get('mxcoan_connect_lock')) {
            return;
        }
        $cache->set('mxcoan_connect_lock', 1, 30);

        // Step 1: request a challenge and store it so /mxcoan/challenge
        // can serve it.
        $challenge = $this->apiPost('/api/integrations/grav/challenge', array(), $apiKey);
        if (empty($challenge['ok']) || empty($challenge['data']['challenge'])) {
            $this->config->set('plugins.' . $this->name . '.challenge', '');
            self::saveConfig($this->name);
            $this->adminMessage(
                'MX Cookieless Analytics: could not reach MetriXs to start verification. Check your site API key and save again.',
                'error'
            );
            return;
        }
        $token = (string) $challenge['data']['challenge'];
        $this->config->set('plugins.' . $this->name . '.challenge', $token);
        self::saveConfig($this->name);

        // Step 2: ask the API to verify. It fetches the challenge back from
        // the site's public domain — only a party controlling this domain
        // can serve the token.
        $verify = $this->apiPost('/api/integrations/grav/verify', array(), $apiKey);
        if (!empty($verify['ok']) && !empty($verify['data']['ok'])) {
            // Connected: clear the challenge (it was single-use for the
            // handshake — never serve a token longer than necessary) and
            // persist the state.
            $this->config->set('plugins.' . $this->name . '.challenge', '');
            $this->config->set('plugins.' . $this->name . '.connected', true);
            self::saveConfig($this->name);
            $this->adminMessage(
                'MX Cookieless Analytics: site verified. Tracking starts once "Enable tracking" is on.',
                'info'
            );
        } else {
            $this->config->set('plugins.' . $this->name . '.challenge', '');
            self::saveConfig($this->name);
            $this->adminMessage(
                'MX Cookieless Analytics: verification failed — the plugin could not serve the challenge token on your public domain (check HTTPS and caching). Saving again retries.',
                'error'
            );
        }
    }

    /**
     * POST JSON to a MetriXs API endpoint.
     *
     * @param string $path   API path, e.g. '/api/integrations/grav/verify'.
     * @param mixed  $body   JSON body.
     * @param string $apiKey Bearer key.
     * @return array{ok: bool, status: int, data: array|null}
     */
    private function apiPost($path, $body = array(), $apiKey = null)
    {
        if ($apiKey === null) {
            $apiKey = (string) $this->config->get('plugins.' . $this->name . '.api_key', '');
        }

        $ch = curl_init($this->apiBase() . $path);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 5,
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
     * The MetriXs API base. Admin-settable, but receives the API key as a
     * Bearer token — anything that is not plain HTTPS is rejected in favour
     * of the default so the key can never be sent in the clear.
     *
     * @return string
     */
    private function apiBase()
    {
        $configured = rtrim((string) $this->config->get('plugins.' . $this->name . '.api_base', ''), '/');
        if ($configured !== '' && stripos($configured, 'https://') === 0) {
            return $configured;
        }

        return 'https://app.metrixs.eu';
    }

    /**
     * The site domain for the data-domain attribute, in order of preference:
     * the configured domain, then the host from system.custom_base_url (the
     * configured site URL — NOT the Host header, which a cache in front can
     * poison into cached HTML), then the request host as last resort.
     *
     * @return string
     */
    private function siteDomain()
    {
        $configured = strtolower(trim((string) $this->config->get('plugins.' . $this->name . '.domain', '')));
        if ($configured !== '') {
            return $configured;
        }

        $customBase = (string) $this->config->get('system.custom_base_url', '');
        if ($customBase !== '') {
            $host = strtolower((string) parse_url($customBase, PHP_URL_HOST));
            if ($host !== '') {
                return $host;
            }
        }

        return (string) $this->grav['uri']->host();
    }

    /**
     * Queue an admin message (no-op outside admin contexts).
     *
     * @param string $text
     * @param string $type
     * @return void
     */
    private function adminMessage($text, $type)
    {
        $messages = isset($this->grav['messages']) ? $this->grav['messages'] : null;
        if ($messages) {
            $messages->addMessage($text, $type);
        }
    }
}

// Grav loads plugins via `include` and expects the FILE to return the plugin
// instance; the class name now also matches Grav's camelize(slug).'Plugin'
// resolution (MxCookielessAnalyticsPlugin), so both load paths work.
// $name and $grav are provided by Grav in the included file's scope.
$instance = new MxCookielessAnalyticsPlugin($name, $grav);
return $instance;