<?php
declare(strict_types=1);

(function (): void {
    $file = __DIR__ . '/../.env';
    if (!is_file($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) putenv($k . '=' . $v);
    }
})();

function env(string $key): string
{
    $v = getenv($key);
    if ($v === false) $v = $_ENV[$key] ?? $_SERVER[$key] ?? '';
    return (string)$v;
}

define('SUPABASE_URL', rtrim(env('SUPABASE_URL'), '/'));
define('SUPABASE_KEY', env('SUPABASE_SERVICE_ROLE_KEY') ?: env('SUPABASE_SERVICE_KEY'));
define('APP_DEBUG', env('APP_DEBUG') === 'true');
define('PER_PAGE', 10);
define('SESSION_LIFETIME', 60 * 60 * 8);

ini_set('display_errors', APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

/* ---------- Helpers ---------- */
function e($v): string { return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }

function str_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s) : (preg_match_all('/./us', $s) ?: strlen($s));
}

function redirect(string $url): never { header('Location: ' . $url); exit; }

function purl(string $file, array $q = []): string { return $file . ($q ? '?' . http_build_query($q) : ''); }

function qs(array $override = []): string
{
    $q = array_filter(array_merge($_GET, $override), fn($v) => $v !== null && $v !== '');
    return purl(basename($_SERVER['SCRIPT_NAME']), $q);
}

function flash(string $type, string $msg): void { $_SESSION['flash'][] = [$type, $msg]; }

function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">'; }
function csrf_check(): void
{
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['_csrf'] ?? ''))) {
        http_response_code(419);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

function current_user(): ?array { return $_SESSION['user'] ?? null; }

/** Tables whose new records wait for Super Admin approval (Admin and Staff submissions). */
const GATED_TABLES = ['farmer', 'farm', 'crop', 'planting', 'harvest'];

/** Number of submitted records waiting for the Super Admin. */
function pending_record_count(): int
{
    global $db;
    $pk = ['farmer' => 'farmer_id', 'farm' => 'farm_id', 'crop' => 'crop_id', 'planting' => 'planting_id', 'harvest' => 'harvest_id'];
    $n = 0;
    foreach (GATED_TABLES as $t) {
        try { $n += (int)$db->select($t, $pk[$t], ['approval_status' => 'eq.pending'], null, 1, 1)['total']; }
        catch (SupabaseException $ex) {}
    }
    return $n;
}
const ROLE_ADMIN = 1, ROLE_STAFF = 2, ROLE_SUPER = 3;

/** Admin and Super Admin both have full create / edit / delete access. */
function is_admin(): bool { return in_array((int)(current_user()['role_id'] ?? 0), [ROLE_ADMIN, ROLE_SUPER], true); }
function is_super_admin(): bool { return (int)(current_user()['role_id'] ?? 0) === ROLE_SUPER; }

/** Who may add and edit records: admins, super admins and staff. Deleting stays admin-only (is_admin()). */
function can_edit(): bool { return current_user() !== null; }

/** Name as stored in the user_role table. */
function role_name(int $id): string { return [ROLE_SUPER => 'Super Admin', ROLE_ADMIN => 'Administrator', ROLE_STAFF => 'Staff'][$id] ?? 'Unknown'; }

function role_badge(int $id): array
{
    return [ROLE_SUPER => ['Super Admin', 'role-super'], ROLE_ADMIN => ['Admin', 'role-admin']][$id] ?? ['Staff', 'role-staff'];
}

function client_ip(): string
{
    foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $h) {
        $v = trim(explode(',', (string)($_SERVER[$h] ?? ''))[0]);
        if ($v !== '' && filter_var($v, FILTER_VALIDATE_IP)) return $v;
    }
    return '';
}

function device_label(): string
{
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '') return 'Unknown';
    if (preg_match('/bot|crawl|spider|curl|wget|python|monitor/i', $ua)) return 'Bot / automated tool';
    $browser = match (true) {
        str_contains($ua, 'Edg/')                               => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
        str_contains($ua, 'Firefox/')                           => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS/') => 'Chrome',
        str_contains($ua, 'Safari/')                            => 'Safari',
        default                                                 => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'Android')                                  => 'Android',
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad')      => 'iOS',
        str_contains($ua, 'Windows')                                  => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
        str_contains($ua, 'Linux')                                    => 'Linux',
        default                                                       => 'unknown device',
    };
    return $browser . ' on ' . $os;
}

function require_login(): void
{
    if (!current_user()) redirect('login.php');
}

function require_super_admin(): void
{
    require_login();
    if (!is_super_admin()) {
        http_response_code(403);
        layout_start('Access denied');
        echo '<div class="alert alert-danger">Access denied. This page is for the Super Admin only. '
           . '<a href="index.php">Back to dashboard</a></div>';
        layout_end();
        exit;
    }
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        layout_start('Access denied');
        echo '<div class="alert alert-danger">Access denied. This page is for administrators only. '
           . '<a href="index.php">Back to dashboard</a></div>';
        layout_end();
        exit;
    }
}

function grade_badge($name): string
{
    $name = trim((string)$name);
    if ($name === '') return '';
    $map = ['Grade A' => 'grade-a', 'Grade B' => 'grade-b', 'Grade C' => 'grade-c', 'Rejected' => 'grade-x'];
    return '<span class="grade-pill ' . ($map[$name] ?? 'grade-other') . '">' . e($name) . '</span>';
}

function money($n): string { return '₱' . number_format((float)$n, 2); }

function svg_icon(string $name, string $class = 'ico'): string
{
    static $paths = [
        'users'         => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'map-pin'       => '<path d="M20 10c0 6-8 12-8 12s-8-6-8-12a8 8 0 0 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
        'sprout'        => '<path d="M7 20h10"/><path d="M10 20c5.5-2.5.8-6.4 3-10"/><path d="M9.5 9.4c1.1.8 1.8 2.2 2.3 3.7-2 .4-3.5.4-4.8-.3-1.2-.6-2.3-1.9-3-4.2 2.8-.5 4.4 0 5.5.8z"/><path d="M14.1 6a7 7 0 0 0-1.1 4c1.9-.1 3.3-.6 4.3-1.4 1-1 1.6-2.3 1.7-4.6-2.7.1-4 1-4.9 2z"/>',
        'calendar-days' => '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/><path d="M8 14h.01"/><path d="M12 14h.01"/><path d="M16 14h.01"/><path d="M8 18h.01"/><path d="M12 18h.01"/><path d="M16 18h.01"/>',
        'wheat'         => '<path d="M2 22 16 8"/><path d="M3.47 12.53 5 11l1.53 1.53a3.5 3.5 0 0 1 0 4.94L5 19l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M7.47 8.53 9 7l1.53 1.53a3.5 3.5 0 0 1 0 4.94L9 15l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M11.47 4.53 13 3l1.53 1.53a3.5 3.5 0 0 1 0 4.94L13 11l-1.53-1.53a3.5 3.5 0 0 1 0-4.94Z"/><path d="M20 2h2v2a4 4 0 0 1-4 4h-2V6a4 4 0 0 1 4-4Z"/><path d="M11.47 17.47 13 19l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L5 19l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/><path d="M15.47 13.47 17 15l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L9 15l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/><path d="M19.47 9.47 21 11l-1.53 1.53a3.5 3.5 0 0 1-4.94 0L13 11l1.53-1.53a3.5 3.5 0 0 1 4.94 0Z"/>',
        'user-round'    => '<circle cx="12" cy="8" r="5"/><path d="M20 21a8 8 0 0 0-16 0"/>',
        'chevron-down'  => '<path d="m6 9 6 6 6-6"/>',
    ];
    if (!isset($paths[$name])) return '';
    return '<svg class="' . e($class) . '" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[$name] . '</svg>';
}

function format_location_label(?array $loc): string
{
    if (!$loc) return '';
    return implode(', ', array_filter([
        trim((string)($loc['city_municipality'] ?? '')),
        trim((string)($loc['province'] ?? '')),
    ], fn($v) => $v !== ''));
}

function format_farmer_address(array $r): string
{
    return implode(', ', array_filter([
        trim((string)($r['street_address'] ?? '')),
        trim((string)($r['location']['city_municipality'] ?? '')),
        trim((string)($r['location']['province'] ?? '')),
    ], fn($v) => $v !== '')) ?: '—';
}

function status_badge(array $r): string
{
    $name = $r['planting_status']['status_name'] ?? '';
    $color = ['Planted' => 'info', 'Growing' => 'primary', 'Ready for Harvest' => 'warning',
              'Harvested' => 'success', 'Failed' => 'danger'][$name] ?? 'secondary';
    return '<span class="badge text-bg-' . $color . '">' . e($name) . '</span>';
}

class SupabaseException extends Exception
{
    public string $pgCode = '';
    public int $httpStatus = 0;
}

class Supabase
{
    /** Tables that use soft deletion (a deleted_at timestamp instead of removing the row). */
    private const SOFT = ['farmer', 'farm', 'crop', 'planting', 'harvest', 'app_user'];
    /** Tables whose new records must be approved by the Super Admin before they appear. */
    private const GATED = ['farmer', 'farm', 'crop', 'planting', 'harvest'];

    private function request(string $method, string $table, array $query = [], ?array $body = null, array $extra = []): array
    {
        if (SUPABASE_URL === '' || SUPABASE_KEY === '') {
            throw new SupabaseException('Database is not configured. Check SUPABASE_URL and SUPABASE_SERVICE_ROLE_KEY in .env.');
        }
        if ($method === 'GET' && in_array($table, self::SOFT, true) && !isset($query['deleted_at'])) {
            $query['deleted_at'] = 'is.null'; // hide soft-deleted rows from every read
        }
        if ($method === 'GET' && in_array($table, self::GATED, true) && !isset($query['approval_status'])) {
            $query['approval_status'] = 'eq.approved'; // hide pending / declined records from every read
        }
        $url = SUPABASE_URL . '/rest/v1/' . $table;
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $headers = array_merge([
            'apikey: ' . SUPABASE_KEY,
            'Authorization: Bearer ' . SUPABASE_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ], $extra);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new SupabaseException(APP_DEBUG ? "Connection failed: $err" : 'Unable to reach the database.');
        }
        $hsize  = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $rawHeaders = substr($resp, 0, $hsize);
        $rawBody = substr($resp, $hsize);
        $data = json_decode($rawBody, true);
        $total = preg_match('/content-range:\s*\S+\/(\d+)/i', $rawHeaders, $m) ? (int)$m[1] : null;

        if ($status === 416) return [[], $total ?? 0];
        if ($status >= 400) {
            $msg = is_array($data) && isset($data['message']) ? (string)$data['message'] : 'Database error';
            $ex = new SupabaseException($msg);
            $ex->pgCode = is_array($data) ? (string)($data['code'] ?? '') : '';
            $ex->httpStatus = $status;
            if ($ex->pgCode === '' && ($status === 401 || $status === 403 || stripos($msg, 'permission denied') !== false)) {
                $ex->pgCode = '42501';
            }
            throw $ex;
        }
        return [is_array($data) ? $data : [], $total];
    }

    public function select(string $table, string $select = '*', array $filters = [], ?string $order = null,
                           int $page = 1, int $per = PER_PAGE): array
    {
        $q = ['select' => $select] + $filters;
        if ($order) $q['order'] = $order;
        $from = ($page - 1) * $per;
        [$data, $total] = $this->request('GET', $table, $q, null, [
            'Range-Unit: items', 'Range: ' . $from . '-' . ($from + $per - 1), 'Prefer: count=exact',
        ]);
        return ['data' => $data, 'total' => $total ?? count($data)];
    }

    public function find(string $table, string $pk, $id, string $select = '*'): ?array
    {
        [$data] = $this->request('GET', $table, ['select' => $select, $pk => 'eq.' . $id, 'limit' => 1]);
        return $data[0] ?? null;
    }

    public function count(string $table, string $pk): int
    {
        return $this->select($table, $pk, [], null, 1, 1)['total'];
    }

    public function insert(string $table, array $row): array
    {
        [$data] = $this->request('POST', $table, [], $row, ['Prefer: return=representation']);
        return $data[0] ?? [];
    }

    public function update(string $table, string $pk, $id, array $row): array
    {
        $filter = [$pk => 'eq.' . $id];
        if (in_array($table, self::SOFT, true)) $filter['deleted_at'] = 'is.null'; // never edit a deleted record
        [$data] = $this->request('PATCH', $table, $filter, $row, ['Prefer: return=representation']);
        return $data[0] ?? [];
    }

    /**
     * Soft delete. A database trigger also soft-deletes everything connected to the record
     * (farmer -> farms -> plantings -> harvests, ...) and writes it all to audit_log.
     * Returns [rows deleted, delete timestamp] so the caller can report what else was deleted.
     */
    public function delete(string $table, string $pk, $id, string $by = ''): array
    {
        if (in_array($table, self::SOFT, true)) {
            $stamp = (new DateTime('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.u\Z');
            $row = ['deleted_at' => $stamp];
            if ($by !== '') $row['updated_by'] = $by;
            [$data] = $this->request('PATCH', $table, [$pk => 'eq.' . $id, 'deleted_at' => 'is.null'], $row,
                                     ['Prefer: return=representation']);
            return [count($data), $stamp];
        }
        $this->request('DELETE', $table, [$pk => 'eq.' . $id]);
        return [1, ''];
    }

    /** Undelete. The trigger brings back what was deleted together with it. Returns rows restored. */
    public function restore(string $table, string $pk, $id, string $by): int
    {
        [$data] = $this->request('PATCH', $table, [$pk => 'eq.' . $id, 'deleted_at' => 'not.is.null'],
                                 ['deleted_at' => null, 'updated_by' => $by], ['Prefer: return=representation']);
        return count($data);
    }

    /** Call a Postgres function (used for "undo update"). */
    public function rpc(string $fn, array $args): array
    {
        [$data] = $this->request('POST', 'rpc/' . $fn, [], $args);
        return $data;
    }

    public function upsert(string $table, string $conflictCol, array $row): void
    {
        $this->request('POST', $table, ['on_conflict' => $conflictCol], $row,
                       ['Prefer: resolution=merge-duplicates,return=minimal']);
    }

    public function deleteWhere(string $table, array $filters): void
    {
        $this->request('DELETE', $table, $filters);
    }
}

/* ---------- Database-backed sessions (serverless instances share no disk) ---------- */
class DbSessionHandler implements SessionHandlerInterface
{
    public function __construct(private Supabase $db) {}

    private static function clean(string $id): string { return preg_replace('/[^A-Za-z0-9,-]/', '', $id); }

    public function open(string $path, string $name): bool { return true; }
    public function close(): bool { return true; }

    public function read(string $id): string|false
    {
        try {
            $row = $this->db->find('app_session', 'id', self::clean($id), 'data,expires_at');
        } catch (SupabaseException $ex) {
            return '';
        }
        if (!$row || strtotime((string)$row['expires_at']) < time()) return '';
        return (string)$row['data'];
    }

    public function write(string $id, string $data): bool
    {
        $id = self::clean($id);
        try {
            if ($data === '') {
                $this->db->deleteWhere('app_session', ['id' => 'eq.' . $id]);
                return true;
            }
            $u = $_SESSION['user'] ?? null;
            $loggedInAt = null;
            if ($u) {
                $rawLogin = $u['logged_in_at'] ?? null;
                $loggedInAt = is_numeric($rawLogin) ? gmdate('c', (int)$rawLogin) : ($rawLogin ?: gmdate('c'));
            }
            $this->db->upsert('app_session', 'id', [
                'id'             => $id,
                'data'           => $data,
                'username'       => $u['username'] ?? null,
                'full_name'      => $u['full_name'] ?? null,
                'role_name'      => $u ? role_name((int)$u['role_id']) : null,
                'user_id'        => $u ? (int)$u['user_id'] : null,
                'status'         => $u ? 'Logged in' : 'Guest (not logged in)',
                'device'         => device_label(),
                'ip_address'     => client_ip() ?: null,
                'logged_in_at'   => $loggedInAt,
                'last_active_at' => gmdate('c'),
                'expires_at'     => gmdate('c', time() + SESSION_LIFETIME),
            ]);
            return true;
        } catch (SupabaseException $ex) {
            error_log('DbSessionHandler::write failed: ' . $ex->getMessage());
            if (APP_DEBUG) trigger_error('DbSessionHandler::write: ' . $ex->getMessage(), E_USER_WARNING);
            return false;
        }
    }

    public function destroy(string $id): bool
    {
        try { $this->db->deleteWhere('app_session', ['id' => 'eq.' . self::clean($id)]); } catch (SupabaseException $ex) {}
        return true;
    }

    public function gc(int $max_lifetime): int|false
    {
        try {
            $this->db->deleteWhere('app_session', ['expires_at' => 'lt.' . gmdate('c')]);
        } catch (SupabaseException $ex) {}
        return 0;
    }
}

$db = new Supabase();

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_set_save_handler(new DbSessionHandler($db), true);
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/* ---------- Layout & Shared UI ---------- */
function nav_items(): array
{
    $items = [
        ['index.php',    'Dashboard',        'bi-speedometer2'],
        ['farmers.php',  'Farmers',          'bi-person-badge'],
        ['farms.php',    'Farms',            'bi-tree'],
        ['crops.php',    'Crops',            'bi-flower1'],
        ['planting.php', 'Planting',         'bi-flower3'],
        ['harvest.php',  'Harvest',          'bi-basket'],
        ['reports.php',  'Reports',          'bi-file-earmark-bar-graph'],
    ];
    if (is_admin()) $items[] = ['users.php', 'Users', 'bi-people'];
    if (is_super_admin()) $items[] = ['activity.php', 'Activity', 'bi-clock-history'];
    return $items;
}

function layout_start(string $title): void
{
    $u = current_user();
    $cur = basename($_SERVER['SCRIPT_NAME']); ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?> | Sproutly</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&amp;family=JetBrains+Mono:wght@400;500;600&amp;display=swap" rel="stylesheet">
  <link href="style.php" rel="stylesheet">
</head>
<body>
<?php if ($u): [$roleLabel, $roleClass] = role_badge((int)$u['role_id']); ?>
<nav class="navbar navbar-expand-xl sticky-top app-nav mb-3 mb-md-4">
  <div class="container-fluid px-3 px-lg-4">
    <a class="navbar-brand app-brand" href="index.php"><span class="brand-mark"><?= svg_icon('wheat', 'ico') ?></span><span>Sproutly</span></a>
    <button class="nav-burger" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation"><span></span><span></span><span></span></button>
    <div id="mainNav" class="collapse navbar-collapse">
      <ul class="navbar-nav app-nav-links">
        <?php foreach (nav_items() as [$file, $label, $icon]): ?>
          <li class="nav-item"><a class="nav-link <?= $cur === $file ? 'active' : '' ?>" href="<?= e($file) ?>"<?= $cur === $file ? ' aria-current="page"' : '' ?>><i class="bi <?= e($icon) ?>" aria-hidden="true"></i><span><?= e($label) ?></span></a></li>
        <?php endforeach; ?>
      </ul>
      <div class="dropdown d-none d-xl-block flex-shrink-0">
        <button class="user-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?= svg_icon('user-round', 'ico') ?><span class="user-name"><?= e($u['full_name']) ?></span><span class="user-dot" aria-hidden="true">&middot;</span><span class="user-role <?= $roleClass ?>"><?= $roleLabel ?></span><?= svg_icon('chevron-down', 'ico chev') ?>
        </button>
        <ul class="dropdown-menu dropdown-menu-end">
          <li><a class="dropdown-item" href="profile.php"><i class="bi bi-key"></i> Change password</a></li>
          <li><hr class="dropdown-divider"></li>
          <li><form method="post" action="logout.php" class="px-3"><?= csrf_field() ?><button class="btn btn-sm btn-danger w-100"><i class="bi bi-box-arrow-right"></i> Logout</button></form></li>
        </ul>
      </div>
      <div class="nav-user d-xl-none">
        <div class="nav-user-card">
          <span class="nav-avatar"><?= svg_icon('user-round', 'ico') ?></span>
          <span class="nav-user-meta"><strong><?= e($u['full_name']) ?></strong><span class="user-role <?= $roleClass ?>"><?= $roleLabel ?></span></span>
        </div>
        <div class="nav-user-actions">
          <a class="btn btn-outline-success" href="profile.php"><i class="bi bi-key"></i> Change password</a>
          <form method="post" action="logout.php"><?= csrf_field() ?><button class="btn btn-outline-danger w-100"><i class="bi bi-box-arrow-right"></i> Logout</button></form>
        </div>
      </div>
    </div>
  </div>
</nav>
<div class="nav-scrim" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-hidden="true"></div>
<?php endif; ?>
<main class="container-fluid px-3 px-lg-4">
<?php foreach ($_SESSION['flash'] ?? [] as [$type, $msg]): ?>
  <div class="alert alert-<?= e($type) ?> alert-dismissible fade show" role="alert">
    <?= e($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
  </div>
<?php endforeach; unset($_SESSION['flash']); ?>
<?php }

function layout_end(): void { ?>
</main>
<footer class="text-center text-muted small py-4">Sproutly &middot; Agriculture Information System</footer>

<?php if (is_admin()): ?>
<div class="modal fade" id="delModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <form id="del_form" method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="del_id">
      <div class="modal-header">
        <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle"></i> Confirm deletion</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-2">Delete <strong id="del_label"></strong>?</p>
        <div class="alert alert-warning py-2 mb-2" id="del_warn" hidden>
          <i class="bi bi-exclamation-triangle-fill"></i> <strong>Everything connected to it will also be deleted:</strong>
          <span id="del_warn_text"></span>
        </div>
        <p class="small text-muted mb-0">Nothing is erased for good. The records are hidden from the website and the Super Admin can undelete them.</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-danger">Yes, delete</button>
      </div>
    </form>
  </div></div>
</div>
<?php endif; ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<style>
.pw-wrap { position: relative; }
.pw-wrap > input { padding-right: 2.75rem; }
.pw-wrap input::-ms-reveal, .pw-wrap input::-ms-clear { display: none; }
.pw-toggle { position: absolute; top: 0; right: 0; height: 100%; width: 2.75rem; display: flex; align-items: center;
  justify-content: center; border: 0; background: transparent; color: #6c757d; cursor: pointer; padding: 0; }
.pw-toggle:hover, .pw-toggle:focus-visible { color: #198754; outline: none; }
</style>
<script>
/* Show / hide toggle on every password field */
(function () {
  document.querySelectorAll('input[type="password"]').forEach(function (inp) {
    var wrap = document.createElement('div');
    wrap.className = 'pw-wrap';
    inp.parentNode.insertBefore(wrap, inp);
    wrap.appendChild(inp);
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'pw-toggle';
    btn.setAttribute('aria-label', 'Show password');
    btn.innerHTML = '<i class="bi bi-eye"></i>';
    btn.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      btn.innerHTML = '<i class="bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + '"></i>';
      inp.focus();
    });
    wrap.appendChild(btn);
  });
})();
</script>
<script>
document.addEventListener('click', function (ev) {
  const btn = ev.target.closest('[data-delete-id]');
  if (!btn) return;
  document.getElementById('del_id').value = btn.dataset.deleteId;
  document.getElementById('del_form').action = btn.dataset.action;
  document.getElementById('del_label').textContent = btn.dataset.label || 'this record';
  var warn = btn.dataset.warn || '';
  document.getElementById('del_warn').hidden = warn === '';
  document.getElementById('del_warn_text').textContent = warn;
});
(function () {
  var nav = document.getElementById('mainNav');
  if (!nav) return;
  nav.addEventListener('show.bs.collapse', function () { document.body.classList.add('nav-open'); });
  nav.addEventListener('hide.bs.collapse', function () { document.body.classList.remove('nav-open'); });
  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && nav.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(nav).hide();
  });
})();
document.querySelectorAll('form.needs-validation').forEach(function (f) {
  f.addEventListener('submit', function (e) {
    if (!f.checkValidity()) { e.preventDefault(); e.stopPropagation(); }
    f.classList.add('was-validated');
  });
});
setTimeout(function () {
  document.querySelectorAll('.alert-success').forEach(function (a) { a.classList.remove('show'); });
}, 4000);
</script>
</body></html>
<?php }

function pagination(int $total, int $page, int $per): void
{
    $pages = (int)ceil($total / $per);
    if ($pages <= 1) return;
    $from = max(1, $page - 2); $to = min($pages, $page + 2); ?>
  <nav class="no-print" aria-label="Pagination"><ul class="pagination pagination-sm justify-content-center flex-wrap mb-0">
    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(qs(['p' => $page - 1])) ?>">&laquo;</a></li>
    <?php if ($from > 1): ?><li class="page-item"><a class="page-link" href="<?= e(qs(['p' => 1])) ?>">1</a></li><li class="page-item disabled"><span class="page-link">&hellip;</span></li><?php endif; ?>
    <?php for ($i = $from; $i <= $to; $i++): ?>
      <li class="page-item <?= $i === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(qs(['p' => $i])) ?>"><?= $i ?></a></li>
    <?php endfor; ?>
    <?php if ($to < $pages): ?><li class="page-item disabled"><span class="page-link">&hellip;</span></li><li class="page-item"><a class="page-link" href="<?= e(qs(['p' => $pages])) ?>"><?= $pages ?></a></li><?php endif; ?>
    <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(qs(['p' => $page + 1])) ?>">&raquo;</a></li>
  </ul></nav>
<?php }

function simple_table(array $headers, array $rows, array $rightCols = []): void
{ ?>
  <div class="table-responsive"><table class="table table-hover align-middle report-table">
    <thead><tr><?php foreach ($headers as $i => $h): ?><th scope="col" class="<?= in_array($i, $rightCols, true) ? 'text-end' : '' ?>"><?= e($h) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="<?= count($headers) ?>" class="text-center text-muted py-4">No data found.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?><tr>
      <?php foreach ($r as $i => $c): $val = trim((string)$c) === '' ? '&mdash;' : $c; ?><td class="<?= in_array($i, $rightCols, true) ? 'text-end' : '' ?>"><?= $val ?></td><?php endforeach; ?>
    </tr><?php endforeach; ?>
    </tbody></table></div>
<?php }

/* ---------- Lookups & Module Definitions ---------- */
$REF = [
    'farmer'     => ['table' => 'farmer', 'id' => 'farmer_id', 'label' => ['last_name', 'first_name']],
    'location'   => ['table' => 'location', 'id' => 'location_id', 'label' => ['city_municipality', 'province']],
    'irrigation' => ['table' => 'irrigation_type', 'id' => 'irrigation_id', 'label' => 'irrigation_name'],
    'soil'       => ['table' => 'soil_type', 'id' => 'soil_type_id', 'label' => 'soil_type_name'],
    'category'   => ['table' => 'crop_category', 'id' => 'category_id', 'label' => 'category_name'],
    'season'     => ['table' => 'season', 'id' => 'season_id', 'label' => 'season_name'],
    'status'     => ['table' => 'planting_status', 'id' => 'status_id', 'label' => 'status_name'],
    'grade'      => ['table' => 'quality_grade', 'id' => 'grade_id', 'label' => 'grade_name'],
    'remarks'    => ['table' => 'harvest_remark', 'id' => 'remark_id', 'label' => 'remark_description'],
    'roles'      => ['table' => 'user_role', 'id' => 'role_id', 'label' => 'role_name'],
    // roles an account can be GIVEN: the Super Admin may give any role, an Administrator may only create Staff
    'roles_assign' => ['table' => 'user_role', 'id' => 'role_id', 'label' => 'role_name'],
    'farm'       => ['table' => 'farm', 'id' => 'farm_id', 'label' => 'farm_name'],
    'crop'       => ['table' => 'crop', 'id' => 'crop_id', 'label' => ['crop_name', 'variety']],
    'planting'   => ['table' => 'planting', 'id' => 'planting_id',
                     'select' => 'planting_id,planting_date,farm(farm_name),crop(crop_name)',
                     'labelFn' => fn($r) => '#' . $r['planting_id'] . ' - ' . ($r['crop']['crop_name'] ?? '?')
                                 . ' @ ' . ($r['farm']['farm_name'] ?? '?') . ' (' . $r['planting_date'] . ')'],
];

$modules = [
    'farmers' => [
        'cascadeWarn' => 'all of this farmer\'s farms, and every planting record and harvest record on those farms.',
        'title' => 'Farmers', 'file' => 'farmers.php', 'icon' => 'bi-person-badge', 'table' => 'farmer', 'pk' => 'farmer_id',
        'select' => '*,location(city_municipality,province)',
        'search' => ['first_name', 'last_name', 'street_address', 'contact_number'],
        'searchRefs' => [['table' => 'location', 'pk' => 'location_id', 'col' => 'city_municipality', 'fk' => 'location_id']],
        'searchHint' => 'Search by name, contact, street, or city',
        'filters' => [['field' => 'location_id', 'label' => 'Location', 'type' => 'select', 'ref' => 'location']],
        'fields' => [
            ['name' => 'first_name', 'label' => 'First name', 'type' => 'text', 'required' => true, 'max_len' => 100],
            ['name' => 'last_name', 'label' => 'Last name', 'type' => 'text', 'required' => true, 'max_len' => 100],
            ['name' => 'contact_number', 'label' => 'Contact number', 'type' => 'text', 'required' => true, 'max_len' => 20,
             'pattern' => '/^[0-9+() -]{7,20}$/', 'hint' => 'Digits, spaces and + ( ) - only (7-20 characters).'],
            ['name' => 'street_address', 'label' => 'Street address', 'type' => 'text', 'required' => false, 'max_len' => 150,
             'hint' => 'Optional (barangay, sitio, or street).'],
            ['name' => 'location_id', 'label' => 'City / Municipality', 'type' => 'select', 'required' => true, 'ref' => 'location'],
            ['name' => 'date_registered', 'label' => 'Date registered', 'type' => 'date', 'required' => true, 'default' => date('Y-m-d')],
        ],
        'columns' => [
            ['ID', 'farmer_id', fn($r) => $r['farmer_id']],
            ['Name', 'last_name', fn($r) => $r['last_name'] . ', ' . $r['first_name']],
            ['Contact', 'contact_number', fn($r) => $r['contact_number']],
            ['Address', 'location_id', fn($r) => format_farmer_address($r)],
            ['Registered', 'date_registered', fn($r) => $r['date_registered']],
        ],
    ],
    'farms' => [
        'cascadeWarn' => 'all planting records on this farm and their harvest records.',
        'title' => 'Farms', 'file' => 'farms.php', 'icon' => 'bi-tree', 'table' => 'farm', 'pk' => 'farm_id',
        'select' => '*,farmer(first_name,last_name),location(city_municipality,province),soil_type(soil_type_name),irrigation_type(irrigation_name)',
        'search' => ['farm_name'],
        'searchRefs' => [['table' => 'location', 'pk' => 'location_id', 'col' => 'city_municipality', 'fk' => 'location_id']],
        'searchHint' => 'Search by farm name or location',
        'filters' => [['field' => 'location_id', 'label' => 'Location', 'type' => 'select', 'ref' => 'location'],
                      ['field' => 'irrigation_id', 'label' => 'Irrigation type', 'type' => 'select', 'ref' => 'irrigation'],
                      ['field' => 'soil_type_id', 'label' => 'Soil type', 'type' => 'select', 'ref' => 'soil']],
        'fields' => [
            ['name' => 'farmer_id', 'label' => 'Owner (Farmer)', 'type' => 'select', 'required' => true, 'ref' => 'farmer'],
            ['name' => 'farm_name', 'label' => 'Farm name', 'type' => 'text', 'required' => true, 'max_len' => 150],
            ['name' => 'location_id', 'label' => 'Location', 'type' => 'select', 'required' => true, 'ref' => 'location'],
            ['name' => 'area_hectares', 'label' => 'Area (hectares)', 'type' => 'number', 'required' => true, 'min' => 0.01, 'max' => 9999.99, 'step' => '0.01'],
            ['name' => 'soil_type_id', 'label' => 'Soil type', 'type' => 'select', 'required' => true, 'ref' => 'soil'],
            ['name' => 'irrigation_id', 'label' => 'Irrigation type', 'type' => 'select', 'required' => true, 'ref' => 'irrigation'],
        ],
        'columns' => [
            ['ID', 'farm_id', fn($r) => $r['farm_id']],
            ['Farm', 'farm_name', fn($r) => $r['farm_name']],
            ['Owner', null, fn($r) => ($r['farmer']['last_name'] ?? '') . ', ' . ($r['farmer']['first_name'] ?? '')],
            ['Location', 'location_id', fn($r) => format_location_label($r['location'] ?? null) ?: '—'],
            ['Area (ha)', 'area_hectares', fn($r) => $r['area_hectares']],
            ['Soil', null, fn($r) => $r['soil_type']['soil_type_name'] ?? ''],
            ['Irrigation', null, fn($r) => $r['irrigation_type']['irrigation_name'] ?? ''],
        ],
    ],
    'crops' => [
        'cascadeWarn' => 'all planting records of this crop and their harvest records.',
        'title' => 'Crops', 'file' => 'crops.php', 'icon' => 'bi-flower1', 'table' => 'crop', 'pk' => 'crop_id',
        'select' => '*,crop_category(category_name),season(season_name)',
        'search' => ['crop_name', 'variety'],
        'searchHint' => 'Search by crop name or variety',
        'filters' => [['field' => 'category_id', 'label' => 'Category', 'type' => 'select', 'ref' => 'category'],
                      ['field' => 'season_id', 'label' => 'Recommended season', 'type' => 'select', 'ref' => 'season']],
        'fields' => [
            ['name' => 'crop_name', 'label' => 'Crop name', 'type' => 'text', 'required' => true, 'max_len' => 100],
            ['name' => 'variety', 'label' => 'Variety', 'type' => 'text', 'required' => true, 'max_len' => 100],
            ['name' => 'category_id', 'label' => 'Category', 'type' => 'select', 'required' => true, 'ref' => 'category'],
            ['name' => 'growing_period_days', 'label' => 'Growing period (days)', 'type' => 'number', 'required' => true, 'int' => true, 'min' => 1, 'max' => 730, 'step' => '1'],
            ['name' => 'season_id', 'label' => 'Recommended season', 'type' => 'select', 'required' => true, 'ref' => 'season'],
        ],
        'columns' => [
            ['ID', 'crop_id', fn($r) => $r['crop_id']],
            ['Crop', 'crop_name', fn($r) => $r['crop_name']],
            ['Variety', 'variety', fn($r) => $r['variety']],
            ['Category', null, fn($r) => $r['crop_category']['category_name'] ?? ''],
            ['Growing days', 'growing_period_days', fn($r) => $r['growing_period_days']],
            ['Season', null, fn($r) => $r['season']['season_name'] ?? ''],
        ],
    ],
    'plantings' => [
        'cascadeWarn' => 'all harvest records of this planting record.',
        'title' => 'Planting Records', 'file' => 'planting.php', 'icon' => 'bi-flower3', 'table' => 'planting', 'pk' => 'planting_id',
        'select' => '*,farm(farm_name),crop(crop_name,variety),planting_status(status_name)',
        'search' => [],
        'searchRefs' => [['table' => 'farm', 'pk' => 'farm_id', 'col' => 'farm_name', 'fk' => 'farm_id'],
                         ['table' => 'crop', 'pk' => 'crop_id', 'col' => 'crop_name', 'fk' => 'crop_id']],
        'searchHint' => 'Search by farm or crop name',
        'filters' => [['field' => 'status_id', 'label' => 'Status', 'type' => 'select', 'ref' => 'status']],
        'fields' => [
            ['name' => 'farm_id', 'label' => 'Farm', 'type' => 'select', 'required' => true, 'ref' => 'farm'],
            ['name' => 'crop_id', 'label' => 'Crop', 'type' => 'select', 'required' => true, 'ref' => 'crop'],
            ['name' => 'planting_date', 'label' => 'Planting date', 'type' => 'date', 'required' => true],
            ['name' => 'area_planted', 'label' => 'Area planted (ha)', 'type' => 'number', 'required' => true, 'min' => 0.01, 'max' => 9999.99, 'step' => '0.01'],
            ['name' => 'seed_quantity', 'label' => 'Seed quantity', 'type' => 'number', 'required' => true, 'min' => 0.01, 'max' => 999999.99, 'step' => '0.01'],
            ['name' => 'expected_harvest_date', 'label' => 'Expected harvest date', 'type' => 'date', 'required' => true],
            ['name' => 'status_id', 'label' => 'Status', 'type' => 'select', 'required' => true, 'ref' => 'status'],
        ],
        'validate' => function (array $d, array &$err): void {
            if (isset($d['planting_date'], $d['expected_harvest_date']) && $d['expected_harvest_date'] < $d['planting_date']) {
                $err['expected_harvest_date'] = 'Expected harvest date cannot be before the planting date.';
            }
        },
        'columns' => [
            ['ID', 'planting_id', fn($r) => $r['planting_id']],
            ['Farm', null, fn($r) => $r['farm']['farm_name'] ?? ''],
            ['Crop', null, fn($r) => ($r['crop']['crop_name'] ?? '') . ' (' . ($r['crop']['variety'] ?? '') . ')'],
            ['Planted', 'planting_date', fn($r) => $r['planting_date']],
            ['Area (ha)', 'area_planted', fn($r) => $r['area_planted']],
            ['Seed qty', 'seed_quantity', fn($r) => $r['seed_quantity']],
            ['Expected harvest', 'expected_harvest_date', fn($r) => $r['expected_harvest_date']],
            ['Status', null, 'status_badge', true],
        ],
    ],
    'harvests' => [
        'title' => 'Harvest Records', 'file' => 'harvest.php', 'icon' => 'bi-basket', 'table' => 'harvest', 'pk' => 'harvest_id',
        'select' => '*,planting(planting_date,farm(farm_name),crop(crop_name)),quality_grade(grade_name),harvest_remark(remark_description)',
        'search' => [],
        'searchRefs' => [['table' => 'harvest_remark', 'pk' => 'remark_id', 'col' => 'remark_description', 'fk' => 'remark_id'],
                         ['table' => 'quality_grade', 'pk' => 'grade_id', 'col' => 'grade_name', 'fk' => 'grade_id']],
        'searchHint' => 'Search by remarks or grade',
        'filters' => [['field' => 'grade_id', 'label' => 'Quality grade', 'type' => 'select', 'ref' => 'grade'],
                      ['field' => 'remark_id', 'label' => 'Remarks', 'type' => 'select', 'ref' => 'remarks']],
        'fields' => [
            ['name' => 'planting_id', 'label' => 'Planting record', 'type' => 'select', 'required' => true, 'ref' => 'planting'],
            ['name' => 'harvest_date', 'label' => 'Harvest date', 'type' => 'date', 'required' => true],
            ['name' => 'quantity_kg', 'label' => 'Quantity (kg)', 'type' => 'number', 'required' => true, 'min' => 0.01, 'max' => 99999999.99, 'step' => '0.01'],
            ['name' => 'selling_price_per_kg', 'label' => 'Selling price per kg (₱)', 'type' => 'number', 'required' => true, 'min' => 0, 'max' => 999999.99, 'step' => '0.01'],
            ['name' => 'grade_id', 'label' => 'Quality grade', 'type' => 'select', 'required' => true, 'ref' => 'grade'],
            ['name' => 'remark_id', 'label' => 'Remarks', 'type' => 'select', 'required' => true, 'ref' => 'remarks'],
        ],
        'validate' => function (array $d, array &$err): void {
            global $db;
            if (isset($d['planting_id'], $d['harvest_date'])) {
                $p = $db->find('planting', 'planting_id', $d['planting_id'], 'planting_date');
                if ($p && $d['harvest_date'] < $p['planting_date']) {
                    $err['harvest_date'] = 'Harvest date cannot be before the planting date (' . $p['planting_date'] . ').';
                }
            }
        },
        'columns' => [
            ['ID', 'harvest_id', fn($r) => $r['harvest_id']],
            ['Harvest date', 'harvest_date', fn($r) => $r['harvest_date']],
            ['Planting', null, fn($r) => ($r['planting']['crop']['crop_name'] ?? '?') . ' @ ' . ($r['planting']['farm']['farm_name'] ?? '?')],
            ['Qty (kg)', 'quantity_kg', fn($r) => number_format((float)$r['quantity_kg'], 2)],
            ['Price / kg', 'selling_price_per_kg', fn($r) => money($r['selling_price_per_kg'])],
            ['Est. value', null, fn($r) => money($r['quantity_kg'] * $r['selling_price_per_kg'])],
            ['Grade', null, fn($r) => $r['quality_grade']['grade_name'] ?? ''],
            ['Remarks', null, fn($r) => $r['harvest_remark']['remark_description'] ?? ''],
        ],
    ],
    'users' => [
        'title' => 'Users', 'file' => 'users.php', 'icon' => 'bi-people', 'table' => 'app_user', 'pk' => 'user_id', 'adminOnly' => true,
        'select' => 'user_id,username,full_name,email,role_id,created_at,updated_at,created_by,updated_by,user_role(role_name)',
        'search' => ['username', 'full_name', 'email'],
        'searchHint' => 'Search by username, full name, or email',
        'filters' => [['field' => 'role_id', 'label' => 'Role', 'type' => 'select', 'ref' => 'roles']],
        'fields' => [
            ['name' => 'username', 'label' => 'Username', 'type' => 'text', 'required' => true, 'max_len' => 50, 'pattern' => '/^[A-Za-z0-9_.-]{3,50}$/', 'hint' => '3-50 letters, numbers, _ . -'],
            ['name' => 'full_name', 'label' => 'Full name', 'type' => 'text', 'required' => true, 'max_len' => 100],
            ['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true, 'max_len' => 100],
            ['name' => 'role_id', 'label' => 'Role', 'type' => 'select', 'required' => true, 'ref' => 'roles_assign',
             'hint' => 'Only the Super Admin can create or change Administrator accounts.'],
            ['name' => 'password', 'label' => 'Password', 'type' => 'password', 'hint' => 'Minimum 6 characters. When editing, leave blank to keep the current password.'],
        ],
        'columns' => [
            ['ID', 'user_id', fn($r) => $r['user_id']],
            ['Username', 'username', fn($r) => $r['username']],
            ['Full name', 'full_name', fn($r) => $r['full_name']],
            ['Email', 'email', fn($r) => $r['email']],
            ['Role', null, fn($r) => $r['user_role']['role_name'] ?? ''],
        ],
    ],
];

function friendly(SupabaseException $ex, string $action = 'save'): string
{
    $map = [
        '42501' => 'Database permission denied. Run schema.sql grants and verify SUPABASE_SERVICE_ROLE_KEY in .env.',
        '23503' => 'This action is blocked because the record is linked to other records.',
        '23505' => 'A record with the same unique value already exists.',
        '23514' => 'One of the values breaks a database rule (check numbers and dates).',
        '23502' => 'A required value is missing.',
        '22P02' => 'Invalid value format provided.',
        '22001' => 'One of the text values exceeds the maximum allowed length.',
        '22003' => 'One of the numeric values is out of range.',
        '22007' => 'Invalid date format provided.',
        '22008' => 'Date value is out of range.',
    ];
    if ($ex->pgCode === 'P0001') return $ex->getMessage();
    $msg = $map[$ex->pgCode] ?? "Could not $action the record. Please try again.";
    return APP_DEBUG ? $msg . ' [' . $ex->getMessage() . ']' : $msg;
}

function options(string $refKey): array
{
    global $db, $REF;
    static $cache = [];
    if (isset($cache[$refKey])) return $cache[$refKey];
    $ref = $REF[$refKey];
    $sel = $ref['select'] ?? ($ref['id'] . ',' . implode(',', (array)$ref['label']));
    $out = [];
    foreach ($db->select($ref['table'], $sel, [], $ref['id'] . '.asc', 1, 1000)['data'] as $row) {
        if ($refKey === 'roles' && (int)$row['role_id'] === ROLE_SUPER && !is_super_admin()) continue;
        if ($refKey === 'roles_assign' && !is_super_admin() && (int)$row['role_id'] !== ROLE_STAFF) continue;
        $out[(int)$row[$ref['id']]] = isset($ref['labelFn'])
            ? $ref['labelFn']($row)
            : implode(', ', array_map(fn($c) => $row[$c], (array)$ref['label']));
    }
    return $cache[$refKey] = $out;
}

function collect(array $m, bool $isNew, array &$err): array
{
    $d = [];
    foreach ($m['fields'] as $f) {
        $n = $f['name'];
        $raw = trim((string)($_POST[$n] ?? ''));

        if ($f['type'] === 'password') {
            if ($raw === '') { if ($isNew) $err[$n] = 'Password is required.'; continue; }
            if (strlen($raw) < 6) { $err[$n] = 'Password must be at least 6 characters.'; continue; }
            $d['password_hash'] = password_hash($raw, PASSWORD_DEFAULT);
            continue;
        }
        if ($raw === '') {
            if (!empty($f['required'])) $err[$n] = $f['label'] . ' is required.'; else $d[$n] = null;
            continue;
        }
        switch ($f['type']) {
            case 'number':
                if (!is_numeric($raw)) { $err[$n] = $f['label'] . ' must be a valid number.'; break; }
                $v = $raw + 0;
                if (!empty($f['int']) && floor((float)$v) != $v) { $err[$n] = $f['label'] . ' must be a whole number.'; break; }
                if (isset($f['min']) && $v < $f['min']) { $err[$n] = $f['label'] . ' must be at least ' . $f['min'] . '.'; break; }
                if (isset($f['max']) && $v > $f['max']) { $err[$n] = $f['label'] . ' must not exceed ' . $f['max'] . '.'; break; }
                $d[$n] = $v;
                break;
            case 'date':
                $dt = DateTime::createFromFormat('Y-m-d', $raw);
                if (!$dt || $dt->format('Y-m-d') !== $raw) $err[$n] = $f['label'] . ' must be a valid date.'; else $d[$n] = $raw;
                break;
            case 'select':
                if (!ctype_digit($raw) || !array_key_exists((int)$raw, options($f['ref']))) {
                    $err[$n] = 'Please choose a valid option for ' . strtolower($f['label']) . '.';
                } else $d[$n] = (int)$raw;
                break;
            case 'email':
                if (!filter_var($raw, FILTER_VALIDATE_EMAIL) || str_len($raw) > ($f['max_len'] ?? 255)) $err[$n] = 'Please enter a valid email address.'; else $d[$n] = $raw;
                break;
            default:
                if (str_len($raw) > ($f['max_len'] ?? 255)) { $err[$n] = $f['label'] . ' is too long (max ' . ($f['max_len'] ?? 255) . ' characters).'; break; }
                if (!empty($f['pattern']) && !preg_match($f['pattern'], $raw)) { $err[$n] = $f['label'] . ' has an invalid format.'; break; }
                $d[$n] = $raw;
        }
    }
    if (!$err && isset($m['validate'])) $m['validate']($d, $err);
    return $d;
}

/**
 * Who may manage whose account (Users page):
 *   Super Admin  -> everyone
 *   Administrator -> Staff accounts only (never another Administrator, never a Super Admin)
 */
function can_manage_user(int $targetRole): bool
{
    return is_super_admin() || $targetRole === ROLE_STAFF;
}

/** Stops edit / delete of an account the current person is not allowed to manage. */
function guard_user_target(array $m, string $id): void
{
    global $db;
    if ($m['table'] !== 'app_user' || is_super_admin()) return;
    try {
        $t = $db->find('app_user', 'user_id', $id, 'user_id,role_id');
    } catch (SupabaseException $ex) {
        flash('danger', 'Could not check your permission for this account. Please try again.');
        redirect($m['file']);
    }
    if (!$t || can_manage_user((int)$t['role_id'])) return;
    flash('danger', (int)$t['role_id'] === ROLE_SUPER
        ? 'Access denied. Only the Super Admin can change a Super Admin account.'
        : 'Access denied. Administrators cannot edit or delete Administrator accounts. Only the Super Admin can '
          . '(use "Change password" in your user menu for your own account).');
    redirect($m['file']);
}

function cascade_summary(string $table, int $id, string $stamp): string
{
    global $db;
    try {
        $rows = $db->select('audit_log', 'table_name', [
            'action' => 'eq.delete', 'deleted_stamp' => 'eq.' . $stamp,
            'cascade_from_table' => 'eq.' . $table, 'cascade_from_id' => 'eq.' . $id,
        ], null, 1, 5000)['data'];
    } catch (SupabaseException $ex) {
        return '';
    }
    $names = ['farm' => ['farm', 'farms'], 'planting' => ['planting record', 'planting records'],
              'harvest' => ['harvest record', 'harvest records']];
    $count = [];
    foreach ($rows as $r) $count[$r['table_name']] = ($count[$r['table_name']] ?? 0) + 1;
    $parts = [];
    foreach ($names as $t => [$one, $many]) {
        if (!empty($count[$t])) $parts[] = $count[$t] . ' ' . ($count[$t] === 1 ? $one : $many);
    }
    return implode(', ', $parts);
}

/* ---------- Generic Module CRUD Controller ---------- */
function run_module(string $key): void
{
    global $db, $modules;
    require_login();
    $m = $modules[$key];
    if (!empty($m['adminOnly'])) require_admin();

    $file = $m['file'];
    $isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
    $action = $_GET['action'] ?? 'list';
    $rawId = $_GET['id'] ?? null;
    $id = ($rawId !== null && ctype_digit((string)$rawId) && (int)$rawId > 0) ? (string)(int)$rawId : null;
    $errors = [];
    $old = [];

    if ($isPost && $action === 'delete') {
        csrf_check();
        if (!is_admin()) {
            http_response_code(403);
            flash('danger', 'Access denied. Only administrators can delete records.');
            redirect($file);
        }
        $delId = (int)($_POST['id'] ?? 0);
        if ($delId <= 0) {
            flash('danger', 'Invalid record selected for deletion.');
        } elseif ($key === 'users' && $delId === (int)current_user()['user_id']) {
            flash('danger', 'You cannot delete your own account.');
        } else {
            guard_user_target($m, (string)$delId);
            try {
                [$n, $stamp] = $db->delete($m['table'], $m['pk'], $delId, current_user()['username']);
                if ($n === 0) {
                    flash('warning', 'That record was not found (it may already be deleted).');
                } else {
                    $also = $stamp !== '' ? cascade_summary($m['table'], $delId, $stamp) : '';
                    flash('success', 'Record deleted successfully.'
                        . ($also !== '' ? ' Everything connected to it was deleted too: ' . $also . '.' : ''));
                }
            } catch (SupabaseException $ex) {
                flash('danger', friendly($ex, 'delete'));
            }
        }
        redirect($file);
    }

    if ($isPost && $action === 'save') {
        csrf_check();
        if (!can_edit()) {
            http_response_code(403);
            flash('danger', 'Access denied. You must be logged in to add or edit records.');
            redirect($file);
        }
        $isNew = $id === null;
        if ($rawId !== null && $id === null) {
            flash('danger', 'Invalid record ID.');
            redirect($file);
        }
        $old = $_POST;
        if (!$isNew) guard_user_target($m, (string)$id);
        try {
            $data = collect($m, $isNew, $errors);
            if ($key === 'users' && !$isNew && (int)$id === (int)current_user()['user_id']
                && isset($data['role_id']) && (int)$data['role_id'] !== (int)current_user()['role_id']) {
                $errors['role_id'] = 'You cannot change your own role.';
            }
        } catch (SupabaseException $ex) {
            $data = [];
            $errors['_'] = friendly($ex);
        }
        if (!$errors) {
            try {
                $who = current_user()['username'];
                if ($isNew) {
                    $data['created_by'] = $who;
                    $needsApproval = in_array($m['table'], GATED_TABLES, true) && !is_super_admin();
                    if ($needsApproval) $data['approval_status'] = 'pending';
                    $db->insert($m['table'], $data);
                    flash($needsApproval ? 'info' : 'success', $needsApproval
                        ? 'Record submitted. It will appear in the table once the Super Admin approves it.'
                        : 'Record created successfully.');
                } else {
                    $data['updated_by'] = $who;
                    $db->update($m['table'], $m['pk'], $id, $data);
                    flash('success', 'Record updated successfully.');
                }
                redirect($file);
            } catch (SupabaseException $ex) {
                $errors['_'] = friendly($ex);
            }
        }
        $action = $isNew ? 'add' : 'edit';
    }

    if ($action === 'add' || $action === 'edit') {
        if (!can_edit()) {
            http_response_code(403);
            layout_start('Access denied');
            echo '<div class="alert alert-danger">Access denied. You must be logged in to add or edit records. '
               . '<a href="' . e($file) . '">Back to ' . e($m['title']) . '</a></div>';
            layout_end();
            exit;
        }
        if ($action === 'edit' && $id === null) {
            flash('danger', 'Record not found.');
            redirect($file);
        }
        if ($action === 'edit') guard_user_target($m, (string)$id);
        module_form($m, $action, $id, $errors, $old);
        return;
    }

    if ($action === 'view') {
        if ($id === null) {
            flash('danger', 'Record not found.');
            redirect($file);
        }
        module_view($m, $id);
        return;
    }

    module_list($m);
}

function module_form(array $m, string $action, ?string $id, array $errors, array $old): void
{
    global $db;
    $file = $m['file'];
    $row = [];
    if ($action === 'edit') {
        try {
            $row = $db->find($m['table'], $m['pk'], $id, $m['select']) ?? [];
        } catch (SupabaseException $ex) {
            flash('danger', friendly($ex, 'load'));
            redirect($file);
        }
        if (!$row) {
            flash('danger', 'Record not found.');
            redirect($file);
        }
    }
    $defaults = array_column($m['fields'], 'default', 'name');
    $val = fn($n) => $old ? ($old[$n] ?? '') : ($row[$n] ?? ($defaults[$n] ?? ''));

    layout_start(($action === 'add' ? 'Add ' : 'Edit ') . $m['title']); ?>
    <div class="form-shell">
    <h3 class="form-title"><?= $action === 'add' ? 'Add' : 'Edit' ?> &middot; <?= e($m['title']) ?></h3>
    <?php if (isset($errors['_'])): ?><div class="alert alert-danger"><?= e($errors['_']) ?></div><?php endif; ?>
    <?php if ($errors && !isset($errors['_'])): ?><div class="alert alert-danger">Please fix the highlighted fields below.</div><?php endif; ?>
    <form method="post" action="<?= e(purl($file, ['action' => 'save'] + ($action === 'edit' ? ['id' => $id] : []))) ?>"
          class="card form-card needs-validation" novalidate>
      <?= csrf_field() ?>
      <?php foreach ($m['fields'] as $f): $n = $f['name']; $inv = isset($errors[$n]) ? 'is-invalid' : ''; ?>
        <div class="mb-3">
          <label class="form-label" for="f_<?= e($n) ?>"><?= e($f['label']) ?><?= !empty($f['required']) || ($f['type'] === 'password' && $action === 'add') ? ' <span class="text-danger">*</span>' : '' ?></label>
          <?php if ($f['type'] === 'select'): ?>
            <?php
              $opts = [];
              try {
                  $opts = options($f['ref']);
              } catch (SupabaseException $ex) {
                  $opts = [];
              }
            ?>
            <select class="form-select <?= $inv ?>" id="f_<?= e($n) ?>" name="<?= e($n) ?>" <?= !empty($f['required']) ? 'required' : '' ?>>
              <option value="">-- Select --</option>
              <?php foreach ($opts as $oid => $olabel): ?>
                <option value="<?= $oid ?>" <?= (string)$val($n) === (string)$oid ? 'selected' : '' ?>><?= e($olabel) ?></option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input class="form-control <?= $inv ?>" id="f_<?= e($n) ?>" name="<?= e($n) ?>" type="<?= e($f['type']) ?>"
              value="<?= $f['type'] === 'password' ? '' : e($val($n)) ?>"
              <?= !empty($f['required']) ? 'required' : '' ?>
              <?= isset($f['min']) ? 'min="' . $f['min'] . '"' : '' ?> <?= isset($f['max']) ? 'max="' . $f['max'] . '"' : '' ?>
              <?= isset($f['step']) ? 'step="' . $f['step'] . '"' : '' ?> <?= isset($f['max_len']) && $f['type'] !== 'number' ? 'maxlength="' . $f['max_len'] . '"' : '' ?>
              <?= $f['type'] === 'password' ? 'autocomplete="new-password" minlength="6"' : '' ?>>
          <?php endif; ?>
          <?php if (isset($errors[$n])): ?><div class="invalid-feedback d-block"><?= e($errors[$n]) ?></div>
          <?php elseif (!empty($f['hint'])): ?><div class="form-text"><?= e($f['hint']) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
      <div class="form-actions">
        <button class="btn btn-success"><i class="bi bi-check-lg"></i> Save</button>
        <a class="btn btn-outline-secondary" href="<?= e($file) ?>">Cancel</a>
      </div>
    </form>
    </div>
    <?php layout_end();
}

function module_view(array $m, ?string $id): void
{
    global $db;
    $file = $m['file'];
    try {
        $row = $db->find($m['table'], $m['pk'], $id, $m['select']);
    } catch (SupabaseException $ex) {
        flash('danger', friendly($ex, 'load'));
        redirect($file);
    }
    if (!$row) {
        flash('danger', 'Record not found.');
        redirect($file);
    }

    layout_start('View ' . $m['title']); ?>
    <div class="form-shell">
    <h3 class="form-title">View &middot; <?= e($m['title']) ?> #<?= e($id) ?></h3>
    <div class="card form-card"><dl class="row mb-0">
      <?php foreach ($m['columns'] as $c): ?>
        <dt class="col-sm-4"><?= e($c[0]) ?></dt><dd class="col-sm-8"><?= !empty($c[3]) ? $c[2]($row) : e($c[2]($row)) ?></dd>
      <?php endforeach; ?>
      <?php foreach (['created_at' => 'Created at', 'created_by' => 'Created by', 'updated_at' => 'Last updated', 'updated_by' => 'Updated by'] as $k => $lbl):
          if (!array_key_exists($k, $row)) continue; ?>
        <dt class="col-sm-4"><?= $lbl ?></dt><dd class="col-sm-8"><?= e($row[$k] ?? '—') ?></dd>
      <?php endforeach; ?>
    </dl></div>
    <div class="form-actions mt-3">
      <?php if (can_edit() && !($m['table'] === 'app_user' && !can_manage_user((int)($row['role_id'] ?? 0)))): ?>
        <a class="btn btn-success" href="<?= e(purl($file, ['action' => 'edit', 'id' => $id])) ?>"><i class="bi bi-pencil"></i> Edit</a>
      <?php endif; ?>
      <a class="btn btn-outline-secondary" href="<?= e($file) ?>">Back to list</a>
    </div>
    </div>
    <?php layout_end();
}

function module_list(array $m): void
{
    global $db;
    $file = $m['file'];

    $filters = [];
    $clean = fn($s) => trim(preg_replace('/[,()*%\\\\]/', '', (string)$s));
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '' && ($s = $clean($q)) !== '') {
        $parts = array_map(fn($c) => "$c.ilike.*$s*", $m['search'] ?? []);
        foreach ($m['searchRefs'] ?? [] as $sr) {
            try {
                $ids = array_column($db->select($sr['table'], $sr['pk'], [$sr['col'] => "ilike.*$s*"], null, 1, 200)['data'], $sr['pk']);
            } catch (SupabaseException $ex) { $ids = []; }
            if ($ids) $parts[] = $sr['fk'] . '.in.(' . implode(',', array_map('intval', $ids)) . ')';
        }
        $filters['or'] = '(' . ($parts ? implode(',', $parts) : $m['pk'] . '.eq.0') . ')';
    }
    foreach ($m['filters'] as $f) {
        $v = trim((string)($_GET['f_' . $f['field']] ?? ''));
        if ($v === '') continue;
        if ($f['type'] === 'text' && ($s = $clean($v)) !== '') $filters[$f['field']] = "ilike.*$s*";
        elseif ($f['type'] === 'select' && ctype_digit($v)) $filters[$f['field']] = "eq.$v";
    }

    $searching = $q !== '';
    foreach ($m['filters'] as $f) {
        if (trim((string)($_GET['f_' . $f['field']] ?? '')) !== '') { $searching = true; break; }
    }

    if ($m['table'] === 'app_user') {
        $conds = ['approval_status.eq.approved']; // pending / declined requests are handled under Requests
        if (!is_super_admin()) $conds[] = 'role_id.neq.' . ROLE_SUPER;
        $filters['and'] = '(' . implode(',', $conds) . ')';
    }

    $sortable = array_filter(array_column($m['columns'], 1));
    $sort = in_array($_GET['sort'] ?? '', $sortable, true) ? $_GET['sort'] : $m['pk'];
    $dir = isset($_GET['dir']) ? (($_GET['dir'] === 'desc') ? 'desc' : 'asc') : 'desc'; // newest first by default
    $pg = max(1, (int)($_GET['p'] ?? 1));

    try {
        $res = $db->select($m['table'], $m['select'], $filters, "$sort.$dir", $pg, PER_PAGE);
        $loadErr = '';
    } catch (SupabaseException $ex) {
        $res = ['data' => [], 'total' => 0];
        $loadErr = friendly($ex, 'load');
    }

    layout_start($m['title']); ?>
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
      <h3 class="mb-0"><i class="bi <?= e($m['icon']) ?>"></i> <?= e($m['title']) ?></h3>
      <?php if (can_edit()): ?>
        <a class="btn btn-success" href="<?= e(purl($file, ['action' => 'add'])) ?>"><i class="bi bi-plus-lg"></i> Add new</a>
      <?php endif; ?>
    </div>

    <form class="card p-3 mb-3" method="get" action="<?= e($file) ?>">
      <div class="row g-2 align-items-end">
        <div class="col-md-4"><label class="form-label">Search</label>
          <input name="q" class="form-control" placeholder="<?= e($m['searchHint'] ?? 'Search records...') ?>" value="<?= e($q) ?>"></div>
        <?php foreach ($m['filters'] as $f): $fv = $_GET['f_' . $f['field']] ?? ''; ?>
          <div class="col-md-3"><label class="form-label"><?= e($f['label']) ?></label>
            <?php if ($f['type'] === 'select'): ?>
              <?php
                $filterOpts = [];
                try {
                    $filterOpts = options($f['ref']);
                } catch (SupabaseException $ex) {
                    $filterOpts = [];
                }
              ?>
              <select name="f_<?= e($f['field']) ?>" class="form-select"><option value="">All</option>
                <?php foreach ($filterOpts as $oid => $ol): ?>
                  <option value="<?= $oid ?>" <?= (string)$fv === (string)$oid ? 'selected' : '' ?>><?= e($ol) ?></option>
                <?php endforeach; ?>
              </select>
            <?php else: ?><input name="f_<?= e($f['field']) ?>" class="form-control" placeholder="Type to filter by <?= e(strtolower($f['label'])) ?>" value="<?= e($fv) ?>"><?php endif; ?></div>
        <?php endforeach; ?>
        <div class="col-auto d-flex gap-2">
          <button class="btn btn-success"><i class="bi bi-search"></i> Apply</button>
          <a class="btn btn-outline-secondary" href="<?= e($file) ?>">Reset</a>
        </div>
      </div>
    </form>

    <?php if ($loadErr): ?><div class="alert alert-danger"><?= e($loadErr) ?></div><?php endif; ?>
    <?php if (!$loadErr && $searching && (int)$res['total'] === 0): ?>
      <div class="alert alert-warning d-flex align-items-center gap-2" role="alert">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
          <?php if ($q !== ''): ?>No match found for <strong>&ldquo;<?= e($q) ?>&rdquo;</strong><?= count(array_filter(array_map(fn($f) => trim((string)($_GET['f_' . $f['field']] ?? '')), $m['filters']))) ? ' with the selected filters' : '' ?>.
          <?php else: ?>No match found for the selected filters.<?php endif; ?>
          Check the spelling, try a different keyword, or <a href="<?= e($file) ?>" class="alert-link">reset the search</a>.
        </div>
      </div>
    <?php endif; ?>
    <div class="card p-2">
    <div class="table-responsive"><table class="table table-striped table-hover align-middle mb-0">
      <thead><tr>
        <?php foreach ($m['columns'] as $c): ?>
          <th><?php if ($c[1]): $nd = ($sort === $c[1] && $dir === 'asc') ? 'desc' : 'asc'; ?>
            <a href="<?= e(qs(['sort' => $c[1], 'dir' => $nd, 'p' => 1])) ?>"><?= e($c[0]) ?>
              <?= $sort === $c[1] ? ($dir === 'asc' ? '<i class="bi bi-caret-up-fill"></i>' : '<i class="bi bi-caret-down-fill"></i>') : '' ?></a>
          <?php else: echo e($c[0]); endif; ?></th>
        <?php endforeach; ?>
        <th class="text-end">Actions</th>
      </tr></thead>
      <tbody>
      <?php if (!$res['data']): ?><tr><td colspan="<?= count($m['columns']) + 1 ?>" class="text-center text-muted py-4"><?= $searching ? 'No match found.' : 'No records found.' ?></td></tr><?php endif; ?>
      <?php foreach ($res['data'] as $r): $rid = $r[$m['pk']]; ?>
        <tr>
          <?php foreach ($m['columns'] as $c): $cell = !empty($c[3]) ? $c[2]($r) : e($c[2]($r)); ?><td><?= trim((string)$cell) === '' ? '&mdash;' : $cell ?></td><?php endforeach; ?>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" title="View" href="<?= e(purl($file, ['action' => 'view', 'id' => $rid])) ?>"><i class="bi bi-eye"></i></a>
            <?php $locked = $m['table'] === 'app_user' && !can_manage_user((int)($r['role_id'] ?? 0)); ?>
            <?php if ($locked): ?>
              <span class="lock-note" title="Only the Super Admin can manage this account"><i class="bi bi-lock-fill"></i> Super Admin only</span>
            <?php endif; ?>
            <?php if (can_edit() && !$locked): ?>
              <a class="btn btn-sm btn-outline-success" title="Edit" href="<?= e(purl($file, ['action' => 'edit', 'id' => $rid])) ?>"><i class="bi bi-pencil"></i></a>
            <?php endif; ?>
            <?php if (is_admin() && !$locked): ?>
              <button type="button" class="btn btn-sm btn-outline-danger" title="Delete" data-bs-toggle="modal" data-bs-target="#delModal"
                      data-delete-id="<?= (int)$rid ?>" data-action="<?= e(purl($file, ['action' => 'delete'])) ?>"
                      data-label="<?= e($m['title'] . ' #' . $rid) ?>" data-warn="<?= e($m['cascadeWarn'] ?? '') ?>"><i class="bi bi-trash"></i></button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div></div>
    <div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
      <small class="text-muted"><?= (int)$res['total'] ?> record(s)</small>
      <?php pagination($res['total'], $pg, PER_PAGE); ?>
    </div>
    <?php layout_end();
}