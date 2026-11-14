<?php
require __DIR__ . '/../lib/core.php';
require_login();

function dash_table(array $cols, array $rows, string $empty): void { ?>
  <div class="table-responsive">
    <table class="table table-hover align-middle dash-table">
      <thead><tr>
        <?php foreach ($cols as [$h, $cls]): ?><th scope="col" class="<?= e($cls) ?>"><?= e($h) ?></th><?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td class="c-empty text-center text-muted" colspan="<?= count($cols) ?>"><?= e($empty) ?></td></tr><?php endif; ?>
      <?php foreach ($rows as $r): ?><tr>
        <?php foreach ($r as $i => $cell): ?><td class="<?= e($cols[$i][1]) ?>" data-label="<?= e($cols[$i][0]) ?>"><?= trim((string)$cell) === '' ? '&mdash;' : $cell ?></td><?php endforeach; ?>
      </tr><?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php }

layout_start('Dashboard');
echo '<h1 class="page-title">Dashboard</h1>';

if (is_super_admin()) {
    try {
        $pend = $db->select('app_user', 'user_id,username,full_name,email,role_id,created_at',
                            ['approval_status' => 'eq.pending'], 'created_at.asc', 1, 20);
    } catch (SupabaseException $ex) {
        $pend = ['data' => [], 'total' => 0];
    }
    if ($pend['total'] > 0): ?>
<section class="panel mb-3" aria-label="Account requests">
  <div class="panel-head"><h2 class="panel-title"><i class="bi bi-person-plus"></i> Account requests
    <span class="badge text-bg-danger"><?= (int)$pend['total'] ?></span></h2>
    <a class="view-all" href="activity.php?view=requests">Review all</a></div>
  <p class="text-muted small">These people created an account and cannot sign in until you approve them.</p>
  <div class="table-responsive"><table class="table align-middle mb-0"><tbody>
    <?php foreach ($pend['data'] as $q): ?>
      <tr>
        <td><strong><?= e($q['full_name']) ?></strong><br><small class="text-muted"><?= e($q['username']) ?> &middot; <?= e($q['email']) ?></small></td>
        <td class="text-end text-nowrap">
          <form method="post" action="activity.php" class="d-inline-flex gap-1 align-items-center">
            <?= csrf_field() ?><input type="hidden" name="do" value="approve"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="id" value="<?= (int)$q['user_id'] ?>">
            <select name="role_id" class="form-select form-select-sm w-auto" aria-label="Role">
              <option value="<?= ROLE_STAFF ?>" <?= (int)$q['role_id'] === ROLE_STAFF ? 'selected' : '' ?>>Staff</option>
              <option value="<?= ROLE_ADMIN ?>" <?= (int)$q['role_id'] === ROLE_ADMIN ? 'selected' : '' ?>>Admin</option>
            </select>
            <button class="btn btn-sm btn-success"><i class="bi bi-check-lg"></i> Approve</button>
          </form>
          <form method="post" action="activity.php" class="d-inline" onsubmit="return confirm('Decline this account request?');">
            <?= csrf_field() ?><input type="hidden" name="do" value="reject"><input type="hidden" name="return" value="dashboard"><input type="hidden" name="id" value="<?= (int)$q['user_id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i> Decline</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif;
}

try {
    $cards = [['farmers', 'Farmers', 'users'], ['farms', 'Farms', 'map-pin'], ['crops', 'Crops', 'sprout'],
              ['plantings', 'Planting records', 'calendar-days'], ['harvests', 'Harvest records', 'wheat']];
    $counts = [];
    foreach ($cards as [$k]) $counts[$k] = $db->count($modules[$k]['table'], $modules[$k]['pk']);

    $recentP = $db->select('planting', $modules['plantings']['select'], [], 'planting_id.desc', 1, 5)['data'];
    $recentH = $db->select('harvest', $modules['harvests']['select'], [], 'harvest_id.desc', 1, 5)['data'];

    $byStatus = [];
    foreach ($db->select('planting', 'status_id,planting_status(status_name)', [], null, 1, 1000)['data'] as $r) {
        $n = $r['planting_status']['status_name'] ?? '?';
        $byStatus[$n] = ($byStatus[$n] ?? 0) + 1;
    }
    arsort($byStatus);
    $statusTotal = array_sum($byStatus);

    $qtyByCrop = []; $totalQty = 0.0; $totalValue = 0.0;
    foreach ($db->select('harvest', 'quantity_kg,selling_price_per_kg,planting(crop(crop_name))', [], null, 1, 1000)['data'] as $r) {
        $n = $r['planting']['crop']['crop_name'] ?? '?';
        $qtyByCrop[$n] = ($qtyByCrop[$n] ?? 0) + (float)$r['quantity_kg'];
        $totalQty += (float)$r['quantity_kg'];
        $totalValue += $r['quantity_kg'] * $r['selling_price_per_kg'];
    }
    asort($qtyByCrop); 
    $cropSlices = [];
    foreach ($qtyByCrop as $name => $qty) {
        if ($qty > 0) $cropSlices[] = ['name' => (string)$name, 'qty' => round($qty, 2)];
    }
} catch (SupabaseException $ex) {
    echo '<div class="alert alert-danger">' . e(friendly($ex, 'load dashboard data')) . '</div>';
    layout_end();
    exit;
}

$tones = ['farmers' => 'tone-green', 'farms' => 'tone-brown', 'crops' => 'tone-pink', 'plantings' => 'tone-blue', 'harvests' => 'tone-sage'];
$statusColors = ['Harvested' => '#86c096', 'Growing' => '#e4a3b2', 'Planted' => '#8dbbe2', 'Ready for Harvest' => '#d4ad86', 'Failed' => '#d87c8a'];
?>
<section class="stat-grid" aria-label="Record counts">
  <?php foreach ($cards as [$k, $label, $icon]): ?>
    <a class="stat-card <?= $tones[$k] ?>" href="<?= e($modules[$k]['file']) ?>">
      <span class="stat-top"><span class="stat-label"><?= e($label) ?></span><?= svg_icon($icon, 'stat-icon') ?></span>
      <span class="stat-num"><?= number_format((int)$counts[$k]) ?></span>
    </a>
  <?php endforeach; ?>
</section>

<section class="metric-grid" aria-label="Harvest totals">
  <div class="stat-card metric-card tone-blue">
    <span class="stat-label">Total harvested quantity</span>
    <span class="stat-num stat-num-lg"><?= number_format($totalQty, 2) ?> kg</span>
  </div>
  <div class="stat-card metric-card tone-brown">
    <span class="stat-label">Estimated harvest value</span>
    <span class="stat-num stat-num-lg"><?= e(money($totalValue)) ?></span>
  </div>
</section>

<section class="chart-grid">
  <div class="panel">
    <div class="panel-head"><h2 class="panel-title">Plantings by status</h2></div>
    <?php if (!$byStatus): ?>
      <p class="panel-empty">No planting records yet.</p>
    <?php else: ?>
      <ul class="status-list">
        <?php foreach ($byStatus as $name => $count): $pct = (int)round($count / $statusTotal * 100); ?>
          <li>
            <div class="status-head"><span><?= e($name) ?></span><span class="mono"><?= (int)$count ?> (<?= $pct ?>%)</span></div>
            <div class="bar" role="progressbar" aria-label="<?= e($name) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $pct ?>">
              <i style="width: <?= $pct ?>%; background: <?= e($statusColors[$name] ?? '#b9c3b9') ?>"></i>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>

  <div class="panel crop-panel">
    <div class="panel-head">
      <h2 class="panel-title">Harvested quantity by crop (kg)</h2>
      <span class="crop-tag" id="cropTag"></span>
    </div>
    <?php if (!$cropSlices): ?>
      <p class="panel-empty">No harvest records yet.</p>
    <?php else: ?>
      <div class="crop-wrap">
        <div class="crop-body" id="cropChart">
          <div class="crop-pie"><svg id="cropPie" viewBox="-108 -108 216 216" role="img" aria-label="Pie chart of harvested quantity by crop"></svg></div>
          <ul class="crop-legend" id="cropLegend"></ul>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>

<section class="recent-grid">
  <div class="panel panel-soft panel-table panel-green">
    <div class="panel-head"><h2 class="panel-title">Recent planting records</h2><a class="view-all" href="planting.php">View all</a></div>
    <?php dash_table(
        [['Farm', 'c-sub1'], ['Crop', 'c-main'], ['Planted', 'c-sub2 c-lbl'], ['Status', 'c-side']],
        array_map(fn($r) => [e($r['farm']['farm_name'] ?? ''), e($r['crop']['crop_name'] ?? ''), e($r['planting_date']), status_badge($r)], $recentP),
        'No planting records yet.'); ?>
  </div>
  <div class="panel panel-soft panel-table panel-blue">
    <div class="panel-head"><h2 class="panel-title">Recent harvest records</h2><a class="view-all" href="harvest.php">View all</a></div>
    <?php dash_table(
        [['Date', 'c-sub1'], ['Crop', 'c-main'], ['Qty (kg)', 'c-side c-qty text-end'], ['Grade', 'c-sub2']],
        array_map(fn($r) => [e($r['harvest_date']), e($r['planting']['crop']['crop_name'] ?? ''), number_format((float)$r['quantity_kg'], 2),
            grade_badge($r['quality_grade']['grade_name'] ?? '')], $recentH),
        'No harvest records yet.'); ?>
  </div>
</section>

<script>
(function () {
  var data = <?= json_encode($cropSlices, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
  var root = document.getElementById('cropChart');
  var tag = document.getElementById('cropTag');
  if (!root || !data.length) { if (tag) tag.hidden = true; return; }

  var NS = 'http://www.w3.org/2000/svg';
  var palette = ['#8ec29b','#92bfe3','#e6a8b7','#cfa985','#a8d4b3','#b2d1eb','#f0c1cd','#dfc0a1','#79b18a','#7caad6'];
  var svg = document.getElementById('cropPie');
  var legend = document.getElementById('cropLegend');
  var total = data.reduce(function (s, d) { return s + d.qty; }, 0);
  var R = 100, RHI = 104;

  function fmt(n) { return n.toLocaleString('en-US', { maximumFractionDigits: 2 }); }
  function pct(n) { return (n / total * 100).toFixed(1); }
  function el(name, attrs) {
    var n = document.createElementNS(NS, name);
    for (var k in attrs) n.setAttribute(k, attrs[k]);
    return n;
  }
  function arc(a0, a1, r) {
    if (a1 - a0 >= 2 * Math.PI - 1e-6) return 'M ' + r + ' 0 A ' + r + ' ' + r + ' 0 1 1 ' + (-r) + ' 0 A ' + r + ' ' + r + ' 0 1 1 ' + r + ' 0 Z';
    var x0 = r * Math.cos(a0), y0 = r * Math.sin(a0), x1 = r * Math.cos(a1), y1 = r * Math.sin(a1);
    return 'M 0 0 L ' + x0 + ' ' + y0 + ' A ' + r + ' ' + r + ' 0 ' + ((a1 - a0) > Math.PI ? 1 : 0) + ' 1 ' + x1 + ' ' + y1 + ' Z';
  }

  var slices = [], rows = [], angle = -Math.PI / 2;
  data.forEach(function (d, i) {
    var a0 = angle, a1 = angle + d.qty / total * 2 * Math.PI;
    angle = a1;
    d.color = palette[i % palette.length];
    d.a0 = a0; d.a1 = a1;

    var path = el('path', { d: arc(a0, a1, R), fill: d.color, stroke: '#fff', 'stroke-width': 1.5, 'stroke-linejoin': 'round', 'class': 'slice' });
    svg.appendChild(path);
    slices.push(path);

    var li = document.createElement('li');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'legend-row';
    var sw = document.createElement('span'); sw.className = 'swatch'; sw.style.background = d.color;
    var nm = document.createElement('span'); nm.className = 'legend-name'; nm.textContent = d.name;
    var vl = document.createElement('span'); vl.className = 'legend-val'; vl.textContent = fmt(d.qty) + ' kg \u00b7 ' + pct(d.qty) + '%';
    btn.appendChild(sw); btn.appendChild(nm); btn.appendChild(vl);
    li.appendChild(btn); legend.appendChild(li);
    rows.push(btn);
  });

  var hl = el('path', { 'class': 'slice-hl', 'fill-opacity': 1 });
  svg.appendChild(hl);

  var defaultTag = data.length + (data.length === 1 ? ' crop' : ' crops');
  var hover = null, pinned = null;

  function paint(i) {
    root.classList.toggle('has-active', i !== null);
    rows.forEach(function (r, j) { r.classList.toggle('is-active', j === i); });
    if (i === null) { hl.setAttribute('d', ''); tag.textContent = defaultTag; return; }
    var d = data[i];
    hl.setAttribute('d', arc(d.a0, d.a1, RHI));
    hl.setAttribute('fill', d.color);
    tag.textContent = d.name + ': ' + fmt(d.qty) + ' kg (' + pct(d.qty) + '%)';
  }
  function refresh() { paint(hover !== null ? hover : pinned); }
  function enter(i) { hover = i; refresh(); }
  function leave() { hover = null; refresh(); }
  function toggle(i) { pinned = (pinned === i) ? null : i; refresh(); }

  slices.forEach(function (s, i) {
    s.addEventListener('mouseenter', function () { enter(i); });
    s.addEventListener('mouseleave', leave);
    s.addEventListener('click', function () { toggle(i); });
  });
  rows.forEach(function (r, i) {
    r.addEventListener('mouseenter', function () { enter(i); });
    r.addEventListener('mouseleave', leave);
    r.addEventListener('focus', function () { enter(i); });
    r.addEventListener('blur', leave);
    r.addEventListener('click', function () { toggle(i); });
  });
  paint(null);
})();
</script>
<?php layout_end();
