<?php
// Shared by /lead.php (saves each lead) and /leads/ (the private page to manage them).
// Deployed to public_html/leads/lib.php. Opening it directly in a browser shows nothing.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) { http_response_code(404); exit; }

date_default_timezone_set('Asia/Kolkata');

const LEAD_STATUSES = [
    'new' => 'New',
    'contacted' => 'Contacted',
    'interested' => 'Interested',
    'client' => 'Client',
    'lost' => 'Not interested',
];
const LEAD_KEEP_MONTHS = 18;   // the privacy policy promises to delete enquiries that never became clients after this

// Settings live in ONE of these files (the first one found wins), like the chat key:
//   1. leads-config.php one folder ABOVE public_html  (best: not reachable from the web at all)
//   2. public_html/lp-assets/leads-config.php
function leads_config() {
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    $site = dirname(__DIR__);   // public_html
    foreach ([dirname($site) . '/leads-config.php', $site . '/lp-assets/leads-config.php'] as $file) {
        if (is_file($file)) { $cfg = include $file; break; }
    }
    if (!is_array($cfg)) $cfg = [];
    return $cfg;
}

function leads_db() {
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = leads_config();
    $dsn = $c['db_dsn'] ?? ('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . ($c['db_name'] ?? '') . ';charset=utf8mb4');
    $pdo = new PDO($dsn, $c['db_user'] ?? null, $c['db_pass'] ?? null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $id = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $text = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';
    // created on first use, so there is nothing to import by hand
    $pdo->exec("CREATE TABLE IF NOT EXISTS leads (
        id $id,
        created_at VARCHAR(19) NOT NULL,
        updated_at VARCHAR(19) NOT NULL,
        name VARCHAR(120) NOT NULL,
        phone VARCHAR(40) NOT NULL,
        business VARCHAR(200) NOT NULL DEFAULT '',
        needs VARCHAR(300) NOT NULL DEFAULT '',
        form VARCHAR(40) NOT NULL DEFAULT '',
        transcript $text,
        tracking VARCHAR(1000) NOT NULL DEFAULT '',
        page VARCHAR(300) NOT NULL DEFAULT '',
        status VARCHAR(20) NOT NULL DEFAULT 'new',
        notes $text,
        follow_up VARCHAR(10) NOT NULL DEFAULT ''
    )");
    return $pdo;
}

function now_str() { return date('Y-m-d H:i:s'); }
function clip($s, $n) { $s = trim((string)$s); return function_exists('mb_substr') ? mb_substr($s, 0, $n) : substr($s, 0, $n); }
function digits_of($s) { return preg_replace('/\D+/', '', (string)$s); }
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

// a phone number as wa.me wants it: country code + number, digits only (10-digit numbers are taken as Indian)
function wa_number($phone) {
    $d = digits_of($phone);
    if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
    if (strlen($d) === 10) $d = '91' . $d;
    return $d;
}

// small per-visitor counters kept in temp files, used to slow down bots and password guessing
function hit_limit($bucket, $max, $seconds) {
    $file = sys_get_temp_dir() . '/vx_' . $bucket . '_' . md5($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $now = time(); $hits = [];
    if (is_file($file)) foreach (explode(',', (string)@file_get_contents($file)) as $t) { if ((int)$t > $now - $seconds) $hits[] = (int)$t; }
    return [$file, $hits, count($hits) >= $max];
}
function hit_add($file, $hits) { $hits[] = time(); @file_put_contents($file, implode(',', $hits), LOCK_EX); }
