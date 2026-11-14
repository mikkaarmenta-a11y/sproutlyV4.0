<?php
require __DIR__ . '/../lib/core.php';
require_login();

$reports = [
    'farmers'    => 'Farmers and their farms',
    'crops_farm' => 'Crops planted per farm',
    'status'     => 'Planting records by status',
    'harvest'    => 'Harvest records',
    'qty'        => 'Total harvested quantity',
    'value'      => 'Estimated harvest value',
];
$r = $_GET['r'] ?? 'farmers';
if (!isset($reports[$r])) $r = 'farmers';

/* date range: planting_date for planting reports, harvest_date for harvest reports */
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));
$okDate = function (string $s): bool {
    if ($s === '') return true;
    $d = DateTime::createFromFormat('Y-m-d', $s);
    return $d && $d->format('Y-m-d') === $s;
};
$dateErr = '';
if (!$okDate($from) || !$okDate($to)) { $dateErr = 'Please enter valid dates.'; $from = $to = ''; }
elseif ($from !== '' && $to !== '' && $to < $from) { $dateErr = 'The "to" date cannot be before the "from" date.'; $from = $to = ''; }

$range = function (string $col) use ($from, $to): array {
    $p = [];
    if ($from !== '') $p[] = "$col.gte.$from";
    if ($to !== '') $p[] = "$col.lte.$to";
    return $p ? ['and' => '(' . implode(',', $p) . ')'] : [];
};
$dateApplies = in_array($r, ['crops_farm', 'status', 'harvest', 'qty', 'value'], true);

layout_start('Reports'); ?>
<?php if (in_array($r, ['farmers', 'harvest'], true)): ?>
<style media="print">@page { size: A4 landscape; margin: 12mm; }</style>
<?php endif; ?>
<h1 class="page-title">Reports</h1>

<form class="panel report-filter mb-3 no-print" method="get" action="reports.php">
  <div class="row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label">Report</label>
      <select name="r" class="form-select">
        <?php foreach ($reports as $k => $t): ?><option value="<?= e($k) ?>" <?= $r === $k ? 'selected' : '' ?>><?= e($t) ?></option><?php endforeach; ?>
      </select></div>
    <div class="col-6 col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($from) ?>"></div>
    <div class="col-6 col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($to) ?>"></div>
    <div class="col-md-4 d-flex gap-2 flex-wrap">
      <button class="btn btn-success"><i class="bi bi-play-fill"></i> Generate</button>
      <a class="btn btn-outline-secondary" href="<?= e(purl('reports.php', ['r' => $r])) ?>">Reset</a>
      <button type="button" class="btn btn-outline-dark ms-auto" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
    </div>
  </div>
  <div class="form-text">The date range applies to the planting date or the harvest date.</div>
</form>
<?php if ($dateErr): ?><div class="alert alert-warning"><?= e($dateErr) ?></div><?php endif; ?>

<div class="panel panel-soft panel-table panel-green">
  <div class="panel-head"><h2 class="panel-title"><?= e($reports[$r]) ?>
    <?php if ($dateApplies && ($from !== '' || $to !== '')): ?><small>(<?= e($from ?: '...') ?> to <?= e($to ?: '...') ?>)</small><?php endif; ?></h2></div>
<?php
try {
    if ($r === 'farmers') {
        $data = $db->select('farmer', 'first_name,last_name,street_address,location(city_municipality,province),farm(farm_name,location(city_municipality,province),area_hectares,soil_type(soil_type_name),irrigation_type(irrigation_name))', ['farm.deleted_at' => 'is.null'], 'last_name.asc', 1, 1000)['data'];
        $rows = [];
        foreach ($data as $f) {
            $name = e($f['last_name'] . ', ' . $f['first_name']);
            $addr = format_farmer_address($f);
            if (empty($f['farm'])) {
                $rows[] = [$name, e($addr), '<em class="text-muted">No farm</em>', '&mdash;', '&mdash;', '&mdash;', '&mdash;'];
            }
            foreach ($f['farm'] ?? [] as $fm) {
                $farmLoc = format_location_label($fm['location'] ?? null) ?: '—';
                $rows[] = [$name, e($addr), e($fm['farm_name']), e($farmLoc), e($fm['area_hectares']),
                           e($fm['soil_type']['soil_type_name'] ?? '—'), e($fm['irrigation_type']['irrigation_name'] ?? '—')];
            }
        }
        simple_table(['Farmer', 'Address', 'Farm', 'Farm location', 'Area (ha)', 'Soil', 'Irrigation'], $rows);

    } elseif ($r === 'crops_farm' || $r === 'status') {
        $data = $db->select('planting', 'planting_id,area_planted,farm(farm_name),crop(crop_name,variety),planting_status(status_name)',
                            $range('planting_date'), 'planting_id.asc', 1, 1000)['data'];
        $g = [];
        foreach ($data as $p) {
            $key = $r === 'status'
                ? ($p['planting_status']['status_name'] ?? '?')
                : ($p['farm']['farm_name'] ?? '?') . '|' . ($p['crop']['crop_name'] ?? '?') . ' (' . ($p['crop']['variety'] ?? '') . ')';
            $g[$key]['n'] = ($g[$key]['n'] ?? 0) + 1;
            $g[$key]['ha'] = ($g[$key]['ha'] ?? 0) + (float)$p['area_planted'];
        }
        ksort($g);
        $rows = []; $tn = 0; $tha = 0.0;
        foreach ($g as $k => $v) {
            $cells = $r === 'status' ? [e($k)] : array_map('e', explode('|', $k, 2));
            $rows[] = array_merge($cells, [$v['n'], number_format($v['ha'], 2)]);
            $tn += $v['n']; $tha += $v['ha'];
        }
        $head = $r === 'status' ? ['Status', 'No. of plantings', 'Area planted (ha)'] : ['Farm', 'Crop', 'No. of plantings', 'Area planted (ha)'];
        $rows[] = array_merge($r === 'status' ? ['<strong>Total</strong>'] : ['<strong>Total</strong>', 'All crops'], ["<strong>$tn</strong>", '<strong>' . number_format($tha, 2) . '</strong>']);
        simple_table($head, $rows, $r === 'status' ? [1, 2] : [2, 3]);

    } else {
        $data = $db->select('harvest', 'harvest_id,harvest_date,quantity_kg,selling_price_per_kg,planting(farm(farm_name),crop(crop_name)),quality_grade(grade_name),harvest_remark(remark_description)',
                            $range('harvest_date'), 'harvest_date.desc', 1, 1000)['data'];
        $rows = [];
        if ($r === 'harvest') {
            foreach ($data as $h) {
                $rows[] = [e($h['harvest_date']), e($h['planting']['farm']['farm_name'] ?? ''), e($h['planting']['crop']['crop_name'] ?? ''),
                           number_format((float)$h['quantity_kg'], 2), grade_badge($h['quality_grade']['grade_name'] ?? ''), e($h['harvest_remark']['remark_description'] ?? '')];
            }
            simple_table(['Date', 'Farm', 'Crop', 'Qty (kg)', 'Grade', 'Remarks'], $rows, [3]);
        } else {
            $g = []; $tq = 0.0; $tv = 0.0;
            foreach ($data as $h) {
                $c = $h['planting']['crop']['crop_name'] ?? '?';
                $g[$c]['q'] = ($g[$c]['q'] ?? 0) + (float)$h['quantity_kg'];
                $g[$c]['v'] = ($g[$c]['v'] ?? 0) + $h['quantity_kg'] * $h['selling_price_per_kg'];
            }
            ksort($g);
            foreach ($g as $c => $v) {
                $rows[] = $r === 'qty' ? [e($c), number_format($v['q'], 2)] : [e($c), number_format($v['q'], 2), money($v['v'])];
                $tq += $v['q']; $tv += $v['v'];
            }
            if ($r === 'qty') {
                $rows[] = ['<strong>Total</strong>', '<strong>' . number_format($tq, 2) . '</strong>'];
                simple_table(['Crop', 'Total quantity (kg)'], $rows, [1]);
            } else {
                $rows[] = ['<strong>Total</strong>', '<strong>' . number_format($tq, 2) . '</strong>', '<strong>' . money($tv) . '</strong>'];
                simple_table(['Crop', 'Quantity (kg)', 'Estimated value (qty x price)'], $rows, [1, 2]);
            }
        }
    }
} catch (SupabaseException $ex) {
    echo '<div class="alert alert-danger m-3">' . e(friendly($ex, 'generate report')) . '</div>';
} ?>
</div>
<?php layout_end();