<?php
/**
 * Broken Link Bulk Scanner  (plain PHP 8.x — cURL + DOM)
 * ---------------------------------------------------------------------------
 * Crawls a website and reports broken hyperlinks, similar in function to
 * deadlinkchecker.com. It fetches pages with cURL, extracts links with
 * DOMDocument + DOMXPath, normalises them to absolute URLs, tests each unique
 * link (HEAD then GET fallback), follows redirects, classifies the result and
 * produces:
 *   - a self-contained dark-themed HTML dashboard  (link_report.html)
 *   - a CSV export                                 (link_report.csv)
 *   - a live console progress log + final summary
 *
 * The crawl logic (fetchPage, extractLinks, normalizeUrl, checkLink, crawl)
 * is fully separated from the HTML rendering so it can be reused from a CLI
 * cron job or a background worker.
 *
 * Requirements
 *   - PHP 8.0+ with the curl + dom extensions (standard on macOS / Linux)
 *
 * Usage
 *   php link_checker.php --url=https://example.com [options]
 *
 * Options
 *   --url=URL            Starting page to crawl (required)
 *   --mode=MODE          'site' = crawl entire site following internal links
 *                        (default), 'page' = test only links on the start page
 *   --max-pages=N        Hard cap on pages fetched in site mode (default 100)
 *   --max-depth=N        Max crawl depth from the start page (default 3)
 *   --concurrency=N      Parallel requests in flight (default 10)
 *   --delay=MS           Delay between batches in ms (default 200)
 *   --no-assets          Test <a href> only (default also tests
 *                        <img src>, <link href>, <script src>)
 *   --connect-timeout=S  cURL connect timeout, seconds (default 10)
 *   --timeout=S          cURL total timeout, seconds (default 20)
 *   --ignore-robots      Do NOT honour robots.txt (default: honour it)
 *   --insecure           Skip TLS certificate verification
 *   --user-agent=STR     Override the crawler User-Agent string
 *   --output=FILE        HTML report path (default link_report.html)
 *   --csv=FILE           CSV export path  (default link_report.csv)
 *   --help               Show this help
 *
 * Examples
 *   # Whole-site scan, up to 200 pages
 *   php link_checker.php --url=https://example.com --max-pages=200
 *
 *   # Just the links on one page, no asset checks
 *   php link_checker.php --url=https://example.com/page --mode=page --no-assets
 */

// ─────────────────────────────────────────────────────────────────────────────
//  CLI arguments
// ─────────────────────────────────────────────────────────────────────────────

const DEFAULT_UA = 'BrokenLinkBulkScanner/1.0 (+link checker; cURL)';

/**
 * Send progress through an optional frontend-specific writer. The CLI leaves
 * this unset and receives plain text; the web UI installs an HTML-escaping
 * writer so content discovered on untrusted pages can never become markup.
 */
function set_progress_writer(?callable $writer): void {
    $GLOBALS['blbs_progress_writer'] = $writer;
}

function progress(string $message): void {
    $writer = $GLOBALS['blbs_progress_writer'] ?? null;
    if (is_callable($writer)) {
        $writer($message);
        return;
    }
    echo $message;
}

function parse_args(array $argv): array {
    $defaults = [
        'url'             => null,
        'mode'            => 'site',
        'max-pages'       => 100,
        'max-depth'       => 3,
        'concurrency'     => 10,       // parallel requests in flight
        'delay'           => 200,      // ms between batches
        'check-assets'    => true,
        'connect-timeout' => 10,       // seconds
        'timeout'         => 20,       // seconds
        'max-redirs'      => 10,
        'respect-robots'  => true,
        'verify-tls'      => true,
        'render'             => false,    // render pages with Playwright Chromium (run JS)
        'render-wait'        => 4000,     // JS settle time per page, in ms
        'render-concurrency' => 3,        // pages rendered in parallel (heavy)
        'chrome-bin'         => '',        // optional explicit browser binary (else managed Chromium)
        'node'               => 'node',                       // Node.js binary
        'runner'             => __DIR__ . '/render-runner.js', // Playwright render script
        'user-agent'      => DEFAULT_UA,
        'output'          => 'link_report.html',
        'csv'             => 'link_report.csv',
        'pdf'             => '',           // optional PDF export path (needs render engine)
    ];

    $opts = getopt('', [
        'url:', 'mode:', 'max-pages:', 'max-depth:', 'concurrency:', 'delay:', 'no-assets',
        'connect-timeout:', 'timeout:', 'max-redirs:', 'ignore-robots',
        'render', 'render-wait:', 'render-concurrency:', 'chrome-bin:', 'node:', 'runner:',
        'insecure', 'user-agent:', 'output:', 'csv:', 'pdf:', 'help',
    ]);

    if (isset($opts['help']) || empty($opts['url'])) {
        fwrite(STDOUT, <<<HELP

Broken Link Bulk Scanner — crawls a site and reports dead links

Usage:
  php link_checker.php --url=URL [options]

Options:
  --url=URL            Starting page to crawl (required)
  --mode=MODE          'site' (crawl whole site, default) | 'page' (start page only)
  --max-pages=N        Hard cap on pages fetched in site mode (default 100)
  --max-depth=N        Max crawl depth from the start page (default 3)
  --concurrency=N      Parallel requests in flight (default 10)
  --delay=MS           Delay between batches in ms (default 200)
  --no-assets          Test <a href> only (default also tests img/link/script)
  --connect-timeout=S  cURL connect timeout in seconds (default 10)
  --timeout=S          cURL total timeout in seconds (default 20)
  --max-redirs=N       Max redirects to follow per link (default 10)
  --ignore-robots      Do NOT honour robots.txt (default: honour it)
  --render             Render each page with headless Chromium (via Playwright)
                       so JavaScript-built markup/links are seen. Works on macOS,
                       Windows and Linux. Needs Node 18+ and a one-time setup:
                         npm install && npx playwright install chromium
  --render-wait=MS     JS settle time per page in render mode (default 4000)
  --render-concurrency=N  Pages rendered in parallel in render mode (default 3)
  --chrome-bin=PATH    Use a specific browser binary instead of the managed
                       Chromium (e.g. system Chrome/Chromium/Edge/Brave)
  --node=PATH          Node.js binary (default: node)
  --runner=PATH        Render script (default: ./render-runner.js)
  --insecure           Skip TLS certificate verification
  --user-agent=STR     Override the crawler User-Agent
  --output=FILE        HTML report path (default link_report.html)
  --csv=FILE           CSV export path  (default link_report.csv)
  --pdf=FILE           Also export the report as PDF (needs the render engine:
                       Node 18+ and `npx playwright install chromium`)
  --help               Show this help

Examples:
  php link_checker.php --url=https://example.com --max-pages=200
  php link_checker.php --url=https://example.com/page --mode=page --no-assets

HELP);
        exit(empty($opts['url']) && !isset($opts['help']) ? 1 : 0);
    }

    $args = array_merge($defaults, array_intersect_key($opts, $defaults));
    $args['url']             = (string)$opts['url'];
    $args['mode']            = (isset($opts['mode']) && strtolower($opts['mode']) === 'page') ? 'page' : 'site';
    $args['max-pages']       = max(1, (int)($opts['max-pages'] ?? $defaults['max-pages']));
    $args['max-depth']       = max(0, (int)($opts['max-depth'] ?? $defaults['max-depth']));
    $args['concurrency']     = max(1, (int)($opts['concurrency'] ?? $defaults['concurrency']));
    $args['delay']           = max(0, (int)($opts['delay'] ?? $defaults['delay']));
    $args['connect-timeout'] = max(1, (int)($opts['connect-timeout'] ?? $defaults['connect-timeout']));
    $args['timeout']         = max(1, (int)($opts['timeout'] ?? $defaults['timeout']));
    $args['max-redirs']      = max(0, (int)($opts['max-redirs'] ?? $defaults['max-redirs']));
    $args['check-assets']    = !isset($opts['no-assets']);
    $args['respect-robots']  = !isset($opts['ignore-robots']);
    $args['verify-tls']      = !isset($opts['insecure']);
    $args['render']             = isset($opts['render']);
    $args['render-wait']        = max(100, (int)($opts['render-wait'] ?? $defaults['render-wait']));
    $args['render-concurrency'] = max(1, (int)($opts['render-concurrency'] ?? $defaults['render-concurrency']));
    $args['chrome-bin']         = isset($opts['chrome-bin']) ? (string)$opts['chrome-bin'] : $defaults['chrome-bin'];
    $args['node']               = isset($opts['node'])   ? (string)$opts['node']   : $defaults['node'];
    $args['runner']             = isset($opts['runner']) ? (string)$opts['runner'] : $defaults['runner'];
    $args['user-agent']      = isset($opts['user-agent']) ? (string)$opts['user-agent'] : $defaults['user-agent'];
    $args['output']          = isset($opts['output']) ? (string)$opts['output'] : $defaults['output'];
    $args['csv']             = isset($opts['csv']) ? (string)$opts['csv'] : $defaults['csv'];
    $args['pdf']             = isset($opts['pdf']) ? (string)$opts['pdf'] : $defaults['pdf'];

    // Make sure the start URL has a scheme.
    if (!preg_match('#^https?://#i', $args['url'])) {
        $args['url'] = 'https://' . ltrim($args['url'], '/');
    }
    return $args;
}

// ─────────────────────────────────────────────────────────────────────────────
//  URL helpers
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Collapse "." / ".." path segments and lower-case the host so that the same
 * resource always produces one identical, comparable key.
 */
function canonicalize(string $url): string {
    $p = parse_url($url);
    if ($p === false || empty($p['host'])) return $url;

    $scheme = strtolower($p['scheme'] ?? 'http');
    $host   = strtolower($p['host']);
    $port   = isset($p['port']) ? ':' . $p['port'] : '';
    $path   = $p['path'] ?? '/';

    // Resolve dot-segments.
    $out = [];
    foreach (explode('/', $path) as $seg) {
        if ($seg === '..')      { array_pop($out); }
        elseif ($seg === '.')   { continue; }
        else                    { $out[] = $seg; }
    }
    $path = implode('/', $out);
    if ($path === '' || $path[0] !== '/') $path = '/' . $path;

    $query = isset($p['query']) && $p['query'] !== '' ? '?' . $p['query'] : '';
    $url = $scheme . '://' . $host . $port . $path . $query;

    // Percent-encode characters that are illegal in URLs the way browsers do
    // (spaces, control bytes, non-ASCII). cURL otherwise rejects the URL
    // ("Malformed input to a URL function") and a WORKING link reports as broken.
    return preg_replace_callback('/[\x00-\x20\x7F-\xFF]/', fn ($m) => rawurlencode($m[0]), $url);
}

/** Truncate UTF-8 text without requiring the optional mbstring extension. */
function truncate_text(string $text, int $limit): string {
    if ($limit < 0) return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit) . '…' : $text;
    }
    if (preg_match_all('/./us', $text, $chars) !== false) {
        return count($chars[0]) > $limit ? implode('', array_slice($chars[0], 0, $limit)) . '…' : $text;
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '…' : $text;
}

/**
 * Turn a possibly-relative href into an absolute, canonical http(s) URL.
 * Returns null for things we should never test (mailto:, tel:, javascript:,
 * data:, pure #fragments, empty values, non-http schemes). Placeholder
 * anchors (<a href="#">) are caught earlier in extractLinks() and reported
 * as their own class rather than being tested here.
 */
function normalizeUrl(string $href, string $base): ?string {
    $href = trim($href);
    if ($href === '') return null;

    // Skip non-navigable / non-http schemes and pure fragments.
    if (preg_match('#^(mailto:|tel:|sms:|javascript:|data:|file:|ftp:|callto:)#i', $href)) {
        return null;
    }

    // Drop any fragment — it never affects what the server returns.
    if (($h = strpos($href, '#')) !== false) {
        $href = substr($href, 0, $h);
        if ($href === '') return null;
    }

    // Already absolute http(s).
    if (preg_match('#^https?://#i', $href)) {
        return canonicalize($href);
    }

    $b = parse_url($base);
    if (!$b || empty($b['scheme']) || empty($b['host'])) return null;
    $scheme    = $b['scheme'];
    $authority = $scheme . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');

    // Protocol-relative: //host/path
    if (str_starts_with($href, '//')) {
        return canonicalize($scheme . ':' . $href);
    }
    // Root-relative: /path
    if ($href[0] === '/') {
        return canonicalize($authority . $href);
    }
    // Query-relative: replace the current document's query, not its filename.
    // https://host/dir/page?old + ?new => https://host/dir/page?new
    if ($href[0] === '?') {
        $basePath = $b['path'] ?? '/';
        if ($basePath === '') $basePath = '/';
        return canonicalize($authority . $basePath . $href);
    }
    // Schemes we don't recognise but that still contain a colon early on
    // (e.g. "tel:" caught above) — guard against odd "foo:bar" values.
    if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $href) && !preg_match('#^https?:#i', $href)) {
        return null;
    }
    // Document-relative: resolve against the base directory.
    $basePath = $b['path'] ?? '/';
    $dir = preg_replace('#/[^/]*$#', '/', $basePath);
    if ($dir === '' || $dir === null) $dir = '/';
    return canonicalize($authority . $dir . $href);
}

/**
 * Browser/resource hints are not visitor-facing links, and WordPress exposes
 * XML-RPC service-discovery endpoints as <link> metadata on every page. Those
 * endpoints are commonly blocked by WAFs or hosts and should not count as
 * broken links.
 */
function should_skip_link_element(DOMElement $node): bool {
    $rel = strtolower(trim($node->getAttribute('rel')));
    $tokens = $rel === '' ? [] : preg_split('/\s+/', $rel);

    if ($tokens && array_intersect(
            $tokens,
            [
                'preconnect',
                'dns-prefetch',
                'prefetch',
                'preload',
                'modulepreload',
                'pingback',
                'edituri',
                'wlwmanifest',
            ]
        )) {
        return true;
    }

    $type = strtolower(trim($node->getAttribute('type')));

    return in_array($type, ['application/rsd+xml', 'application/wlwmanifest+xml'], true);
}

/**
 * Decide whether an <a> href is a "placeholder" — a link with no real
 * navigable target that the user almost certainly left unfinished. These are
 * the values normalizeUrl() would silently drop, so they'd never appear in the
 * report otherwise. Returns [reasonKey, humanLabel], or null when the href is a
 * genuine link that should be fetched and HTTP-tested instead.
 *
 *   href=""                 → empty
 *   href="   "              → whitespace only (trims to empty)
 *   href="#"                → fragment to the top of the page, no target
 *   href="javascript:..."   → JS pseudo-link that never navigates
 *
 * NOT placeholders (these resolve to something real and are tested normally):
 *   href="#section"         → in-page anchor to an element id
 *   href="test"             → relative URL → fetched (shows as broken if 404)
 *   href="mailto:" / "tel:" → functional, just not HTTP
 */
function placeholder_reason(string $rawHref): ?array {
    $h = trim($rawHref);
    if ($h === '')                          return ['empty',      'Empty href (href="")'];
    if ($h === '#')                         return ['fragment',   'Fragment only (href="#")'];
    if (preg_match('#^javascript:#i', $h))  return ['javascript', 'JavaScript placeholder'];
    return null;
}

/** True when $url is on the same registrable host as $startHost (www-insensitive). */
function sameHost(string $url, string $startHost): bool {
    $h = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($h === '') return false;
    $h = preg_replace('#^www\.#', '', $h);
    $s = preg_replace('#^www\.#', '', strtolower($startHost));
    return $h === $s;
}

/** Path + query of a URL, used for robots.txt matching. */
function path_with_query(string $url): string {
    $p = parse_url($url);
    $path = $p['path'] ?? '/';
    if ($path === '') $path = '/';
    if (isset($p['query']) && $p['query'] !== '') $path .= '?' . $p['query'];
    return $path;
}

// ─────────────────────────────────────────────────────────────────────────────
//  robots.txt
// ─────────────────────────────────────────────────────────────────────────────

/** Parse robots.txt into  agent => ['allow'=>[...], 'disallow'=>[...]]. */
function parse_robots(string $txt): array {
    $groups = [];
    $current = [];          // agents the current rules apply to
    $expectAgent = false;   // a rule line was seen → next User-agent starts a new group

    foreach (preg_split('/\r?\n/', $txt) as $raw) {
        $line = trim(preg_replace('/#.*/', '', $raw));
        if ($line === '' || !str_contains($line, ':')) continue;
        [$field, $value] = array_map('trim', explode(':', $line, 2));
        $field = strtolower($field);

        if ($field === 'user-agent') {
            if ($expectAgent) { $current = []; $expectAgent = false; }
            $ua = strtolower($value);
            $current[] = $ua;
            $groups[$ua] ??= ['allow' => [], 'disallow' => []];
        } elseif ($field === 'allow' || $field === 'disallow') {
            $expectAgent = true;
            foreach ($current as $ua) {
                $groups[$ua][$field][] = $value;
            }
        }
    }
    return $groups;
}

/** Select the most-specific robots group matching the crawler product token. */
function robots_group_for_user_agent(array $groups, string $userAgent): array {
    $product = strtolower(trim((string)preg_split('/[\s\/]/', trim($userAgent), 2)[0]));
    $best = null;
    $bestLen = -1;
    foreach ($groups as $agent => $group) {
        $agent = strtolower(trim((string)$agent));
        if ($agent === '' || $agent === '*') continue;
        if (str_contains($product, $agent) && strlen($agent) > $bestLen) {
            $best = $group;
            $bestLen = strlen($agent);
        }
    }
    return $best ?? ($groups['*'] ?? []);
}

/** Does a robots rule (with * and $ wildcards) match this path? */
function robots_match(string $path, string $rule): bool {
    $pattern = preg_quote($rule, '#');
    $pattern = str_replace('\*', '.*', $pattern);
    if (str_ends_with($pattern, '\$')) {
        $pattern = substr($pattern, 0, -2) . '$';
    }
    return (bool)preg_match('#^' . $pattern . '#', $path);
}

/** Longest-match Allow/Disallow evaluation for the '*' group. */
function robots_allowed(string $path, array $group): bool {
    if (!$group) return true;
    $bestLen = -1; $bestAllow = true;
    foreach (['disallow', 'allow'] as $type) {
        foreach ($group[$type] as $rule) {
            if ($rule === '') continue;        // empty Disallow = allow everything
            if (robots_match($path, $rule)) {
                $len = strlen($rule);
                if ($len > $bestLen || ($len === $bestLen && $type === 'allow')) {
                    $bestLen = $len;
                    $bestAllow = ($type === 'allow');
                }
            }
        }
    }
    return $bestLen < 0 ? true : $bestAllow;
}

/** Fetch and parse robots.txt for the start host; returns the applicable group. */
function load_robots(string $startUrl, array $args): array {
    $p = parse_url($startUrl);
    $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $resp = http_request($base . '/robots.txt', 'GET', $args);
    if ($resp['errno'] !== 0 || $resp['code'] >= 400 || $resp['body'] === '') {
        return [];   // no usable robots.txt → nothing disallowed
    }
    $groups = parse_robots($resp['body']);
    return robots_group_for_user_agent($groups, (string)$args['user-agent']);
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTTP layer (cURL)
// ─────────────────────────────────────────────────────────────────────────────

/** Shared cURL options for a single request. $method is 'GET' or 'HEAD'. */
function curl_options(string $method, array $args): array {
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => $args['max-redirs'],
        CURLOPT_CONNECTTIMEOUT => $args['connect-timeout'],
        CURLOPT_TIMEOUT        => $args['timeout'],
        CURLOPT_USERAGENT      => $args['user-agent'],
        CURLOPT_SSL_VERIFYPEER => $args['verify-tls'],
        CURLOPT_SSL_VERIFYHOST => $args['verify-tls'] ? 2 : 0,
        CURLOPT_ENCODING       => '',                // accept gzip/deflate
        CURLOPT_AUTOREFERER    => true,
        CURLOPT_NOBODY         => ($method === 'HEAD'),
        CURLOPT_HTTPGET        => ($method === 'GET'),
        // Send the Accept / Accept-Language headers every real browser sends.
        // Without them, advertising compression (CURLOPT_ENCODING) but no Accept
        // headers is a classic bot signature that some WAFs (LiteSpeed, mod_
        // security, …) answer with a 403 — even though the page is public.
        CURLOPT_HTTPHEADER     => [
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
            'Accept-Language: en-US,en;q=0.9',
        ],
    ];
    if ($method === 'HEAD') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'HEAD';
    }
    return $opts;
}

/** Normalise a finished cURL handle into our result shape. */
function curl_result(\CurlHandle $ch, string $url, string $method, string|false|null $body): array {
    $info  = curl_getinfo($ch);
    $errno = curl_errno($ch);
    return [
        'body'      => ($body === false || $body === null) ? '' : $body,
        'code'      => (int)($info['http_code'] ?? 0),
        'final'     => $info['url'] ?? $url,
        'ctype'     => (string)($info['content_type'] ?? ''),
        'redirects' => (int)($info['redirect_count'] ?? 0),
        'errno'     => $errno,
        'error'     => $errno ? curl_error($ch) : '',
        'method'    => $method,
    ];
}

/**
 * Single cURL request. $method is 'GET' or 'HEAD'. Always follows redirects
 * and reports the final effective URL + status. Never throws: connection
 * problems come back as code 0 with a non-zero errno. Used for one-off
 * fetches such as robots.txt; bulk work goes through http_multi().
 */
function http_request(string $url, string $method, array $args): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, curl_options($method, $args));
    $body = curl_exec($ch);
    $res  = curl_result($ch, $url, $method, $body);
    return $res;
}

/**
 * Run many cURL requests with at most $concurrency in flight at once, using
 * the curl_multi API. $jobs is a list of ['key'=>mixed, 'url'=>string,
 * 'method'=>'GET'|'HEAD']. Returns  key => result  (same shape as
 * http_request()). The window is kept full: a new request starts as soon as
 * any in-flight one finishes.
 */
function http_multi(array $jobs, array $args, int $concurrency): array {
    $results = [];
    $jobs    = array_values($jobs);
    $total   = count($jobs);
    if ($total === 0) return $results;

    $concurrency = max(1, min($concurrency, $total));
    $mh      = curl_multi_init();
    $handles = [];   // (int)handle => job
    $next    = 0;

    // Launch the next queued job, if any, and register its handle.
    $launch = function () use (&$next, &$handles, $jobs, $total, $mh, $args) {
        if ($next >= $total) return;
        $job = $jobs[$next++];
        $ch  = curl_init($job['url']);
        curl_setopt_array($ch, curl_options($job['method'], $args));
        curl_multi_add_handle($mh, $ch);
        $handles[(int)$ch] = $job;
    };

    for ($i = 0; $i < $concurrency; $i++) $launch();

    do {
        do {
            $mrc = curl_multi_exec($mh, $running);
        } while ($mrc === CURLM_CALL_MULTI_PERFORM);

        // Block until there is activity (or a short timeout) to avoid busy-looping.
        if ($running && curl_multi_select($mh, 1.0) === -1) {
            usleep(50_000);
        }

        while ($done = curl_multi_info_read($mh)) {
            if ($done['msg'] !== CURLMSG_DONE) continue;
            $ch  = $done['handle'];
            $id  = (int)$ch;
            $job = $handles[$id];
            $results[$job['key']] = curl_result($ch, $job['url'], $job['method'], curl_multi_getcontent($ch));
            curl_multi_remove_handle($mh, $ch);
            unset($handles[$id]);
            $launch();   // keep the window full
        }
    } while ($handles);

    curl_multi_close($mh);
    return $results;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Headless rendering (optional --render mode) — via Playwright Chromium
//
//  By default we extract links from the raw HTML cURL downloads. Sites that
//  build their markup with JavaScript (SPAs, lazy-loaded menus, …) expose few
//  or no links that way. With --render we instead fetch each page in a headless
//  browser, let its scripts run, and extract links from the resulting live DOM.
//  In render mode the browser also owns the page's HTTP status / content-type
//  (taken from the navigation it ends up on), which lets the crawl get past
//  client-side bot challenges — e.g. Cloudflare's JS check returns 429 to cURL
//  but a real browser runs the challenge and loads the page. cURL is still used
//  for testing every discovered link; the browser only handles page fetching.
//
//  Rendering is delegated to a small Node helper (render-runner.js) driven by
//  Playwright, exactly like the Accessibility Bulk Scanner's axe-runner.js. PHP
//  owns the crawl; Node owns the browser. This is what makes rendering work
//  universally — macOS, Windows and Linux — with a managed Chromium that
//  `npx playwright install chromium` downloads once, instead of depending on a
//  system-installed Chrome found at OS-specific paths.
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Check that the rendering prerequisites are in place. Returns a human-readable
 * problem string, or null when everything needed to render is available.
 * Shared by the CLI and the web frontend so both report the same diagnostics.
 */
function render_preflight_problem(array $args): ?string {
    // Node present?
    $ver = trim((string)@shell_exec(escapeshellarg($args['node']) . ' --version 2>/dev/null'));
    if ($ver === '') {
        return "Node.js not found (looked for '{$args['node']}'). "
             . "Install Node 18+ and re-run, or pass --node=/path/to/node.";
    }
    // Runner present?
    if (!is_file($args['runner'])) {
        return "Render runner not found at {$args['runner']} (use --runner=PATH).";
    }
    // Playwright installed?
    $nm = dirname($args['runner']) . '/node_modules/playwright';
    if (!is_dir($nm)) {
        return "Node dependencies missing. In " . dirname($args['runner']) . " run:\n"
             . "  npm install\n"
             . "  npx playwright install chromium";
    }
    return null;
}

/**
 * Render a batch of page URLs by driving the Playwright Node runner once.
 * Returns  url => ['ok'=>bool, 'status'=>int, 'ctype'=>string, 'final'=>string,
 * 'html'=>string, 'error'=>string]  — the status/content-type/final URL the
 * browser ended up on (after any client-side redirect/challenge) plus the
 * rendered DOM. URLs the runner never reported back are simply absent from the
 * result, so callers can fall back to a cURL fetch. The runner renders up to
 * $args['render-concurrency'] pages at a time and streams results back as
 * NDJSON; its stderr (live progress) is passed straight through.
 */
function render_multi(array $urls, array $args): array {
    $urls = array_values(array_unique($urls));
    $out  = [];
    if (!$urls) return $out;

    // Hand the runner a plain JSON array of URLs via a temp file.
    $tmp = tempnam(sys_get_temp_dir(), 'blbs_urls_');
    file_put_contents($tmp, json_encode(array_values($urls)));

    $cmdParts = [
        $args['node'], $args['runner'],
        '--input', $tmp,
        '--concurrency', (string)max(1, (int)$args['render-concurrency']),
        '--wait', (string)max(0, (int)$args['render-wait']),
        '--timeout', (string)(max(0, (int)$args['render-wait']) + (int)$args['timeout'] * 1000),
        '--user-agent', (string)$args['user-agent'],
    ];
    if (empty($args['verify-tls'])) $cmdParts[] = '--ignore-tls';
    $cmd = implode(' ', array_map('escapeshellarg', $cmdParts));

    // Let users force a specific browser binary (system Chrome/Edge/Brave, etc.)
    // instead of the managed Chromium, via the runner's env override.
    $env = null;
    $chromeBin = trim((string)($args['chrome-bin'] ?? ''));
    if ($chromeBin !== '') {
        $env = getenv();                 // inherit the full current environment …
        $env['RENDER_CHROME_PATH'] = $chromeBin;  // … plus the override
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname($args['runner']), $env);
    if (!is_resource($proc)) {
        @unlink($tmp);
        progress("  ⚠ could not launch the render runner — using static HTML instead.\n");
        return $out;
    }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $buf  = '';
    $open = [1 => $pipes[1], 2 => $pipes[2]];

    $handleLine = function (string $line) use (&$out): void {
        $line = trim($line);
        if ($line === '') return;
        $obj = json_decode($line, true);
        if (!is_array($obj) || ($obj['type'] ?? '') !== 'result') return;
        if (empty($obj['url'])) return;
        $out[$obj['url']] = [
            'ok'     => !empty($obj['ok']),
            'status' => (int)($obj['status'] ?? 0),
            'ctype'  => (string)($obj['ctype'] ?? ''),
            'final'  => (string)($obj['finalUrl'] ?? $obj['url']),
            'html'   => isset($obj['html']) ? (string)$obj['html'] : '',
            'error'  => (string)($obj['error'] ?? ''),
        ];
    };

    while ($open) {
        $read = $open; $w = null; $e = null;
        if (@stream_select($read, $w, $e, 1, 0) === false) break;
        foreach ($read as $stream) {
            $chunk = fread($stream, 65536);
            if ($chunk === '' || $chunk === false) {
                if (feof($stream)) {
                    $key = array_search($stream, $open, true);
                    if ($key !== false) { fclose($stream); unset($open[$key]); }
                }
                continue;
            }
            if ($stream === $pipes[2]) {
                // Route runner output through the same frontend-aware writer as
                // crawler progress; URLs in this text originate on scanned sites.
                progress($chunk);
            } else {
                $buf .= $chunk;
                while (($nl = strpos($buf, "\n")) !== false) {
                    $handleLine(substr($buf, 0, $nl));
                    $buf = substr($buf, $nl + 1);
                }
            }
        }
    }
    if ($buf !== '') $handleLine($buf);

    proc_close($proc);
    @unlink($tmp);
    return $out;
}

/**
 * Fetch a batch of pages with the headless browser instead of cURL (render
 * mode). Returns the same  key => result  shape as http_multi(), but with the
 * status, content-type, final URL and body taken from a real browser
 * navigation. This is what lets the crawl get past client-side bot challenges
 * (e.g. Cloudflare's JS check) that reject cURL outright — a real browser runs
 * the challenge, so the page (and the status we record) is the post-challenge
 * one. Any page the browser can't fetch falls back to a cURL GET, so a runner
 * failure never blanks out the crawl.
 */
function render_pages(array $fetchJobs, array $args): array {
    $urls = array_map(fn($job) => $job['url'], $fetchJobs);
    progress("  🧭 fetching " . count($urls) . " page(s) with headless Chromium…\n");
    $rendered = render_multi($urls, $args);

    $pageResps = [];
    $needCurl  = [];   // pages the browser couldn't fetch → cURL fallback
    foreach ($fetchJobs as $job) {
        $u = $job['url'];
        $r = $rendered[$u] ?? null;
        if (is_array($r) && $r['ok'] && $r['status'] > 0) {
            $pageResps[$u] = [
                'body'      => $r['html'],
                'code'      => $r['status'],
                'final'     => $r['final'] !== '' ? $r['final'] : $u,
                'ctype'     => $r['ctype'],
                'redirects' => 0,
                'errno'     => 0,
                'error'     => '',
                'method'    => 'GET',
                'rendered'  => true,
            ];
        } else {
            $needCurl[] = $job;
        }
    }

    if ($needCurl) {
        progress("  ↩ " . count($needCurl) . " page(s) the browser couldn't fetch — falling back to cURL\n");
        foreach (http_multi($needCurl, $args, $args['concurrency']) as $u => $resp) {
            $pageResps[$u] = $resp;
        }
    }
    return $pageResps;
}

/**
 * Print a finished HTML report to PDF by driving the Playwright Node runner in
 * its --pdf mode. The HTML report is fully self-contained (inline CSS), so the
 * browser renders it identically. Returns true on success; on any problem it
 * prints a friendly note and returns false so the caller can carry on without
 * a PDF (it's an optional export, never fatal). Needs the same Node/Playwright
 * setup as render mode — checked via render_preflight_problem().
 */
function render_pdf(string $htmlPath, string $pdfPath, array $args): bool {
    $problem = render_preflight_problem($args);
    if ($problem !== null) {
        progress("  ⚠ PDF export needs the render engine — skipped.\n"
           . "     " . str_replace("\n", "\n     ", $problem) . "\n");
        return false;
    }
    if (!is_file($htmlPath)) {
        progress("  ⚠ PDF export skipped — HTML report not found at {$htmlPath}.\n");
        return false;
    }

    $cmdParts = [
        $args['node'], $args['runner'],
        '--pdf-from', $htmlPath,
        '--pdf-out', $pdfPath,
    ];
    $cmd = implode(' ', array_map('escapeshellarg', $cmdParts));

    $env = null;
    $chromeBin = trim((string)($args['chrome-bin'] ?? ''));
    if ($chromeBin !== '') {
        $env = getenv();
        $env['RENDER_CHROME_PATH'] = $chromeBin;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes, dirname($args['runner']), $env);
    if (!is_resource($proc)) {
        progress("  ⚠ could not launch the render runner for PDF export — skipped.\n");
        return false;
    }
    fclose($pipes[0]);
    // Pass the runner's progress through; drain stdout so the pipe can't fill.
    stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);

    if ($code !== 0 || !is_file($pdfPath)) {
        $msg = trim($err) !== '' ? ' (' . trim(explode("\n", trim($err))[0]) . ')' : '';
        progress("  ⚠ PDF export failed{$msg} — skipped.\n");
        return false;
    }
    return true;
}

/**
 * Test a batch of links in parallel. Mirrors the single-link policy: try a
 * cheap HEAD for every URL first, then re-test with GET only those the server
 * rejected or mis-answered for HEAD (400/403/404/405/406/501), returned nothing
 * for, or failed to reach. The 404 retry matters because some endpoints don't
 * implement HEAD and 404 it while serving the resource fine on GET (e.g. the
 * Google Maps JS API) — so a 404 is always confirmed with a real GET before
 * being reported broken.
 * Returns  url => result  with a 'method' field recording which one was used.
 */
function checkLinks(array $urls, array $args): array {
    $concurrency = $args['concurrency'];

    // Wave 1 — HEAD everything.
    $headJobs = [];
    foreach ($urls as $u) $headJobs[] = ['key' => $u, 'url' => $u, 'method' => 'HEAD'];
    $head = http_multi($headJobs, $args, $concurrency);

    // Wave 2 — GET only the links that need a fallback.
    $getJobs = [];
    foreach ($urls as $u) {
        $res = $head[$u];
        $needGet = $res['errno'] !== 0
            || $res['code'] === 0
            || in_array($res['code'], [400, 403, 404, 405, 406, 501], true);
        if ($needGet) $getJobs[] = ['key' => $u, 'url' => $u, 'method' => 'GET'];
    }
    $get = $getJobs ? http_multi($getJobs, $args, $concurrency) : [];

    // Merge: prefer the GET result when it reached the server.
    $out = [];
    foreach ($urls as $u) {
        $res = $head[$u];
        if (isset($get[$u]) && ($get[$u]['code'] > 0 || $res['code'] === 0)) {
            $out[$u] = $get[$u];   // already carries method = 'GET'
        } else {
            $out[$u] = $res;       // already carries method = 'HEAD'
        }
    }
    return $out;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Link extraction (DOM)
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Pull every link out of an HTML document and normalise it to an absolute URL.
 * Honours a <base href> if present. Returns a list of
 *   ['url' => absolute, 'type' => 'a'|'img'|'link'|'script', 'raw' => original]
 * Empty/placeholder anchors (href="", "#", "javascript:…") come back as
 *   ['url' => raw|'(empty href)', 'type' => 'a', 'raw' => …, 'placeholder' => true,
 *    'reasonKey' => 'empty'|'fragment'|'javascript', 'reason' => label, 'text' => …]
 */
function extractLinks(string $html, string $pageUrl, bool $checkAssets): array {
    if (trim($html) === '') return [];

    libxml_use_internal_errors(true);             // swallow malformed-HTML noise
    $doc = new DOMDocument();
    $doc->loadHTML($html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();

    $xp = new DOMXPath($doc);

    // A <base href> overrides the page URL for resolving relative links.
    $base = $pageUrl;
    $baseNode = $xp->query('//base[@href]')->item(0);
    if ($baseNode instanceof DOMElement) {
        $bh = trim($baseNode->getAttribute('href'));
        if ($bh !== '') {
            $resolved = normalizeUrl($bh, $pageUrl);
            if ($resolved) $base = $resolved;
        }
    }

    // attribute => link type
    $targets = ['a' => ['href', 'a']];
    if ($checkAssets) {
        $targets['img']    = ['src', 'img'];
        $targets['link']   = ['href', 'link'];
        $targets['script'] = ['src', 'script'];
    }

    $found = [];
    $seen  = [];
    foreach ($targets as $tag => [$attr, $type]) {
        foreach ($xp->query("//{$tag}[@{$attr}]") as $node) {
            if (!$node instanceof DOMElement) continue;
            $raw = trim($node->getAttribute($attr));

            // Skip <link> metadata/resource hints that are not visitor-facing
            // navigation or assets. This avoids false positives from WordPress
            // XML-RPC/RSD discovery links that hosts often block intentionally.
            if ($type === 'link' && should_skip_link_element($node)) {
                continue;
            }

            // Empty / placeholder anchors (href="", "#", "javascript:…") point
            // nowhere — flag them as dead/unfinished links instead of silently
            // dropping them. Real targets (#section, relative URLs) fall through
            // to normalizeUrl below and are tested over HTTP as usual.
            if ($type === 'a' && ($reason = placeholder_reason($raw)) !== null) {
                $text = truncate_text(trim(preg_replace('/\s+/', ' ', $node->textContent ?? '')), 80);

                // Capture the anchor's opening tag with all its attributes plus
                // its text, so the report can show the whole element (e.g.
                // <a href="#" id="tab1">Link One</a>) for much better context
                // than the bare href alone.
                $openTag = '<a';
                foreach ($node->attributes as $attrNode) {
                    $openTag .= ' ' . $attrNode->nodeName . '="' . $attrNode->nodeValue . '"';
                }
                $openTag .= '>';
                $element = $openTag . $text . '</a>';
                $element = truncate_text($element, 200);

                $key = 'placeholder|' . $reason[0] . '|' . $text;
                if (isset($seen[$key])) continue;   // de-dupe within a page
                $seen[$key] = true;
                $found[] = ['url' => $raw === '' ? '(empty href)' : $raw, 'type' => 'a',
                            'raw' => $raw, 'placeholder' => true, 'element' => $element,
                            'reasonKey' => $reason[0], 'reason' => $reason[1], 'text' => $text];
                continue;
            }

            $abs = normalizeUrl($raw, $base);
            if ($abs === null) continue;
            $key = $type . '|' . $abs;
            if (isset($seen[$key])) continue;       // de-dupe within a page
            $seen[$key] = true;
            $found[] = ['url' => $abs, 'type' => $type, 'raw' => $raw];
        }
    }
    return $found;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Classification
// ─────────────────────────────────────────────────────────────────────────────

/** Map a status code (+ errno) to a class key and human label. */
function classify(int $code, int $errno, int $redirects = 0): array {
    if ($errno !== 0 || $code === 0)        return ['conn',     'Connection error'];
    if ($code >= 400 && $code < 500)        return ['client',   'Client error'];
    if ($code >= 500)                       return ['server',   'Server error'];
    if ($redirects > 0)                     return ['redirect', 'Redirect'];
    if ($code >= 200 && $code < 300)        return ['ok',       'OK'];
    if ($code >= 300 && $code < 400)        return ['redirect', 'Redirect'];
    return ['conn', 'Unknown'];
}

/** Is this classification a broken link? */
function is_broken(string $class): bool {
    return in_array($class, ['client', 'server', 'conn'], true);
}

// ─────────────────────────────────────────────────────────────────────────────
//  Crawler  (BFS — no deep recursion)
// ─────────────────────────────────────────────────────────────────────────────

function polite_delay(array $args): void {
    if ($args['delay'] > 0) usleep($args['delay'] * 1000);
}

/**
 * Breadth-first crawl, one depth level at a time. Each level fetches all of
 * its pages in parallel, then tests every newly-discovered link in parallel,
 * so the slow part (network round-trips) is overlapped up to --concurrency.
 *
 * In 'site' mode it follows internal <a> links to discover pages (up to
 * max-pages / max-depth) while testing ALL links it finds. In 'page' mode it
 * tests only the links on the start page.
 *
 * Returns a structure consumed by the report/CSV/summary builders.
 */
function crawl(array $args): array {
    $start     = canonicalize($args['url']);
    $startHost = (string)parse_url($start, PHP_URL_HOST);

    $robots = $args['respect-robots'] ? load_robots($start, $args) : [];
    if ($args['respect-robots']) {
        progress($robots ? "🤖 robots.txt loaded — disallowed paths will be skipped.\n"
                         : "🤖 No robots.txt restrictions found.\n");
    }

    // Confirm the rendering prerequisites once if JS rendering was requested.
    $renderReady = false;
    if (!empty($args['render'])) {
        $problem = render_preflight_problem($args);
        if ($problem === null) {
            $renderReady = true;
            $engine = trim((string)($args['chrome-bin'] ?? '')) !== ''
                ? "headless Chromium ({$args['chrome-bin']})"
                : 'headless Chromium (Playwright)';
            progress("🧭 JS rendering ON — {$engine}\n");
        } else {
            progress("⚠  --render requested but unavailable — using static HTML instead.\n"
               . "     " . str_replace("\n", "\n     ", $problem) . "\n");
        }
    }

    $level   = [$start];           // page URLs to fetch at the current depth
    $queued  = [$start => true];   // every URL ever enqueued (avoid dupes)
    $visited = [];                 // pages actually fetched
    $tested  = [];                 // url => result row (each link tested once)
    $pageErrors = [];              // start/internal pages that failed to load
    $pagesFetched = 0;
    $depth = 0;

    progress("\n🔗 Crawling {$start}  (mode: {$args['mode']}, max-pages: {$args['max-pages']}, "
       . "depth: {$args['max-depth']}, concurrency: {$args['concurrency']})\n");

    while ($level && $pagesFetched < $args['max-pages']) {
        // Decide which pages in this level to actually fetch (skip visited /
        // robots-disallowed, and stop at the max-pages cap).
        $fetchJobs = [];
        foreach ($level as $pageUrl) {
            if (isset($visited[$pageUrl])) continue;
            if ($args['respect-robots'] && !robots_allowed(path_with_query($pageUrl), $robots)) {
                progress("  ⤫ robots disallow — skipping: {$pageUrl}\n");
                continue;
            }
            if ($pagesFetched + count($fetchJobs) >= $args['max-pages']) {
                progress("⚠  Reached max-pages cap ({$args['max-pages']}). Stopping crawl.\n");
                break;
            }
            $visited[$pageUrl] = true;
            $fetchJobs[] = ['key' => $pageUrl, 'url' => $pageUrl, 'method' => 'GET'];
        }
        if (!$fetchJobs) break;

        progress("\n── depth {$depth} — fetching " . count($fetchJobs) . " page(s) in parallel\n");
        polite_delay($args);

        // In render mode the browser — not cURL — fetches pages, so JS-built
        // markup is captured and client-side bot challenges (e.g. Cloudflare)
        // get a chance to resolve; the status we record is the one the browser
        // ended up on. Otherwise pages are fetched with plain cURL.
        if ($renderReady) {
            $pageResps = render_pages($fetchJobs, $args);
        } else {
            $pageResps = http_multi($fetchJobs, $args, $args['concurrency']);
        }
        $pagesFetched += count($pageResps);

        // Extract links from every fetched page; collect the links not yet
        // tested, and queue internal <a> targets for the next depth level.
        $nextLevel = [];
        $newLinks  = [];   // url => ['type'=>, 'source'=>]  (first sighting wins)
        foreach ($pageResps as $pageUrl => $resp) {
            if ($resp['errno'] !== 0 || $resp['code'] === 0 || $resp['code'] >= 400) {
                $label = $resp['code'] > 0 ? "HTTP {$resp['code']}" : ('connection error: ' . $resp['error']);
                $pageErrors[$pageUrl] = $label;
                progress("  ⚠ could not load page ({$label}) — {$pageUrl}\n");
                continue;
            }
            if (stripos($resp['ctype'], 'html') === false) {
                progress("  · non-HTML page ({$resp['ctype']}) — not parsed — {$pageUrl}\n");
                continue;
            }

            $links = extractLinks($resp['body'], $resp['final'] ?: $pageUrl, $args['check-assets']);
            $tag = !empty($resp['rendered']) ? ' [rendered]' : '';
            progress("  • {$pageUrl} — " . count($links) . " link(s){$tag}\n");

            foreach ($links as $lnk) {
                // Empty/placeholder anchors point nowhere — record them
                // directly (no HTTP test), keyed per page + reason + anchor
                // text so distinct dead links on the same page are each shown.
                if (!empty($lnk['placeholder'])) {
                    // Key by the link's identity (reason + text), NOT the page,
                    // so the same placeholder repeated site-wide collapses into
                    // one grouped row that lists every page it appears on.
                    $key = 'placeholder|' . $lnk['reasonKey'] . '|' . $lnk['text'];
                    if (!isset($tested[$key])) {
                        $tested[$key] = [
                            'url'       => $lnk['url'],
                            'element'   => $lnk['element'] ?? '',
                            'code'      => 0,
                            'class'     => 'placeholder',
                            'label'     => $lnk['reason'],
                            'final'     => $lnk['url'],
                            'method'    => '—',
                            'redirects' => 0,
                            'type'      => 'a',
                            'source'    => $pageUrl,
                            'sources'   => [],
                            'error'     => $lnk['text'] !== '' ? 'text: "' . $lnk['text'] . '"' : '(no link text)',
                            'internal'  => true,
                        ];
                    }
                    $tested[$key]['sources'][$pageUrl] = true;   // set: unique pages
                    continue;
                }

                // Record every page a link appears on (a set keyed by page URL),
                // so identical links found across the site become one row with a
                // "found on N pages" count instead of repeating per page.
                $u = $lnk['url'];
                // Robots rules govern all crawler requests, including the HEAD
                // and GET calls used to test a discovered internal link.
                if ($args['respect-robots']
                    && sameHost($u, $startHost)
                    && !robots_allowed(path_with_query($u), $robots)) {
                    progress("  ⤫ robots disallow — not testing: {$u}\n");
                    continue;
                }
                if (isset($tested[$u])) {
                    $tested[$u]['sources'][$pageUrl] = true;       // tested earlier, seen again
                } elseif (isset($newLinks[$u])) {
                    $newLinks[$u]['sources'][$pageUrl] = true;
                } else {
                    $newLinks[$u] = ['type' => $lnk['type'], 'sources' => [$pageUrl => true]];
                }
                // In site mode, queue internal <a> targets for the next level.
                if ($args['mode'] === 'site'
                    && $lnk['type'] === 'a'
                    && sameHost($u, $startHost)
                    && !isset($queued[$u])
                    && ($depth + 1) <= $args['max-depth']) {
                    $queued[$u] = true;
                    $nextLevel[] = $u;
                }
            }
        }

        // Test all newly-discovered links for this level in parallel.
        if ($newLinks) {
            progress("  ↻ testing " . count($newLinks) . " new link(s) in parallel…\n");
            polite_delay($args);
            $checked = checkLinks(array_keys($newLinks), $args);
            $broken = 0;
            foreach ($newLinks as $u => $meta) {
                $chk = $checked[$u];
                [$cls, $label] = classify($chk['code'], $chk['errno'], $chk['redirects']);
                if (is_broken($cls)) $broken++;
                $tested[$u] = [
                    'url'       => $u,
                    'code'      => $chk['code'],
                    'class'     => $cls,
                    'label'     => $label,
                    'final'     => $chk['final'],
                    'method'    => $chk['method'],
                    'redirects' => $chk['redirects'],
                    'type'      => $meta['type'],
                    'source'    => array_key_first($meta['sources']),
                    'sources'   => $meta['sources'],
                    'error'     => $chk['error'],
                    'internal'  => sameHost($u, $startHost),
                ];
            }
            progress("    done — {$broken} broken in this batch\n");
        }

        $level = $nextLevel;
        $depth++;
    }

    // Collapse each issue's page-set into an ordered list + count for the report.
    foreach ($tested as $k => $r) {
        $pages = isset($r['sources']) ? array_keys($r['sources']) : [$r['source']];
        $tested[$k]['pages']     = $pages;
        $tested[$k]['pageCount'] = count($pages);
        unset($tested[$k]['sources']);
    }

    return [
        'results'      => $tested,
        'pageErrors'   => $pageErrors,
        'pagesFetched' => $pagesFetched,
        'startHost'    => $startHost,
        'start'        => $start,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  Aggregation
// ─────────────────────────────────────────────────────────────────────────────

function aggregate(array $crawl): array {
    $counts = ['ok' => 0, 'redirect' => 0, 'client' => 0, 'server' => 0, 'conn' => 0, 'placeholder' => 0];
    $internal = 0; $external = 0; $broken = 0; $placeholders = 0;
    $byCode = [];

    foreach ($crawl['results'] as $r) {
        $counts[$r['class']] = ($counts[$r['class']] ?? 0) + 1;
        if ($r['internal']) $internal++; else $external++;
        if (is_broken($r['class'])) $broken++;
        // Placeholder links have no HTTP status — keep them out of the
        // status-code breakdown (their code 0 is not a connection error).
        if ($r['class'] === 'placeholder') { $placeholders++; continue; }
        $codeKey = $r['code'] > 0 ? (string)$r['code'] : 'ERR';
        $byCode[$codeKey] = ($byCode[$codeKey] ?? 0) + 1;
    }
    krsort($byCode, SORT_STRING);

    return [
        'counts'   => $counts,
        'internal' => $internal,
        'external' => $external,
        'broken'   => $broken,
        'placeholders' => $placeholders,
        'pageFailures' => count($crawl['pageErrors'] ?? []),
        'totalLinks' => count($crawl['results']),
        'byCode'   => $byCode,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
//  HTML report
// ─────────────────────────────────────────────────────────────────────────────

function class_color(string $class): string {
    switch ($class) {
        case 'ok':       return '#22c55e';
        case 'redirect': return '#3b82f6';
        case 'client':   return '#f59e0b';
        case 'server':   return '#ef4444';
        case 'conn':     return '#a855f7';
        case 'placeholder': return '#2dd4bf';
        default:         return '#94a3b8';
    }
}

function status_badge(array $r): string {
    $code = $r['code'] > 0 ? (string)$r['code'] : 'ERR';
    $errorClass = $r['code'] > 0 ? '' : ' badge-error';
    return "<span class=\"badge badge-{$r['class']}$errorClass\">$code</span>";
}

function short_url(string $url): string {
    return htmlspecialchars(preg_replace('#^https?://#', '', $url));
}

/**
 * Render the "Found on page" cell. A link found on a single page shows that
 * page; one found on many collapses into a "<n> pages" disclosure listing them
 * all (capped, with the full set always in the CSV). This is what groups the
 * otherwise-repetitive per-page rows into one entry per unique issue.
 */
function sources_cell(array $r): string {
    $pages = !empty($r['pages']) ? $r['pages'] : [$r['source']];
    $n = count($pages);
    $link = fn(string $p): string =>
        '<a href="' . htmlspecialchars($p) . '" target="_blank" rel="noopener">' . short_url($p) . '</a>';
    if ($n <= 1) return $link($pages[0]);

    $cap = 30;
    $items = '';
    foreach (array_slice($pages, 0, $cap) as $p) $items .= $link($p) . '<br>';
    if ($n > $cap) $items .= '<span class="more">…and ' . ($n - $cap) . ' more (see CSV)</span>';
    return '<details class="pages"><summary>' . $n . ' pages</summary>'
         . '<div class="pagelist">' . $items . '</div></details>';
}

function status_filter_cards(array $agg): string {
    $c = $agg['counts'];
    $cards = [
        ['All',              $agg['totalLinks'], 'all'],
        ['OK (2xx)',         $c['ok'],       'ok'],
        ['Redirect (3xx)',   $c['redirect'], 'redirect'],
        ['Client (4xx)',     $c['client'],   'client'],
        ['Server (5xx)',     $c['server'],   'server'],
        ['Connection error', $c['conn'],     'conn'],
        ['Empty / placeholder', $c['placeholder'] ?? 0, 'placeholder'],
    ];
    $html = '';
    foreach ($cards as [$label, $n, $slug]) {
        $active = $slug === 'all' ? ' active' : '';
        $pressed = $slug === 'all' ? 'true' : 'false';
        $html .= <<<CARD

      <button type="button" class="card card-link$active" data-filter="$slug"
              aria-pressed="$pressed" title="Show $label links in the table below">
        <div class="card-label">$label</div>
        <div class="card-value"><span class="card-score tc-$slug">$n</span><span class="card-sub">links</span></div>
      </button>
CARD;
    }
    return '<div class="status-filters" role="group" aria-label="Filter tested links by status">'
         . $html . '</div>';
}

/** Page fetch failures are crawl results too, even when no links were extracted. */
function page_errors_table(array $crawl): string {
    if (empty($crawl['pageErrors'])) return '';

    $rows = '';
    foreach ($crawl['pageErrors'] as $url => $error) {
        $urlEsc = htmlspecialchars((string)$url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $errorEsc = htmlspecialchars((string)$error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $rows .= '<tr><td class="url-cell"><a href="' . $urlEsc
               . '" target="_blank" rel="noopener">' . short_url((string)$url) . '</a></td>'
               . '<td class="note">' . $errorEsc . '</td></tr>';
    }

    return <<<HTML

<div class="section-title">⚠ Pages That Failed To Load</div>
<div class="table-wrap">
  <table id="page-errors">
    <thead><tr><th style="text-align:left">Page</th><th style="text-align:left">Error</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

/** Full results table with compact status-summary cards acting as filters. */
function full_table(array $crawl, array $agg): string {
    $rows = '';
    $i = 0;
    foreach ($crawl['results'] as $r) {
        $i++;
        $redir = $r['redirects'] > 0 ? " <span class=\"mini\">↩{$r['redirects']}</span>" : '';
        $note  = $r['error'] !== '' ? htmlspecialchars(truncate_text($r['error'], 100))
                                    : ($r['final'] !== $r['url'] ? '→ ' . short_url($r['final']) : '');
        $scope = $r['internal'] ? 'int' : 'ext';
        $rows .= "<tr data-class=\"{$r['class']}\" data-broken=\"" . (is_broken($r['class']) ? '1' : '0') . "\">"
               . "<td class=\"num\">$i</td>"
               . "<td>" . status_badge($r) . "</td>"
               . "<td><span class=\"cls tc-{$r['class']}\">{$r['label']}</span>$redir</td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['url']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['url']) . "</a></td>"
               . "<td class=\"ttype\">{$r['type']}</td>"
               . "<td class=\"scope\">$scope</td>"
               . "<td class=\"url-cell\">" . sources_cell($r) . "</td>"
               . "<td class=\"note\">$note</td>"
               . "</tr>";
    }
    $filters = status_filter_cards($agg);
    return <<<HTML

<div class="section-title">🔗 All Tested Links</div>
$filters
<div class="table-wrap">
  <table id="all-links">
    <thead><tr><th>#</th><th>Status</th><th style="text-align:left">Class</th>
      <th style="text-align:left">Link</th><th>Type</th><th>Scope</th>
      <th style="text-align:left">Found on page</th><th style="text-align:left">Note</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function build_html(array $crawl, array $agg, array $args, string $generatedAt): string {
    $pageErrors = page_errors_table($crawl);
    $full    = full_table($crawl, $agg);
    $startEsc = htmlspecialchars($args['url']);
    $modeEsc  = $args['mode'] === 'site' ? 'Whole site' : 'Single page';
    $assetsEsc = $args['check-assets'] ? 'a, img, link, script' : 'a only';
    $robotsEsc = $args['respect-robots'] ? 'honoured' : 'ignored';
    $renderEsc = !empty($args['render']) ? 'on (headless Chromium)' : 'off';
    $concEsc   = (int)$args['concurrency'];
    $pages = $crawl['pagesFetched'];
    $failedPages = count($crawl['pageErrors'] ?? []);
    $total = $agg['totalLinks'];

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broken Link Report — $generatedAt</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root { --ink:#0F1E33; --body:#33415C; --muted:#64748B; --soft:#94A3B8;
    --line:#E6EAF1; --line-strong:#C9D4E5; --bg:#fff; --bg-soft:#F5F7FA;
    --accent:#0D8A7E; --accent-tint:#E6F4F2; --accent-line:#BFE3DE;
    --good:#1F9D5B; --warn:#D97A2B; --bad:#D64541; --blue:#2A78D6; --purple:#7C5CE7; }
  *,*::before,*::after { box-sizing:border-box; }
  body { margin:0; padding:0 28px 40px; color:var(--body); background:var(--bg-soft);
    font:16px/1.55 'Source Sans 3',system-ui,Helvetica,Arial,sans-serif; }
  .report-shell { max-width:1400px; margin:0 auto; }
  .brandbar { display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:18px 0 16px;
    margin-bottom:24px; border-bottom:1px solid var(--line); }
  .brandbar .logo { display:grid; place-items:center; width:30px; height:30px; flex:none; border-radius:50%;
    background:conic-gradient(var(--good) 0 76%,var(--line) 76% 100%); }
  .brandbar .logo::before { content:''; width:20px; height:20px; border-radius:50%; background:var(--bg-soft); }
  .brandname { color:var(--ink); font:700 17px 'Poppins',sans-serif; }
  h1 { margin:6px 0 4px; color:var(--ink); font:700 1.6rem 'Poppins',sans-serif; }
  .report-meta { display:grid; grid-template-columns:repeat(9,minmax(0,1fr)); gap:8px; margin:12px 0 22px; }
  .rm-item { min-width:0; padding:9px 10px; background:var(--bg); border:1px solid var(--line); border-radius:10px; }
  .rm-site { grid-column:1/-1; padding:10px 14px; }
  .rm-label { color:var(--soft); font-size:.58rem; font-weight:600; letter-spacing:.065em; text-transform:uppercase; }
  .rm-value { margin-top:2px; overflow-wrap:anywhere; color:var(--ink);
    font:600 .74rem/1.25 'Poppins',sans-serif; }
  .rm-site .rm-value { font-size:.9rem; }
  .rm-value a { color:var(--accent); text-decoration:none; } .rm-value a:hover { text-decoration:underline; }
  .section-title { margin:32px 0 10px; color:var(--muted); font:700 .8rem 'Poppins',sans-serif;
    text-transform:uppercase; letter-spacing:.1em; }
  .status-filters { display:grid; grid-template-columns:repeat(7,minmax(108px,1fr)); gap:8px;
    overflow-x:auto; margin:0 0 10px; padding-bottom:2px; }
  .card { min-width:0; padding:9px 11px; color:inherit; text-align:left; background:var(--bg);
    border:1px solid var(--line); border-radius:10px; font:inherit; }
  .card-link { cursor:pointer; transition:border-color .15s,box-shadow .15s,transform .15s,background .15s; }
  .card-link:hover { border-color:var(--accent-line); box-shadow:0 2px 10px rgba(13,138,126,.12); transform:translateY(-1px); }
  .card-link:focus-visible { outline:2px solid var(--accent); outline-offset:2px; }
  .card-link.active { border-color:var(--accent); background:var(--accent-tint);
    box-shadow:inset 0 0 0 1px var(--accent); }
  .card-label { overflow:hidden; color:var(--muted); text-overflow:ellipsis; white-space:nowrap;
    font-size:.6rem; font-weight:600; letter-spacing:.045em; text-transform:uppercase; }
  .card-value { display:flex; align-items:baseline; gap:5px; margin-top:2px; }
  .card-score { color:var(--ink); font:700 1.45rem/1 'Poppins',sans-serif; }
  .card-sub { color:var(--soft); font-size:.62rem; }
  .table-wrap { overflow-x:auto; margin-top:4px; background:var(--bg); border:1px solid var(--line); border-radius:12px; }
  table { width:100%; border-collapse:collapse; color:var(--body); font-size:.77rem; }
  th,td { padding:9px 10px; text-align:center; border-bottom:1px solid var(--line); }
  th { color:var(--muted); background:var(--bg-soft); font-weight:600; letter-spacing:.05em; text-transform:uppercase; white-space:nowrap; }
  td.url-cell { max-width:360px; overflow:hidden; text-align:left; text-overflow:ellipsis; white-space:nowrap; }
  td.url-cell a { color:var(--accent); text-decoration:none; } td.url-cell a:hover { text-decoration:underline; }
  td.num { width:32px; color:var(--soft); }
  td.ttype { color:var(--muted); font:400 .7rem 'IBM Plex Mono',monospace; }
  td.scope { color:var(--muted); font-size:.7rem; }
  td.note { max-width:280px; overflow:hidden; color:var(--muted); text-align:left; text-overflow:ellipsis; white-space:nowrap; font-size:.7rem; }
  tr:hover td { background:var(--accent-tint); }
  .badge { display:inline-block; min-width:34px; padding:2px 8px; color:#fff; border-radius:12px; font-size:.72rem; font-weight:700; }
  .mini { display:inline-block; margin-left:4px; padding:0 6px; color:var(--body); background:var(--line); border-radius:10px; font-size:.66rem; }
  code { padding:1px 6px; color:var(--ink); background:var(--bg-soft); border:1px solid var(--line);
    border-radius:6px; font:400 .72rem 'IBM Plex Mono',monospace; }
  .legend { margin-top:22px; color:var(--muted); font-size:.72rem; }
  .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:4px; vertical-align:middle; }

  /* "Found on N pages" disclosure — collapses repeated per-page rows into one. */
  details.pages > summary { cursor:pointer; color:var(--accent); white-space:nowrap; }
  details.pages .pagelist { margin-top: 5px; line-height: 1.6; }
  details.pages .pagelist a { color:var(--accent); text-decoration:none; }
  details.pages .pagelist a:hover { text-decoration: underline; }
  details.pages .more { color:var(--muted); font-size:.7rem; }

  /* Status palette — high-contrast shades (≥4.5:1) for the light theme. Applied
     via classes so badges and class labels stay legible on white. */
  .cls { font-weight: 600; }
  .tc-ok { color:var(--good); } .tc-redirect { color:var(--blue); }
  .tc-client { color:var(--warn); } .tc-server { color:var(--bad); }
  .tc-conn { color:var(--purple); } .tc-placeholder { color:var(--bad); }
  .tc-all { color:var(--accent); }
  .tc-broken { color:var(--bad); }
  .badge-ok { background:var(--good); } .badge-redirect { background:var(--blue); }
  .badge-client { background:var(--warn); } .badge-server { background:var(--bad); }
  .badge-conn { background:var(--purple); } .badge-placeholder { background:var(--bad); }
  .badge-error { background:var(--bad); }

  @media (max-width:1100px) {
    .report-meta { grid-template-columns:repeat(3,minmax(0,1fr)); }
  }
  @media (max-width:600px) {
    body { padding:0 14px 32px; }
    .report-meta { grid-template-columns:repeat(2,minmax(0,1fr)); gap:7px; }
    .status-filters { grid-template-columns:repeat(7,112px); }
  }

  @page { size:A4 landscape; }
  /* PDF / print: preserve the complete HTML report while adapting its wide
     table and interactive controls to landscape paper. */
  @media print {
    body { padding:0 6px; background:#fff; }
    .brandbar { margin-bottom:14px; }
    .report-shell { max-width:none; }
    .report-meta .rm-item:not(.rm-site) { display:none; }
    .table-wrap { overflow: visible; }
    .status-filters { overflow:visible; }
    details.pages > summary { list-style: none; }
    .card-link { cursor: default; }
    /* Ignore any interactive screen filter and print the same complete result
       set that the HTML report shows when its default All card is selected. */
    #all-links tbody tr { display: table-row !important; }
    td.url-cell, td.note { max-width: none; white-space: normal;
                           overflow: visible; text-overflow: clip;
                           word-break: break-word; }
    code { white-space: normal; word-break: break-word; }
    thead { display: table-header-group; }
    tr { break-inside: avoid; }
  }
</style>
</head>
<body>
<div class="report-shell">
<header class="brandbar">
  <span class="logo"></span>
  <span class="brandname">Website Health Check</span>
</header>
<h1>Broken Link Bulk Report</h1>
<div class="report-meta">
  <div class="rm-item rm-site"><div class="rm-label">Site</div>
    <div class="rm-value"><a href="$startEsc" target="_blank" rel="noopener">$startEsc</a></div></div>
  <div class="rm-item"><div class="rm-label">Pages crawled</div><div class="rm-value">$pages</div></div>
  <div class="rm-item"><div class="rm-label">Links tested</div><div class="rm-value">$total</div></div>
  <div class="rm-item"><div class="rm-label">Pages failed</div><div class="rm-value">$failedPages</div></div>
  <div class="rm-item"><div class="rm-label">Scan mode</div><div class="rm-value">$modeEsc</div></div>
  <div class="rm-item"><div class="rm-label">Elements checked</div><div class="rm-value">$assetsEsc</div></div>
  <div class="rm-item"><div class="rm-label">robots.txt</div><div class="rm-value">$robotsEsc</div></div>
  <div class="rm-item"><div class="rm-label">JavaScript rendering</div><div class="rm-value">$renderEsc</div></div>
  <div class="rm-item"><div class="rm-label">Concurrency</div><div class="rm-value">$concEsc</div></div>
  <div class="rm-item"><div class="rm-label">Generated</div><div class="rm-value">$generatedAt</div></div>
</div>

$full
$pageErrors

<div class="legend">
  <span class="dot badge-ok"></span> OK &nbsp;
  <span class="dot badge-redirect"></span> Redirect &nbsp;
  <span class="dot badge-client"></span> Client error (4xx) &nbsp;
  <span class="dot badge-server"></span> Server error (5xx) &nbsp;
  <span class="dot badge-conn"></span> Connection error &nbsp;
  <span class="dot badge-placeholder"></span> Empty / placeholder link
</div>
</div>

<script>
  // Client-side filtering of the "All Tested Links" table.
  const rows = Array.from(document.querySelectorAll('#all-links tbody tr'));
  function applyFilter(f) {
    rows.forEach(tr => {
      let show = true;
      if (f === 'broken')      show = tr.dataset.broken === '1';
      else if (f !== 'all')    show = tr.dataset.class === f;
      tr.style.display = show ? '' : 'none';
    });
  }
  function setFilter(f) {
    document.querySelectorAll('.card-link[data-filter]').forEach(card => {
      const active = card.dataset.filter === f;
      card.classList.toggle('active', active);
      card.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
    applyFilter(f);
  }
  document.querySelectorAll('.card-link[data-filter]').forEach(card =>
    card.addEventListener('click', () => setFilter(card.dataset.filter)));

  // Default view: show the complete result set.
  setFilter('all');

  // Expand every "N pages" disclosure when printing (incl. Save as PDF) so the
  // full page list is visible on paper, then collapse again afterwards.
  window.addEventListener('beforeprint', () =>
    document.querySelectorAll('details.pages').forEach(d => d.open = true));
  window.addEventListener('afterprint', () =>
    document.querySelectorAll('details.pages').forEach(d => d.open = false));
</script>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Concise PDF template
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Collapse detailed link/page records into problem types for the PDF. The HTML
 * report deliberately keeps every instance; the PDF is a compact handoff that
 * shows only categories/statuses and their counts.
 */
function pdf_problem_types(array $crawl): array {
    $types = [];
    $add = static function (string $key, string $category, string $detail, string $tone) use (&$types): void {
        if (!isset($types[$key])) {
            $types[$key] = [
                'category' => $category,
                'detail'   => $detail,
                'tone'     => $tone,
                'count'    => 0,
            ];
        }
        $types[$key]['count']++;
    };

    foreach (($crawl['results'] ?? []) as $r) {
        $class = (string)($r['class'] ?? '');
        if ($class === 'ok') continue;

        if ($class === 'redirect') {
            $add('redirect', 'Redirect', 'Followed redirect chain', 'redirect');
        } elseif ($class === 'client') {
            $code = (int)($r['code'] ?? 0);
            $add("client|{$code}", 'Client error', $code > 0 ? "HTTP {$code}" : '4xx response', 'client');
        } elseif ($class === 'server') {
            $code = (int)($r['code'] ?? 0);
            $add("server|{$code}", 'Server error', $code > 0 ? "HTTP {$code}" : '5xx response', 'server');
        } elseif ($class === 'conn') {
            $add('conn', 'Connection error', 'Network, timeout, DNS or TLS failure', 'conn');
        } elseif ($class === 'placeholder') {
            $detail = (string)($r['label'] ?? 'Empty / placeholder link');
            $add('placeholder|' . $detail, 'Empty / placeholder', $detail, 'placeholder');
        }
    }

    foreach (($crawl['pageErrors'] ?? []) as $error) {
        $error = (string)$error;
        $detail = preg_match('/^HTTP\s+\d+/', $error, $m) ? $m[0] : 'Connection or rendering failure';
        $add('page|' . $detail, 'Page failed to load', $detail, 'page');
    }

    $order = ['client' => 1, 'server' => 2, 'conn' => 3, 'page' => 4, 'redirect' => 5, 'placeholder' => 6];
    uasort($types, static function (array $a, array $b) use ($order): int {
        $byTone = ($order[$a['tone']] ?? 99) <=> ($order[$b['tone']] ?? 99);
        return $byTone !== 0 ? $byTone : strnatcasecmp($a['detail'], $b['detail']);
    });
    return array_values($types);
}

/**
 * List each unique error record once, with the number of distinct source pages
 * on which it was found. The crawler has already collapsed repeated instances
 * of the same link into one result and retained its source-page set.
 */
function pdf_unique_errors(array $crawl): array {
    $errors = [];
    foreach (($crawl['results'] ?? []) as $r) {
        $class = (string)($r['class'] ?? '');
        if (!is_broken($class) && $class !== 'placeholder') continue;

        $code = (int)($r['code'] ?? 0);
        if ($class === 'client') {
            $category = 'Client error';
            $status = $code > 0 ? "HTTP {$code}" : '4xx response';
        } elseif ($class === 'server') {
            $category = 'Server error';
            $status = $code > 0 ? "HTTP {$code}" : '5xx response';
        } elseif ($class === 'conn') {
            $category = 'Connection error';
            $status = 'Network, timeout, DNS or TLS failure';
        } else {
            $category = 'Empty / placeholder';
            $status = (string)($r['label'] ?? 'Empty / placeholder link');
        }

        $sourcePages = array_values(array_unique(array_filter(
            array_map('strval', (array)($r['pages'] ?? [])),
            static fn(string $page): bool => $page !== ''
        )));
        $pageCount = $sourcePages ? count($sourcePages) : max(1, (int)($r['pageCount'] ?? 1));
        $errors[] = [
            'category' => $category,
            'status'   => $status,
            'target'   => (string)($r['url'] ?? ''),
            'tone'     => $class,
            'pages'    => $pageCount,
        ];
    }

    foreach (($crawl['pageErrors'] ?? []) as $url => $error) {
        $errors[] = [
            'category' => 'Page failed to load',
            'status'   => (string)$error,
            'target'   => (string)$url,
            'tone'     => 'page',
            'pages'    => 1,
        ];
    }

    usort($errors, static function (array $a, array $b): int {
        $byCategory = strnatcasecmp($a['category'], $b['category']);
        return $byCategory !== 0 ? $byCategory : strnatcasecmp($a['target'], $b['target']);
    });
    return $errors;
}

/** Retain the earlier concise summary builder for possible future PDF options. */
function build_pdf_summary_html(array $crawl, array $agg, array $args, string $generatedAt): string {
    $types = pdf_problem_types($crawl);
    $rows = '';
    foreach ($types as $type) {
        $category = htmlspecialchars($type['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $detail = htmlspecialchars($type['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tone = preg_replace('/[^a-z]/', '', (string)$type['tone']);
        $count = (int)$type['count'];
        $rows .= "<tr><td><span class=\"type-dot t-{$tone}\"></span><strong>{$category}</strong></td>"
               . "<td>{$detail}</td><td class=\"count\">{$count}</td></tr>";
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="3" class="clean">No automated link problems were detected.</td></tr>';
    }

    $errors = pdf_unique_errors($crawl);
    $errorRows = '';
    foreach ($errors as $error) {
        $category = htmlspecialchars($error['category'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $status = htmlspecialchars($error['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $target = htmlspecialchars($error['target'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $tone = preg_replace('/[^a-z]/', '', (string)$error['tone']);
        $pageCount = (int)$error['pages'];
        $errorRows .= "<tr><td><span class=\"type-dot t-{$tone}\"></span><strong>{$category}</strong>"
                    . "<div class=\"error-status\">{$status}</div></td>"
                    . "<td class=\"error-url\">{$target}</td><td class=\"pages\">{$pageCount}</td></tr>";
    }
    if ($errorRows === '') {
        $errorRows = '<tr><td colspan="3" class="clean">No unique errors were detected.</td></tr>';
    }
    $errorCount = count($errors);

    $startEsc = htmlspecialchars((string)$args['url'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $modeEsc = ($args['mode'] ?? 'site') === 'site' ? 'Whole site' : 'Single page';
    $pages = (int)($crawl['pagesFetched'] ?? 0);
    $typeCount = count($types);
    $broken = (int)($agg['broken'] ?? 0);
    $redirects = (int)($agg['counts']['redirect'] ?? 0);
    $placeholders = (int)($agg['placeholders'] ?? 0);
    $pageFailures = (int)($agg['pageFailures'] ?? 0);

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broken Link Summary - $generatedAt</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  :root { --ink:#0F1E33; --body:#33415C; --muted:#64748B; --soft:#94A3B8;
    --line:#E6EAF1; --bg:#fff; --bg-soft:#F5F7FA; --accent:#0D8A7E;
    --good:#1F9D5B; --warn:#D97A2B; --bad:#D64541; --blue:#2A78D6; --purple:#7C5CE7; }
  * { box-sizing:border-box; }
  body { margin:0; padding:0 34px 34px; color:var(--body); background:var(--bg-soft);
    font:15px/1.5 'Source Sans 3',system-ui,sans-serif; }
  .brandbar { display:flex; align-items:center; gap:14px; padding:18px 0 16px;
    margin-bottom:24px; border-bottom:1px solid var(--line); }
  .logo { display:grid; place-items:center; width:30px; height:30px; border-radius:50%;
    background:conic-gradient(var(--good) 0 76%,var(--line) 76% 100%); }
  .logo::before { content:''; width:20px; height:20px; border-radius:50%; background:var(--bg-soft); }
  .brandname { color:var(--ink); font:700 17px 'Poppins',sans-serif; }
  .brandctx { margin-left:auto; color:var(--soft); font-size:13px; }
  h1 { margin:0 0 4px; color:var(--ink); font:700 28px 'Poppins',sans-serif; }
  .intro { max-width:760px; margin:0 0 20px; color:var(--muted); }
  .meta { display:grid; grid-template-columns:2fr repeat(3,1fr); gap:12px; margin-bottom:22px; }
  .meta-item,.stat { padding:12px 16px; background:var(--bg); border:1px solid var(--line); border-radius:12px; }
  .label { color:var(--soft); font-size:10px; font-weight:600; letter-spacing:.08em; text-transform:uppercase; }
  .value { margin-top:3px; overflow-wrap:anywhere; color:var(--ink); font:600 14px 'Poppins',sans-serif; }
  .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:12px; margin:0 0 24px; }
  .stat { text-align:center; }
  .stat .n { color:var(--ink); font:700 27px/1.1 'Poppins',sans-serif; }
  .stat .l { margin-top:4px; color:var(--muted); font-size:11px; }
  .stat.bad .n { color:var(--bad); } .stat.warn .n { color:var(--warn); } .stat.teal .n { color:var(--accent); }
  h2 { margin:0 0 10px; color:var(--muted); font:700 12px 'Poppins',sans-serif;
    letter-spacing:.1em; text-transform:uppercase; }
  .table-wrap { overflow:hidden; background:var(--bg); border:1px solid var(--line); border-radius:12px; }
  table { width:100%; border-collapse:collapse; }
  th,td { padding:11px 14px; border-bottom:1px solid var(--line); text-align:left; }
  th { color:var(--muted); background:var(--bg-soft); font-size:11px; letter-spacing:.06em; text-transform:uppercase; }
  tr:last-child td { border-bottom:0; }
  td.count,th.count { width:90px; text-align:center; font-weight:700; color:var(--ink); }
  td.pages,th.pages { width:110px; text-align:center; font-weight:700; color:var(--ink); }
  .type-dot { display:inline-block; width:9px; height:9px; margin-right:8px; border-radius:50%; }
  .t-client,.t-redirect { background:var(--warn); } .t-server,.t-page { background:var(--bad); }
  .t-conn { background:var(--purple); } .t-placeholder { background:var(--accent); }
  .section-next { margin-top:22px; }
  .error-status { margin:2px 0 0 17px; color:var(--muted); font-size:11px; }
  .error-url { color:var(--ink); overflow-wrap:anywhere; word-break:break-word; }
  .clean { padding:24px; color:var(--good); text-align:center; }
  .footnote { margin-top:14px; color:var(--soft); font-size:11px; }
  @media print { body { background:#fff; padding:0; } .meta-item,.stat,.summary-wrap { break-inside:avoid; }
    thead { display:table-header-group; } tr { break-inside:avoid; } .section-next { break-after:avoid; } }
</style>
</head>
<body>
<header class="brandbar"><span class="logo"></span><span class="brandname">Website Health Check</span>
  <span class="brandctx">Broken link summary · powered by 2create</span></header>
<h1>Broken Link Summary</h1>
<p class="intro">This PDF groups findings by problem type and lists each unique error once. Repeated source-page instances and full page details remain available in the HTML report.</p>
<div class="meta">
  <div class="meta-item"><div class="label">Site</div><div class="value">$startEsc</div></div>
  <div class="meta-item"><div class="label">Scan mode</div><div class="value">$modeEsc</div></div>
  <div class="meta-item"><div class="label">Pages scanned</div><div class="value">$pages</div></div>
  <div class="meta-item"><div class="label">Generated</div><div class="value">$generatedAt</div></div>
</div>
<div class="stats">
  <div class="stat teal"><div class="n">$typeCount</div><div class="l">Problem types</div></div>
  <div class="stat bad"><div class="n">$broken</div><div class="l">Broken links</div></div>
  <div class="stat warn"><div class="n">$redirects</div><div class="l">Redirected links</div></div>
  <div class="stat"><div class="n">$pageFailures</div><div class="l">Pages failed</div></div>
</div>
<h2>Problem types</h2>
<div class="table-wrap summary-wrap"><table>
  <thead><tr><th>Category</th><th>Type / status</th><th class="count">Count</th></tr></thead>
  <tbody>$rows</tbody>
</table></div>
<h2 class="section-next">Unique errors ($errorCount)</h2>
<div class="table-wrap error-wrap"><table>
  <thead><tr><th>Error / status</th><th>Affected link or page</th><th class="pages">Pages</th></tr></thead>
  <tbody>$errorRows</tbody>
</table></div>
<p class="footnote">Placeholder links: $placeholders. Each error is listed once; Pages is the number of distinct source pages where the affected link appears (or 1 for a failed page).</p>
</body>
</html>
HTML;
}

/** Build the PDF from the exact same exhaustive template as the HTML report. */
function build_pdf_html(array $crawl, array $agg, array $args, string $generatedAt): string {
    return build_html($crawl, $agg, $args, $generatedAt);
}

/** Render the full report as an A4 landscape PDF. */
function render_report_pdf(array $crawl, array $agg, array $args,
                           string $pdfPath, string $generatedAt): bool {
    $tmpBase = tempnam(sys_get_temp_dir(), 'blbs_pdf_');
    if ($tmpBase === false) {
        progress("  ⚠ Could not create the temporary PDF report — skipped.\n");
        return false;
    }
    // Chromium determines how to load file:// content from the extension. Keep
    // the unique temp name, but give the report an HTML suffix so it is
    // rendered as a document rather than printed as plain source code.
    $tmp = $tmpBase . '.html';
    if (!@rename($tmpBase, $tmp)) {
        @unlink($tmpBase);
        progress("  ⚠ Could not prepare the temporary PDF report — skipped.\n");
        return false;
    }
    try {
        if (file_put_contents($tmp, build_pdf_html($crawl, $agg, $args, $generatedAt)) === false) {
            progress("  ⚠ Could not write the temporary PDF report — skipped.\n");
            return false;
        }
        return render_pdf($tmp, $pdfPath, $args);
    } finally {
        @unlink($tmp);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSV export
// ─────────────────────────────────────────────────────────────────────────────

function build_csv(array $crawl): string {
    // source_page comes right after link_url so every row — especially "#"
    // placeholders, whose link_url/final_url are both just "#" — immediately
    // shows where the link was found instead of burying it in a late column.
    // found_on_count + all_source_pages carry the full grouping so the exhaustive
    // page list (capped in the HTML/PDF) is never lost in the data export.
    $fields = ['link_url', 'source_page', 'found_on_count', 'all_source_pages',
               'status_code', 'classification', 'label', 'final_url',
               'method', 'redirects', 'link_type', 'scope', 'error', 'record_type'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $fields, ',', '"', '');
    foreach ($crawl['results'] as $r) {
        $pages = !empty($r['pages']) ? $r['pages'] : [$r['source']];
        fputcsv($fh, [
            $r['url'],
            $r['source'],
            count($pages),
            implode(' ', $pages),
            $r['code'] > 0 ? $r['code'] : '0',
            $r['class'],
            $r['label'],
            $r['final'],
            $r['method'],
            $r['redirects'],
            $r['type'],
            $r['internal'] ? 'internal' : 'external',
            $r['error'],
            'link',
        ], ',', '"', '');
    }
    foreach (($crawl['pageErrors'] ?? []) as $url => $error) {
        $status = preg_match('/^HTTP\s+(\d+)/', (string)$error, $m) ? $m[1] : '0';
        fputcsv($fh, [
            $url, $url, 1, $url, $status, 'page_error', 'Page failed to load',
            $url, 'GET', 0, 'page', 'internal', $error, 'page_error',
        ], ',', '"', '');
    }
    rewind($fh);
    $csv = stream_get_contents($fh);
    fclose($fh);
    return $csv;
}

// ─────────────────────────────────────────────────────────────────────────────
//  Console summary
// ─────────────────────────────────────────────────────────────────────────────

function print_summary(array $crawl, array $agg): void {
    $c = $agg['counts'];
    echo "\n─── Broken Link Summary ─────────────────────────────────\n";
    printf("  Pages crawled : %d\n", $crawl['pagesFetched']);
    printf("  Links tested  : %d  (%d internal, %d external)\n",
        $agg['totalLinks'], $agg['internal'], $agg['external']);
    printf("  Results       : ✓ %d OK   → %d redirect   ✗ %d 4xx   ✗ %d 5xx   ⚠ %d conn\n",
        $c['ok'], $c['redirect'], $c['client'], $c['server'], $c['conn']);
    printf("  Broken total  : %d\n", $agg['broken']);
    printf("  Placeholders  : %d  (empty / # / javascript: links)\n", $agg['placeholders']);
    printf("  Page failures : %d\n", $agg['pageFailures']);

    if ($agg['broken'] > 0) {
        echo "\n  Broken links:\n";
        $shown = 0;
        foreach ($crawl['results'] as $r) {
            if (!is_broken($r['class'])) continue;
            $code = $r['code'] > 0 ? $r['code'] : 'ERR';
            printf("    [%s] %s  (on %s)\n", $code, $r['url'], $r['source']);
            if (++$shown >= 25) { echo "    … and more (see report).\n"; break; }
        }
    }
    echo "─────────────────────────────────────────────────────────\n\n";
}

// ─────────────────────────────────────────────────────────────────────────────
//  Main
// ─────────────────────────────────────────────────────────────────────────────

function main(array $argv): void {
    if (!extension_loaded('curl') || !extension_loaded('dom')) {
        fwrite(STDERR, "❌  This script needs the PHP curl and dom extensions.\n");
        exit(1);
    }

    $args = parse_args($argv);

    // 1 — Crawl + test
    $crawl = crawl($args);
    if (!$crawl['results'] && !$crawl['pageErrors']) {
        fwrite(STDERR, "❌  No links were found. Check that the start URL is reachable.\n");
        exit(1);
    }

    // 2 — Aggregate
    $agg = aggregate($crawl);

    // 3 — HTML report
    $generatedAt = date('Y-m-d H:i');
    file_put_contents($args['output'], build_html($crawl, $agg, $args, $generatedAt));
    echo "✅  HTML report → {$args['output']}\n";

    // 4 — CSV export
    file_put_contents($args['csv'], build_csv($crawl));
    echo "✅  CSV export  → {$args['csv']}\n";

    // 5 — Optional PDF export
    if (trim((string)$args['pdf']) !== '') {
        if (render_report_pdf($crawl, $agg, $args, $args['pdf'], $generatedAt)) {
            echo "✅  PDF export  → {$args['pdf']}\n";
        }
    }

    // 6 — Console summary
    print_summary($crawl, $agg);
}

// Auto-run only when this file is the CLI entry point. This keeps the engine
// safely reusable from the web UI and from the regression test harness.
if (PHP_SAPI === 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    main($argv);
}
