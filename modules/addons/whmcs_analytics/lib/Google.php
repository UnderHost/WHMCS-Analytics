<?php
/**
 * WHMCS Analytics — Google OAuth + GA4 Data API helper.
 *
 * Self-contained: uses cURL + JSON only (no google/apiclient dependency).
 * Original work (c) 2026 UnderHost.com. Not derived from any third-party theme.
 */

namespace WhmcsAnalytics;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class Google
{
    const OAUTH_AUTH   = 'https://accounts.google.com/o/oauth2/v2/auth';
    const OAUTH_TOKEN  = 'https://oauth2.googleapis.com/token';
    // GA4 (read-only) + Search Console (read-only)
    const SCOPE        = 'https://www.googleapis.com/auth/analytics.readonly https://www.googleapis.com/auth/webmasters.readonly';
    const ADMIN_API    = 'https://analyticsadmin.googleapis.com/v1beta';
    const DATA_API     = 'https://analyticsdata.googleapis.com/v1beta';
    const SC_API       = 'https://www.googleapis.com/webmasters/v3';
    const TABLE        = 'mod_whmcs_analytics';

    /** Ensure the settings table exists (called on activate). */
    public static function installTable()
    {
        if (!Capsule::schema()->hasTable(self::TABLE)) {
            Capsule::schema()->create(self::TABLE, function ($t) {
                $t->increments('id');
                $t->string('setting', 64)->unique();
                $t->text('value')->nullable();
            });
        }
    }

    public static function dropTable()
    {
        Capsule::schema()->dropIfExists(self::TABLE);
    }

    /* ---------------- settings storage (sensitive values encrypted) ------- */

    protected static $secretKeys = ['client_secret', 'refresh_token', 'access_token'];

    public static function set($key, $value)
    {
        if (in_array($key, self::$secretKeys, true) && $value !== null && $value !== '') {
            $value = self::encrypt($value);
        }
        Capsule::table(self::TABLE)->updateOrInsert(['setting' => $key], ['value' => $value]);
    }

    public static function get($key, $default = null)
    {
        $row = Capsule::table(self::TABLE)->where('setting', $key)->first();
        if (!$row) {
            return $default;
        }
        $value = $row->value;
        if (in_array($key, self::$secretKeys, true) && $value !== null && $value !== '') {
            $value = self::decrypt($value);
        }
        return $value;
    }

    public static function forget($key)
    {
        Capsule::table(self::TABLE)->where('setting', $key)->delete();
    }

    protected static function encrypt($v)
    {
        try {
            return 'enc:' . (new \WHMCS\Security\Encryption())->encrypt($v);
        } catch (\Throwable $e) {
            return $v; // fall back to plaintext if the service is unavailable
        }
    }

    protected static function decrypt($v)
    {
        if (strpos((string) $v, 'enc:') === 0) {
            try {
                return (new \WHMCS\Security\Encryption())->decrypt(substr($v, 4));
            } catch (\Throwable $e) {
                return '';
            }
        }
        return $v;
    }

    /* ---------------- OAuth ---------------------------------------------- */

    public static function isConfigured($clientId, $clientSecret)
    {
        return !empty($clientId) && !empty($clientSecret);
    }

    public static function isConnected()
    {
        return !empty(self::get('refresh_token'));
    }

    public static function authUrl($clientId, $redirectUri, $state)
    {
        return self::OAUTH_AUTH . '?' . http_build_query([
            'client_id'              => $clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            'access_type'            => 'offline',
            'include_granted_scopes' => 'true',
            'prompt'                 => 'consent',
            'state'                  => $state,
        ]);
    }

    /** Exchange an auth code for tokens; stores the refresh token. */
    public static function exchangeCode($clientId, $clientSecret, $redirectUri, $code)
    {
        $res = self::httpPost(self::OAUTH_TOKEN, [
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]);
        if (!empty($res['refresh_token'])) {
            self::set('refresh_token', $res['refresh_token']);
        }
        if (!empty($res['access_token'])) {
            self::set('access_token', $res['access_token']);
            self::set('access_expires', (string) (time() + (int) ($res['expires_in'] ?? 3000)));
        }
        return $res;
    }

    /** Return a valid access token, refreshing if needed. */
    public static function accessToken($clientId, $clientSecret)
    {
        $token   = self::get('access_token');
        $expires = (int) self::get('access_expires', 0);
        if ($token && $expires > time() + 30) {
            return $token;
        }
        $refresh = self::get('refresh_token');
        if (!$refresh) {
            throw new \RuntimeException('Not connected to Google. Complete the OAuth connection in the module settings.');
        }
        $res = self::httpPost(self::OAUTH_TOKEN, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refresh,
            'grant_type'    => 'refresh_token',
        ]);
        if (empty($res['access_token'])) {
            throw new \RuntimeException('Failed to refresh Google access token: ' . self::errText($res));
        }
        self::set('access_token', $res['access_token']);
        self::set('access_expires', (string) (time() + (int) ($res['expires_in'] ?? 3000)));
        return $res['access_token'];
    }

    public static function disconnect()
    {
        self::forget('refresh_token');
        self::forget('access_token');
        self::forget('access_expires');
        self::forget('property_id');
        self::forget('property_name');
        self::forget('sc_site');
    }

    /* ---------------- Search Console ------------------------------------ */

    /** List the Search Console sites the connected account can read. */
    public static function listSites($clientId, $clientSecret)
    {
        $token = self::accessToken($clientId, $clientSecret);
        $res   = self::httpGet(self::SC_API . '/sites', $token);
        $out   = [];
        foreach (($res['siteEntry'] ?? []) as $s) {
            if (($s['permissionLevel'] ?? '') === 'siteUnverifiedUser') {
                continue;
            }
            $out[] = ['url' => $s['siteUrl'] ?? '', 'level' => $s['permissionLevel'] ?? ''];
        }
        return $out;
    }

    /** Query Search Console Search Analytics for the stored site. */
    public static function searchAnalytics($clientId, $clientSecret, array $body)
    {
        $token = self::accessToken($clientId, $clientSecret);
        $site  = self::get('sc_site');
        if (!$site) {
            throw new \RuntimeException('No Search Console site selected in the module settings.');
        }
        return self::httpPost(
            self::SC_API . '/sites/' . rawurlencode($site) . '/searchAnalytics/query',
            $body,
            $token,
            true
        );
    }

    /**
     * Query Search Console and page through ALL rows (up to $maxRows) using
     * startRow paging. Returns the raw ['rows' => [...]] merged; throws/returns
     * the error payload if Google reports one.
     *
     * @param array $body base request (dimensions, startDate, endDate, type, dimensionFilterGroups)
     */
    public static function searchAnalyticsAll($clientId, $clientSecret, array $body, $maxRows = 25000)
    {
        $pageSize = 25000; // Search Console API max rowLimit
        $startRow = 0;
        $all      = [];
        do {
            $body['rowLimit'] = min($pageSize, $maxRows - count($all));
            $body['startRow'] = $startRow;
            $res = self::searchAnalytics($clientId, $clientSecret, $body);
            if (isset($res['error'])) {
                // Return the error to the caller (do not throw: sync handles it).
                return ['error' => $res['error'], 'rows' => $all];
            }
            $rows = $res['rows'] ?? [];
            $all  = array_merge($all, $rows);
            $got  = count($rows);
            $startRow += $got;
        } while ($got === $body['rowLimit'] && count($all) < $maxRows);

        return ['rows' => $all];
    }

    /* ---------------- GA4 Admin + Data ---------------------------------- */

    /** List GA4 properties the connected account can read. */
    public static function listProperties($clientId, $clientSecret)
    {
        $token = self::accessToken($clientId, $clientSecret);
        $out   = [];
        $summaries = self::httpGet(self::ADMIN_API . '/accountSummaries?pageSize=200', $token);
        foreach (($summaries['accountSummaries'] ?? []) as $acct) {
            foreach (($acct['propertySummaries'] ?? []) as $prop) {
                // property = "properties/123456789"
                $id = str_replace('properties/', '', $prop['property'] ?? '');
                if ($id === '') {
                    continue;
                }
                $out[] = [
                    'id'   => $id,
                    'name' => ($acct['displayName'] ?? '') . ' — ' . ($prop['displayName'] ?? $id),
                ];
            }
        }
        return $out;
    }

    /** Run a GA4 report against the stored property. */
    public static function runReport($clientId, $clientSecret, array $body)
    {
        $token    = self::accessToken($clientId, $clientSecret);
        $property = self::get('property_id');
        if (!$property) {
            throw new \RuntimeException('No GA4 property selected in the module settings.');
        }
        return self::httpPost(
            self::DATA_API . '/properties/' . $property . ':runReport',
            $body,
            $token,
            true
        );
    }

    public static function runRealtime($clientId, $clientSecret, array $body)
    {
        $token    = self::accessToken($clientId, $clientSecret);
        $property = self::get('property_id');
        if (!$property) {
            throw new \RuntimeException('No GA4 property selected.');
        }
        return self::httpPost(
            self::DATA_API . '/properties/' . $property . ':runRealtimeReport',
            $body,
            $token,
            true
        );
    }

    /* ---------------- HTTP ---------------------------------------------- */

    protected static function httpPost($url, array $data, $bearer = null, $json = false)
    {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($json) {
            $payload = json_encode($data);
            $headers[] = 'Content-Type: application/json';
        } else {
            $payload = http_build_query($data);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        }
        if ($bearer) {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Google API request failed: ' . $err);
        }
        curl_close($ch);
        return json_decode($raw, true) ?: [];
    }

    protected static function httpGet($url, $bearer)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $bearer],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        return json_decode($raw, true) ?: [];
    }

    public static function errText($res)
    {
        if (!empty($res['error']['message'])) {
            return $res['error']['message'];
        }
        if (!empty($res['error_description'])) {
            return $res['error_description'];
        }
        return is_array($res) ? json_encode($res) : (string) $res;
    }
}
