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
    return $scheme . '://' . $host . $port . $path . $query;
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

/** Fetch and parse robots.txt for the start URL's host; returns the '*' group. */
function load_robots(string $startUrl, array $args): array {
    $p = parse_url($startUrl);
    $base = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $resp = http_request($base . '/robots.txt', 'GET', $args);
    if ($resp['errno'] !== 0 || $resp['code'] >= 400 || $resp['body'] === '') {
        return [];   // no usable robots.txt → nothing disallowed
    }
    $groups = parse_robots($resp['body']);
    return $groups['*'] ?? [];
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
    curl_close($ch);
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
            curl_close($ch);
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
        echo "  ⚠ could not launch the render runner — using static HTML instead.\n";
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
                // Pass the runner's live progress through. STDERR only exists in
                // the CLI SAPI; under the web UI, echo it into the progress log.
                if (defined('STDERR')) fwrite(STDERR, $chunk);
                else echo $chunk;
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
    echo "  🧭 fetching " . count($urls) . " page(s) with headless Chromium…\n";
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
        echo "  ↩ " . count($needCurl) . " page(s) the browser couldn't fetch — falling back to cURL\n";
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
        echo "  ⚠ PDF export needs the render engine — skipped.\n"
           . "     " . str_replace("\n", "\n     ", $problem) . "\n";
        return false;
    }
    if (!is_file($htmlPath)) {
        echo "  ⚠ PDF export skipped — HTML report not found at {$htmlPath}.\n";
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
        echo "  ⚠ could not launch the render runner for PDF export — skipped.\n";
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
        echo "  ⚠ PDF export failed{$msg} — skipped.\n";
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

            // Skip <link> connection/resource hints. Their href is a hostname or
            // asset to warm up, not a navigable document: preconnect/dns-prefetch
            // point at a bare CDN origin (fonts.googleapis.com/) that legitimately
            // 404s, and preload/prefetch/modulepreload duplicate links fetched
            // elsewhere. Testing them as links produces only false positives.
            if ($type === 'link') {
                $rel = strtolower(trim($node->getAttribute('rel')));
                if ($rel !== '' && array_intersect(
                        preg_split('/\s+/', $rel),
                        ['preconnect', 'dns-prefetch', 'prefetch', 'preload', 'modulepreload']
                    )) {
                    continue;
                }
            }

            // Empty / placeholder anchors (href="", "#", "javascript:…") point
            // nowhere — flag them as dead/unfinished links instead of silently
            // dropping them. Real targets (#section, relative URLs) fall through
            // to normalizeUrl below and are tested over HTTP as usual.
            if ($type === 'a' && ($reason = placeholder_reason($raw)) !== null) {
                $text = trim(preg_replace('/\s+/', ' ', $node->textContent ?? ''));
                if (mb_strlen($text) > 80) $text = mb_substr($text, 0, 80) . '…';

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
                if (mb_strlen($element) > 200) $element = mb_substr($element, 0, 200) . '…';

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
function classify(int $code, int $errno): array {
    if ($errno !== 0 || $code === 0)        return ['conn',     'Connection error'];
    if ($code >= 200 && $code < 300)        return ['ok',       'OK'];
    if ($code >= 300 && $code < 400)        return ['redirect', 'Redirect'];
    if ($code >= 400 && $code < 500)        return ['client',   'Client error'];
    if ($code >= 500)                       return ['server',   'Server error'];
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
        echo $robots ? "🤖 robots.txt loaded — disallowed paths will be skipped.\n"
                     : "🤖 No robots.txt restrictions found.\n";
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
            echo "🧭 JS rendering ON — {$engine}\n";
        } else {
            echo "⚠  --render requested but unavailable — using static HTML instead.\n"
               . "     " . str_replace("\n", "\n     ", $problem) . "\n";
        }
    }

    $level   = [$start];           // page URLs to fetch at the current depth
    $queued  = [$start => true];   // every URL ever enqueued (avoid dupes)
    $visited = [];                 // pages actually fetched
    $tested  = [];                 // url => result row (each link tested once)
    $pageErrors = [];              // start/internal pages that failed to load
    $pagesFetched = 0;
    $depth = 0;

    echo "\n🔗 Crawling {$start}  (mode: {$args['mode']}, max-pages: {$args['max-pages']}, "
       . "depth: {$args['max-depth']}, concurrency: {$args['concurrency']})\n";

    while ($level && $pagesFetched < $args['max-pages']) {
        // Decide which pages in this level to actually fetch (skip visited /
        // robots-disallowed, and stop at the max-pages cap).
        $fetchJobs = [];
        foreach ($level as $pageUrl) {
            if (isset($visited[$pageUrl])) continue;
            if ($args['respect-robots'] && !robots_allowed(path_with_query($pageUrl), $robots)) {
                echo "  ⤫ robots disallow — skipping: {$pageUrl}\n";
                continue;
            }
            if ($pagesFetched + count($fetchJobs) >= $args['max-pages']) {
                echo "⚠  Reached max-pages cap ({$args['max-pages']}). Stopping crawl.\n";
                break;
            }
            $visited[$pageUrl] = true;
            $fetchJobs[] = ['key' => $pageUrl, 'url' => $pageUrl, 'method' => 'GET'];
        }
        if (!$fetchJobs) break;

        echo "\n── depth {$depth} — fetching " . count($fetchJobs) . " page(s) in parallel\n";
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
                echo "  ⚠ could not load page ({$label}) — {$pageUrl}\n";
                continue;
            }
            if (stripos($resp['ctype'], 'html') === false) {
                echo "  · non-HTML page ({$resp['ctype']}) — not parsed — {$pageUrl}\n";
                continue;
            }

            $links = extractLinks($resp['body'], $resp['final'] ?: $pageUrl, $args['check-assets']);
            $tag = !empty($resp['rendered']) ? ' [rendered]' : '';
            echo "  • {$pageUrl} — " . count($links) . " link(s){$tag}\n";

            foreach ($links as $lnk) {
                // Empty/placeholder anchors point nowhere — record them
                // directly (no HTTP test), keyed per page + reason + anchor
                // text so distinct dead links on the same page are each shown.
                if (!empty($lnk['placeholder'])) {
                    $key = 'placeholder|' . $pageUrl . '|' . $lnk['reasonKey'] . '|' . $lnk['text'];
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
                            'error'     => $lnk['text'] !== '' ? 'text: "' . $lnk['text'] . '"' : '(no link text)',
                            'internal'  => true,
                        ];
                    }
                    continue;
                }

                $u = $lnk['url'];
                if (!isset($tested[$u]) && !isset($newLinks[$u])) {
                    $newLinks[$u] = ['type' => $lnk['type'], 'source' => $pageUrl];
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
            echo "  ↻ testing " . count($newLinks) . " new link(s) in parallel…\n";
            polite_delay($args);
            $checked = checkLinks(array_keys($newLinks), $args);
            $broken = 0;
            foreach ($newLinks as $u => $meta) {
                $chk = $checked[$u];
                [$cls, $label] = classify($chk['code'], $chk['errno']);
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
                    'source'    => $meta['source'],
                    'error'     => $chk['error'],
                    'internal'  => sameHost($u, $startHost),
                ];
            }
            echo "    done — {$broken} broken in this batch\n";
        }

        $level = $nextLevel;
        $depth++;
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
    $c = class_color($r['class']);
    $code = $r['code'] > 0 ? (string)$r['code'] : 'ERR';
    return "<span class=\"badge\" style=\"background:$c\">$code</span>";
}

function short_url(string $url): string {
    return htmlspecialchars(preg_replace('#^https?://#', '', $url));
}

function summary_cards(array $agg): string {
    $c = $agg['counts'];
    $cards = [
        ['OK (2xx)',         $c['ok'],       class_color('ok')],
        ['Redirect (3xx)',   $c['redirect'], class_color('redirect')],
        ['Client (4xx)',     $c['client'],   class_color('client')],
        ['Server (5xx)',     $c['server'],   class_color('server')],
        ['Connection error', $c['conn'],     class_color('conn')],
        ['Empty / placeholder', $c['placeholder'] ?? 0, class_color('placeholder')],
    ];
    $html = '';
    foreach ($cards as [$label, $n, $color]) {
        $html .= <<<CARD

      <div class="card">
        <div class="card-label">$label</div>
        <div class="card-score" style="color:$color">$n</div>
        <div class="card-sub">links</div>
      </div>
CARD;
    }
    $broken = $agg['broken'];
    $brokenColor = $broken === 0 ? '#22c55e' : '#f87171';
    $total = $agg['totalLinks'];
    return <<<HTML
<div class="section-title">🔗 Links by Status</div>
<div class="cards">$html</div>
<div class="stats">
  <span><strong style="color:$brokenColor">$broken</strong> / $total broken links</span>
  <span><strong>{$agg['internal']}</strong> internal</span>
  <span><strong>{$agg['external']}</strong> external</span>
</div>
HTML;
}

/** The headline section: only broken links (4xx / 5xx / connection). */
function broken_table(array $crawl): string {
    $rows = '';
    $i = 0;
    foreach ($crawl['results'] as $r) {
        if (!is_broken($r['class'])) continue;
        $i++;
        $color = class_color($r['class']);
        $extra = $r['error'] !== '' ? htmlspecialchars(mb_substr($r['error'], 0, 120))
                                    : ($r['final'] !== $r['url'] ? 'final: ' . short_url($r['final']) : '');
        $rows .= "<tr>"
               . "<td class=\"num\">$i</td>"
               . "<td>" . status_badge($r) . "</td>"
               . "<td><span style=\"color:$color;font-weight:600\">{$r['label']}</span></td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['url']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['url']) . "</a></td>"
               . "<td class=\"ttype\">{$r['type']}</td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['source']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['source']) . "</a></td>"
               . "<td class=\"note\">" . $extra . "</td>"
               . "</tr>";
    }
    if ($rows === '') {
        return '<div class="section-title">🚦 Broken Links</div>'
             . '<p style="color:#22c55e;font-size:0.9rem">No broken links found. Every tested link returned a 2xx or a successful redirect.</p>';
    }
    return <<<HTML

<div class="section-title">🚦 Broken Links</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead><tr><th>#</th><th>Status</th><th style="text-align:left">Class</th>
      <th style="text-align:left">Broken link</th><th>Type</th>
      <th style="text-align:left">Found on page</th><th style="text-align:left">Note</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

/** Empty / placeholder links: <a href=""> / "#" / "javascript:…" that go nowhere. */
function placeholder_table(array $crawl): string {
    $rows = '';
    $i = 0;
    foreach ($crawl['results'] as $r) {
        if ($r['class'] !== 'placeholder') continue;
        $i++;
        $color = class_color('placeholder');
        // Prefer the full element markup (<a href="#" id="tab1">…</a>) for
        // context; fall back to the bare href for older reports without it.
        $href  = !empty($r['element'])
            ? '<code>' . htmlspecialchars($r['element']) . '</code>'
            : '<code>href="' . htmlspecialchars($r['url'] === '(empty href)' ? '' : $r['url']) . '"</code>';
        $text  = $r['error'] !== '' ? htmlspecialchars($r['error']) : '—';
        $rows .= "<tr>"
               . "<td class=\"num\">$i</td>"
               . "<td class=\"ttype\">$href</td>"
               . "<td><span style=\"color:$color;font-weight:600\">" . htmlspecialchars($r['label']) . "</span></td>"
               . "<td class=\"note\" style=\"max-width:320px\">$text</td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['source']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['source']) . "</a></td>"
               . "</tr>";
    }
    if ($rows === '') return '';
    return <<<HTML

<div class="section-title">🔗 Empty / Placeholder Links</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead><tr><th>#</th><th style="text-align:left">Element</th><th style="text-align:left">Reason</th>
      <th style="text-align:left">Link text</th><th style="text-align:left">Found on page</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

/** Status-code breakdown table. */
function code_breakdown(array $agg): string {
    if (!$agg['byCode']) return '';
    $rows = '';
    foreach ($agg['byCode'] as $code => $n) {
        $isErr = $code === 'ERR';
        $num = $isErr ? 0 : (int)$code;
        [$cls] = $isErr ? ['conn'] : classify($num, 0);
        $color = class_color($cls);
        $rows .= "<tr><td><span class=\"badge\" style=\"background:$color\">$code</span></td>"
               . "<td style=\"text-align:left;color:$color;font-weight:600\">" . ($isErr ? 'Connection error' : classify($num,0)[1]) . "</td>"
               . "<td>$n</td></tr>";
    }
    return <<<HTML

<div class="section-title">📊 By Status Code</div>
<div class="table-wrap" style="margin-top:10px;max-width:420px">
  <table>
    <thead><tr><th>Code</th><th style="text-align:left">Meaning</th><th>Links</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

/** Full results table with client-side filter buttons. */
function full_table(array $crawl): string {
    $rows = '';
    $i = 0;
    foreach ($crawl['results'] as $r) {
        $i++;
        $color = class_color($r['class']);
        $redir = $r['redirects'] > 0 ? " <span class=\"mini\">↩{$r['redirects']}</span>" : '';
        $note  = $r['error'] !== '' ? htmlspecialchars(mb_substr($r['error'], 0, 100))
                                    : ($r['final'] !== $r['url'] ? '→ ' . short_url($r['final']) : '');
        $scope = $r['internal'] ? 'int' : 'ext';
        $rows .= "<tr data-class=\"{$r['class']}\" data-broken=\"" . (is_broken($r['class']) ? '1' : '0') . "\">"
               . "<td class=\"num\">$i</td>"
               . "<td>" . status_badge($r) . "</td>"
               . "<td><span style=\"color:$color;font-weight:600\">{$r['label']}</span>$redir</td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['url']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['url']) . "</a></td>"
               . "<td class=\"ttype\">{$r['type']}</td>"
               . "<td class=\"scope\">$scope</td>"
               . "<td class=\"url-cell\"><a href=\"" . htmlspecialchars($r['source']) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($r['source']) . "</a></td>"
               . "<td class=\"note\">$note</td>"
               . "</tr>";
    }
    return <<<HTML

<div class="section-title">📋 All Tested Links</div>
<div class="filters">
  <button class="fbtn active" data-filter="all">All</button>
  <button class="fbtn" data-filter="broken">Broken only</button>
  <button class="fbtn" data-filter="ok">OK</button>
  <button class="fbtn" data-filter="redirect">Redirect</button>
  <button class="fbtn" data-filter="client">4xx</button>
  <button class="fbtn" data-filter="server">5xx</button>
  <button class="fbtn" data-filter="conn">Connection</button>
  <button class="fbtn" data-filter="placeholder">Empty / placeholder</button>
</div>
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

/** Pages that themselves failed to load during the crawl. */
function page_errors_table(array $crawl): string {
    if (!$crawl['pageErrors']) return '';
    $rows = '';
    foreach ($crawl['pageErrors'] as $url => $label) {
        $rows .= "<tr><td class=\"url-cell\"><a href=\"" . htmlspecialchars($url) . "\" target=\"_blank\" rel=\"noopener\">" . short_url($url) . "</a></td>"
               . "<td style=\"text-align:left;color:#fca5a5\">" . htmlspecialchars($label) . "</td></tr>";
    }
    return <<<HTML

<div class="section-title">⚠ Pages That Failed To Load</div>
<div class="table-wrap" style="margin-top:10px">
  <table>
    <thead><tr><th style="text-align:left">Page URL</th><th style="text-align:left">Problem</th></tr></thead>
    <tbody>$rows</tbody>
  </table>
</div>
HTML;
}

function build_html(array $crawl, array $agg, array $args, string $generatedAt): string {
    $cards   = summary_cards($agg);
    $broken  = broken_table($crawl);
    $placeholders = placeholder_table($crawl);
    $codes   = code_breakdown($agg);
    $full    = full_table($crawl);
    $perrs   = page_errors_table($crawl);
    $startEsc = htmlspecialchars($args['url']);
    $modeEsc  = $args['mode'] === 'site' ? 'Whole site' : 'Single page';
    $assetsEsc = $args['check-assets'] ? 'a, img, link, script' : 'a only';
    $robotsEsc = $args['respect-robots'] ? 'honoured' : 'ignored';
    $renderEsc = !empty($args['render']) ? 'on (headless Chromium)' : 'off';
    $concEsc   = (int)$args['concurrency'];
    $pages = $crawl['pagesFetched'];
    $total = $agg['totalLinks'];

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broken Link Report — $generatedAt</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body  { font-family: system-ui, -apple-system, sans-serif;
          background: #0f172a; color: #e2e8f0; margin: 0; padding: 24px 28px; }
  h1    { font-size: 1.6rem; margin-bottom: 4px; color: #f8fafc; }
  .meta { font-size: 0.8rem; color: #64748b; margin-bottom: 22px; line-height: 1.6; }
  .section-title { font-size: 0.8rem; font-weight: 700; color: #64748b;
                   text-transform: uppercase; letter-spacing: .1em; margin: 32px 0 10px; }
  .cards { display: flex; flex-wrap: wrap; gap: 12px; }
  .card  { background: #1e293b; border-radius: 10px; padding: 16px 22px; min-width: 148px; flex: 1; }
  .card-label { font-size: 0.72rem; color: #94a3b8; text-transform: uppercase; letter-spacing: .06em; }
  .card-score { font-size: 2.4rem; font-weight: 700; line-height: 1.1; margin: 4px 0; }
  .card-sub   { font-size: 0.7rem; color: #64748b; }
  .stats { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 12px;
           font-size: 0.85rem; color: #94a3b8; }
  .table-wrap { overflow-x: auto; border-radius: 10px; background: #1e293b; margin-top: 4px; }
  table  { width: 100%; border-collapse: collapse; font-size: 0.77rem; }
  th, td { padding: 8px 10px; text-align: center; border-bottom: 1px solid #334155; }
  th     { background: #0f172a; color: #94a3b8; font-weight: 600;
           text-transform: uppercase; letter-spacing: .05em; white-space: nowrap; }
  td.url-cell { text-align: left; max-width: 360px; overflow: hidden;
                text-overflow: ellipsis; white-space: nowrap; }
  td.url-cell a { color: #93c5fd; text-decoration: none; }
  td.url-cell a:hover { text-decoration: underline; }
  td.num   { color: #475569; width: 32px; }
  td.ttype { color: #94a3b8; font-family: ui-monospace, monospace; font-size: 0.7rem; }
  td.scope { color: #94a3b8; font-size: 0.7rem; }
  td.note  { text-align: left; color: #64748b; font-size: 0.7rem;
             max-width: 280px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  tr:hover td { background: #263045; }
  .badge { display: inline-block; min-width: 34px; padding: 2px 8px; border-radius: 12px;
           color: #fff; font-weight: 700; font-size: 0.72rem; }
  .mini  { display: inline-block; padding: 0 6px; border-radius: 10px; background: #334155;
           color: #cbd5e1; font-size: 0.66rem; margin-left: 4px; }
  .filters { display: flex; flex-wrap: wrap; gap: 6px; margin: 4px 0 10px; }
  .fbtn  { background: #1e293b; color: #94a3b8; border: 1px solid #334155;
           border-radius: 8px; padding: 5px 12px; font-size: 0.74rem; cursor: pointer; }
  .fbtn:hover  { background: #263045; }
  .fbtn.active { background: #2563eb; color: #fff; border-color: #2563eb; }
  code   { font-family: ui-monospace, monospace; font-size: 0.72rem;
           background: #0f172a; color: #cbd5e1; padding: 1px 6px; border-radius: 6px; }
  .legend { margin-top: 22px; font-size: 0.72rem; color: #64748b; }
  .dot { display:inline-block; width:9px; height:9px; border-radius:50%; margin-right:4px; vertical-align:middle; }
</style>
</head>
<body>
<h1>🔗 Broken Link Bulk Report</h1>
<div class="meta">
  Start URL: <strong>$startEsc</strong> &nbsp;|&nbsp;
  Mode: <strong>$modeEsc</strong> &nbsp;|&nbsp;
  Pages crawled: <strong>$pages</strong> &nbsp;|&nbsp;
  Links tested: <strong>$total</strong><br>
  Checked elements: <strong>$assetsEsc</strong> &nbsp;|&nbsp;
  robots.txt: <strong>$robotsEsc</strong> &nbsp;|&nbsp;
  JS rendering: <strong>$renderEsc</strong> &nbsp;|&nbsp;
  Concurrency: <strong>$concEsc</strong> &nbsp;|&nbsp;
  Generated: <strong>$generatedAt</strong>
</div>

$cards
$broken
$placeholders
$perrs
$codes
$full

<div class="legend">
  <span class="dot" style="background:#22c55e"></span> OK &nbsp;
  <span class="dot" style="background:#3b82f6"></span> Redirect &nbsp;
  <span class="dot" style="background:#f59e0b"></span> Client error (4xx) &nbsp;
  <span class="dot" style="background:#ef4444"></span> Server error (5xx) &nbsp;
  <span class="dot" style="background:#a855f7"></span> Connection error &nbsp;
  <span class="dot" style="background:#2dd4bf"></span> Empty / placeholder link
</div>

<script>
  // Client-side filtering of the "All Tested Links" table.
  const rows = Array.from(document.querySelectorAll('#all-links tbody tr'));
  document.querySelectorAll('.fbtn').forEach(btn => {
    btn.addEventListener('click', () => {
      document.querySelectorAll('.fbtn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const f = btn.dataset.filter;
      rows.forEach(tr => {
        let show = true;
        if (f === 'broken')      show = tr.dataset.broken === '1';
        else if (f !== 'all')    show = tr.dataset.class === f;
        tr.style.display = show ? '' : 'none';
      });
    });
  });
</script>
</body>
</html>
HTML;
}

// ─────────────────────────────────────────────────────────────────────────────
//  CSV export
// ─────────────────────────────────────────────────────────────────────────────

function build_csv(array $crawl): string {
    // source_page comes right after link_url so every row — especially "#"
    // placeholders, whose link_url/final_url are both just "#" — immediately
    // shows where the link was found instead of burying it in a late column.
    $fields = ['link_url', 'source_page', 'status_code', 'classification', 'label', 'final_url',
               'method', 'redirects', 'link_type', 'scope', 'error'];
    $fh = fopen('php://temp', 'r+');
    fputcsv($fh, $fields);
    foreach ($crawl['results'] as $r) {
        fputcsv($fh, [
            $r['url'],
            $r['source'],
            $r['code'] > 0 ? $r['code'] : '0',
            $r['class'],
            $r['label'],
            $r['final'],
            $r['method'],
            $r['redirects'],
            $r['type'],
            $r['internal'] ? 'internal' : 'external',
            $r['error'],
        ]);
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
        if (render_pdf($args['output'], $args['pdf'], $args)) {
            echo "✅  PDF export  → {$args['pdf']}\n";
        }
    }

    // 6 — Console summary
    print_summary($crawl, $agg);
}

// Only auto-run from the command line. When this file is included from the
// web UI (index.php) the functions above are reused without running main().
if (PHP_SAPI === 'cli') {
    main($argv);
}
