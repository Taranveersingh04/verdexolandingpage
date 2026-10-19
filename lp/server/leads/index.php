<?php
// Verdexo leads: a private page to work through landing page leads (call, WhatsApp, status, notes, follow-ups).
// Deployed to public_html/leads/index.php, so it opens at verdexoventures.com/leads/. Password in leads-config.php.

require __DIR__ . '/lib.php';

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

$https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
session_name('vx_leads');
session_set_cookie_params(['lifetime' => 0, 'path' => '/leads/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf'];
$self = strtok($_SERVER['REQUEST_URI'] ?? '/leads/', '?');

function go($to) { header('Location: ' . $to, true, 303); exit; }
function check_password($given) {
    $want = (string)(leads_config()['admin_password'] ?? '');
    if ($want === '' || stripos($want, 'CHANGE') !== false) return false;
    return str_starts_with($want, '$2y$') ? password_verify($given, $want) : hash_equals($want, $given);
}

// ---- log in / out ---------------------------------------------------------------------------------------
$loginError = '';
if (($_POST['action'] ?? '') === 'login') {
    [$file, $hits, $locked] = hit_limit('login', 10, 900);   // 10 wrong passwords, then a 15-minute pause
    if ($locked) $loginError = 'Too many tries. Please wait 15 minutes.';
    elseif (check_password((string)($_POST['password'] ?? ''))) {
        session_regenerate_id(true); $_SESSION['in'] = true; go($self);
    } else { hit_add($file, $hits); $loginError = 'That password is not right.'; }
}
$configured = (string)(leads_config()['admin_password'] ?? '') !== '' && stripos((string)leads_config()['admin_password'], 'CHANGE') === false;

if (empty($_SESSION['in'])) { page_start('Leads · Log in'); ?>
  <main class="login">
    <h1>Verdexo leads</h1>
    <?php if (!$configured): ?><p class="err">Set <b>admin_password</b> in leads-config.php first.</p><?php endif; ?>
    <form method="post">
      <input type="hidden" name="action" value="login" />
      <label>Password<input type="password" name="password" autocomplete="current-password" autofocus required /></label>
      <?php if ($loginError): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
      <button class="btn">Log in</button>
    </form>
  </main>
<?php page_end(); exit; }

// ---- changes (every change needs the page's token, so other sites can't make them) ------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['t'] ?? ''))) { http_response_code(400); exit('Please reload the page and try again.'); }
    $act = $_POST['action'] ?? ''; $id = (int)($_POST['id'] ?? 0); $back = (string)($_POST['back'] ?? $self);
    if (!str_starts_with($back, '/leads/')) $back = $self;
    if ($act === 'logout') { $_SESSION = []; session_destroy(); go($self); }
    if ($act === 'save' && $id) {
        $status = array_key_exists($_POST['status'] ?? '', LEAD_STATUSES) ? $_POST['status'] : 'new';
        $follow = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_POST['follow_up'] ?? '')) ? $_POST['follow_up'] : '';
        if (in_array($status, ['client', 'lost'], true)) $follow = '';   // closed: nothing left to follow up
        leads_db()->prepare('UPDATE leads SET status = ?, notes = ?, follow_up = ?, updated_at = ? WHERE id = ?')
            ->execute([$status, clip($_POST['notes'] ?? '', 5000), $follow, now_str(), $id]);
        go($back . '#lead-' . $id);
    }
    if ($act === 'delete' && $id) {   // for anyone who asks to be forgotten
        leads_db()->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        go($back);
    }
    go($self);
}

$db = leads_db();
// keep the privacy policy's promise: enquiries that never became clients go after 18 months without activity
$db->prepare('DELETE FROM leads WHERE status <> ? AND updated_at < ?')->execute(['client', date('Y-m-d H:i:s', strtotime('-' . LEAD_KEEP_MONTHS . ' months'))]);

// ---- spreadsheet export ------------------------------------------------------------------------------------
if (($_GET['export'] ?? '') === 'csv' && hash_equals($csrf, (string)($_GET['t'] ?? ''))) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="verdexo-leads-' . date('Y-m-d') . '.csv"');
    $outf = fopen('php://output', 'w');
    fwrite($outf, "\xEF\xBB\xBF");   // so Excel reads Hindi and ₹ correctly
    $csv = fn($row) => fputcsv($outf, $row, ',', '"', '');   // explicit escape: newer PHP warns without it
    $csv(['Received (IST)', 'Name', 'Phone', 'Business', 'Wants help with', 'Came from', 'Status', 'Follow-up', 'Notes', 'gclid', 'utm_source', 'utm_campaign', 'utm_term']);
    foreach ($db->query('SELECT * FROM leads ORDER BY id DESC') as $r) {
        $tr = json_decode((string)$r['tracking'], true) ?: [];
        // a leading = + - @ would run as a formula in Excel; a quote mark keeps it as plain text
        $cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
        $csv(array_map($cell, [$r['created_at'], $r['name'], $r['phone'], $r['business'], $r['needs'], $r['form'],
            LEAD_STATUSES[$r['status']] ?? $r['status'], $r['follow_up'], $r['notes'], $tr['gclid'] ?? '', $tr['utm_source'] ?? '', $tr['utm_campaign'] ?? '', $tr['utm_term'] ?? '']));
    }
    exit;
}

// ---- the list ------------------------------------------------------------------------------------------------
$filter = array_key_exists($_GET['status'] ?? '', LEAD_STATUSES) ? $_GET['status'] : '';
$q = clip($_GET['q'] ?? '', 80);
$counts = array_fill_keys(array_keys(LEAD_STATUSES), 0);
foreach ($db->query('SELECT status, COUNT(*) n FROM leads GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
$total = array_sum($counts);

$today = date('Y-m-d');
$due = $db->prepare("SELECT * FROM leads WHERE follow_up <> '' AND follow_up <= ? AND status IN ('new', 'contacted', 'interested') ORDER BY follow_up, id");
$due->execute([$today]); $due = $due->fetchAll();

$sql = 'SELECT * FROM leads WHERE 1 = 1'; $args = [];
if ($filter) { $sql .= ' AND status = ?'; $args[] = $filter; }
if ($q !== '') { $sql .= ' AND (name LIKE ? OR phone LIKE ? OR business LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
$list = $db->prepare($sql . ' ORDER BY id DESC LIMIT 300'); $list->execute($args); $list = $list->fetchAll();
$back = $_SERVER['REQUEST_URI'] ?? $self;

function when($s) {
    $t = strtotime($s); $d = time() - $t;
    if ($d < 3600) return max(1, (int)round($d / 60)) . ' min ago';
    if ($d < 86400 && date('Y-m-d', $t) === date('Y-m-d')) return 'Today, ' . date('g:i A', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, ' . date('g:i A', $t);
    return date('Y', $t) === date('Y') ? date('j M, g:i A', $t) : date('j M Y', $t);
}
function lead_card($r, $csrf, $back) {
    $first = strtok(trim($r['name']), ' ');
    $msg = "Hi $first, this is the Verdexo team. You asked for a free growth plan" . ($r['business'] !== '' ? ' for ' . $r['business'] : '') . ". When's a good time for a quick 5-minute call?";
    $tr = json_decode((string)$r['tracking'], true) ?: [];
    $late = $r['follow_up'] !== '' && $r['follow_up'] < date('Y-m-d') && in_array($r['status'], ['new', 'contacted', 'interested'], true);
    ?>
    <article class="lead s-<?= h($r['status']) ?>" id="lead-<?= (int)$r['id'] ?>">
      <header>
        <div><h3><?= h($r['name']) ?></h3><p><?= $r['business'] !== '' ? h($r['business']) . ' · ' : '' ?><?= h(when($r['created_at'])) ?> · <?= h($r['form']) ?></p></div>
        <span class="pill"><?= h(LEAD_STATUSES[$r['status']] ?? $r['status']) ?></span>
      </header>
      <?php if ($r['needs'] !== ''): ?><p class="needs">Wants help with: <b><?= h($r['needs']) ?></b></p><?php endif; ?>
      <div class="acts">
        <a class="btn" href="tel:+<?= h(wa_number($r['phone'])) ?>">Call <?= h($r['phone']) ?></a>
        <a class="btn wa" href="https://wa.me/<?= h(wa_number($r['phone'])) ?>?text=<?= rawurlencode($msg) ?>" target="_blank" rel="noopener">WhatsApp</a>
      </div>
      <?php if ($r['follow_up'] !== ''): ?><p class="due<?= $late ? ' late' : '' ?>">Follow up: <?= h(date('D j M', strtotime($r['follow_up']))) ?><?= $late ? ' (overdue)' : '' ?></p><?php endif; ?>
      <form method="post" class="edit">
        <input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="save" />
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" /><input type="hidden" name="back" value="<?= h($back) ?>" />
        <div class="row">
          <label>Status<select name="status"><?php foreach (LEAD_STATUSES as $k => $v): ?><option value="<?= h($k) ?>"<?= $k === $r['status'] ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></label>
          <label>Follow up on<input type="date" name="follow_up" value="<?= h($r['follow_up']) ?>" /></label>
        </div>
        <label>Notes<textarea name="notes" rows="2" placeholder="e.g. Wants ads for 2 clinics. Call after 6 PM."><?= h($r['notes']) ?></textarea></label>
        <button class="btn">Save</button>
      </form>
      <?php if ($r['transcript'] || $tr): ?>
      <details><summary>More details</summary>
        <?php if ($r['transcript']): ?><p class="lbl">Chat</p><pre><?= h($r['transcript']) ?></pre><?php endif; ?>
        <?php if ($tr): ?><p class="lbl">Ad tracking</p><p class="small"><?php foreach ($tr as $k => $v) echo h($k) . ': ' . h($v) . '<br>'; ?></p><?php endif; ?>
      </details>
      <?php endif; ?>
      <form method="post" class="del" onsubmit="return confirm('Delete <?= h(addslashes($r['name'])) ?> for good? Only do this if they asked us to delete their details.')">
        <input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="delete" />
        <input type="hidden" name="id" value="<?= (int)$r['id'] ?>" /><input type="hidden" name="back" value="<?= h($back) ?>" />
        <button>Delete</button>
      </form>
    </article>
<?php }

page_start('Leads · Verdexo'); ?>
  <main>
    <div class="top">
      <h1>Leads <small><?= $total ?></small></h1>
      <form method="post"><input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="logout" /><button class="link">Log out</button></form>
    </div>
    <nav class="tabs">
      <a href="<?= h($self) ?>"<?= $filter === '' ? ' class="on"' : '' ?>>All <span><?= $total ?></span></a>
      <?php foreach (LEAD_STATUSES as $k => $v): ?><a href="?status=<?= h($k) ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= h($v) ?> <span><?= $counts[$k] ?></span></a><?php endforeach; ?>
    </nav>
    <form class="search" method="get"><?php if ($filter): ?><input type="hidden" name="status" value="<?= h($filter) ?>" /><?php endif; ?><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, phone or business" /><button class="btn">Search</button></form>

    <?php if ($due && !$filter && $q === ''): ?>
      <h2>Follow up today <span><?= count($due) ?></span></h2>
      <?php foreach ($due as $r) lead_card($r, $csrf, $back); ?>
      <h2>All leads</h2>
    <?php endif; ?>
    <?php if (!$list): ?><p class="empty"><?= $total ? 'No leads match.' : 'No leads yet. New ones from the landing page will appear here.' ?></p><?php endif; ?>
    <?php foreach ($list as $r) lead_card($r, $csrf, $back); ?>
    <p class="foot"><a href="?export=csv&amp;t=<?= h($csrf) ?>">Download all leads (Excel / CSV)</a> · Leads that never became clients are deleted after <?= LEAD_KEEP_MONTHS ?> months without activity, as our privacy policy promises.</p>
  </main>
<?php page_end();

function page_start($title) { ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta name="robots" content="noindex, nofollow" />
<title><?= h($title) ?></title>
<style>
:root { --sans: Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; --ink: #2c4660; --text: #4f6578; --muted: #586a7b; --line: #e1e6ec; --page: #f5f6f8; --surface: #eef1f5; --violet: #8a63cc; --violet-d: #6c48b2; --field: #8796a5; }
* { box-sizing: border-box; margin: 0; }
body { background: var(--page); color: var(--text); font: 400 15px/1.5 var(--sans); -webkit-font-smoothing: antialiased; }
main { width: min(760px, 100% - 24px); margin: 0 auto; padding: 20px 0 48px; }
h1 { color: var(--ink); font-size: 26px; letter-spacing: -.02em; } h1 small { font-size: 15px; color: var(--muted); font-weight: 500; }
h2 { margin: 26px 0 10px; color: var(--ink); font-size: 17px; } h2 span { color: #b42318; }
.top { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.btn { display: inline-flex; align-items: center; justify-content: center; height: 42px; padding: 0 16px; border: 0; border-radius: 999px; background: linear-gradient(180deg, #45627f, #2c4660); color: #fff; font: 600 14px/1 var(--sans); text-decoration: none; cursor: pointer; }
.btn.wa { background: #1fa855; }
.link { border: 0; background: none; color: var(--violet-d); font: 600 14px var(--sans); cursor: pointer; }
.tabs { display: flex; gap: 6px; margin-top: 14px; overflow-x: auto; padding-bottom: 4px; scrollbar-width: none; }
.tabs a { flex: none; padding: 7px 12px; border-radius: 999px; background: #fff; box-shadow: 0 0 0 1px var(--line); color: var(--ink); font-size: 13.5px; font-weight: 500; text-decoration: none; }
.tabs a span { color: var(--muted); } .tabs a.on { background: var(--ink); color: #fff; box-shadow: none; } .tabs a.on span { color: #c9d4df; }
.search { display: flex; gap: 8px; margin-top: 12px; }
.search input { flex: 1; min-width: 0; height: 42px; padding: 0 14px; border: 0; border-radius: 999px; background: #fff; box-shadow: inset 0 0 0 1.5px var(--line); font: 16px var(--sans); }
.lead { margin-top: 12px; padding: 16px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 1px var(--line), 0 6px 18px -14px rgba(44, 70, 96, .4); }
.lead.s-new { box-shadow: 0 0 0 2px var(--violet), 0 6px 18px -14px rgba(44, 70, 96, .4); }
.lead header { display: flex; justify-content: space-between; gap: 10px; align-items: flex-start; }
.lead h3 { color: var(--ink); font-size: 17px; } .lead header p { font-size: 13px; color: var(--muted); }
.pill { flex: none; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 600; background: var(--surface); color: var(--ink); }
.s-new .pill { background: #efe8fb; color: var(--violet-d); } .s-interested .pill { background: #fff4d6; color: #8a5a00; }
.s-client .pill { background: #dcf5e4; color: #166534; } .s-lost .pill { background: #f2f2f2; color: #6b7280; }
.needs { margin-top: 8px; font-size: 14px; }
.acts { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; } .acts .btn { flex: 1 1 140px; }
.due { margin-top: 10px; font-size: 13.5px; font-weight: 600; color: var(--ink); } .due.late { color: #b42318; }
.edit { margin-top: 12px; padding-top: 12px; border-top: 1px solid var(--line); display: grid; gap: 10px; }
.row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
label { display: grid; gap: 4px; font-size: 12.5px; font-weight: 600; color: var(--muted); }
select, input[type=date], textarea, input[type=password] { width: 100%; padding: 10px 12px; border: 0; border-radius: 12px; box-shadow: inset 0 0 0 1.5px var(--field); background: #fff; color: var(--ink); font: 16px/1.4 var(--sans); }
select:focus, input:focus, textarea:focus { outline: none; box-shadow: inset 0 0 0 2px var(--violet); }
.edit .btn { justify-self: start; }
details { margin-top: 10px; font-size: 13.5px; } summary { cursor: pointer; color: var(--violet-d); font-weight: 600; }
.lbl { margin-top: 8px; font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; }
pre { margin-top: 4px; padding: 10px; border-radius: 10px; background: var(--surface); white-space: pre-wrap; font: 13px/1.5 var(--sans); color: var(--ink); }
.small { font-size: 12.5px; color: var(--muted); overflow-wrap: anywhere; }
.del { margin-top: 8px; text-align: right; } .del button { border: 0; background: none; color: #9aa4ae; font: 12.5px var(--sans); cursor: pointer; }
.del button:hover { color: #b42318; }
.empty { margin-top: 24px; color: var(--muted); }
.foot { margin-top: 28px; font-size: 12.5px; color: var(--muted); } .foot a { color: var(--violet-d); font-weight: 600; }
.login { width: min(360px, 100% - 32px); padding-top: 18vh; } .login form { display: grid; gap: 12px; margin-top: 18px; }
.err { color: #b42318; font-size: 13.5px; }
@media (max-width: 420px) { .row { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<?php }
function page_end() { ?>
</body>
</html>
<?php }
