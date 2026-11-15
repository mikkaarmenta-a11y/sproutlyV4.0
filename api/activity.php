<?php
require __DIR__ . '/../lib/core.php';
require_super_admin();

$tables = [
    'farmer' => ['Farmer', 'farmers'], 'farm' => ['Farm', 'farms'], 'crop' => ['Crop', 'crops'],
    'planting' => ['Planting record', 'plantings'], 'harvest' => ['Harvest record', 'harvests'], 'app_user' => ['User', 'users'],
];
$pkOf = ['farmer' => 'farmer_id', 'farm' => 'farm_id', 'crop' => 'crop_id', 'planting' => 'planting_id',
         'harvest' => 'harvest_id', 'app_user' => 'user_id'];
$views = ['requests' => 'Requests', 'approvals' => 'Approvals', 'deleted' => 'Deleted', 'updated' => 'Updated', 'all' => 'All activity', 'sessions' => 'Sessions'];
$view = $_GET['view'] ?? 'requests';
if (!isset($views[$view])) $view = 'deleted';
$me = current_user()['username'];

/* ---------- Actions: undelete / undo update ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    try {
        if ($do === 'restore') {
            $t = (string)($_POST['table'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            if (!isset($pkOf[$t]) || $id <= 0) throw new RuntimeException('Invalid record.');
            if ($db->restore($t, $pkOf[$t], $id, $me) === 0) {
                flash('warning', 'That record is not deleted (it may already be restored).');
            } else {
                flash('success', 'Record undeleted. Connected records that were deleted together with it were brought back too.');
            }
        } elseif ($do === 'approve' || $do === 'reject') {
            $uid = (int)($_POST['id'] ?? 0);
            $u = $uid > 0 ? $db->find('app_user', 'user_id', $uid, 'user_id,full_name,role_id,approval_status') : null;
            if (!$u) throw new RuntimeException('Account request not found.');
            if ($do === 'approve' && $u['approval_status'] === 'approved') {
                flash('warning', $u['full_name'] . ' is already approved.');
            } elseif ($do === 'reject' && $u['approval_status'] !== 'pending') {
                flash('warning', 'Only pending requests can be declined.');
            } elseif ($do === 'approve') {
                $role = (int)($_POST['role_id'] ?? $u['role_id']);
                if (!in_array($role, [ROLE_ADMIN, ROLE_STAFF], true)) throw new RuntimeException('Please choose Admin or Staff.');
                $db->update('app_user', 'user_id', $uid, ['approval_status' => 'approved', 'role_id' => $role,
                            'approved_by' => $me, 'approved_at' => gmdate('c'), 'updated_by' => $me]);
                flash('success', $u['full_name'] . ' was approved and can now sign in.');
            } else {
                $db->update('app_user', 'user_id', $uid, ['approval_status' => 'rejected', 'approved_by' => $me,
                            'approved_at' => gmdate('c'), 'updated_by' => $me]);
                flash('success', 'The request from ' . $u['full_name'] . ' was declined.');
            }
            if (($_POST['return'] ?? '') === 'dashboard') redirect('index.php');
        } elseif ($do === 'approve_record' || $do === 'reject_record') {
            $t = (string)($_POST['table'] ?? '');
            $id = (int)($_POST['id'] ?? 0);
            if (!in_array($t, GATED_TABLES, true) || $id <= 0) throw new RuntimeException('Invalid record.');
            $ok = $do === 'approve_record';
            $db->update($t, $pkOf[$t], $id, [
                'approval_status' => $ok ? 'approved' : 'rejected',
                'approved_by' => $me, 'approved_at' => gmdate('c'), 'updated_by' => $me,
            ]);
            flash('success', $ok ? 'Record approved. It now appears in its table.' : 'Record declined. It will not appear in the table.');
            if (($_POST['return'] ?? '') === 'dashboard') redirect('index.php');
        } elseif ($do === 'revert') {
            $logId = (int)($_POST['log_id'] ?? 0);
            if ($logId <= 0) throw new RuntimeException('Invalid entry.');
            $res = $db->rpc('revert_update', ['p_log_id' => $logId, 'p_by' => $me]);
            flash('success', 'Update undone' . (!empty($res['label']) ? ': ' . $res['label'] : '') . '.');
        }
    } catch (SupabaseException $ex) {
        flash('danger', friendly($ex, 'undo'));
    } catch (RuntimeException $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect(purl('activity.php', array_filter(['view' => $view, 'f_table' => $_GET['f_table'] ?? '', 'p' => $_GET['p'] ?? ''])));
}

/* ---------- Readable values ---------- */
$fieldDefs = [];   // table => field name => field definition
foreach ($modules as $mod) {
    foreach ($mod['fields'] as $f) $fieldDefs[$mod['table']][$f['name']] = $f;
}
function show_value(string $table, string $field, $val): string
{
    global $fieldDefs;
    if ($val === null || $val === '') return '—';
    $f = $fieldDefs[$table][$field] ?? null;
    if ($f && $f['type'] === 'select') {
        try {
            $opts = options($f['ref']);
        } catch (SupabaseException $ex) {
            $opts = [];
        }
        return $opts[(int)$val] ?? ('#' . $val . ' (deleted)');
    }
    if ($field === 'password_hash') return '(password)';
    return (string)$val;
}
function field_label(string $table, string $field): string
{
    global $fieldDefs;
    if ($field === 'password_hash') return 'Password';
    return $fieldDefs[$table][$field]['label'] ?? ucwords(str_replace('_', ' ', $field));
}
function when_label(?string $ts): string
{
    if (!$ts) return '—';
    try {
        return (new DateTime($ts))->setTimezone(new DateTimeZone('Asia/Manila'))->format('M j, Y g:i A');
    } catch (Exception $ex) {
        return $ts;
    }
}
function action_badge(string $a, bool $undone): string
{
    $map = ['delete' => ['danger', 'Deleted'], 'update' => ['primary', 'Updated'],
            'restore' => ['success', 'Undeleted'], 'revert' => ['secondary', 'Update undone']];
    [$c, $t] = $map[$a] ?? ['secondary', $a];
    $out = '<span class="badge text-bg-' . $c . '">' . e($t) . '</span>';
    if ($undone) $out .= ' <span class="badge text-bg-light border">' . ($a === 'delete' ? 'undeleted' : 'undone') . '</span>';
    return $out;
}

/* ---------- Query ---------- */
$pg = max(1, (int)($_GET['p'] ?? 1));
$ft = (string)($_GET['f_table'] ?? '');
if (!isset($tables[$ft])) $ft = '';
$rows = []; $total = 0; $loadErr = '';
$kids = []; // delete stamp => counts of connected records deleted with it
$sessions = [];
$requests = [];
$approvals = [];
$pendingCount = 0;
$pendingRecords = pending_record_count();

try {
    $pendingCount = $db->select('app_user', 'user_id', ['approval_status' => 'eq.pending'], null, 1, 1)['total'];
} catch (SupabaseException $ex) {}

try {
    if ($view === 'requests') {
        $res = $db->select('app_user', 'user_id,username,full_name,email,role_id,approval_status,created_at,approved_by,approved_at,user_role(role_name)',
                           ['approval_status' => 'in.(pending,rejected)'], 'created_at.desc', $pg, PER_PAGE);
        $requests = $res['data']; $total = $res['total'];
    } elseif ($view === 'approvals') {
        foreach ($modules as $mod) {
            if (!in_array($mod['table'], GATED_TABLES, true)) continue;
            $r = $db->select($mod['table'], $mod['select'], ['approval_status' => 'in.(pending,rejected)'],
                             $mod['pk'] . '.desc', 1, 50);
            foreach ($r['data'] as $row) $approvals[] = ['mod' => $mod, 'row' => $row];
        }
        usort($approvals, fn($a, $b) => strcmp((string)($b['row']['created_at'] ?? ''), (string)($a['row']['created_at'] ?? '')));
        $total = count($approvals);
    } elseif ($view === 'sessions') {
        $res = $db->select('app_session', 'username,full_name,role_name,status,device,ip_address,logged_in_at,last_active_at,expires_at',
                           [], 'last_active_at.desc', $pg, PER_PAGE);
        $sessions = $res['data']; $total = $res['total'];
    } else {
        $filters = [];
        if ($view === 'deleted') { $filters['action'] = 'eq.delete'; $filters['undone_at'] = 'is.null'; }
        if ($view === 'updated') { $filters['action'] = 'in.(update,revert)'; }
        if ($ft !== '') $filters['table_name'] = 'eq.' . $ft;
        $res = $db->select('audit_log', '*', $filters, 'log_id.desc', $pg, PER_PAGE);
        $rows = $res['data']; $total = $res['total'];

        if ($view === 'deleted') {
            $stamps = array_values(array_unique(array_filter(array_map(
                fn($r) => empty($r['cascade_from_table']) ? $r['deleted_stamp'] : null, $rows))));
            if ($stamps) {
                $quoted = implode(',', array_map(fn($s) => '"' . $s . '"', $stamps));
                $k = $db->select('audit_log', 'table_name,deleted_stamp,cascade_from_table,cascade_from_id',
                                 ['action' => 'eq.delete', 'undone_at' => 'is.null', 'cascade_from_table' => 'not.is.null',
                                  'deleted_stamp' => 'in.(' . $quoted . ')'], null, 1, 5000)['data'];
                foreach ($k as $r) {
                    $key = strtotime($r['deleted_stamp']) . '|' . $r['cascade_from_table'] . '|' . $r['cascade_from_id'];
                    $kids[$key][$r['table_name']] = ($kids[$key][$r['table_name']] ?? 0) + 1;
                }
            }
        }
    }
} catch (SupabaseException $ex) {
    $loadErr = friendly($ex, 'load');
    if ($ex->pgCode === '42P01' || stripos($ex->getMessage(), 'audit_log') !== false || stripos($ex->getMessage(), 'app_session') !== false) {
        $loadErr .= ' Did you run database/sessions.sql and database/super_admin.sql in Supabase?';
    }
}

function kids_text(array $c): string
{
    $names = ['farm' => ['farm', 'farms'], 'planting' => ['planting record', 'planting records'],
              'harvest' => ['harvest record', 'harvest records']];
    $p = [];
    foreach ($names as $t => [$one, $many]) {
        if (!empty($c[$t])) $p[] = $c[$t] . ' ' . ($c[$t] === 1 ? $one : $many);
    }
    return implode(', ', $p);
}

layout_start('Activity'); ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h3 class="mb-0"><i class="bi bi-clock-history"></i> Activity</h3>
  <span class="text-muted small">Super Admin only &middot; times shown in Philippine time</span>
</div>

<ul class="nav nav-pills mb-3">
  <?php foreach ($views as $k => $label): ?>
    <li class="nav-item"><a class="nav-link <?= $view === $k ? 'active' : '' ?>" href="<?= e(purl('activity.php', ['view' => $k])) ?>"><?= e($label) ?><?= $k === 'requests' && $pendingCount > 0 ? ' <span class="badge text-bg-danger">' . (int)$pendingCount . '</span>' : '' ?><?= $k === 'approvals' && $pendingRecords > 0 ? ' <span class="badge text-bg-danger">' . (int)$pendingRecords . '</span>' : '' ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($loadErr): ?><div class="alert alert-danger"><?= e($loadErr) ?></div><?php endif; ?>

<?php if ($view === 'requests'): ?>
  <p class="text-muted small mb-2">People who created an account. They cannot sign in until you approve them. You can change the role they asked for.</p>
  <div class="card p-2"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Requested on</th><th>Name</th><th>Username</th><th>Email</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if (!$requests && !$loadErr): ?><tr><td colspan="6" class="text-center text-muted py-4">No account requests.</td></tr><?php endif; ?>
    <?php foreach ($requests as $q): $pending = $q['approval_status'] === 'pending'; ?>
      <tr>
        <td class="text-nowrap"><?= e(when_label($q['created_at'])) ?></td>
        <td><strong><?= e($q['full_name']) ?></strong></td>
        <td><?= e($q['username']) ?></td>
        <td><?= e($q['email']) ?></td>
        <td><span class="badge text-bg-<?= $pending ? 'warning' : 'secondary' ?>"><?= $pending ? 'Waiting for approval' : 'Declined' ?></span>
          <?php if (!$pending): ?><div class="small text-muted">by <?= e($q['approved_by'] ?? '?') ?>, <?= e(when_label($q['approved_at'] ?? null)) ?></div><?php endif; ?></td>
        <td class="text-end">
          <form method="post" class="d-inline-flex gap-1 align-items-center">
            <?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="id" value="<?= (int)$q['user_id'] ?>">
            <select name="role_id" class="form-select form-select-sm w-auto" aria-label="Role">
              <option value="<?= ROLE_STAFF ?>" <?= (int)$q['role_id'] === ROLE_STAFF ? 'selected' : '' ?>>Staff</option>
              <option value="<?= ROLE_ADMIN ?>" <?= (int)$q['role_id'] === ROLE_ADMIN ? 'selected' : '' ?>>Admin</option>
            </select>
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button>
          </form>
          <?php if ($pending): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Decline this account request?');">
              <?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="id" value="<?= (int)$q['user_id'] ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Decline</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>

<?php elseif ($view === 'approvals'): ?>
  <p class="text-muted small mb-2">New farmers, farms, crops, planting and harvest records added by Admin or Staff. They stay hidden from the tables until you approve them.</p>
  <div class="card p-2"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Submitted</th><th>Type</th><th>Details</th><th>Added by</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if (!$approvals && !$loadErr): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing is waiting for approval.</td></tr><?php endif; ?>
    <?php foreach ($approvals as $a): $mod = $a['mod']; $row = $a['row']; $t = $mod['table']; $rid = (int)$row[$mod['pk']];
          $isPending = ($row['approval_status'] ?? '') === 'pending';
          $bits = [];
          foreach ($mod['columns'] as $c) {
              if ($c[0] === 'ID' || count($bits) >= 4) continue;
              $cell = $c[2]($row); $cell = !empty($c[3]) ? trim(strip_tags((string)$cell)) : trim((string)$cell);
              if ($cell !== '') $bits[] = $c[0] . ': ' . $cell;
          } ?>
      <tr>
        <td class="text-nowrap"><?= e(when_label($row['created_at'] ?? null)) ?></td>
        <td><strong><?= e($tables[$t][0] ?? $t) ?></strong></td>
        <td class="small"><?= e(implode(' · ', $bits)) ?></td>
        <td><?= e($row['created_by'] ?? '—') ?></td>
        <td><span class="badge text-bg-<?= $isPending ? 'warning' : 'secondary' ?>"><?= $isPending ? 'Waiting for approval' : 'Declined' ?></span>
          <?php if (!$isPending): ?><div class="small text-muted">by <?= e($row['approved_by'] ?? '?') ?>, <?= e(when_label($row['approved_at'] ?? null)) ?></div><?php endif; ?></td>
        <td class="text-end text-nowrap">
          <form method="post" class="d-inline">
            <?= csrf_field() ?><input type="hidden" name="do" value="approve_record"><input type="hidden" name="table" value="<?= e($t) ?>"><input type="hidden" name="id" value="<?= $rid ?>">
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button>
          </form>
          <?php if ($isPending): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Decline this record? It will not appear in the table.');">
              <?= csrf_field() ?><input type="hidden" name="do" value="reject_record"><input type="hidden" name="table" value="<?= e($t) ?>"><input type="hidden" name="id" value="<?= $rid ?>">
              <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Decline</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
<?php elseif ($view !== 'sessions'): ?>
  <form class="card p-3 mb-3" method="get" action="activity.php">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="row g-2 align-items-end">
      <div class="col-md-4"><label class="form-label">Type of record</label>
        <select name="f_table" class="form-select"><option value="">All</option>
          <?php foreach ($tables as $t => [$label]): ?><option value="<?= e($t) ?>" <?= $ft === $t ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-auto d-flex gap-2">
        <button class="btn btn-success"><i class="bi bi-search"></i> Apply</button>
        <a class="btn btn-outline-secondary" href="<?= e(purl('activity.php', ['view' => $view])) ?>">Reset</a>
      </div>
    </div>
  </form>
  <p class="text-muted small mb-2">
    <?php if ($view === 'deleted'): ?>Records that are deleted right now. <strong>Undelete</strong> brings a record back together with everything that was deleted along with it.
    <?php elseif ($view === 'updated'): ?>Every change made to a record. <strong>Undo update</strong> puts the old values back (only if nothing changed them again since).
    <?php else: ?>Everything that was deleted, updated, undeleted or undone, newest first.<?php endif; ?>
  </p>

  <div class="card p-2"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>When</th><th>Action</th><th>Record</th><th>By</th><th><?= $view === 'deleted' ? 'Connected records' : 'Details' ?></th><th class="text-end">Actions</th></tr></thead>
    <tbody>
    <?php if (!$rows && !$loadErr): ?><tr><td colspan="6" class="text-center text-muted py-4">Nothing here yet.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r):
        $t = $r['table_name']; $undone = !empty($r['undone_at']); $typeName = $tables[$t][0] ?? $t;
        $old = is_array($r['old_data'] ?? null) ? $r['old_data'] : [];
        $new = is_array($r['new_data'] ?? null) ? $r['new_data'] : [];
        $isRoot = empty($r['cascade_from_table']); ?>
      <tr>
        <td class="text-nowrap"><?= e(when_label($r['logged_at'])) ?></td>
        <td><?= action_badge($r['action'], $undone) ?></td>
        <td><strong><?= e($r['record_label'] ?: ($typeName . ' #' . $r['record_id'])) ?></strong><br><small class="text-muted"><?= e($typeName) ?> #<?= (int)$r['record_id'] ?></small></td>
        <td><?= e($r['performed_by'] ?: '—') ?></td>
        <td>
          <?php if ($r['action'] === 'delete'): ?>
            <?php if (!$isRoot): ?>
              <span class="text-muted">Deleted together with <?= e(strtolower($tables[$r['cascade_from_table']][0] ?? $r['cascade_from_table'])) ?> #<?= (int)$r['cascade_from_id'] ?></span>
            <?php else:
                $kc = $kids[strtotime($r['deleted_stamp']) . '|' . $t . '|' . $r['record_id']] ?? []; ?>
              <?= $kc ? 'Also deleted: ' . e(kids_text($kc)) : '<span class="text-muted">Nothing else was connected</span>' ?>
            <?php endif; ?>
            <?php if ($old): ?>
              <details class="mt-1"><summary class="small">Show the deleted data</summary>
                <dl class="row small mb-0 mt-1">
                  <?php foreach ($old as $f => $v): if (!isset($fieldDefs[$t][$f])) continue; ?>
                    <dt class="col-5"><?= e(field_label($t, $f)) ?></dt><dd class="col-7"><?= e(show_value($t, $f, $v)) ?></dd>
                  <?php endforeach; ?>
                </dl>
              </details>
            <?php endif; ?>
            <?php if ($undone): ?><div class="small text-muted">Undeleted by <?= e($r['undone_by'] ?? '?') ?> on <?= e(when_label($r['undone_at'])) ?></div><?php endif; ?>

          <?php elseif (in_array($r['action'], ['update', 'revert'], true)): ?>
            <?php foreach ($r['changed_fields'] ?? [] as $f): ?>
              <div class="small"><strong><?= e(field_label($t, $f)) ?>:</strong>
                <span class="text-danger"><?= e(show_value($t, $f, $old[$f] ?? null)) ?></span>
                &rarr; <span class="text-success"><?= e(show_value($t, $f, $new[$f] ?? null)) ?></span></div>
            <?php endforeach; ?>
            <?php if ($undone): ?><div class="small text-muted">Undone by <?= e($r['undone_by'] ?? '?') ?> on <?= e(when_label($r['undone_at'])) ?></div><?php endif; ?>

          <?php else: ?>
            <span class="text-muted">Brought back (along with anything deleted together with it).</span>
          <?php endif; ?>
        </td>
        <td class="text-end text-nowrap">
          <?php if ($r['action'] === 'delete' && !$undone): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Undelete this record? Anything that was deleted together with it will be brought back too.');">
              <?= csrf_field() ?><input type="hidden" name="do" value="restore"><input type="hidden" name="table" value="<?= e($t) ?>"><input type="hidden" name="id" value="<?= (int)$r['record_id'] ?>">
              <button class="btn btn-sm btn-outline-success"><i class="bi bi-arrow-counterclockwise"></i> Undelete</button>
            </form>
          <?php elseif ($r['action'] === 'update' && !$undone && array_diff($r['changed_fields'] ?? [], ['password_hash'])): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Undo this update and put the old values back?');">
              <?= csrf_field() ?><input type="hidden" name="do" value="revert"><input type="hidden" name="log_id" value="<?= (int)$r['log_id'] ?>">
              <button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-counterclockwise"></i> Undo update</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>

<?php else: ?>
  <p class="text-muted small mb-2">Who is using Sproutly right now. A guest is someone on the login page. Sessions end automatically after 8 hours without activity.</p>
  <div class="card p-2"><div class="table-responsive"><table class="table table-hover align-middle mb-0">
    <thead><tr><th>Person</th><th>Role</th><th>Status</th><th>Device</th><th>IP address</th><th>Signed in</th><th>Last active</th></tr></thead>
    <tbody>
    <?php if (!$sessions && !$loadErr): ?><tr><td colspan="7" class="text-center text-muted py-4">No sessions.</td></tr><?php endif; ?>
    <?php foreach ($sessions as $s):
        $expired = strtotime((string)$s['expires_at']) < time();
        $status = $expired ? 'Expired' : (string)$s['status']; ?>
      <tr>
        <td><?= $s['username'] ? '<strong>' . e($s['full_name'] ?: $s['username']) . '</strong><br><small class="text-muted">' . e($s['username']) . '</small>' : '<span class="text-muted">Guest</span>' ?></td>
        <td><?= e($s['role_name'] ?: '—') ?></td>
        <td><span class="badge text-bg-<?= $expired ? 'secondary' : ($s['username'] ? 'success' : 'light border') ?>"><?= e($status) ?></span></td>
        <td><?= e($s['device'] ?: '—') ?></td>
        <td class="text-nowrap"><?= e($s['ip_address'] ?: '—') ?></td>
        <td class="text-nowrap"><?= e(when_label($s['logged_in_at'])) ?></td>
        <td class="text-nowrap"><?= e(when_label($s['last_active_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div></div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mt-3 flex-wrap gap-2">
  <small class="text-muted"><?= (int)$total ?> entr<?= $total === 1 ? 'y' : 'ies' ?></small>
  <?php pagination($total, $pg, PER_PAGE); ?>
</div>
<?php layout_end();