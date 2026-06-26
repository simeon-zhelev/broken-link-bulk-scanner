#!/usr/bin/env node
/**
 * render-runner.js — headless rendering engine for the Broken Link Bulk Scanner
 * ---------------------------------------------------------------------------
 * Launches a single headless Chromium instance (via Playwright), then loads
 * each page URL, lets its JavaScript run, and serializes the resulting live
 * DOM. The rendered HTML is streamed back as NDJSON — one compact JSON object
 * per line on stdout — so the PHP orchestrator can swap each page's static
 * body for the JS-built markup and discover links that only exist after
 * scripts run (SPAs, lazy-loaded menus, …).
 *
 * This mirrors axe-runner.js from the Accessibility Bulk Scanner: PHP owns the
 * crawl, HTTP status checks, and link testing; Node + Playwright only supply
 * the richer, rendered markup. Using Playwright (instead of shelling out to a
 * system Chrome) is what makes rendering work universally — macOS, Windows and
 * Linux — with a managed Chromium that `npx playwright install chromium`
 * downloads once.
 *
 * Input:  a JSON file (--input) containing either
 *           ["https://a", "https://b", ...]
 *         or
 *           [{ "url": "https://a" }, ...]
 *         If --input is omitted, the same JSON is read from stdin.
 *
 * Output (stdout), one line per URL:
 *   {"type":"meta","total":12}
 *   {"type":"result","url":"...","ok":true,"status":200,"ctype":"text/html","finalUrl":"...","html":"<!doctype html>…"}
 *   {"type":"result","url":"...","ok":false,"status":429,"error":"Timeout 15000ms exceeded"}
 * status/ctype/finalUrl come from the last main-frame document response, so they
 * reflect what the browser ended up on after any client-side redirect/challenge.
 *
 * Progress lines are written to stderr (so they don't pollute the NDJSON).
 *
 * Usage:
 *   node render-runner.js --input urls.json --concurrency 3 \
 *        --wait 4000 --timeout 20000 \
 *        --user-agent "BrokenLinkBulkScanner/1.0" [--ignore-tls]
 *
 *   # PDF mode — print a local HTML report to PDF and exit:
 *   node render-runner.js --pdf-from report.html --pdf-out report.pdf
 */

'use strict';

const fs = require('fs');
const path = require('path');
const os = require('os');

// ─── Lazy, friendly dependency loading ──────────────────────────────────────
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  process.stderr.write(
    '\n❌  Missing Node dependencies. Run:\n' +
    '      npm install\n' +
    '      npx playwright install chromium\n' +
    `   (original error: ${e.message})\n`
  );
  process.exit(2);
}

// ─── Arg parsing ─────────────────────────────────────────────────────────────
function parseArgs(argv) {
  const out = {
    input: null,
    concurrency: 3,
    wait: 4000,         // JS settle time per page, in ms
    timeout: 0,         // navigation timeout in ms (0 = derive from wait)
    userAgent: 'BrokenLinkBulkScanner/1.0 (+render)',
    ignoreTls: false,
    pdfFrom: null,      // local HTML file to print to PDF
    pdfOut: null,       // PDF output path (paired with pdfFrom)
  };
  for (let i = 2; i < argv.length; i++) {
    const a = argv[i];
    const next = () => argv[++i];
    if (a === '--input') out.input = next();
    else if (a === '--concurrency') out.concurrency = Math.max(1, parseInt(next(), 10) || 3);
    else if (a === '--wait') out.wait = Math.max(0, parseInt(next(), 10) || 0);
    else if (a === '--timeout') out.timeout = Math.max(0, parseInt(next(), 10) || 0);
    else if (a === '--user-agent') out.userAgent = next();
    else if (a === '--ignore-tls') out.ignoreTls = true;
    else if (a === '--pdf-from') out.pdfFrom = next();
    else if (a === '--pdf-out') out.pdfOut = next();
  }
  // Navigation budget: explicit --timeout, else the settle time plus headroom.
  if (out.timeout === 0) out.timeout = out.wait + 15000;
  return out;
}

function readInput(inputPath) {
  let raw;
  try {
    raw = inputPath ? fs.readFileSync(inputPath, 'utf8') : fs.readFileSync(0, 'utf8');
  } catch (e) {
    process.stderr.write(`❌  Could not read input: ${e.message}\n`);
    process.exit(2);
  }
  let parsed;
  try {
    parsed = JSON.parse(raw);
  } catch (e) {
    process.stderr.write(`❌  Could not parse input JSON: ${e.message}\n`);
    process.exit(2);
  }
  if (!Array.isArray(parsed)) {
    process.stderr.write('❌  Input JSON must be an array of URLs or {url} objects.\n');
    process.exit(2);
  }
  return parsed
    .map((item) => (typeof item === 'string' ? { url: item } : { url: item && item.url }))
    .filter((it) => it && it.url);
}

// ─── Render one URL ────────────────────────────────────────────────────────────
async function renderUrl(browser, urlObj, opts) {
  const context = await browser.newContext({
    userAgent: opts.userAgent,
    ignoreHTTPSErrors: opts.ignoreTls,
    bypassCSP: true,
  });
  const page = await context.newPage();

  // Track the last main-frame *document* response so we can report the HTTP
  // status a real browser actually ended up on — after any client-side
  // challenge or redirect (e.g. Cloudflare's JS bot check) has run. This is the
  // status PHP trusts in render mode, so the crawl reflects the browser's view
  // rather than cURL's (which such challenges block outright).
  let docStatus = 0;
  let docType = '';
  page.on('response', (resp) => {
    try {
      const req = resp.request();
      if (req.resourceType() === 'document' && resp.frame() === page.mainFrame()) {
        docStatus = resp.status();
        docType = resp.headers()['content-type'] || '';
      }
    } catch (_) { /* some responses expose neither frame nor request */ }
  });

  try {
    // Get the document, then give scripts/fetches time to settle, mirroring the
    // old --virtual-time-budget behaviour.
    const navResp = await page.goto(urlObj.url, { waitUntil: 'domcontentloaded', timeout: opts.timeout });
    if (navResp && !docStatus) {
      docStatus = navResp.status();
      docType = navResp.headers()['content-type'] || '';
    }
    // Best-effort "network is quiet" wait, capped so a chatty page can't hang us.
    // A challenge that resolves during this window updates docStatus via the
    // response listener above.
    await page
      .waitForLoadState('networkidle', { timeout: Math.min(opts.wait + 5000, opts.timeout) })
      .catch(() => {});
    if (opts.wait > 0) await page.waitForTimeout(opts.wait);

    const html = await page.content();
    return {
      type: 'result', url: urlObj.url, ok: true,
      status: docStatus, ctype: docType, finalUrl: page.url(), html,
    };
  } catch (err) {
    return {
      type: 'result',
      url: urlObj.url,
      ok: false,
      status: docStatus,
      ctype: docType,
      finalUrl: page.url(),
      error: (err && err.message ? err.message : String(err)).split('\n')[0].slice(0, 200),
    };
  } finally {
    await context.close().catch(() => {});
  }
}

// ─── HTML-report → PDF ─────────────────────────────────────────────────────────
/**
 * Print a local, self-contained HTML report to PDF. The report's CSS is inline
 * so a file:// load renders identically to the browser; printBackground keeps
 * the report's theme. Used for the "Download PDF" export.
 */
async function renderPdf(browser, opts) {
  const fromAbs = path.resolve(opts.pdfFrom);
  if (!fs.existsSync(fromAbs)) throw new Error(`HTML file not found: ${fromAbs}`);

  const context = await browser.newContext();
  const page = await context.newPage();
  try {
    await page.goto('file://' + fromAbs, { waitUntil: 'networkidle', timeout: opts.timeout || 30000 });
    await page.pdf({
      path: opts.pdfOut,
      printBackground: true,
      format: 'A4',
      margin: { top: '12mm', bottom: '14mm', left: '10mm', right: '10mm' },
    });
    process.stderr.write(`✓ PDF written → ${opts.pdfOut}\n`);
  } finally {
    await context.close().catch(() => {});
  }
}

// ─── Concurrency pool ────────────────────────────────────────────────────────
async function runPool(browser, urls, opts) {
  let index = 0;
  let done = 0;
  const total = urls.length;

  const emit = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');

  async function worker() {
    while (true) {
      const myIndex = index++;
      if (myIndex >= total) return;
      const urlObj = urls[myIndex];
      const res = await renderUrl(browser, urlObj, opts);
      done++;
      emit(res);
      const tag = res.ok
        ? `✓ HTTP ${res.status || '?'} (${res.html.length} bytes)`
        : `✗ ${res.error}`;
      process.stderr.write(`  [${done}/${total}] ${tag}  ${urlObj.url}\n`);
    }
  }

  const workers = [];
  const n = Math.min(opts.concurrency, total || 1);
  for (let i = 0; i < n; i++) workers.push(worker());
  await Promise.all(workers);
}

// ─── Browser launch (cross-platform, with fallback) ─────────────────────────
/**
 * Launch headless Chromium. The standard launch works on macOS / Windows /
 * normal Linux with the Chromium that `npx playwright install chromium`
 * downloads. Some locked-down Linux containers crash Playwright's default
 * `headless_shell` binary; in that case we fall back to the full Chromium build
 * with the new headless mode. An explicit executable can be forced via the
 * RENDER_CHROME_PATH environment variable (set by --chrome-bin on the PHP side),
 * which lets users point at a system Chrome/Chromium/Edge/Brave instead.
 */
async function launchBrowser() {
  const baseArgs = ['--no-sandbox', '--disable-dev-shm-usage', '--disable-gpu'];

  // 1 — explicit override (system browser or a specific build)
  if (process.env.RENDER_CHROME_PATH) {
    return chromium.launch({
      headless: true,
      executablePath: process.env.RENDER_CHROME_PATH,
      args: baseArgs.concat('--headless=new'),
    });
  }

  // 2 — standard launch (the normal path on real machines)
  try {
    return await chromium.launch({ headless: true, args: baseArgs });
  } catch (firstErr) {
    process.stderr.write(
      `   ⚠  Default Chromium launch failed (${firstErr.message.split('\n')[0]}); ` +
      'trying full-build fallback …\n'
    );
  }

  // 3 — fallback: locate a full chromium build in the Playwright cache
  //     (cross-platform cache locations: Linux/macOS ~/.cache, Windows LOCALAPPDATA)
  const cacheRoots = [
    process.env.PLAYWRIGHT_BROWSERS_PATH,
    path.join(os.homedir(), '.cache', 'ms-playwright'),
    process.env.LOCALAPPDATA && path.join(process.env.LOCALAPPDATA, 'ms-playwright'),
  ].filter(Boolean);

  let exe = null;
  for (const cacheRoot of cacheRoots) {
    try {
      for (const dir of fs.readdirSync(cacheRoot)) {
        if (/^chromium-\d+$/.test(dir)) {
          for (const candidate of [
            path.join(cacheRoot, dir, 'chrome-linux', 'chrome'),
            path.join(cacheRoot, dir, 'chrome-mac', 'Chromium.app', 'Contents', 'MacOS', 'Chromium'),
            path.join(cacheRoot, dir, 'chrome-win', 'chrome.exe'),
          ]) {
            if (fs.existsSync(candidate)) { exe = candidate; break; }
          }
        }
        if (exe) break;
      }
    } catch (_) { /* cache dir not found */ }
    if (exe) break;
  }

  if (!exe) throw new Error('Chromium launch failed and no full build found. Run: npx playwright install chromium');
  return chromium.launch({
    headless: true,
    executablePath: exe,
    args: baseArgs.concat('--headless=new'),
  });
}

// ─── Main ────────────────────────────────────────────────────────────────────
(async () => {
  const opts = parseArgs(process.argv);

  // PDF mode: print a local HTML report to PDF and exit. No URL input needed.
  if (opts.pdfFrom) {
    if (!opts.pdfOut) {
      process.stderr.write('❌  --pdf-from requires --pdf-out.\n');
      process.exit(2);
    }
    const browser = await launchBrowser();
    try {
      await renderPdf(browser, opts);
    } finally {
      await browser.close().catch(() => {});
    }
    return;
  }

  const urls = readInput(opts.input);

  if (!urls.length) {
    process.stderr.write('❌  No URLs to render.\n');
    process.exit(1);
  }

  process.stderr.write(
    `⚡  render: ${urls.length} page(s), concurrency ${opts.concurrency}, ` +
    `settle ${opts.wait}ms, timeout ${opts.timeout}ms\n`
  );

  // Emit a small meta line first so the orchestrator can confirm startup.
  process.stdout.write(JSON.stringify({ type: 'meta', total: urls.length }) + '\n');

  const browser = await launchBrowser();
  try {
    await runPool(browser, urls, opts);
  } finally {
    await browser.close().catch(() => {});
  }
})().catch((e) => {
  process.stderr.write(`❌  Fatal: ${e && e.stack ? e.stack : e}\n`);
  process.exit(1);
});
