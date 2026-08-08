# Changelog — WHMCS Analytics

All notable changes to this project are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/), and this project follows
[Semantic Versioning](https://semver.org/).

## [Unreleased]

_Changes for the next release go here. Suggested headings: **Added**, **Changed**,
**Fixed**, **Removed**, **Security**._

## [2.1.0] — 2026-08-09

### Added

- **Service Account authentication** — a second connection method alongside OAuth.
  Paste a Google service-account JSON key and share your GA4 property + Search
  Console site with the service-account email. **No OAuth consent screen, no app
  verification, and the token never expires** (so the daily Search Console sync
  won't break after 7 days like an unpublished OAuth app). Signs a JWT (RS256)
  and exchanges it for an access token — no extra PHP dependencies. Choose the
  method under **Settings & connection**; both paths feed the same dashboard.

### Changed

- The OAuth Client ID/Secret are no longer required when using Service Account
  auth (relaxed the credential gates in the AJAX endpoint, widget, cron, and
  daily hook). The setup guide now documents both connection methods.

## [2.0.2] — 2026-08-09

### Fixed

- **AI SEO Advisor was cut off mid-answer.** The output-token cap was too low —
  Gemini "flash" models spend part of the budget on internal thinking, which
  truncated the visible reply (and left broken Markdown). Raised the ceilings:
  Gemini `maxOutputTokens` 1300 → 8192, and OpenAI/Anthropic/DeepSeek
  `max_tokens` 1300 → 2500.

### Changed

- **Richer advisor output.** The prompt now asks for 3–6 specific bullets per
  section (citing real queries/pages/numbers), ~400–800 words, starting directly
  at the first heading — so recommendations are fuller and more concrete.

## [2.0.1] — 2026-08-08 — public GitHub release

### Added

- **AI SEO Advisor** — a bring-your-own-key LLM tab (OpenAI, Google Gemini,
  Anthropic/Claude, DeepSeek) that turns a GA4 + Search Console snapshot into
  prioritized recommendations. Consent-gated; only aggregate metrics are sent.
- **World-map heatmaps** on the GA4 Countries and Search Console country views.
- **Dedicated addon page** with Dashboard / Settings / **Setup guide** tabs, plus
  an in-UI copy-the-redirect-URI helper and Google Cloud links on the Configure
  screen.
- **Apps & Integrations listing** metadata (`whmcs.json` + `logo.png`).

### Fixed

- Admin dashboard **widget** now registers via the `AdminHomeWidgets` hook and is
  self-contained under `modules/addons/whmcs_analytics/` (no `modules/widgets/`
  file), resolving the widget not appearing and a class-redeclaration crash when
  a stale copy was left behind. Widget uses the full-width embed styling and a
  masonry re-layout nudge so neighbouring widgets no longer overlap.

### Changed

- Refreshed default AI model IDs to current releases; keys/models overridable.
- Subtle "Powered by UnderHost" footer credit on the dashboard/widget; UnderHost
  banner on the admin settings + setup-guide screens only (never on the stats).

## [1.0.0] — 2026-08-07 — first public release

Repackaged as a generic, public WHMCS addon. Works on a stock WHMCS install with
no theme edits: the dashboard is available both as a WHMCS **dashboard widget**
and on the addon's own **Dashboard** tab.

### Added

- **Pluggable Search Console history storage** with a driver abstraction
  (`lib/Db/*`, `lib/Storage.php`). Choose the backend in the settings:
  - **Local WHMCS database** (default, zero setup) — stores history in the WHMCS
    MySQL DB via its own isolated PDO connection (ANSI_QUOTES so the SQLite-style
    SQL runs unchanged); tables auto-created.
  - **External MySQL** — host/port/name/user/pass via PDO.
  - **libSQL / Turso** — remote libSQL endpoint (generalizes the old Bunny
    client) with URL + full/read-only tokens.
  A **Test connection** button verifies the chosen backend.
- **Setup guide tab** — step-by-step in-app instructions (Google OAuth, connect,
  choose data, pick storage, enable the widget).
- **AI SEO Advisor** (`lib/AiAdvisor.php`, dashboard **SEO Advisor** tab) —
  bring-your-own-key LLM (OpenAI default, Anthropic/Claude, DeepSeek) that turns a
  GA4 + Search Console snapshot into prioritized recommendations. Consent-gated:
  an aggregate snapshot (top queries/pages/countries + KPIs, no visitor PII) is
  sent only when an admin clicks **Get advice**. Provider/model/key configured in
  settings; advice rendered from Markdown.
- Portable read queries: `FULL OUTER JOIN` replaced with a `UNION`-of-keys +
  `LEFT JOIN` so comparisons run on both MySQL and libSQL; the week bucket and
  schema/upsert DDL are dialect-specific per driver.

### Changed

- Renamed everything for public release: module slug `whmcs_analytics`, namespace
  `WhmcsAnalytics`, widget `WhmcsAnalyticsWidget`, settings table
  `mod_whmcs_analytics`, display name **WHMCS Analytics**. (CSS/JS class prefixes
  kept as-is internally.)
- Settings screen reworked around the storage picker; the old Bunny env-var
  configuration (`BUNNY_DB_*`, `bunny.config.php`) and `BUNNY_SETUP.md` are
  removed in favor of in-UI configuration. See `STORAGE.md`.

### Requirements

- PHP 8.1+, WHMCS 8.x. For the MySQL backends: MySQL 8.0+ / MariaDB 10.2+ (CTEs).

## [0.3.1] — 2026-08-07

### Added

- **Dedicated addon page** (`whmcs_analytics.php`) — the full analytics
  dashboard is now available directly at *Addons → WHMCS Analytics*, not
  only the admin homepage. A **Dashboard / Settings** tab bar at the top switches
  between the live dashboard and the connection/threshold settings
  (`?view=settings`). Same UI + assets as the homepage embed and the widget.

### Changed

- **World-map colours** (`assets/geomap.js`) — darker, more saturated ramp and a
  square-root colour scale so a single dominant country no longer washes the rest
  of the map out to near-white; mid-tier countries now read clearly. The legend
  and tooltips still display the real (un-scaled) figures.

## [0.3.0] — 2026-08-07

### Added — world-map heatmaps + chart polish

- **World-map heatmap for countries** (`assets/geomap.js`, bundled
  `assets/echarts.min.js` v5.5.1 + `assets/world.geo.json`) — a heat-shaded,
  zoomable/pannable ECharts choropleth now sits above the country tables in both
  the **GA4 Countries** tab (shaded by users) and the **Search Console Countries**
  view (shaded by impressions). ECharts and the GeoJSON load lazily and only once.
  `CpgaGeoMap.render()` resolves country keys from ISO alpha-2 (GA4 `countryId`),
  ISO alpha-3 (Search Console), or English name to the map's ISO-3 keys, and
  adapts its palette to light/dark admin themes. The existing tables are kept
  intact beneath each map.

### Changed

- **GA4 Countries endpoint** (`ajax.php`) now returns a `geo` payload carrying
  both the map dataset (with ISO country codes via the `countryId` dimension) and
  the original table rows.
- **Chart polish** (`assets/ga.js`) — GA time-series line charts get point-style
  legends/tooltips, thousands-separated values, and cleaner axes.

### Fixed

- **Keyword-detail drawer charts** (`assets/gsc.js`) showed `undefined` in their
  tooltips because the datasets had no label. Each chart (Position / Clicks /
  Impressions / CTR) now carries its metric name and a formatted tooltip.

## [0.2.0] — 2026-07-25

### Added — Search Console expansion + historical storage

- **Bunny Database (libSQL) integration** (`lib/Bunny.php`) — dependency-free
  libSQL HTTP pipeline client over cURL. Credentials read from server env vars
  (`BUNNY_DB_URL` / `BUNNY_DB_TOKEN` / `BUNNY_DB_READONLY_TOKEN`) with a protected
  `config/bunny.config.php` fallback. Read path uses the read-only token.
- **Historical store** (`lib/GscStore.php`) — per-dimension daily tables (query,
  page, country, device, search appearance) + daily totals + per-query meta
  (first/last seen, best/worst position) + per-site sync state. Impression-
  weighted average position; week-over-week and period-over-period comparisons
  computed at read time.
- **Daily ingestion** (`lib/GscSync.php`, `hooks.php` `DailyCronJob`,
  `cron/gsc_sync.php`) — incremental trailing-window refresh + resumable monthly
  backfill (default 12 months), time-boxed. Manual **Sync** button on the
  dashboard loops until backfill completes.
- **Read API** (`lib/GscApi.php`) — Bunny-backed endpoints: `view`, `summary`,
  `movers`, `opportunities`, `detail`, `sync`, `status`. Full sorting (incl.
  position/click/impression/CTR change), filtering (text, min impressions/clicks,
  position range, movement), and comparison handling. Keyword-detail breakdowns
  (pages/countries/devices for one query) fetched live from Google.
- **Search Console sub-app** (`assets/gsc.js`, `assets/gsc.css`) — replaces the
  simple keywords table with a view switcher, filter + comparison bar, sortable
  movement table with accessible badges (↑/↓/–/New/Lost), summary cards, Top
  Movers, Opportunities, and a keyword detail drawer with four separate charts
  (Position over time uses a reversed axis so position 1 is at the top).
- **Module settings**: Bunny status + stored-coverage display and configurable
  tracking/opportunity thresholds (history window, movement threshold, CTR /
  page-one / declining thresholds).
- **Google.php**: `searchAnalyticsAll()` paging helper (startRow paging) for
  full-range ingestion.
- Docs: `BUNNY_SETUP.md`, `SEARCH_CONSOLE.md`.

### Security

- The full-access Bunny token is used only by the sync/cron writer and the
  dashboard Sync button; all dashboard reads use the read-only token. Data
  endpoints require an authenticated WHMCS admin session. Only aggregate Search
  Console metrics are stored (no personal data).

## [0.1.0] — 2026-07-24

- Initial release: GA4 OAuth connection, property/site pickers, dashboard widget
  with graph + KPI tiles + tabs (Real Time, Pages, Countries, Browsers,
  Languages, OS, Devices, Screen Resolution, Source, Search Console).
