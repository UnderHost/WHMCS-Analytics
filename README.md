<p align="center">
  <img src="modules/addons/whmcs_analytics/logo.png" width="110" alt="WHMCS Analytics">
</p>

<h1 align="center">WHMCS Analytics</h1>

<p align="center">
  <strong>Google Analytics 4 + Google Search Console, right inside your WHMCS admin.</strong><br>
  Live traffic reports, a world-map heatmap, keyword rank tracking, URL index-status checks,
  anomaly alerts, and an optional AI SEO advisor — with no theme edits, on a stock WHMCS install.
</p>

<p align="center">
  <img alt="Version" src="https://img.shields.io/badge/version-2.2.8-5865F2">
  <img alt="WHMCS" src="https://img.shields.io/badge/WHMCS-8.x-00b1b3">
  <img alt="PHP" src="https://img.shields.io/badge/PHP-8.1%2B-777bb3">
  <img alt="License" src="https://img.shields.io/badge/license-MIT-green">
</p>

---

## What it does

Stop tab-hopping between WHMCS, Google Analytics, and Search Console. WHMCS Analytics puts your
website's traffic and SEO data on the WHMCS admin dashboard — as a home-page **widget** and a full
**dedicated addon page**.

### Live Google Analytics 4
- Active users, new users, sessions, page views, engagement, bounce rate
- Breakdowns: pages, countries, browsers, languages, operating systems, devices, screen resolution, source
- A **world-map heatmap** of visitors by country
- Fast charts bundled locally (no external CDNs)

### Google Search Console
- Query & page performance: clicks, impressions, CTR, average position
- **Weekly keyword position tracking** with movement badges (improved / declined / new / lost)
- **Top Movers** and automatic **SEO Opportunities** (CTR gaps, page-one chances, declining keywords)
- Per-keyword history: position, clicks, impressions, and CTR over time
- A world-map heatmap of search impressions by country

### Indexing & Alerts
- **URL Inspection** — check any page's Google index status (Indexed / Not indexed / Excluded)
  from the dashboard, with coverage, canonical, robots.txt, last-crawl, and mobile details
- **Alerts** — a prioritized feed of what needs attention: weekly & 28-day traffic drops or
  spikes, worsening bounce rate, search-click swings, ranking drops, page-two opportunities,
  and (from WHMCS) revenue up or down

### AI SEO Advisor *(optional — bring your own key)*
- Turns your GA4 + Search Console data into prioritized, specific recommendations
- Works with **OpenAI**, **Google Gemini**, **Anthropic (Claude)**, or **DeepSeek**
- **Privacy-first:** only an aggregate snapshot (top queries/pages/countries + KPIs — no visitor PII)
  is sent, and only when an admin clicks *Get advice*

### Flexible history storage
Search Console history is stored in the backend **you** choose:

| Backend | Setup | Notes |
|---|---|---|
| **Local WHMCS database** | Zero setup (default) | Stored in your WHMCS DB, backed up with it |
| **External MySQL** | host / port / db / user / pass | Offload off the main DB. MySQL 8.0+/MariaDB 10.2+ |
| **libSQL / Turso** | URL + token | Remote libSQL endpoint |

---

## Requirements

- **WHMCS** 8.x
- **PHP** 8.1+ with the `cURL` extension (and `pdo_mysql` for the MySQL storage options)
- A **Google Analytics 4** property and a free **Google Cloud OAuth client**
- For the MySQL storage backends: **MySQL 8.0+** or **MariaDB 10.2+**

---

## Quick start

1. **Download** `whmcs-analytics-2.2.8.zip` from the [latest release](../../releases/latest).
2. **Extract** it into your WHMCS root — it drops files into `modules/addons/whmcs_analytics/`.
3. In WHMCS: **Configuration → System Settings → Addon Modules**, find **WHMCS Analytics**, click
   **Activate**, and tick the admin roles that may use it.
4. Click **Configure** and paste your Google OAuth **Client ID** and **Client Secret**.
5. Open **Addons → WHMCS Analytics → Setup guide** and follow the steps to connect Google.

Full details: [INSTALL.md](INSTALL.md) · Complete walkthrough: [INSTRUCTION.md](INSTRUCTION.md)

---

## Screenshots

> _coming soon_

---

## Documentation

- **[INSTALL.md](INSTALL.md)** — install, upgrade, and uninstall
- **[INSTRUCTION.md](INSTRUCTION.md)** — Google Cloud setup, connecting, storage, the dashboard, the AI advisor, and troubleshooting
- **[CHANGELOG.md](CHANGELOG.md)** — release notes

---

## Privacy & security

- Talks directly to Google's official APIs over HTTPS; your analytics data stays on your server.
- Data endpoints require an authenticated WHMCS admin session.
- Only **aggregate** Search Console metrics are stored (no visitor personal data).
- The AI SEO Advisor is **off until you enable it**, is consent-gated, and only ever sends an
  aggregate snapshot to the provider you choose.

---

## License & credits

MIT License. Built and maintained by **[UnderHost](https://underhost.com/)**.

Bundled third-party libraries: [Chart.js](https://www.chartjs.org/) (MIT) and
[Apache ECharts](https://echarts.apache.org/) (Apache-2.0), both included locally.

Parts of the development and documentation were assisted by
**[Claude](https://www.anthropic.com/claude)** (Anthropic).
