<?php
/**
 * AI SEO Advisor — sends a compact GA4 + Search Console snapshot to a
 * bring-your-own-key LLM (OpenAI / Anthropic / DeepSeek) and returns prioritized
 * SEO recommendations as Markdown.
 *
 * Privacy: this transmits AGGREGATE analytics (top queries, pages, countries,
 * KPIs — no visitor PII) to the third-party LLM the admin configures. It is
 * gated behind an explicit consent toggle in the settings and only runs when an
 * admin clicks "Get advice".
 */

namespace WhmcsAnalytics;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

class AiAdvisor
{
    const PROVIDERS = [
        'openai'    => 'OpenAI',
        'gemini'    => 'Google Gemini',
        'anthropic' => 'Anthropic (Claude)',
        'deepseek'  => 'DeepSeek',
    ];

    /** Sensible, current defaults; the admin can override the model in settings. */
    const DEFAULT_MODEL = [
        'openai'    => 'gpt-5.6-terra',
        'gemini'    => 'gemini-3.6-flash',
        'anthropic' => 'claude-sonnet-5',
        'deepseek'  => 'deepseek-chat',
    ];

    public static function provider()
    {
        $p = (string) Google::get('ai_provider', 'openai');
        return isset(self::PROVIDERS[$p]) ? $p : 'openai';
    }

    public static function providerLabel($p = null)
    {
        $p = $p ?: self::provider();
        return self::PROVIDERS[$p] ?? $p;
    }

    public static function model()
    {
        $m = trim((string) Google::get('ai_model', ''));
        return $m !== '' ? $m : self::DEFAULT_MODEL[self::provider()];
    }

    public static function hasKey()
    {
        return trim((string) Google::get('ai_api_key', '')) !== '';
    }

    public static function consented()
    {
        return (string) Google::get('ai_consent', '') === '1';
    }

    public static function isReady()
    {
        return self::hasKey() && self::consented() && function_exists('curl_init');
    }

    public static function reason()
    {
        if (!function_exists('curl_init')) { return 'PHP cURL extension is not available.'; }
        if (!self::hasKey())    { return 'No API key saved for ' . self::providerLabel() . '.'; }
        if (!self::consented()) { return 'Data-sharing consent has not been granted.'; }
        return '';
    }

    /* ------------------------------------------------------------------ */
    /* Advice                                                              */
    /* ------------------------------------------------------------------ */

    public static function advise($clientId, $clientSecret)
    {
        if (!self::isReady()) {
            return ['error' => self::reason(), 'need_config' => true];
        }
        try {
            $snapshot = self::snapshot($clientId, $clientSecret);
            $messages = self::buildMessages($snapshot);
            $advice   = self::call($messages);
            return [
                'ok'       => true,
                'advice'   => $advice,
                'provider' => self::providerLabel(),
                'model'    => self::model(),
                'range'    => $snapshot['range'] ?? '',
            ];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Snapshot (GA4 live + Search Console history)                        */
    /* ------------------------------------------------------------------ */

    protected static function snapshot($clientId, $clientSecret)
    {
        $end   = date('Y-m-d', strtotime('-1 day'));
        $start = date('Y-m-d', strtotime('-28 day'));
        $range = [['startDate' => $start, 'endDate' => $end]];
        $snap  = [
            'property' => Google::get('property_name', ''),
            'site'     => Google::get('sc_site', ''),
            'range'    => $start . ' → ' . $end,
            'ga4'      => [],
            'search_console' => null,
        ];

        // ---- GA4 (live) ----
        try {
            $kpi = Google::runReport($clientId, $clientSecret, [
                'dateRanges' => $range,
                'metrics'    => array_map(function ($m) { return ['name' => $m]; },
                    ['activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagementRate', 'bounceRate']),
            ]);
            $k = $kpi['rows'][0]['metricValues'] ?? [];
            $snap['ga4']['kpis'] = [
                'active_users'    => (int) ($k[0]['value'] ?? 0),
                'new_users'       => (int) ($k[1]['value'] ?? 0),
                'sessions'        => (int) ($k[2]['value'] ?? 0),
                'page_views'      => (int) ($k[3]['value'] ?? 0),
                'engagement_rate' => round((float) ($k[4]['value'] ?? 0) * 100, 1) . '%',
                'bounce_rate'     => round((float) ($k[5]['value'] ?? 0) * 100, 1) . '%',
            ];
        } catch (\Throwable $e) { $snap['ga4']['error'] = $e->getMessage(); }

        $snap['ga4']['top_pages']     = self::gaTop($clientId, $clientSecret, $range, 'pagePath', 'screenPageViews', 8);
        $snap['ga4']['top_countries'] = self::gaTop($clientId, $clientSecret, $range, 'country', 'activeUsers', 6);
        $snap['ga4']['top_sources']   = self::gaTop($clientId, $clientSecret, $range, 'sessionSourceMedium', 'sessions', 6);
        $snap['ga4']['devices']       = self::gaTop($clientId, $clientSecret, $range, 'deviceCategory', 'activeUsers', 4);

        // ---- Search Console (stored history) ----
        try {
            if (Storage::isConfigured() && GscStore::hasData()) {
                $cov = GscStore::coverage();
                $scEnd   = $cov['max_date'];
                $scStart = date('Y-m-d', strtotime($scEnd . ' -27 day'));
                $prevEnd = date('Y-m-d', strtotime($scStart . ' -1 day'));
                $prevStart = date('Y-m-d', strtotime($prevEnd . ' -27 day'));

                $sum = GscStore::summary('query', $scStart, $scEnd, $prevStart, $prevEnd, 1.0);
                $rows = GscStore::compareAll('query', $scStart, $scEnd, $prevStart, $prevEnd, 300);

                $queries = [];
                foreach (array_slice($rows, 0, 15) as $r) {
                    $ci = (int) $r['cur_impr']; $cc = (int) $r['cur_clicks'];
                    $cp = $r['cur_pos'] !== null ? round((float) $r['cur_pos'], 1) : null;
                    $pp = $r['prev_pos'] !== null ? round((float) $r['prev_pos'], 1) : null;
                    $queries[] = [
                        'query'    => $r['k'],
                        'clicks'   => $cc,
                        'impr'     => $ci,
                        'ctr'      => $ci > 0 ? round($cc / $ci * 100, 1) . '%' : '0%',
                        'position' => $cp,
                        'pos_change' => ($cp !== null && $pp !== null) ? round($pp - $cp, 1) : null,
                    ];
                }
                $snap['search_console'] = [
                    'range'   => $scStart . ' → ' . $scEnd,
                    'summary' => $sum,
                    'top_queries' => $queries,
                ];
            }
        } catch (\Throwable $e) { $snap['search_console'] = ['error' => $e->getMessage()]; }

        return $snap;
    }

    protected static function gaTop($clientId, $clientSecret, $range, $dim, $metric, $limit)
    {
        try {
            $resp = Google::runReport($clientId, $clientSecret, [
                'dateRanges' => $range,
                'dimensions' => [['name' => $dim]],
                'metrics'    => [['name' => $metric]],
                'orderBys'   => [['metric' => ['metricName' => $metric], 'desc' => true]],
                'limit'      => $limit,
            ]);
            $out = [];
            foreach (($resp['rows'] ?? []) as $r) {
                $out[] = [
                    'label' => $r['dimensionValues'][0]['value'] ?? '',
                    'value' => (int) ($r['metricValues'][0]['value'] ?? 0),
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ------------------------------------------------------------------ */
    /* Prompt                                                              */
    /* ------------------------------------------------------------------ */

    protected static function buildMessages($snapshot)
    {
        $system = 'You are a senior SEO consultant reviewing a web-hosting company\'s '
            . 'Google Analytics 4 and Google Search Console data. Give specific, prioritized, '
            . 'actionable recommendations grounded in the actual numbers, queries and pages provided — '
            . 'cite them. Avoid generic advice and filler. Focus on realistic wins for a hosting brand '
            . '(offshore/anti-DMCA/dedicated/VPS/shared hosting keywords, landing pages, CTR and content gaps). '
            . 'Respond in clear GitHub-flavored Markdown using these exact section headings, each with '
            . '3–6 specific bullet points that cite real queries, pages and numbers from the data: '
            . '"## Quick wins", "## Keyword opportunities", "## Content ideas", "## CTR & technical", "## Watch-outs". '
            . 'Be thorough but skimmable — roughly 400–800 words. Start directly with the first "## " heading; '
            . 'no preamble and no closing summary.';

        $user = "Here is the site's analytics snapshot as JSON. Base every recommendation on it.\n\n"
            . json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . "\n\nWrite the SEO recommendations now.";

        return ['system' => $system, 'user' => $user];
    }

    /* ------------------------------------------------------------------ */
    /* Provider dispatch                                                   */
    /* ------------------------------------------------------------------ */

    protected static function call($messages)
    {
        switch (self::provider()) {
            case 'anthropic': return self::callAnthropic($messages);
            case 'gemini':    return self::callGemini($messages);
            case 'deepseek':  return self::callOpenAiCompatible($messages, 'https://api.deepseek.com/v1/chat/completions');
            default:          return self::callOpenAiCompatible($messages, 'https://api.openai.com/v1/chat/completions');
        }
    }

    protected static function callOpenAiCompatible($messages, $endpoint)
    {
        $body = [
            'model' => self::model(),
            'messages' => [
                ['role' => 'system', 'content' => $messages['system']],
                ['role' => 'user', 'content' => $messages['user']],
            ],
            'temperature' => 0.4,
            'max_tokens' => 2500,
        ];
        $data = self::http($endpoint, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . trim((string) Google::get('ai_api_key', '')),
        ], $body);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') { throw new \RuntimeException('The model returned an empty response.'); }
        return $text;
    }

    protected static function callAnthropic($messages)
    {
        $body = [
            'model' => self::model(),
            'max_tokens' => 2500,
            'temperature' => 0.4,
            'system' => $messages['system'],
            'messages' => [['role' => 'user', 'content' => $messages['user']]],
        ];
        $data = self::http('https://api.anthropic.com/v1/messages', [
            'Content-Type: application/json',
            'x-api-key: ' . trim((string) Google::get('ai_api_key', '')),
            'anthropic-version: 2023-06-01',
        ], $body);
        $text = '';
        foreach (($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') { $text .= $block['text']; }
        }
        if ($text === '') { throw new \RuntimeException('The model returned an empty response.'); }
        return $text;
    }

    protected static function callGemini($messages)
    {
        // Google Generative Language API (Gemini). Uses a Google AI Studio API
        // key sent as a header (not in the URL). This is separate from the
        // Analytics OAuth connection.
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
            . rawurlencode(self::model()) . ':generateContent';
        $body = [
            'system_instruction' => ['parts' => [['text' => $messages['system']]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $messages['user']]]]],
            // Gemini "flash" models spend output tokens on internal thinking, so
            // give a generous ceiling — otherwise the visible answer gets cut off.
            'generationConfig' => ['temperature' => 0.4, 'maxOutputTokens' => 8192],
        ];
        $data = self::http($url, [
            'Content-Type: application/json',
            'x-goog-api-key: ' . trim((string) Google::get('ai_api_key', '')),
        ], $body);
        $text = '';
        foreach (($data['candidates'][0]['content']['parts'] ?? []) as $part) {
            if (isset($part['text'])) { $text .= $part['text']; }
        }
        if ($text === '') { throw new \RuntimeException('The model returned an empty response.'); }
        return $text;
    }

    protected static function http($url, array $headers, array $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 70,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($raw === false) { $err = curl_error($ch); curl_close($ch); throw new \RuntimeException('Request failed: ' . $err); }
        curl_close($ch);
        $data = json_decode($raw, true);
        if (!is_array($data)) { throw new \RuntimeException('Provider returned a non-JSON response (HTTP ' . $code . ').'); }
        if ($code >= 400) {
            $msg = $data['error']['message'] ?? ($data['error'] ?? ('HTTP ' . $code));
            throw new \RuntimeException(is_string($msg) ? $msg : json_encode($msg));
        }
        return $data;
    }
}
