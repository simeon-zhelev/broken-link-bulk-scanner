<?php
declare(strict_types=1);

require dirname(__DIR__) . '/link_checker.php';

$failures = [];
$checks = 0;

function check(bool $condition, string $message): void {
    global $failures, $checks;
    $checks++;
    if (!$condition) $failures[] = $message;
}

// Query-only references replace the current query without dropping the page.
check(
    normalizeUrl('?new=1', 'https://example.com/dir/page?old=1')
        === 'https://example.com/dir/page?new=1',
    'query-only URL resolution must preserve the current document path'
);

// A matching crawler-specific group takes precedence over the wildcard group.
$groups = parse_robots(<<<ROBOTS
User-agent: BrokenLinkBulkScanner
Disallow: /private
Allow: /private/public

User-agent: *
Disallow:
ROBOTS);
$group = robots_group_for_user_agent($groups, DEFAULT_UA);
check(!robots_allowed('/private/secret', $group), 'named robots group must disallow private paths');
check(robots_allowed('/private/public/info', $group), 'longer Allow rule must win');

// A followed redirect remains a redirect unless the terminal response failed.
check(classify(200, 0, 1)[0] === 'redirect', '301 -> 200 must classify as redirect');
check(classify(404, 0, 1)[0] === 'client', '301 -> 404 must classify by terminal failure');

// Page fetch failures must survive into both generated artifacts.
$crawl = [
    'results' => [],
    'pageErrors' => ['https://example.com/bad?<script>' => 'HTTP 404'],
    'pagesFetched' => 1,
    'startHost' => 'example.com',
    'start' => 'https://example.com/',
];
$agg = aggregate($crawl);
$args = [
    'url' => 'https://example.com/',
    'mode' => 'site',
    'check-assets' => true,
    'respect-robots' => true,
    'render' => false,
    'concurrency' => 2,
];
$html = build_html($crawl, $agg, $args, '2026-01-01 00:00');
$csv = build_csv($crawl);
check(str_contains($html, 'Pages That Failed To Load'), 'HTML must include the page-failure section');
check(str_contains($html, 'HTTP 404'), 'HTML must include the page failure reason');
check(!str_contains($html, 'bad?<script>'), 'page failure URLs must be HTML escaped');
check(
    str_contains($html, '<button type="button" class="card card-link active" data-filter="all"'),
    'All Tested Links must highlight the All status card by default'
);
check(str_contains($html, "setFilter('all');"), 'All Tested Links must show every row by default');
check(
    substr_count($html, 'class="card card-link') === 7,
    'All Tested Links must render All plus six status filter cards'
);
check(
    str_contains($html, '<div class="section-title">🔗 All Tested Links</div>'),
    'status cards and tested links must share one section'
);
check(!str_contains($html, 'Links by Status'), 'separate Links by Status section must be removed');
check(!str_contains($html, 'Broken only'), 'legacy filter pills must be removed');
check(!str_contains($html, '<div class="stats">'), 'redundant broken/internal/external totals must be removed');
check(
    str_contains($html, 'grid-template-columns:repeat(9,minmax(0,1fr))'),
    'report metadata must use a compact nine-column desktop row'
);
check(
    str_contains($html, "card.setAttribute('aria-pressed', active ? 'true' : 'false')"),
    'status filter cards must expose their active state accessibly'
);
check(
    str_contains(status_badge(['code' => 0, 'class' => 'placeholder']), 'badge-error'),
    'ERR status badges must use the red error class'
);
check(
    str_contains($html, '.tc-placeholder { color:var(--bad); }')
        && str_contains($html, '.badge-placeholder { background:var(--bad); }'),
    'Empty / placeholder indicators must use the red error colour'
);
check(
    !str_contains($html, 'Broken link report · powered by 2create'),
    'report header must omit the powered-by text'
);
check(str_contains($csv, 'page_error'), 'CSV must include page-error records');
check($agg['pageFailures'] === 1, 'aggregate must count page failures');

// The PDF is intentionally concise, while the HTML report remains exhaustive.
$detailedCrawl = [
    'results' => [
        'https://example.com/missing-one' => [
            'url' => 'https://example.com/missing-one', 'code' => 404,
            'class' => 'client', 'label' => 'Client error',
            'final' => 'https://example.com/missing-one', 'method' => 'HEAD',
            'redirects' => 0, 'type' => 'a', 'source' => 'https://example.com/source-alpha',
            'pages' => ['https://example.com/source-alpha', 'https://example.com/source-beta'], 'pageCount' => 2,
            'error' => '', 'internal' => true,
        ],
        'https://example.com/missing-two' => [
            'url' => 'https://example.com/missing-two', 'code' => 404,
            'class' => 'client', 'label' => 'Client error',
            'final' => 'https://example.com/missing-two', 'method' => 'HEAD',
            'redirects' => 0, 'type' => 'a', 'source' => 'https://example.com/source-gamma',
            'pages' => ['https://example.com/source-gamma'], 'pageCount' => 1,
            'error' => '', 'internal' => true,
        ],
        'https://example.com/healthy' => [
            'url' => 'https://example.com/healthy', 'code' => 200,
            'class' => 'ok', 'label' => 'OK',
            'final' => 'https://example.com/healthy', 'method' => 'HEAD',
            'redirects' => 0, 'type' => 'a', 'source' => 'https://example.com/',
            'pages' => ['https://example.com/'], 'pageCount' => 1,
            'error' => '', 'internal' => true,
        ],
    ],
    'pageErrors' => [],
    'pagesFetched' => 1,
    'startHost' => 'example.com',
    'start' => 'https://example.com/',
];
$detailedAgg = aggregate($detailedCrawl);
$detailedHtml = build_html($detailedCrawl, $detailedAgg, $args, '2026-01-01 00:00');
$pdfHtml = build_pdf_html($detailedCrawl, $detailedAgg, $args, '2026-01-01 00:00');
check(str_contains($detailedHtml, 'https://example.com/missing-one'), 'HTML must retain the first error instance');
check(str_contains($detailedHtml, 'https://example.com/missing-two'), 'HTML must retain the second error instance');
check(str_contains($detailedHtml, 'https://example.com/source-alpha'), 'HTML must retain detailed source pages');
check($pdfHtml === $detailedHtml, 'PDF and HTML exports must use the same report template');
check(str_contains($pdfHtml, 'All Tested Links'), 'PDF must include the exhaustive tested-links table');
check(str_contains($pdfHtml, 'https://example.com/source-alpha'), 'PDF must retain detailed source pages');
check(str_contains($pdfHtml, '@page { size:A4 landscape; }'), 'PDF print CSS must request A4 landscape');
check(
    str_contains($pdfHtml, '.report-meta .rm-item:not(.rm-site) { display:none; }'),
    'PDF must hide secondary scan metadata while retaining the site'
);
check(
    str_contains($pdfHtml, 'html { font-size:13px; }')
        && str_contains($pdfHtml, 'table { font-size:.72rem; line-height:1.25; }')
        && str_contains($pdfHtml, 'th,td { padding:4px 5px; }'),
    'PDF must use condensed print typography and table spacing'
);
check(
    !str_contains($pdfHtml, 'tr[data-class="ok"] { display: none'),
    'PDF must retain passing links just like the HTML report'
);
$runnerSource = file_get_contents(dirname(__DIR__) . '/render-runner.js');
check($runnerSource !== false, 'PDF renderer source must be readable');
check(
    str_contains((string)$runnerSource, 'landscape: true'),
    'PDF renderer must print in landscape mode'
);

// Edit & rescan must reveal the populated form client-side, matching the
// Accessibility Bulk Scanner interaction instead of navigating to an empty URL.
$webSource = file_get_contents(dirname(__DIR__) . '/index.php');
check($webSource !== false, 'web frontend source must be readable');
check(
    str_contains((string)$webSource, 'type="button" class="edit-btn" id="editBtn"'),
    'Edit & rescan must be a client-side button'
);
check(
    str_contains((string)$webSource, "form.classList.remove('minimized')"),
    'Edit & rescan must expand the populated scan form'
);
check(
    !str_contains((string)$webSource, '<a class="edit-btn" href="?">'),
    'Edit & rescan must not discard settings through navigation'
);
check(
    str_contains((string)$webSource, '.scan-run { padding-top:12px; }'),
    'scanning status must have extra spacing above it'
);

// The web frontend installs this kind of writer around crawl progress.
$renderedProgress = '';
set_progress_writer(static function (string $message) use (&$renderedProgress): void {
    $renderedProgress .= htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
});
progress('url"><script>alert(1)</script>');
set_progress_writer(null);
check(!str_contains($renderedProgress, '<script>'), 'web progress must render untrusted URLs as text');
check(str_contains($renderedProgress, '&lt;script&gt;'), 'web progress must retain an escaped diagnostic');

// Text truncation works for Unicode whether or not mbstring is installed.
check(truncate_text('абвг', 3) === 'абв…', 'UTF-8 truncation must be character-safe');

if ($failures) {
    fwrite(STDERR, "Regression failures:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "OK ({$checks} checks)\n";
