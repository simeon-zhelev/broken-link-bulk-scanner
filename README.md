# Broken Link Bulk Scanner

Crawl an entire website and surface every dead link — all in a self-contained dark-themed HTML dashboard + CSV export.

Use it either way:

- **Browser UI** — `php -S localhost:8090`, open the page, type a URL, watch a live progress log, then read the report inline. ([jump to setup](#quick-start--web-ui))
- **Command line** — `php link_checker.php --url=…` for scripting and cron. ([jump to setup](#quick-start--cli))

Works with any site (WordPress, Shopify, static, …) — no API key, no account required. Everything runs locally via plain PHP + cURL.

## How it works

`link_checker.php` crawls breadth-first one depth level at a time, fetching all pages at a level **in parallel** (up to `--concurrency`) and then testing every newly-discovered link in parallel via PHP's `curl_multi` API. It extracts links with DOMDocument + DOMXPath, normalises them to absolute URLs, tests each unique link (HEAD then GET fallback), follows redirects, classifies the result and produces:

With `--render`, each page is additionally loaded in **headless Chrome** (`--dump-dom`) so JavaScript runs and links built on the client are extracted from the live DOM — cURL still owns the HTTP status of each page and tests every discovered link.

- a self-contained dark-themed HTML dashboard (`link_report.html`)
- a CSV export (`link_report.csv`)
- a live console progress log + final summary

`index.php` is a thin web wrapper over the same engine — it reuses the crawler and report builders directly (no duplicated logic), streams the progress log live to the browser, and embeds the finished report on the page.

## Quick start — Web UI

Prefer a browser? Start PHP's built-in server in the project directory and open the page:

```bash
php -S localhost:8090
# then open http://localhost:8090
```

Enter a URL, pick a few options (scan mode, max pages/depth, concurrency, asset/robots/TLS toggles, and an optional **Render JavaScript** toggle for SPAs), and hit **Scan**. You'll see a **live progress log** while it crawls, then the full report embedded on the page with **Open report** and **Download CSV** buttons. Reports are written to a local `reports/` folder (git-ignored).

> Run the UI **locally only** — it fetches whatever URL you type, so don't expose it on a public host without adding your own authentication.

## Quick start — CLI

```bash
# Whole-site scan (up to 100 pages, depth 3)
php link_checker.php --url=https://example.com

# Larger site, more requests in parallel
php link_checker.php --url=https://example.com --max-pages=500 --max-depth=5 --concurrency=20

# Just the links on one page, no asset checks
php link_checker.php --url=https://example.com/page --mode=page --no-assets

# JavaScript-rendered site (SPA): run the page in headless Chrome first
php link_checker.php --url=https://example.com --render --render-wait=6000
```

## Requirements

- **PHP 8.0+** with the `curl` and `dom` extensions (standard on macOS, most Linux distros)
- *(optional)* **Chrome / Chromium / Edge / Brave** — only needed for `--render` (JavaScript rendering)

No Node.js, no Composer, no external services.

## Options

| Option | Default | Description |
|---|---|---|
| `--url` | *(required)* | Starting page to crawl |
| `--mode` | `site` | `site` = crawl entire site following internal links; `page` = test only links on the start page |
| `--max-pages` | `100` | Hard cap on pages fetched in site mode |
| `--max-depth` | `3` | Max crawl depth from the start page |
| `--concurrency` | `10` | Parallel requests in flight at once |
| `--delay` | `200` | Delay between batches, in milliseconds |
| `--no-assets` | off | Test `<a href>` only (default also tests `<img src>`, `<link href>`, `<script src>`) |
| `--connect-timeout` | `10` | cURL connect timeout, in seconds |
| `--timeout` | `20` | cURL total timeout, in seconds |
| `--max-redirs` | `10` | Max redirects to follow per link |
| `--ignore-robots` | off | Do NOT honour robots.txt (default: honour it) |
| `--render` | off | Render each page in **headless Chrome** so JavaScript-built markup/links are seen (SPAs). Needs Chrome/Chromium/Edge/Brave installed |
| `--render-wait` | `4000` | JS settle time per page in render mode, in milliseconds |
| `--render-concurrency` | `3` | Parallel headless-Chrome processes in render mode |
| `--chrome-bin` | *(auto)* | Explicit browser binary path (else auto-detected, or `$CHROME_BIN`) |
| `--insecure` | off | Skip TLS certificate verification |
| `--user-agent` | *(built-in)* | Override the crawler User-Agent string |
| `--output` | `link_report.html` | HTML report path |
| `--csv` | `link_report.csv` | CSV export path |

## Report contents

1. **Links by Status** — counts of OK / Redirect / 4xx / 5xx / Connection error / Empty anchors, internal vs external totals.
2. **Broken Links** — headline table with status badge, class, broken URL, link type, and the page it was found on.
3. **Placeholder Anchors (`href="#"`)** — `<a href="#">` links that point nowhere (dead/unfinished links), with their anchor text and source page. Real in-page anchors like `#section` are **not** flagged.
4. **Pages That Failed To Load** — internal pages the crawler itself could not fetch.
5. **By Status Code** — code-level breakdown table.
6. **All Tested Links** — full filterable table (All / Broken only / OK / Redirect / 4xx / 5xx / Connection / Anchors).

The CSV mirrors the full link data for spreadsheets / BI tools.

## Scheduling regular scans

Cron example — every Monday at 07:00:

```cron
0 7 * * 1 php /path/to/link_checker.php --url=https://example.com --output=/path/to/reports/links-$(date +\%F).html --csv=/path/to/reports/links-$(date +\%F).csv
```

## Troubleshooting

**`Call to undefined function curl_init()` / DOMDocument errors** — install the missing PHP extensions, e.g. `sudo apt install php-curl php-xml` on Debian/Ubuntu. macOS's built-in PHP includes both.

**All links return connection errors** — verify the start URL is reachable (`curl -I <url>`). If the site uses a WAF or bot filter, try raising `--timeout` or passing a realistic `--user-agent`.

**0 links found** — the start page may not be HTML (check `Content-Type`), or it may build its links with JavaScript. For JS-rendered sites add `--render` (CLI) or tick **Render JavaScript** (web UI) to load each page in headless Chrome before extracting links.

## Project structure

```
sitelinks-bulk-scanner/
├── link_checker.php   # crawler + report generator (run this from the CLI)
├── index.php          # web UI — `php -S localhost:8090`, then open in a browser
├── examples/          # sample HTML + CSV report (demo data, no real site)
├── README.md
└── LICENSE            # MIT
```

## License

MIT — see [LICENSE](LICENSE).
