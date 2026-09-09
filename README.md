# MX Cookieless Analytics for Grav

Cookieless, privacy-friendly web analytics for [Grav CMS](https://getgrav.org). The official MetriXs plugin.

- **No cookies, no consent banner.** MetriXs sets no cookies and stores no personal data or persistent identifiers, so no consent is required under the ePrivacy Directive / GDPR cookie rules.
- **Opt-in, off by default.** Activating the plugin loads nothing and sends nothing. Tracking starts only after you paste your site API key and enable tracking.
- **Automatic verification.** No DNS records, no theme editing: the plugin proves domain ownership to MetriXs itself.
- **EU-hosted.** Data lives on MetriXs servers in Germany and never leaves the EU.
- **Admin exclusion.** Users with admin access are excluded by default, so your own visits never pollute your stats. Regular logged-in users (members) are still tracked.
- **AI traffic panel.** See visitors arriving from ChatGPT, Perplexity, Gemini and other AI assistants. Team and Pro plans add a GEO-readiness audit (can AI systems read your pages?) and a classic SEO audit on the Visibility tab.
- **Security tab.** An audit of your site's HTTPS, security-header and cookie posture, plus a live feed of blocked bots.
- **MCP server.** Query your analytics read-only from Claude, ChatGPT, Gemini or Copilot: MetriXs ships a first-party MCP server at mcp.metrixs.eu.

## Installation

Install via GPM or the Admin plugin:

```sh
bin/gpm install mx-cookieless-analytics
```

## Setup

1. Create a free account at [app.metrixs.eu](https://app.metrixs.eu) and add your site.
2. Create a site API key in the MetriXs dashboard: **Settings → Sites → API keys**.
3. In Grav Admin, open **Plugins → MX Cookieless Analytics**, paste the API key, enable tracking, and save.
4. The plugin verifies your site automatically (a short challenge/response handshake with your MetriXs account). When the Admin shows "site verified", tracking is live.

Your dashboard is at [app.metrixs.eu](https://app.metrixs.eu): visitors, pageviews, sources, geography, devices, bounce rate, and more.

## Configuration

| Option | Default | Description |
|---|---|---|
| `enabled` | `false` | Master switch. Nothing is injected until this is on **and** the site is connected. |
| `api_key` | `''` | Site-scoped API key (`mtx_live_…`) from the MetriXs dashboard. Grants analytics access for this one site only, revocable at any time. |
| `api_base` | `https://app.metrixs.eu` | MetriXs endpoint. Only change for self-hosted setups. |
| `domain` | `''` | Site domain used for tracking. Leave empty to auto-detect. |
| `exclude_admin` | `true` | Skip tracking for logged-in admin users. |
| `connected` | `false` | Set automatically by the verification flow. Do not edit by hand. |
| `challenge` | `''` | Verification token. Set automatically. Do not edit by hand. |

The tracker script is served from `app.metrixs.eu` with a versioned URL, injected into the page output on every request (a single, cheap string replacement) and loaded asynchronously (`defer`). It works fine alongside full-page caching and optimization setups.

## Disconnecting

Turn off **Enable tracking** to pause instantly. To disconnect the site entirely, revoke the API key in the MetriXs dashboard (**Settings → Sites → API keys**); ingestion stops immediately.

## Privacy

What MetriXs does **not** do: no cookies, no visitor IP storage (daily-rotating salted hash only), no cross-day visitor identification, no data outside the EU. Details: [metrixs.eu/data-policy](https://metrixs.eu/data-policy).

## License

MIT. See [LICENSE](LICENSE).
