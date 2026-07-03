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
function web_args(array $r, string $htmlPath, string $csvPath): array {
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
        'csv'             => $csvPath,
        'pdf'             => '',
    ];
}

$req       = $_GET + $_POST;
$urlInput  = trim((string)($req['url'] ?? ''));
$isScan    = $urlInput !== '';
$extError  = (!extension_loaded('curl') || !extension_loaded('dom'))
    ? 'This tool needs the PHP curl and dom extensions, which are not loaded.' : null;

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
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Broken Link Bulk Scanner</title>
<style>
  *, *::before, *::after { box-sizing: border-box; }
  body { font-family: system-ui, -apple-system, sans-serif; background: #f8fafc;
         color: #1e293b; margin: 0; padding: 48px 24px 80px; }
  .header { max-width: 720px; margin: 0 auto 36px; text-align: center; }
  .badge { display: inline-block; background: #eef2ff; color: #4f46e5; font-size: 0.72rem;
           font-weight: 400; letter-spacing: .1em; text-transform: uppercase;
           padding: 7px 18px; border-radius: 999px; margin-bottom: 24px; }
  h1   { font-size: 34px; font-weight: 700; letter-spacing: -0.02em;
         margin: 0 0 16px; color: #0f172a; }
  .sub { font-size: 16px; color: #475569; margin: 0; line-height: 1.65; }
  .panel { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;
           padding: 32px 36px; max-width: 920px; margin: 0 auto;
           box-shadow: 0 1px 3px rgba(15, 23, 42, .04); }
  label { display: block; font-size: 0.9rem; font-weight: 700; color: #0f172a;
          margin-bottom: 8px; }
  label .hint { font-weight: 400; color: #64748b; }
  input[type=text], input[type=number], select {
    width: 100%; background: #ffffff; border: 1px solid #cbd5e1; color: #1e293b;
    border-radius: 10px; padding: 12px 14px; font-size: 0.95rem; }
  input:focus, select:focus { outline: none; border-color: #4f46e5;
    box-shadow: 0 0 0 3px rgba(79, 70, 229, .12); }
  .row  { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 20px; }
  .row > div { flex: 1; min-width: 130px; }
  .checks { display: flex; flex-wrap: wrap; gap: 20px; margin-top: 22px; }
  .check { display: flex; align-items: center; gap: 9px; font-size: 0.9rem; color: #334155; }
  .check input { width: 17px; height: 17px; accent-color: #4f46e5; }
  .check label { display: inline; margin: 0; font-weight: 400; font-size: 0.9rem; color: #334155; }
  button.go { margin-top: 26px; background: #4f46e5; color: #fff; border: none; cursor: pointer;
              border-radius: 10px; padding: 13px 28px; font-size: 0.95rem; font-weight: 600; }
  button.go:hover { background: #4338ca; }
  .footer { max-width: 920px; margin: 28px auto 0; text-align: center;
            font-size: 0.85rem; color: #64748b; }
  details summary { cursor: pointer; color: #475569; font-size: 0.8rem; margin-top: 20px; }
  .err { background: #fee2e2; color: #991b1b; border: 1px solid #fca5a5; padding: 12px 16px;
         border-radius: 8px; margin: 0 auto 20px; max-width: 920px; }
  .log { background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px;
         font-family: ui-monospace, monospace; font-size: 0.78rem; color: #334155;
         white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow-y: auto;
         margin: 0 auto 18px; max-width: 920px; }
  .result { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px;
            padding: 24px 28px; margin: 0 auto 18px; max-width: 920px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, .04); }
  .result h2 { margin: 0 0 12px; font-size: 1.15rem; color: #0f172a; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px; }
  .actions a { text-decoration: none; border-radius: 10px; padding: 10px 20px; font-size: 0.85rem;
               font-weight: 600; }
  .a-html { background: #4f46e5; color: #fff; }
  .a-csv  { background: #e2e8f0; color: #1e293b; }
  .a-pdf  { background: #b91c1c; color: #fff; }
  .a-new  { background: transparent; color: #4f46e5; border: 1px solid #cbd5e1; }
  iframe { width: 100%; height: 78vh; border: 1px solid #e2e8f0; border-radius: 16px;
           background: #ffffff; margin: 4px auto 0; max-width: 920px; display: block; }
</style>
</head>
<body>
<div class="header">
  <div class="badge">HTTP Crawler · Link Checker</div>
  <h1>Broken Link Bulk Scanner</h1>
  <div class="sub">Enter a website address, crawl every page, and see every dead link, image,
  and asset in one dashboard. Optionally render JavaScript to catch links built by SPAs.</div>
</div>

<?php if ($extError): ?>
  <div class="err"><?= e($extError) ?></div>
<?php endif; ?>

<?php if (!$isScan): /* ---------- the form ---------- */ ?>
<form class="panel" method="get" action="">
  <input type="hidden" name="submitted" value="1">
  <div>
    <label for="url">Website URL <span class="hint">— the page to start crawling from</span></label>
    <input type="text" id="url" name="url" placeholder="https://example.com" autofocus
           value="<?= e($val['url']) ?>" <?= $extError ? 'disabled' : '' ?>>
  </div>

  <div class="row">
    <div>
      <label for="mode">Scan mode</label>
      <select id="mode" name="mode">
        <option value="site" <?= $val['mode']==='site'?'selected':'' ?>>Whole site (crawl)</option>
        <option value="page" <?= $val['mode']==='page'?'selected':'' ?>>Single page only</option>
      </select>
    </div>
    <div>
      <label for="max-pages">Max pages <span class="hint">— crawl cap</span></label>
      <input type="number" id="max-pages" name="max-pages" min="1" max="2000" value="<?= e($val['max-pages']) ?>">
    </div>
    <div>
      <label for="max-depth">Max depth</label>
      <input type="number" id="max-depth" name="max-depth" min="0" max="20" value="<?= e($val['max-depth']) ?>">
    </div>
    <div>
      <label for="concurrency">Concurrency</label>
      <input type="number" id="concurrency" name="concurrency" min="1" max="50" value="<?= e($val['concurrency']) ?>">
    </div>
  </div>

  <div class="checks">
    <div class="check"><input type="checkbox" id="check-assets" name="check-assets" value="1" <?= $val['check-assets']?'checked':'' ?>><label for="check-assets">Test assets (img / link / script)</label></div>
    <div class="check"><input type="checkbox" id="respect-robots" name="respect-robots" value="1" <?= $val['respect-robots']?'checked':'' ?>><label for="respect-robots">Honour robots.txt</label></div>
    <div class="check"><input type="checkbox" id="verify-tls" name="verify-tls" value="1" <?= $val['verify-tls']?'checked':'' ?>><label for="verify-tls">Verify TLS certificates</label></div>
  </div>

  <div class="check" style="margin-top:18px">
    <input type="checkbox" id="render" name="render" value="1" <?= $val['render']?'checked':'' ?> <?= $renderReady ? '' : 'disabled' ?>>
    <label for="render">
      Render JavaScript with headless Chromium
      <?php if ($renderReady): ?>
        <span style="color:#64748b">— slower, but finds links built by JS (SPAs)</span>
      <?php else: ?>
        <span style="color:#b45309">— setup needed, so this is unavailable (run <code>npm install &amp;&amp; npx playwright install chromium</code>)</span>
      <?php endif; ?>
    </label>
  </div>
  <?php if ($renderReady): ?>
  <div class="row" id="render-opts" style="<?= $val['render'] ? '' : 'display:none' ?>">
    <div>
      <label for="render-wait">JS settle time (ms)</label>
      <input type="number" id="render-wait" name="render-wait" min="100" max="30000" step="100" value="<?= e($val['render-wait']) ?>">
    </div>
    <div style="flex:3"></div>
  </div>
  <script>
    document.getElementById('render').addEventListener('change', function () {
      document.getElementById('render-opts').style.display = this.checked ? '' : 'none';
    });
  </script>
  <?php endif; ?>

  <button class="go" type="submit" <?= $extError ? 'disabled' : '' ?>>Scan for broken links →</button>
</form>
<div class="footer">Crawls with PHP cURL + DOM — no external API or key required.</div>

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
  $csvRel   = 'reports/' . $id . '.csv';
  $htmlPath = REPORTS_DIR . '/' . $id . '.html';
  $csvPath  = REPORTS_DIR . '/' . $id . '.csv';
  $args     = web_args($req, $htmlPath, $csvPath);

  echo '<div class="result"><h2>Crawling ' . e($args['url']) . ' …</h2>'
     . '<div class="sub" style="margin:0">Live progress — the full report appears below when finished.</div></div>';
  echo '<div class="log">';
  flush();

  $crawl = crawl($args);   // echoes progress straight into the .log box

  echo '</div>';
  flush();

  if (!$crawl['results'] && !$crawl['pageErrors']) {
      echo '<div class="err">No links were found. Check that the start URL is reachable and serves HTML.</div>';
      echo '<div class="actions"><a class="a-new" href="?">← New scan</a></div>';
  } else {
      $agg = aggregate($crawl);
      file_put_contents($htmlPath, build_html($crawl, $agg, $args, date('Y-m-d H:i')));
      file_put_contents($csvPath,  build_csv($crawl));

      // Export the report as PDF when the render engine is available. It's a
      // best-effort extra — the button only appears if the PDF was produced.
      $pdfRel = null;
      if ($renderReady) {
          $pdfPath = REPORTS_DIR . '/' . $id . '.pdf';
          if (render_pdf($htmlPath, $pdfPath, $args)) {
              $pdfRel = 'reports/' . $id . '.pdf';
          }
      }

      $broken   = (int)($agg['broken'] ?? 0);
      $total    = (int)($agg['totalLinks'] ?? 0);
      $headline = "Scan complete — {$broken} broken of {$total} links tested.";

      echo '<div class="result"><h2>✅ ' . e($headline) . '</h2>'
         . '<div class="actions">'
         . '<a class="a-html" href="' . e($htmlRel) . '" target="_blank">Open full report ↗</a>'
         . '<a class="a-csv" href="' . e($csvRel) . '" download>Download CSV</a>'
         . ($pdfRel ? '<a class="a-pdf" href="' . e($pdfRel) . '" download>Download PDF</a>' : '')
         . '<a class="a-new" href="?">← New scan</a>'
         . '</div></div>';
      // Defer loading the embedded report until the page has fully finished
      // loading. The crawl response is streamed, so the browser would otherwise
      // request the iframe mid-stream — and the single-threaded PHP dev server
      // (php -S) serializes requests, so that request blocks behind the still-
      // open crawl connection and the browser eventually shows a broken-frame
      // icon (worse the bigger the site / report). Setting src on window.load,
      // when the connection is closed and the worker is free, avoids the race.
      echo '<iframe data-src="' . e($htmlRel) . '" title="Broken link report"></iframe>';
      echo '<script>window.addEventListener("load",function(){'
         . 'var f=document.querySelector("iframe[data-src]");'
         . 'if(f){f.src=f.getAttribute("data-src");}'
         . '});</script>';
  }

endif; ?>
</body>
</html>
