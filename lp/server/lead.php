<?php
// Verdexo landing page: saves each lead (forms and chat) to the database, for the private /leads/ page.
// Deployed to public_html/lead.php. The page also emails every lead through FormSubmit, so if this file or the
// database is ever down, leads still reach the inbox (and the other way round).

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

require __DIR__ . '/leads/lib.php';

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') out(['ok' => false, 'error' => 'method'], 405);

// only accept leads from the landing page itself (and local testing)
$from = $_SERVER['HTTP_ORIGIN'] ?? ($_SERVER['HTTP_REFERER'] ?? '');
if (!preg_match('#^(https?://(www\.)?verdexoventures\.com|http://(localhost|127\.0\.0\.1)(:\d+)?)(/|$)#', $from)) {
    out(['ok' => false, 'error' => 'origin'], 403);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) out(['ok' => false, 'error' => 'bad-request'], 400);

// bots fill in the hidden "company_website" field that people never see: say thanks, store nothing
if (trim((string)($in['company_website'] ?? '')) !== '') out(['ok' => true]);

$name = clip($in['name'] ?? '', 120);
$phone = clip($in['phone'] ?? '', 40);
if ($name === '' || strlen(digits_of($phone)) < 8) out(['ok' => false, 'error' => 'missing'], 400);

// at most 8 leads per visitor per 10 minutes, so a bot can't flood the list
[$rlFile, $hits, $over] = hit_limit('lead', 8, 600);
if ($over) out(['ok' => false, 'error' => 'rate'], 429);
hit_add($rlFile, $hits);

$tracking = [];
foreach (['gclid', 'gbraid', 'wbraid', 'fbclid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'] as $k) {
    if (!empty($in[$k])) $tracking[$k] = clip($in[$k], 200);
}
$forms = ['lead-form' => 'Free growth plan', 'quick-form' => 'Call back', 'chat' => 'AI chat'];
$form = $forms[(string)($in['form'] ?? '')] ?? 'Other';
if ($form === 'AI chat' && !empty($in['mode'])) $form .= ' (' . clip($in['mode'], 20) . ')';

try {
    $now = now_str();
    leads_db()->prepare('INSERT INTO leads (created_at, updated_at, name, phone, business, needs, form, transcript, tracking, page, status, notes, follow_up)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
        $now, $now, $name, $phone,
        clip($in['business'] ?? '', 200),
        clip($in['needs'] ?? '', 300),
        $form,
        clip($in['transcript'] ?? '', 20000),
        $tracking ? json_encode($tracking) : '',
        clip($in['page'] ?? '', 300),
        'new', '', '',
    ]);
} catch (Throwable $e) {
    error_log('lead.php: ' . $e->getMessage());
    out(['ok' => false, 'error' => 'storage'], 500);
}
out(['ok' => true]);
