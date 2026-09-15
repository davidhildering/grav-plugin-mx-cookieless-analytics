# 1.1.1
## 2026-09-16

1. [](#new)
    * Updated the bundled tracker script (v1.2.0): custom-event properties that look like personal data (PII) are now removed automatically before they are stored, and property limits (max 30 props, key <= 300 chars, value <= 2000 chars) are enforced. Privacy: MetriXs still stores no personal data.

# 1.1.0
## 2026-09-14

1. [](#new)
    * One-click connect: a "Connect with MetriXs" link at the top of the plugin settings opens the MetriXs dashboard, where you create (or open) your account and the site is added, connected and verified automatically. No API key copying; works on your phone. The manual API-key flow works unchanged.

# 1.0.1
## 2026-09-02

1. [](#new)
    * Added a `docs` field to the plugin manifest.
2. [](#fixed)
    * Grav 2.0 (admin2/API stack): the connect flow never ran — `Plugin::isAdmin()` is unreliable there. The handshake now runs unconditionally from `onAdminAfterSave` (after the config write, so its result is never clobbered by the form post) and reads the submitted values from the saved config object.
    * "Exclude logged-in admins" no longer excludes every authenticated user: exclusion is permission-based (`admin.login` / `api.access`), so front-end members are still tracked.
    * The API key field is now a `password` field instead of clear text.
3. [](#improved)
    * The verification handshake now runs only from the save handler (never on the admin page-render path) with a ~5s total timeout.
    * `api_base` must be HTTPS; anything else falls back to the default so the API key is never sent in the clear.
    * The verification challenge is cleared once the handshake completes instead of being served indefinitely.
    * `data-domain` prefers the configured site URL (`system.custom_base_url`) over the Host header, which cannot be trusted behind a cache.
    * Plugin class renamed to `MxCookielessAnalyticsPlugin`, matching Grav's standard class-name resolution.

# 1.0.0
## 2026-09-02

1. [](#new)
    * Initial release: opt-in tracker injection (off until you connect), automatic site verification via challenge/response (no DNS records needed), admin-exclusion toggle, Admin-panel settings.
