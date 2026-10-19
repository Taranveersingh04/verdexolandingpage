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
    <div class="login-card">
      <i class="mark" aria-hidden="true"></i>
      <h1>Verdexo leads</h1>
      <p class="sub">Private. For the Verdexo team only.</p>
      <?php if (!$configured): ?><p class="err">Set <b>admin_password</b> in leads-config.php first.</p><?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="login" />
        <label class="field"><span>Password</span><input type="password" name="password" autocomplete="current-password" autofocus required /></label>
        <?php if ($loginError): ?><p class="err"><?= h($loginError) ?></p><?php endif; ?>
        <button class="btn primary">Log in</button>
      </form>
    </div>
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
$dueOnly = !$filter && ($_GET['due'] ?? '') === '1';
$q = clip($_GET['q'] ?? '', 80);
$counts = array_fill_keys(array_keys(LEAD_STATUSES), 0);
foreach ($db->query('SELECT status, COUNT(*) n FROM leads GROUP BY status') as $r) $counts[$r['status']] = (int)$r['n'];
$total = array_sum($counts);

$today = date('Y-m-d');
$due = $db->prepare("SELECT * FROM leads WHERE follow_up <> '' AND follow_up <= ? AND status IN ('new', 'contacted', 'interested') ORDER BY follow_up, id");
$due->execute([$today]); $due = $due->fetchAll();

if ($dueOnly) { $list = $due; }
else {
    $sql = 'SELECT * FROM leads WHERE 1 = 1'; $args = [];
    if ($filter) { $sql .= ' AND status = ?'; $args[] = $filter; }
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR phone LIKE ? OR business LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%"); }
    $list = $db->prepare($sql . ' ORDER BY id DESC LIMIT 300'); $list->execute($args); $list = $list->fetchAll();
}
$back = $_SERVER['REQUEST_URI'] ?? $self;
$heading = $dueOnly ? 'Follow up today' : ($filter ? LEAD_STATUSES[$filter] : ($q !== '' ? 'Search results' : 'All leads'));

function when($s) {
    $t = strtotime($s); $d = time() - $t;
    if ($d < 3600) return max(1, (int)round($d / 60)) . ' min ago';
    if ($d < 86400 && date('Y-m-d', $t) === date('Y-m-d')) return 'Today, ' . date('g:i A', $t);
    if (date('Y-m-d', $t) === date('Y-m-d', strtotime('-1 day'))) return 'Yesterday, ' . date('g:i A', $t);
    return date('Y', $t) === date('Y') ? date('j M, g:i A', $t) : date('j M Y', $t);
}
function initials($name) {
    $parts = preg_split('/\s+/', trim($name)) ?: [''];
    $first = function_exists('mb_substr') ? mb_substr($parts[0], 0, 1) : substr($parts[0], 0, 1);
    $last = count($parts) > 1 ? (function_exists('mb_substr') ? mb_substr(end($parts), 0, 1) : substr(end($parts), 0, 1)) : '';
    return strtoupper($first . $last);
}
function icon($name) {
    $p = [
        'call' => '<path d="M6.6 3.5h2.6l1.4 4-2 1.3a11 11 0 0 0 6.6 6.6l1.3-2 4 1.4v2.6a2 2 0 0 1-2.2 2A16 16 0 0 1 4.6 5.7a2 2 0 0 1 2-2.2z"/>',
        'wa' => '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M9.2 8.2c.3 2.6 3.9 6.3 6.6 6.6"/>',
        'cal' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M8 3v4M16 3v4M3 10h18"/>',
        'chev' => '<path d="m9 6 6 6-6 6"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
        'down' => '<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>',
    ][$name];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}
function lead_card($r, $csrf, $back) {
    $first = strtok(trim($r['name']), ' ');
    $msg = "Hi $first, this is the Verdexo team. You asked for a free growth plan" . ($r['business'] !== '' ? ' for ' . $r['business'] : '') . ". When's a good time for a quick 5-minute call?";
    $tr = json_decode((string)$r['tracking'], true) ?: [];
    $open = in_array($r['status'], ['new', 'contacted', 'interested'], true);
    $late = $r['follow_up'] !== '' && $r['follow_up'] < date('Y-m-d') && $open;
    $todayDue = $r['follow_up'] === date('Y-m-d') && $open;
    $id = (int)$r['id'];
    ?>
    <article class="lead s-<?= h($r['status']) ?>" id="lead-<?= $id ?>">
      <div class="top">
        <span class="av" aria-hidden="true"><?= h(initials($r['name'])) ?></span>
        <div class="who">
          <h3><?= h($r['name']) ?></h3>
          <?php if ($r['business'] !== ''): ?><p><?= h($r['business']) ?></p><?php endif; ?>
          <p class="ph"><?= h($r['phone']) ?></p>
        </div>
        <span class="pill"><?= h(LEAD_STATUSES[$r['status']] ?? $r['status']) ?></span>
      </div>
      <div class="meta">
        <span><?= h(when($r['created_at'])) ?></span><?php if ($r['form'] !== ''): ?><span><?= h($r['form']) ?></span><?php endif; ?>
        <?php if ($r['follow_up'] !== ''): ?><span class="fu<?= $late ? ' late' : ($todayDue ? ' today' : '') ?>"><?= icon('cal') ?><?= $late ? 'Overdue · ' : ($todayDue ? 'Today · ' : '') ?><?= h(date('D j M', strtotime($r['follow_up']))) ?></span><?php endif; ?>
      </div>
      <?php if ($r['needs'] !== ''): ?><p class="needs"><span>Wants help with</span><?= h($r['needs']) ?></p><?php endif; ?>
      <?php if (trim((string)$r['notes']) !== ''): ?><p class="note"><?= h($r['notes']) ?></p><?php endif; ?>
      <div class="acts">
        <a class="btn call" href="tel:+<?= h(wa_number($r['phone'])) ?>"><?= icon('call') ?>Call</a>
        <a class="btn wa" href="https://wa.me/<?= h(wa_number($r['phone'])) ?>?text=<?= rawurlencode($msg) ?>" target="_blank" rel="noopener"><?= icon('wa') ?>WhatsApp</a>
      </div>
      <details class="upd">
        <summary><span>Update status, follow-up and notes</span><?= icon('chev') ?></summary>
        <form method="post" class="edit">
          <input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="save" />
          <input type="hidden" name="id" value="<?= $id ?>" /><input type="hidden" name="back" value="<?= h($back) ?>" />
          <fieldset class="st"><legend>Status</legend>
            <?php foreach (LEAD_STATUSES as $k => $v): ?><label class="s-<?= h($k) ?>"><input type="radio" name="status" value="<?= h($k) ?>"<?= $k === $r['status'] ? ' checked' : '' ?> /><span><?= h($v) ?></span></label><?php endforeach; ?>
          </fieldset>
          <label class="field"><span>Follow up on</span><input type="date" name="follow_up" value="<?= h($r['follow_up']) ?>" /></label>
          <label class="field"><span>Notes</span><textarea name="notes" rows="3" placeholder="e.g. Wants ads for 2 clinics. Call after 6 PM."><?= h($r['notes']) ?></textarea></label>
          <button class="btn primary">Save</button>
        </form>
        <?php if ($r['transcript'] || $tr || $r['page']): ?>
          <div class="extra">
            <?php if ($r['transcript']): ?><p class="lbl">Chat</p><pre><?= h($r['transcript']) ?></pre><?php endif; ?>
            <?php if ($tr): ?><p class="lbl">Ad tracking</p><p class="small"><?php foreach ($tr as $k => $v) echo '<span>' . h($k) . '</span> ' . h($v) . '<br>'; ?></p><?php endif; ?>
          </div>
        <?php endif; ?>
        <form method="post" class="del" onsubmit="return confirm('Delete <?= h(addslashes($r['name'])) ?> for good? Only do this if they asked us to delete their details.')">
          <input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="delete" />
          <input type="hidden" name="id" value="<?= $id ?>" /><input type="hidden" name="back" value="<?= h($back) ?>" />
          <button>Delete this lead</button>
        </form>
      </details>
    </article>
<?php }

page_start('Leads · Verdexo'); ?>
  <header class="bar">
    <div class="bar-in">
      <a class="brand" href="<?= h($self) ?>"><i class="mark" aria-hidden="true"></i><span>Leads</span></a>
      <div class="bar-r">
        <a class="ghost" href="?export=csv&amp;t=<?= h($csrf) ?>" title="Download all leads for Excel"><?= icon('down') ?><span>Export</span></a>
        <form method="post"><input type="hidden" name="t" value="<?= h($csrf) ?>" /><input type="hidden" name="action" value="logout" /><button class="ghost">Log out</button></form>
      </div>
    </div>
  </header>
  <main>
    <section class="stats" aria-label="Summary">
      <a class="stat<?= $filter === 'new' ? ' on' : '' ?>" href="?status=new"><b><?= $counts['new'] ?></b><span>New</span></a>
      <a class="stat due<?= $dueOnly ? ' on' : '' ?><?= $due ? ' hot' : '' ?>" href="?due=1"><b><?= count($due) ?></b><span>Follow up today</span></a>
      <a class="stat<?= $filter === 'interested' ? ' on' : '' ?>" href="?status=interested"><b><?= $counts['interested'] ?></b><span>Interested</span></a>
      <a class="stat<?= $filter === 'client' ? ' on' : '' ?>" href="?status=client"><b><?= $counts['client'] ?></b><span>Clients</span></a>
    </section>

    <form class="search" method="get" role="search">
      <?php if ($filter): ?><input type="hidden" name="status" value="<?= h($filter) ?>" /><?php endif; ?>
      <?= icon('search') ?><input type="search" name="q" value="<?= h($q) ?>" placeholder="Search name, phone or business" aria-label="Search leads" />
    </form>
    <nav class="tabs" aria-label="Filter by status">
      <a href="<?= h($self) ?>"<?= !$filter && !$dueOnly ? ' class="on"' : '' ?>>All <span><?= $total ?></span></a>
      <?php foreach (LEAD_STATUSES as $k => $v): ?><a href="?status=<?= h($k) ?>"<?= $filter === $k ? ' class="on"' : '' ?>><?= h($v) ?> <span><?= $counts[$k] ?></span></a><?php endforeach; ?>
    </nav>

    <?php if ($due && !$filter && !$dueOnly && $q === ''): ?>
      <h2 class="sec hot">Follow up today <span><?= count($due) ?></span></h2>
      <?php foreach ($due as $r) lead_card($r, $csrf, $back); ?>
    <?php endif; ?>
    <h2 class="sec"><?= h($heading) ?> <span><?= count($list) ?></span></h2>
    <?php if (!$list): ?>
      <div class="empty"><i class="mark" aria-hidden="true"></i><p><?= $total ? 'Nothing here right now.' : 'No leads yet. New ones from the landing page will appear here, newest first.' ?></p></div>
    <?php endif; ?>
    <?php foreach ($list as $r) lead_card($r, $csrf, $back); ?>
    <p class="foot">Leads that never became clients are deleted after <?= LEAD_KEEP_MONTHS ?> months without activity, as our privacy policy promises.</p>
  </main>
<?php page_end();

function page_start($title) { ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
<meta name="robots" content="noindex, nofollow" />
<meta name="theme-color" content="#ffffff" />
<title><?= h($title) ?></title>
<style>
:root {
  --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", Inter, Roboto, system-ui, sans-serif;
  --ink: #1f3448; --text: #4f6578; --muted: #6b7c8c; --line: #e4e9ef; --page: #f4f6f9; --surface: #eef1f5;
  --violet: #8a63cc; --violet-d: #6c48b2; --field: #c3ccd6; --navy: #2c4660;
  --vx-mark: url("data:image/webp;base64,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");
}
* { box-sizing: border-box; margin: 0; }
html { -webkit-text-size-adjust: 100%; }
body { min-height: 100vh; background: var(--page); color: var(--text); font: 400 15px/1.5 var(--sans); -webkit-font-smoothing: antialiased; }
a { color: inherit; text-decoration: none; }
button { font: inherit; }
svg { width: 18px; height: 18px; flex: none; }
.mark { display: block; background: var(--vx-mark) center / contain no-repeat; }

/* top bar */
.bar { position: sticky; top: 0; z-index: 10; background: rgba(255, 255, 255, .92); -webkit-backdrop-filter: saturate(1.4) blur(10px); backdrop-filter: saturate(1.4) blur(10px); border-bottom: 1px solid var(--line); padding-top: env(safe-area-inset-top); }
.bar-in { display: flex; align-items: center; justify-content: space-between; gap: 12px; width: min(820px, 100% - 32px); height: 60px; margin: 0 auto; }
.brand { display: flex; align-items: center; gap: 10px; color: var(--ink); font-size: 19px; font-weight: 700; letter-spacing: -.02em; }
.brand .mark { width: 36px; height: 18px; }
.bar-r { display: flex; align-items: center; gap: 4px; }
.ghost { display: inline-flex; align-items: center; gap: 6px; height: 36px; padding: 0 12px; border: 0; border-radius: 10px; background: none; color: var(--muted); font-size: 14px; font-weight: 600; cursor: pointer; }
.ghost:hover { background: var(--surface); color: var(--ink); }
.ghost svg { width: 16px; height: 16px; }

main { width: min(820px, 100% - 32px); margin: 0 auto; padding: 20px 0 56px; }

/* summary tiles */
.stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.stat { display: grid; gap: 2px; padding: 14px 16px; border-radius: 16px; background: #fff; box-shadow: 0 0 0 1px var(--line); transition: box-shadow .15s, transform .15s; }
.stat:hover { transform: translateY(-1px); box-shadow: 0 0 0 1px var(--line), 0 8px 18px -12px rgba(31, 52, 72, .35); }
.stat b { color: var(--ink); font-size: 26px; font-weight: 700; line-height: 1.1; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.stat span { font-size: 13px; color: var(--muted); font-weight: 500; }
.stat.on { box-shadow: 0 0 0 2px var(--violet); }
.stat.due.hot b { color: #c2410c; }

/* search and filters */
.search { position: relative; margin-top: 16px; }
.search svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--muted); }
.search input { width: 100%; height: 46px; padding: 0 16px 0 42px; border: 0; border-radius: 14px; background: #fff; box-shadow: 0 0 0 1px var(--line); color: var(--ink); font: 16px var(--sans); }
.search input:focus { outline: none; box-shadow: 0 0 0 2px var(--violet); }
.tabs { display: flex; gap: 6px; margin: 12px -16px 0; padding: 0 16px 2px; overflow-x: auto; scrollbar-width: none; }
.tabs::-webkit-scrollbar { display: none; }
.tabs a { flex: none; padding: 7px 13px; border-radius: 999px; color: var(--text); font-size: 13.5px; font-weight: 600; }
.tabs a span { color: var(--muted); font-weight: 500; margin-left: 2px; }
.tabs a:hover { background: #fff; }
.tabs a.on { background: var(--navy); color: #fff; } .tabs a.on span { color: #c9d4df; }

.sec { display: flex; align-items: center; gap: 8px; margin: 26px 2px 10px; color: var(--ink); font-size: 15px; font-weight: 700; }
.sec span { padding: 1px 8px; border-radius: 999px; background: var(--surface); color: var(--muted); font-size: 12.5px; font-weight: 600; }
.sec.hot span { background: #ffedd5; color: #c2410c; }

/* lead cards */
.lead { margin-top: 10px; padding: 16px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 1px var(--line), 0 1px 2px rgba(31, 52, 72, .04); scroll-margin-top: 80px; }
.lead .top { display: flex; align-items: center; gap: 12px; }
.av { flex: none; width: 42px; height: 42px; border-radius: 50%; display: grid; place-items: center; font-size: 15px; font-weight: 700; letter-spacing: .02em; }
.who { flex: 1; min-width: 0; }
.who h3 { color: var(--ink); font-size: 16.5px; font-weight: 650; letter-spacing: -.01em; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.who p { font-size: 13.5px; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.who p.ph { color: var(--ink); font-weight: 500; font-variant-numeric: tabular-nums; }
.pill { flex: none; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 650; }
.meta { display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin-top: 12px; font-size: 12.5px; color: var(--muted); }
.meta .fu { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 8px; background: var(--surface); color: var(--ink); font-weight: 600; }
.meta .fu svg { width: 14px; height: 14px; }
.meta .fu.today { background: #fff4e5; color: #b45309; } .meta .fu.late { background: #fdecec; color: #b42318; }
.needs { margin-top: 10px; font-size: 14px; color: var(--ink); font-weight: 500; }
.needs span { display: block; font-size: 11.5px; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; color: var(--muted); margin-bottom: 1px; }
.note { margin-top: 10px; padding: 10px 12px; border-radius: 12px; background: var(--page); font-size: 13.5px; color: var(--text); white-space: pre-wrap; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.acts { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin-top: 14px; }
.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; height: 44px; padding: 0 16px; border: 0; border-radius: 12px; font-size: 15px; font-weight: 650; cursor: pointer; transition: filter .15s, transform .1s; }
.btn:active { transform: translateY(1px); }
.btn.call { background: var(--surface); color: var(--ink); }
.btn.call:hover { filter: brightness(.97); }
.btn.wa { background: #1fa855; color: #fff; }
.btn.wa:hover { filter: brightness(1.05); }
.btn.primary { background: linear-gradient(180deg, #45627f, #2c4660); color: #fff; box-shadow: inset 0 1px 0 rgba(255, 255, 255, .2); }

/* the update panel */
.upd { margin-top: 12px; border-top: 1px solid var(--line); }
.upd summary { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 12px 2px 0; color: var(--violet-d); font-size: 14px; font-weight: 650; cursor: pointer; list-style: none; }
.upd summary::-webkit-details-marker { display: none; }
.upd summary svg { width: 16px; height: 16px; transition: transform .2s; }
.upd[open] summary svg { transform: rotate(90deg); }
.edit { display: grid; gap: 14px; margin-top: 14px; }
.st { display: flex; flex-wrap: wrap; gap: 6px; border: 0; padding: 0; }
.st legend { margin-bottom: 6px; font-size: 12.5px; font-weight: 600; color: var(--muted); }
.st label { cursor: pointer; }
.st input { position: absolute; opacity: 0; pointer-events: none; }
.st span { display: inline-block; padding: 7px 12px; border-radius: 999px; background: #fff; box-shadow: inset 0 0 0 1px var(--field); color: var(--text); font-size: 13.5px; font-weight: 600; }
.st input:checked + span { box-shadow: none; }
.st input:focus-visible + span { outline: 2px solid var(--violet); outline-offset: 2px; }
.field { display: grid; gap: 6px; }
.field > span { font-size: 12.5px; font-weight: 600; color: var(--muted); }
.field input, .field textarea { width: 100%; padding: 11px 13px; border: 0; border-radius: 12px; background: #fff; box-shadow: inset 0 0 0 1px var(--field); color: var(--ink); font: 16px/1.4 var(--sans); }
.field input:focus, .field textarea:focus { outline: none; box-shadow: inset 0 0 0 2px var(--violet); }
.field textarea { resize: vertical; }
.edit .btn { justify-self: start; min-width: 120px; }
.extra { margin-top: 16px; }
.lbl { margin: 12px 0 4px; font-size: 11.5px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--muted); }
pre { padding: 12px; border-radius: 12px; background: var(--page); white-space: pre-wrap; overflow-wrap: anywhere; font: 13px/1.55 var(--sans); color: var(--ink); max-height: 260px; overflow: auto; }
.small { font-size: 12.5px; color: var(--ink); overflow-wrap: anywhere; } .small span { color: var(--muted); }
.del { margin-top: 14px; text-align: right; }
.del button { border: 0; background: none; color: #98a2ad; font-size: 12.5px; cursor: pointer; }
.del button:hover { color: #b42318; text-decoration: underline; }

/* status colours: avatar, pill and chosen status */
.s-new .av, .s-new .pill, .st .s-new input:checked + span { background: #efe8fb; color: #6c48b2; }
.s-contacted .av, .s-contacted .pill, .st .s-contacted input:checked + span { background: #e3eefb; color: #1d5fa8; }
.s-interested .av, .s-interested .pill, .st .s-interested input:checked + span { background: #fff1d6; color: #8a5a00; }
.s-client .av, .s-client .pill, .st .s-client input:checked + span { background: #dcf5e4; color: #166534; }
.s-lost .av, .s-lost .pill, .st .s-lost input:checked + span { background: #eef0f2; color: #6b7280; }
.lead.s-new { box-shadow: 0 0 0 1px #d8c9f3, 0 6px 16px -12px rgba(108, 72, 178, .45); }

.empty { display: grid; place-items: center; gap: 12px; padding: 48px 20px; border-radius: 18px; background: #fff; box-shadow: 0 0 0 1px var(--line); text-align: center; color: var(--muted); }
.empty .mark { width: 64px; height: 32px; opacity: .5; }
.foot { margin-top: 28px; font-size: 12.5px; color: var(--muted); text-align: center; }
.err { color: #b42318; font-size: 13.5px; }

/* login */
.login { display: grid; place-items: center; min-height: 100vh; padding: 24px 16px; }
.login-card { width: min(380px, 100%); padding: 32px 28px; border-radius: 24px; background: #fff; box-shadow: 0 0 0 1px var(--line), 0 30px 60px -30px rgba(31, 52, 72, .35); text-align: center; }
.login-card .mark { width: 56px; height: 28px; margin: 0 auto 14px; }
.login-card h1 { color: var(--ink); font-size: 22px; letter-spacing: -.02em; }
.login-card .sub { margin-top: 4px; font-size: 13.5px; color: var(--muted); }
.login-card form { display: grid; gap: 12px; margin-top: 22px; text-align: left; }
.login-card .btn { width: 100%; height: 48px; }

@media (max-width: 560px) {
  .stats { grid-template-columns: 1fr 1fr; }
  .ghost span { display: none; }
  .bar-in, main { width: calc(100% - 24px); }
  .tabs { margin: 12px -12px 0; padding: 0 12px 2px; }
}
@media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
</style>
</head>
<body>
<?php }
function page_end() { ?>
</body>
</html>
<?php }
