<?php
/**
 * Broken Link Bulk Scanner — Web UI
 *
 * A thin web front-end over link_checker.php. Reuses the crawler + report
 * builders directly; nothing is duplicated. Run it with PHP's built-in server:
 *
 *     php -S localhost:8083
 *
 * then open http://localhost:8083 in a browser.
 *
 * NOTE: this fetches arbitrary URLs you type, so run it locally — do not expose
 * it on a public host without adding your own authentication.
 */

require __DIR__ . '/link_checker.php';

const REPORTS_DIR = __DIR__ . '/reports';

/** Build the crawler $args array from a request, mirroring parse_args(). */
function web_args(array $r, string $htmlPath): array {
    $url = trim((string)($r['url'] ?? ''));
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    return [
        'url'             => $url,
        'mode'            => (($r['mode'] ?? 'site') === 'page') ? 'page' : 'site',
        'max-pages'       => max(1, min(2000, (int)($r['max-pages'] ?? 100))),
        'max-depth'       => max(0, min(20,   (int)($r['max-depth'] ?? 3))),
        'concurrency'     => max(1, min(50,   (int)($r['concurrency'] ?? 10))),
        'delay'           => max(0, (int)($r['delay'] ?? 200)),
        'check-assets'    => !empty($r['check-assets']),
        'connect-timeout' => max(1, (int)($r['connect-timeout'] ?? 10)),
        'timeout'         => max(1, (int)($r['timeout'] ?? 20)),
        'max-redirs'      => 10,
        'respect-robots'  => !empty($r['respect-robots']),
        'verify-tls'      => !empty($r['verify-tls']),
        'render'             => !empty($r['render']),
        'render-wait'        => max(100, min(30000, (int)($r['render-wait'] ?? 4000))),
        'render-concurrency' => 3,
        'chrome-bin'         => '',
        'node'               => 'node',
        'runner'             => __DIR__ . '/render-runner.js',
        'user-agent'      => DEFAULT_UA,
        'output'          => $htmlPath,
        'csv'             => '',
        'pdf'             => '',
    ];
}

$req       = $_GET + $_POST;
$urlInput  = trim((string)($req['url'] ?? ''));
$extError  = (!extension_loaded('curl') || !extension_loaded('dom'))
    ? 'This tool needs the PHP curl and dom extensions, which are not loaded.' : null;
$isScan    = $urlInput !== '' && $extError === null;

// Values used to re-populate the form (defaults on first load).
$submitted = isset($req['submitted']);
$val = [
    'url'          => $urlInput,
    'mode'         => ($req['mode'] ?? 'site') === 'page' ? 'page' : 'site',
    'max-pages'    => (int)($req['max-pages'] ?? 100),
    'max-depth'    => (int)($req['max-depth'] ?? 3),
    'concurrency'  => (int)($req['concurrency'] ?? 10),
    // checkboxes: default ON before the form is ever submitted
    'check-assets'   => $submitted ? !empty($req['check-assets'])   : true,
    'respect-robots' => $submitted ? !empty($req['respect-robots']) : true,
    'verify-tls'     => $submitted ? !empty($req['verify-tls'])     : true,
    // JS rendering: default OFF (slower, needs a browser installed)
    'render'         => !empty($req['render']),
    'render-wait'    => (int)($req['render-wait'] ?? 4000),
];
// Rendering is available when Node + Playwright are set up (cross-platform).
$renderProblem = render_preflight_problem([
    'node'   => 'node',
    'runner' => __DIR__ . '/render-runner.js',
]);
$renderReady = ($renderProblem === null);
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broken Link Bulk Scanner</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700&family=Source+Sans+3:wght@400;500;600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --ink:#0F1E33; --body:#33415C; --muted:#64748B; --soft:#94A3B8;
    --line:#E6EAF1; --line-strong:#C9D4E5; --panel:#fff; --bg:#EEF1F5;
    --accent:#0D8A7E; --accent-hover:#0B7468; --accent-tint:#E6F4F2; --accent-line:#BFE3DE;
    --critical:#CF4A3A; --warning:#D97A2B; --ok:#0D8A7E;
    --radius:20px; --radius-sm:12px;
    --shadow:0 1px 2px rgba(15,30,51,.04),0 12px 30px rgba(15,30,51,.05);
  }
  *, *::before, *::after { box-sizing:border-box; }
  body { margin:0; min-height:100vh; color:var(--body); background:var(--bg);
    font:16px/1.55 'Source Sans 3',system-ui,-apple-system,Helvetica,Arial,sans-serif;
    -webkit-font-smoothing:antialiased; }
  .wrap { max-width:920px; margin:0 auto; padding:56px 20px 80px; }
  .hero { text-align:center; margin-bottom:32px; }
  .hero::before { content:'↗'; display:flex; align-items:center; justify-content:center;
    width:64px; height:64px; margin:0 auto 22px; font:700 28px 'Poppins',sans-serif;
    color:var(--accent); background:linear-gradient(160deg,#EAF6F4,#D8EEEA);
    border-radius:20px; box-shadow:0 8px 20px rgba(13,138,126,.18); }
  .hero h1 { margin:0 0 14px; color:var(--ink); font:700 clamp(30px,6.5vw,44px)/1.05 'Poppins',sans-serif;
    letter-spacing:-.02em; }
  .hero p { max-width:610px; margin:0 auto; color:var(--muted); font-size:clamp(16px,3.6vw,18px); }
  .eyebrow { display:inline-block; margin-bottom:18px; padding:6px 14px; border:1px solid var(--accent-line);
    border-radius:999px; color:var(--accent); background:var(--accent-tint);
    font:600 12px 'Poppins',sans-serif; letter-spacing:.14em; text-transform:uppercase; }
  .card { padding:30px; background:var(--panel); border:1px solid var(--line);
    border-radius:var(--radius); box-shadow:var(--shadow); }
  .grid { display:grid; grid-template-columns:repeat(4,1fr); gap:20px 22px; }
  .field { display:flex; flex-direction:column; gap:7px; min-width:0; }
  .field.full { grid-column:1/-1; }
  label { color:var(--ink); font:600 14px 'Poppins',sans-serif; }
  label .hint { color:var(--muted); font:400 13px 'Source Sans 3',sans-serif; }
  input[type=text],input[type=number],select { width:100%; padding:13px 15px; color:var(--ink); background:#fff;
    border:1px solid var(--line-strong); border-radius:var(--radius-sm); font:inherit;
    transition:border-color .15s,box-shadow .15s; }
  #url { padding:16px 18px; border-radius:14px; font-size:16px; }
  input::placeholder { color:var(--soft); }
  input:focus,select:focus { outline:none; border-color:var(--accent); box-shadow:0 0 0 3px rgba(13,138,126,.15); }
  select { appearance:none; padding-right:46px; cursor:pointer; background-repeat:no-repeat;
    background-position:right 16px center; background-size:14px;
    background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748B' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M6 9l6 6 6-6'/%3E%3C/svg%3E"); }
  .checks { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; grid-column:1/-1; }
  .check { display:flex; flex-direction:row; align-items:center; gap:12px; padding:14px 16px;
    background:#fff; border:1px solid var(--line); border-radius:var(--radius-sm);
    transition:border-color .15s,background .15s; }
  .check:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  .check input { width:18px; height:18px; accent-color:var(--accent); flex:none; }
  .check label { color:var(--body); font:400 14px 'Source Sans 3',sans-serif; }
  .check code { font:400 .82em 'IBM Plex Mono',monospace; background:var(--bg); border:1px solid var(--line);
    padding:1px 5px; border-radius:5px; }
  .render-check { grid-column:1/-1; }
  .render-options { grid-column:1/3; }
  .form-actions { display:flex; align-items:center; gap:14px; flex-wrap:wrap; margin-top:26px; }
  button.primary { padding:15px 30px; color:#fff; background:var(--accent); border:0; border-radius:999px;
    cursor:pointer; box-shadow:0 8px 18px rgba(13,138,126,.22); font:700 16px 'Poppins',sans-serif;
    transition:transform .05s,background .15s,box-shadow .15s; }
  button.primary:hover { background:var(--accent-hover); }
  button.primary:active { transform:translateY(1px); }
  button.primary:disabled { opacity:.55; cursor:not-allowed; box-shadow:none; }
  .note { color:var(--muted); font-size:13px; }
  .footer { margin-top:34px; color:var(--muted); text-align:center; font-size:13px; }
  .footer strong { color:var(--accent); font-weight:600; }
  .err { margin-bottom:20px; padding:14px 16px; color:#8A2E20; background:#FBEEEB;
    border:1px solid #E7C3BC; border-radius:var(--radius-sm); }
  .scan-card { padding:24px 30px 30px; }
  .form-summary { display:none; align-items:center; gap:12px; padding-bottom:20px; margin-bottom:20px;
    border-bottom:1px solid var(--line); }
  form.minimized .grid,form.minimized .form-actions { display:none; }
  form.minimized .form-summary { display:flex; }
  .form-summary .label { flex:none; color:var(--ink); font:600 14px 'Poppins',sans-serif; }
  .form-summary .target { flex:1; min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
    color:var(--body); font:13px 'IBM Plex Mono',monospace; }
  button.edit-btn { flex:none; padding:9px 18px; color:var(--accent); background:#fff;
    border:1px solid var(--accent-line); border-radius:999px; text-decoration:none;
    cursor:pointer; font:600 13px 'Poppins',sans-serif; }
  button.edit-btn:hover { background:var(--accent-tint); border-color:var(--accent); }
  .scan-run { padding-top:12px; }
  .statusbar { display:flex; align-items:center; gap:12px; margin-bottom:14px; }
  .spinner { width:18px; height:18px; flex:none; border:2px solid var(--line); border-top-color:var(--accent);
    border-radius:50%; animation:spin .8s linear infinite; }
  @keyframes spin { to { transform:rotate(360deg); } }
  .status-message { color:var(--ink); font:600 15px 'Poppins',sans-serif; }
  .bar { height:10px; overflow:hidden; background:var(--line); border-radius:999px; }
  .bar i { display:block; width:35%; height:100%; background:linear-gradient(90deg,#22B3A2,var(--accent));
    border-radius:999px; animation:scanbar 1.4s ease-in-out infinite; }
  @keyframes scanbar { 0% { transform:translateX(-110%); } 100% { transform:translateX(300%); } }
  .log { max-height:300px; overflow:auto; margin-top:16px; padding:14px;
    color:var(--body); background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm);
    white-space:pre-wrap; word-break:break-word; font:13px/1.55 'IBM Plex Mono',monospace; }
  .result-view { margin-top:26px; }
  .result-heading { margin:0 0 14px; color:var(--ink); font:700 20px 'Poppins',sans-serif; }
  .summary { display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:12px; margin:6px 0 18px; }
  .stat { padding:16px; text-align:center; background:var(--bg); border:1px solid var(--line); border-radius:var(--radius-sm); }
  .stat .n { color:var(--ink); font:700 28px/1.1 'Poppins',sans-serif; }
  .stat .l { margin-top:4px; color:var(--muted); font-size:12px; }
  .stat.bad .n { color:var(--critical); } .stat.warn .n { color:var(--warning); } .stat.ok .n { color:var(--ok); }
  .result-actions { display:flex; flex-wrap:wrap; gap:12px; margin-bottom:18px; }
  a.btn { padding:11px 20px; color:var(--ink); background:#fff; border:1px solid var(--line-strong);
    border-radius:999px; text-decoration:none; font:600 14px 'Poppins',sans-serif;
    transition:border-color .15s,background .15s; }
  a.btn:hover { border-color:var(--accent-line); background:var(--accent-tint); }
  a.btn.solid { color:#fff; background:var(--accent); border-color:var(--accent); box-shadow:0 8px 18px rgba(13,138,126,.22); }
  a.btn.solid:hover { background:var(--accent-hover); }
  iframe.report { display:block; width:100%; height:680px; background:#fff; border:1px solid var(--line);
    border-radius:var(--radius-sm); }
  @media (max-width:720px) {
    .wrap { padding:36px 14px 56px; }
    .hero { margin-bottom:24px; }
    .hero::before { width:56px; height:56px; margin-bottom:18px; }
    .card { padding:20px; border-radius:16px; }
    .grid { grid-template-columns:1fr 1fr; gap:16px; }
    .checks { grid-template-columns:1fr; }
    .render-options { grid-column:1/-1; }
    .form-summary { flex-wrap:wrap; }
    .form-summary .target { flex-basis:100%; order:3; }
    .result-actions { flex-direction:column; }
    .result-actions a { text-align:center; }
    iframe.report { height:70vh; min-height:420px; }
  }
  @media (max-width:480px) {
    .grid,.summary { grid-template-columns:1fr; }
    .form-actions { flex-direction:column; align-items:stretch; }
    button.primary { width:100%; }
    .note { text-align:center; }
  }
</style>
</head>
<body>
<div class="wrap">
<header class="hero">
  <span class="eyebrow">HTTP Crawler · Link Checker</span>
  <h1>Broken Link Bulk Scanner</h1>
  <p>Enter a website address, crawl every page, and surface dead links, images, and assets in one clear report. Optionally render JavaScript to inspect SPA-generated links.</p>
</header>

<?php if ($extError): ?>
  <div class="err"><?= e($extError) ?></div>
<?php endif; ?>

<div class="card <?= $isScan ? 'scan-card' : '' ?>">
<form id="scanForm" method="get" action="" class="<?= $isScan ? 'minimized' : '' ?>">
  <input type="hidden" name="submitted" value="1">
  <div class="form-summary">
    <span class="label" id="scanLabel">Scanning</span>
    <span class="target"><?= e($urlInput) ?></span>
    <button type="button" class="edit-btn" id="editBtn">Edit &amp; rescan</button>
  </div>
  <div class="grid">
  <div class="field full">
    <label for="url">Website URL <span class="hint">— the page to start crawling from</span></label>
    <input type="text" id="url" name="url" placeholder="https://example.com" autofocus
           autocomplete="off" spellcheck="false" value="<?= e($val['url']) ?>" <?= $extError ? 'disabled' : '' ?>>
  </div>

    <div class="field">
      <label for="mode">Scan mode</label>
      <select id="mode" name="mode">
        <option value="site" <?= $val['mode']==='site'?'selected':'' ?>>Whole site (crawl)</option>
        <option value="page" <?= $val['mode']==='page'?'selected':'' ?>>Single page only</option>
      </select>
    </div>
    <div class="field">
      <label for="max-pages">Max pages <span class="hint">— crawl cap</span></label>
      <input type="number" id="max-pages" name="max-pages" min="1" max="2000" value="<?= e($val['max-pages']) ?>">
    </div>
    <div class="field">
      <label for="max-depth">Max depth</label>
      <input type="number" id="max-depth" name="max-depth" min="0" max="20" value="<?= e($val['max-depth']) ?>">
    </div>
    <div class="field">
      <label for="concurrency">Concurrency</label>
      <input type="number" id="concurrency" name="concurrency" min="1" max="50" value="<?= e($val['concurrency']) ?>">
    </div>

  <div class="checks">
    <div class="check"><input type="checkbox" id="check-assets" name="check-assets" value="1" <?= $val['check-assets']?'checked':'' ?>><label for="check-assets">Test assets (img / link / script)</label></div>
    <div class="check"><input type="checkbox" id="respect-robots" name="respect-robots" value="1" <?= $val['respect-robots']?'checked':'' ?>><label for="respect-robots">Honour robots.txt</label></div>
    <div class="check"><input type="checkbox" id="verify-tls" name="verify-tls" value="1" <?= $val['verify-tls']?'checked':'' ?>><label for="verify-tls">Verify TLS certificates</label></div>
  </div>

  <div class="check render-check">
    <input type="checkbox" id="render" name="render" value="1" <?= $val['render']?'checked':'' ?> <?= $renderReady ? '' : 'disabled' ?>>
    <label for="render">
      Render JavaScript with headless Chromium
      <?php if ($renderReady): ?>
        <span class="hint">— slower, but finds links built by JS (SPAs)</span>
      <?php else: ?>
        <span class="hint">— setup needed (run <code>npm install &amp;&amp; npx playwright install chromium</code>)</span>
      <?php endif; ?>
    </label>
  </div>
  <?php if ($renderReady): ?>
  <div class="field render-options" id="render-opts" style="<?= $val['render'] ? '' : 'display:none' ?>">
      <label for="render-wait">JS settle time (ms)</label>
      <input type="number" id="render-wait" name="render-wait" min="100" max="30000" step="100" value="<?= e($val['render-wait']) ?>">
  </div>
  <script>
    document.getElementById('render').addEventListener('change', function () {
      document.getElementById('render-opts').style.display = this.checked ? '' : 'none';
    });
  </script>
  <?php endif; ?>
  </div>

  <div class="form-actions">
    <button class="primary" type="submit" <?= $extError ? 'disabled' : '' ?>>Scan website</button>
    <span class="note">Pages and links are checked in parallel; larger sites may take a few minutes.</span>
  </div>
</form>
<script>
  (function () {
    var form = document.getElementById('scanForm');
    var edit = document.getElementById('editBtn');
    if (!form || !edit) return;
    edit.addEventListener('click', function () {
      form.classList.remove('minimized');
      var url = document.getElementById('url');
      if (url) url.focus();
    });
  }());
</script>

<?php if (!$isScan): /* ---------- ready to scan ---------- */ ?>
</div>
<div class="footer">Powered by <strong>PHP cURL + DOM</strong>. No external API or key required.</div>

<?php else: /* ---------- run the scan, stream progress ---------- */

  // Stream output live so the long-running crawl shows progress.
  @ini_set('zlib.output_compression', '0');
  @ini_set('output_buffering', '0');
  @ini_set('implicit_flush', '1');
  while (ob_get_level() > 0) { ob_end_flush(); }
  ob_implicit_flush(true);
  set_time_limit(0);
  ignore_user_abort(false);

  @mkdir(REPORTS_DIR, 0775, true);
  $id       = date('Ymd-His') . '-' . substr(md5($urlInput . mt_rand()), 0, 6);
  $htmlRel  = 'reports/' . $id . '.html';
  $htmlPath = REPORTS_DIR . '/' . $id . '.html';
  $args     = web_args($req, $htmlPath);

  echo '<section class="scan-run">'
     . '<div class="statusbar"><span class="spinner" id="scanSpinner"></span>'
     . '<span class="status-message" id="scanStatus">Crawling the site and checking links…</span></div>'
     . '<div class="bar" id="scanBar"><i></i></div>';
  echo '<div class="log">';
  flush();

  // The scanned site controls discovered URLs and browser-runner messages.
  // Treat every progress byte as text when embedding it in this HTML stream.
  set_progress_writer(static function (string $message): void {
      echo e($message);
      flush();
  });
  try {
      $crawl = crawl($args);
  } finally {
      set_progress_writer(null);
  }

  echo '</div>';
  echo '<script>document.getElementById("scanSpinner").style.display="none";'
     . 'document.getElementById("scanBar").style.display="none";</script>';
  flush();

  if (!$crawl['results'] && !$crawl['pageErrors']) {
      echo '<script>document.getElementById("scanLabel").textContent="Scan failed";'
         . 'document.getElementById("scanStatus").textContent="No crawlable links found.";</script>';
      echo '<div class="err">No links were found. Check that the start URL is reachable and serves HTML.</div>';
      echo '<div class="result-actions"><a class="btn" href="?">← New scan</a></div>';
  } else {
      $agg = aggregate($crawl);
      $generatedAt = date('Y-m-d H:i');
      file_put_contents($htmlPath, build_html($crawl, $agg, $args, $generatedAt));

      // Export the report as PDF when the render engine is available. It's a
      // best-effort extra — the button only appears if the PDF was produced.
      $pdfRel = null;
      if ($renderReady) {
          $pdfPath = REPORTS_DIR . '/' . $id . '.pdf';
          if (render_report_pdf($crawl, $agg, $args, $pdfPath, $generatedAt)) {
              $pdfRel = 'reports/' . $id . '.pdf';
          }
      }

      $broken   = (int)($agg['broken'] ?? 0);
      $total    = (int)($agg['totalLinks'] ?? 0);
      $failed   = (int)($agg['pageFailures'] ?? 0);
      $redirects = (int)($agg['counts']['redirect'] ?? 0);
      $placeholders = (int)($agg['placeholders'] ?? 0);
      $pages = (int)($crawl['pagesFetched'] ?? 0);
      $headline = "Scan complete — {$broken} broken of {$total} links tested; {$failed} page(s) failed to load.";

      echo '<script>document.getElementById("scanLabel").textContent="Scanned";'
         . 'document.getElementById("scanStatus").textContent="Scan complete.";</script>'
         . '<section class="result-view"><h2 class="result-heading">✅ ' . e($headline) . '</h2>'
         . '<div class="summary">'
         . '<div class="stat"><div class="n">' . $pages . '</div><div class="l">Pages scanned</div></div>'
         . '<div class="stat"><div class="n">' . $total . '</div><div class="l">Links tested</div></div>'
         . '<div class="stat ' . ($broken ? 'bad' : 'ok') . '"><div class="n">' . $broken . '</div><div class="l">Broken links</div></div>'
         . '<div class="stat warn"><div class="n">' . $redirects . '</div><div class="l">Redirects</div></div>'
         . '<div class="stat"><div class="n">' . $placeholders . '</div><div class="l">Placeholders</div></div>'
         . '<div class="stat ' . ($failed ? 'bad' : 'ok') . '"><div class="n">' . $failed . '</div><div class="l">Pages failed</div></div>'
         . '</div><div class="result-actions">'
         . '<a class="btn solid" href="' . e($htmlRel) . '" target="_blank" rel="noopener">Open full report ↗</a>'
         . ($pdfRel ? '<a class="btn" href="' . e($pdfRel) . '" download>Download PDF</a>' : '')
         . '<a class="btn" href="?">← New scan</a>'
         . '</div>';
      // Defer loading the embedded report until the page has fully finished
      // loading. The crawl response is streamed, so the browser would otherwise
      // request the iframe mid-stream — and the single-threaded PHP dev server
      // (php -S) serializes requests, so that request blocks behind the still-
      // open crawl connection and the browser eventually shows a broken-frame
      // icon (worse the bigger the site / report). Setting src on window.load,
      // when the connection is closed and the worker is free, avoids the race.
      echo '<iframe class="report" data-src="' . e($htmlRel) . '" title="Broken link report"></iframe></section>';
      echo '<script>window.addEventListener("load",function(){'
         . 'var f=document.querySelector("iframe[data-src]");'
         . 'if(f){f.src=f.getAttribute("data-src");}'
         . '});</script>';
  }

  echo '</section></div><div class="footer">Powered by <strong>PHP cURL + DOM</strong>. No external API or key required.</div>';

endif; ?>
</div>
</body>
</html>
