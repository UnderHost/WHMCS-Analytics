# Instructions — WHMCS Analytics

A complete walkthrough: setting up Google, connecting, choosing storage, using the
dashboard, the AI SEO Advisor, and troubleshooting.

> Already installed? See **[INSTALL.md](INSTALL.md)** for upload/activate steps.
> Everything below is also available in-app on the **Setup guide** tab.

---

## Step 1 — Create Google OAuth credentials

You need a Google Cloud project with two APIs enabled and an OAuth client. It's free.

1. Go to the **[Google Cloud Console](https://console.cloud.google.com/)** and create (or pick) a project.
2. In the **[API Library](https://console.cloud.google.com/apis/library)**, enable:
   - **Google Analytics Data API**
   - **Google Search Console API**
3. Open **[APIs & Services → Credentials](https://console.cloud.google.com/apis/credentials)** →
   **Create credentials → OAuth client ID**.
   - **Application type:** *Web application*
   - **Name:** anything (e.g. `WHMCS`)
   - **Authorized JavaScript origins:** *leave empty*
   - **Authorized redirect URIs:** click **+ Add URI** and paste your module's redirect URI
     (see below) — it must match **exactly**.
4. Click **Create** and copy the **Client ID** and **Client secret**.

### Where do I get the redirect URI?

In WHMCS: **Addons → WHMCS Analytics → Settings & connection**. At the top there's a
**"Your Authorized redirect URI"** box with a **Copy** button. It looks like:

```
https://your-whmcs-admin/addonmodules.php?module=whmcs_analytics&oauth=callback
```

Copy that exact value into the Google OAuth client. (Google lets you edit the client later
if you need to fix it.)

### Publish the app (important)

New OAuth apps start in **Testing** mode, which (a) only lets listed test users sign in and
(b) **expires refresh tokens after 7 days** — which would break the daily sync. To avoid
that, in **Google Auth Platform → Audience** click **Publish app** (moves it to *Production*).

At the *"Google hasn't verified this app"* screen, click **Advanced → Go to … (unsafe)** —
this is expected and safe for **your own** app. Full Google verification is only needed if
you distribute the app to many outside users.

---

## Alternative to Steps 1–3 — Service Account (recommended for servers)

If you'd rather skip the OAuth consent screen, verification, and the 7-day token expiry,
connect with a **service account** instead. There's no Client ID/Secret and no "Connect
with Google" redirect — the token never expires, so the daily sync can't break.

1. In the **[API Library](https://console.cloud.google.com/apis/library)**, enable the
   **Google Analytics Data API** and **Search Console API**.
2. In **[IAM & Admin → Service Accounts](https://console.cloud.google.com/iam-admin/serviceaccounts)**,
   create a service account, then **Keys → Add key → Create new key → JSON** and download it.
3. Give the service account read access to your data:
   - **GA4:** Admin → **Property Access Management** → add the service-account email as **Viewer**.
   - **Search Console:** Settings → **Users and permissions** → add the same email.
4. In WHMCS: **Addons → WHMCS Analytics → Settings & connection**, switch to the
   **Service account** method, paste the JSON key, and click **Connect with service account**.
5. Choose your **GA4 property** and **Search Console site** (Step 3 below), then continue to
   Step 4. You can skip Steps 1–2 entirely with this method.

---

## Step 2 — Enter your Client ID & Secret

In WHMCS: **Configuration → System Settings → Addon Modules → WHMCS Analytics → Configure**.
Paste the **Client ID** and **Client secret**, tick the admin roles that may use the module,
and **Save Changes**.

---

## Step 3 — Connect Google & choose your data

Open **Addons → WHMCS Analytics → Settings & connection**:

1. Click **Connect with Google** and approve access.
2. Choose your **GA4 property** (the site whose analytics you want to show).
3. (Optional) Choose your **Search Console site** to enable keyword tracking.

---

## Step 4 — Choose where Search Console history is stored

GA4 data is always fetched **live** from Google and needs no database. Only Search Console
**history** is stored. On the same tab, under **Search Console history storage**, pick one:

| Backend | What to enter | Notes |
|---|---|---|
| **Local WHMCS database** *(default)* | nothing | Zero setup; tables auto-created; backed up with WHMCS |
| **External MySQL** | host, port, database, user, password | Offload off the main DB. MySQL 8.0+/MariaDB 10.2+ |
| **libSQL / Turso** | URL (`libsql://…` or `https://…`) + token | Remote libSQL endpoint |

Click **Test connection** to verify, then **Save**. History fills in on the daily cron, or
click **Sync** on the dashboard's Search Console tab to start now. A 12-month backfill
completes over a few daily runs.

---

## Step 5 — Show the dashboard

Two places, both live once connected:

- **Home-page widget:** on the WHMCS admin **Home** page, open **Manage Widgets**
  (top-right gear) and enable **Google Analytics**.
- **Dedicated page:** **Addons → WHMCS Analytics → Dashboard** (full width).

---

## Using the dashboard

- **Date range:** pick a start/end and click **Apply**.
- **Graph:** users, sessions, and page views over time, with KPI tiles.
- **Real Time:** active users right now, by country.
- **Pages / Browsers / Languages / Operating Systems / Devices / Screen Resolution / Source:**
  sortable tables.
- **Countries:** a shaded **world-map heatmap** plus the country table.
- **Search Console:** the full SEO app —
  - Views: Queries / Pages / Countries / Devices / Dates / Search appearance
  - Range + comparison controls (previous period / week / month / year / custom)
  - **Explorer** (sortable, filterable), **Top Movers**, and **Opportunities**
  - Click any query for a detail drawer: weekly position/clicks/impressions/CTR charts and
    per-page/country/device breakdowns
- **SEO Advisor:** see below.

---

## AI SEO Advisor

Get prioritized, specific SEO recommendations generated from your own GA4 + Search Console
data by the LLM of your choice.

### Configure

**Settings & connection → AI SEO Advisor:**

1. Choose a **Provider**: OpenAI, Google Gemini, Anthropic (Claude), or DeepSeek.
2. Paste an **API key** for that provider. Get one from:
   - OpenAI — <https://platform.openai.com/api-keys>
   - Google Gemini (AI Studio) — <https://aistudio.google.com/apikey>
   - Anthropic — <https://console.anthropic.com/settings/keys>
   - DeepSeek — <https://platform.deepseek.com/api_keys>
3. (Optional) Set a specific **Model** — otherwise a sensible current default is used.
4. Tick the **consent** box and **Save AI settings**.

> The Gemini key comes from **Google AI Studio** and is **separate** from the Analytics OAuth
> connection. Make sure the *Generative Language API* is enabled on that key's project.

### Use

On the dashboard, open the **SEO Advisor** tab and click **Get advice**. It builds an
aggregate snapshot of your data, sends it to your chosen model, and renders the
recommendations (Quick wins, Keyword opportunities, Content ideas, CTR & technical, Watch-outs).

### Privacy

Nothing is sent until you click *Get advice*, and only an **aggregate snapshot** (top queries,
pages, countries, and KPIs — **no visitor personal data**) is transmitted to the provider you
selected. The advisor is completely optional and off until you enable it.

---

## Troubleshooting

**"Error 403: access_denied" when connecting**
Your OAuth app is in *Testing* mode. In **Google Auth Platform → Audience**, either add your
Google account under **Test users**, or (recommended) click **Publish app**. See Step 1.

**"redirect_uri_mismatch" when connecting**
The redirect URI in Google doesn't exactly match the module's. Copy the value from
**Settings & connection → Your Authorized redirect URI** (use the Copy button) into your
Google OAuth client's **Authorized redirect URIs**, and try again.

**The connection drops after ~7 days**
Your app is still in *Testing* mode (tokens expire weekly). **Publish** it to Production
(Step 1) for a durable connection.

**The widget doesn't appear in Manage Widgets**
Make sure the module is **Activated** (the widget registers via the addon). If you upgraded
from a pre-2.0 build, delete the stale `modules/widgets/WhmcsAnalytics.php` (see
[INSTALL.md](INSTALL.md) → Upgrading).

**Widget shows "0 / No data"**
Confirm the correct **GA4 property** is selected on *Settings & connection*, and that the
property actually has traffic for the chosen date range.

**AI Advisor says the model is unavailable / deprecated**
LLM model IDs change over time. Just type a current model you have access to in the
**Model** field (e.g. a current OpenAI, Gemini, Claude, or DeepSeek model) and save.

**Search Console tab says storage isn't configured**
Pick a backend under *Search Console history storage* and click **Test connection**. The
Local (WHMCS database) option needs no setup.

---

## Security & privacy summary

- Communicates directly with Google's official APIs over HTTPS; analytics data stays on your server.
- All data endpoints require an authenticated WHMCS admin session.
- Only **aggregate** Search Console metrics are stored — no visitor personal data.
- API keys/tokens you enter are stored in the module's settings table on your own server.

---

Built and maintained by **[UnderHost](https://underhost.com/)** · MIT License.
