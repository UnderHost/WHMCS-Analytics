# Installation — WHMCS Analytics

> For the full setup walkthrough (Google Cloud, connecting, storage, the AI advisor,
> troubleshooting) see **[INSTRUCTION.md](INSTRUCTION.md)**.

## Requirements

- **WHMCS** 8.x
- **PHP** 8.1 or newer with the **cURL** extension enabled
- **pdo_mysql** (present on virtually all WHMCS hosts) — required for the Local and
  External MySQL storage backends
- A **Google Analytics 4** property and a free **Google Cloud OAuth client**
- For the MySQL storage backends: **MySQL 8.0+** or **MariaDB 10.2+**

## 1. Download

Grab **`whmcs-analytics-2.0.1.zip`** from the
[latest release](../../releases/latest).

## 2. Upload / extract

The ZIP contains a `modules/` tree that mirrors the WHMCS directory layout:

```
modules/addons/whmcs_analytics/…
```

Extract it **into your WHMCS root** so the files land at
`modules/addons/whmcs_analytics/`. You can do this two ways:

- **cPanel / File Manager:** upload the ZIP to the WHMCS root and *Extract* it there.
- **SSH / SFTP:** upload and unzip:
  ```bash
  cd /path/to/whmcs
  unzip whmcs-analytics-2.0.1.zip
  ```

After extraction you should have:

```
modules/addons/whmcs_analytics/
├── whmcs_analytics.php      # main addon module
├── hooks.php               # widget registration + daily Search Console sync
├── ajax.php                # authenticated data endpoint
├── whmcs.json / logo.png   # Apps & Integrations listing
├── assets/                 # JS, CSS, bundled Chart.js + ECharts + world map
├── cron/gsc_sync.php       # optional standalone CLI sync
└── lib/                    # Google, Storage drivers, Search Console, AI advisor, widget
```

> The entire product lives in this **one folder** — nothing goes into `modules/widgets/`.

## 3. Activate

1. In WHMCS go to **Configuration → System Settings → Addon Modules**
   (older UI: *Setup → Addon Modules*).
2. Find **WHMCS Analytics** and click **Activate**.
3. Under **Access Control**, tick the admin role groups allowed to use it
   (e.g. *Full Administrator*), and **Save Changes**.

## 4. Configure credentials

Click **Configure** on the module row and paste your Google OAuth **Client ID** and
**Client Secret**. The field descriptions link straight to the Google Cloud Console.
Don't have them yet? Follow **[INSTRUCTION.md](INSTRUCTION.md) → Step 1**.

## 5. Connect & finish

Open **Addons → WHMCS Analytics** and use the **Setup guide** tab. In short:

1. Add the **redirect URI** (shown with a copy button on the *Settings & connection* tab)
   to your Google OAuth client.
2. Click **Connect with Google**, then choose your **GA4 property** and **Search Console site**.
3. Pick a **history storage** backend (Local is the zero-setup default) and **Test connection**.
4. On the WHMCS **Home** dashboard, open *Manage Widgets* and enable **Google Analytics**.

## Cron (optional but recommended)

Search Console history backfills automatically on the **daily WHMCS cron** — no action
needed. To backfill faster you can also run the standalone CLI script from system cron:

```bash
php /path/to/whmcs/modules/addons/whmcs_analytics/cron/gsc_sync.php
```

## Upgrading

When updating from an **older build**, the safest approach is to **delete the old module
folder first**, then extract the new ZIP:

```bash
rm -rf /path/to/whmcs/modules/addons/whmcs_analytics
# then extract the new whmcs-analytics-2.0.1.zip at the WHMCS root
```

> **Important — upgrading from a pre-2.0 build:** earlier versions shipped the dashboard
> widget at `modules/widgets/WhmcsAnalytics.php`. That file is **gone** in 2.x. If it's
> still on your server it will cause a *"Cannot declare class WhmcsAnalyticsWidget"* fatal.
> Delete it:
> ```bash
> rm -f /path/to/whmcs/modules/widgets/WhmcsAnalytics.php
> ```

Deleting and re-uploading does **not** lose your settings — connection details, the
selected property/site, and stored history are kept in the database.

## Uninstalling

1. On the module's **Settings & connection** tab, click **Disconnect** (revokes the Google token).
2. **Deactivate** the module in *Addon Modules*.
3. Delete the folder `modules/addons/whmcs_analytics/`.
4. (Optional) Drop the settings table `mod_whmcs_analytics` and any `gsc_*` history tables
   if you chose the Local/MySQL storage backend and want a full cleanup.
