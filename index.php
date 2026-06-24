<?php
/**
 * Broken Link Bulk Scanner — Web UI
 *
 * A thin web front-end over link_checker.php. Reuses the crawler + report
 * builders directly; nothing is duplicated. Run it with PHP's built-in server:
 *
 *     php -S localhost:8090
 *
 * then open http://localhost:8090 in a browser.
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
        'user-agent'      => DEFAULT_UA,
        'output'          => $htmlPath,
        'csv'             => $csvPath,
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
];
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
  body { font-family: system-ui, -apple-system, sans-serif; background: #0f172a;
         color: #e2e8f0; margin: 0; padding: 28px 28px 60px; }
  h1   { font-size: 1.6rem; margin: 0 0 4px; color: #f8fafc; }
  .sub { font-size: 0.85rem; color: #64748b; margin-bottom: 26px; line-height: 1.6; }
  .panel { background: #1e293b; border-radius: 12px; padding: 22px 24px; max-width: 760px; }
  label { display: block; font-size: 0.74rem; color: #94a3b8; text-transform: uppercase;
          letter-spacing: .06em; margin-bottom: 6px; }
  input[type=text], input[type=number], select {
    width: 100%; background: #0f172a; border: 1px solid #334155; color: #e2e8f0;
    border-radius: 8px; padding: 10px 12px; font-size: 0.9rem; }
  input:focus, select:focus { outline: none; border-color: #2563eb; }
  .row  { display: flex; flex-wrap: wrap; gap: 16px; margin-top: 16px; }
  .row > div { flex: 1; min-width: 130px; }
  .checks { display: flex; flex-wrap: wrap; gap: 18px; margin-top: 18px; }
  .check { display: flex; align-items: center; gap: 8px; font-size: 0.82rem; color: #cbd5e1; }
  .check input { width: 16px; height: 16px; accent-color: #2563eb; }
  .check label { display: inline; margin: 0; text-transform: none; letter-spacing: 0;
                 font-size: 0.82rem; color: #cbd5e1; }
  button.go { margin-top: 22px; background: #2563eb; color: #fff; border: none; cursor: pointer;
              border-radius: 8px; padding: 12px 26px; font-size: 0.95rem; font-weight: 600; }
  button.go:hover { background: #1d4ed8; }
  details summary { cursor: pointer; color: #94a3b8; font-size: 0.8rem; margin-top: 20px; }
  .err { background: #7f1d1d; color: #fecaca; padding: 12px 16px; border-radius: 8px;
         margin-bottom: 20px; max-width: 760px; }
  .log { background: #020617; border: 1px solid #1e293b; border-radius: 10px; padding: 16px;
         font-family: ui-monospace, monospace; font-size: 0.78rem; color: #cbd5e1;
         white-space: pre-wrap; word-break: break-word; max-height: 360px; overflow-y: auto;
         margin: 0 0 18px; }
  .result { background: #1e293b; border-radius: 12px; padding: 20px 24px; margin-bottom: 18px; }
  .result h2 { margin: 0 0 12px; font-size: 1.1rem; color: #f8fafc; }
  .actions { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 4px; }
  .actions a { text-decoration: none; border-radius: 8px; padding: 9px 18px; font-size: 0.85rem;
               font-weight: 600; }
  .a-html { background: #2563eb; color: #fff; }
  .a-csv  { background: #334155; color: #e2e8f0; }
  .a-new  { background: transparent; color: #93c5fd; border: 1px solid #334155; }
  iframe { width: 100%; height: 78vh; border: 1px solid #1e293b; border-radius: 12px;
           background: #0f172a; margin-top: 4px; }
</style>
</head>
<body>
<h1>🔗 Broken Link Bulk Scanner</h1>
<div class="sub">Enter a URL, crawl the site, and see every dead link in one dashboard.</div>

<?php if ($extError): ?>
  <div class="err"><?= e($extError) ?></div>
<?php endif; ?>

<?php if (!$isScan): /* ---------- the form ---------- */ ?>
<form class="panel" method="get" action="">
  <input type="hidden" name="submitted" value="1">
  <div>
    <label for="url">Website URL</label>
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
      <label for="max-pages">Max pages</label>
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

  <button class="go" type="submit" <?= $extError ? 'disabled' : '' ?>>Scan for broken links →</button>
</form>

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

      $broken   = (int)($agg['broken'] ?? 0);
      $total    = (int)($agg['totalLinks'] ?? 0);
      $headline = "Scan complete — {$broken} broken of {$total} links tested.";

      echo '<div class="result"><h2>✅ ' . e($headline) . '</h2>'
         . '<div class="actions">'
         . '<a class="a-html" href="' . e($htmlRel) . '" target="_blank">Open full report ↗</a>'
         . '<a class="a-csv" href="' . e($csvRel) . '" download>Download CSV</a>'
         . '<a class="a-new" href="?">← New scan</a>'
         . '</div></div>';
      echo '<iframe src="' . e($htmlRel) . '" title="Broken link report"></iframe>';
  }

endif; ?>
</body>
</html>
