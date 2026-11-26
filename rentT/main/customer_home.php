<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$me = require_customer();

const LOCS = ['Main Office', 'Iloilo City', 'Iloilo Airport', 'Customer Address'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $code = '';
    $newId = 0;
    $ok = run_tx($pdo, function (PDO $pdo) use ($me, &$code, &$newId) {
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $d1 = DateTime::createFromFormat('!Y-m-d', $_POST['start_date'] ?? '');
        $d2 = DateTime::createFromFormat('!Y-m-d', $_POST['end_date'] ?? '');
        $pickup = $_POST['pickup'] ?? ''; $drop = $_POST['dropoff'] ?? '';
        if (!$d1 || !$d2) throw new UserError('Please choose valid dates.');
        if ($d1 < new DateTime('today')) throw new UserError('Start date cannot be in the past.');
        $days = (int)$d1->diff($d2)->format('%r%a');
        if ($days < 1) throw new UserError('End date must be after the start date.');
        if (!in_array($pickup, LOCS, true)) throw new UserError('Choose a pickup location.');
        if ($drop === '' || $drop === 'Same as pickup') $drop = $pickup;
        if (!in_array($drop, LOCS, true)) throw new UserError('Invalid drop-off location.');
        if (empty($_POST['terms'])) throw new UserError('You must accept the rental terms.');

        $v = $pdo->prepare('SELECT vehicle_id, brand, model, plate_number, rate_per_day, status FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
        $v->execute([$vid]);
        $veh = $v->fetch();
        if (!$veh || $veh['status'] !== 'Available') throw new UserError('That vehicle is not available.');

        $pick = $d1->format('Y-m-d') . ' 09:00:00'; $ret = $d2->format('Y-m-d') . ' 09:00:00';
        $clash = $pdo->prepare("SELECT 1 FROM rentals WHERE vehicle_id = ? AND rental_status IN ('Pending','Approved','Active','Overdue')
                                AND pickup_datetime < ? AND return_datetime > ? LIMIT 1 FOR UPDATE");
        $clash->execute([$vid, $ret, $pick]);
        if ($clash->fetch()) throw new UserError('The vehicle is already booked for those dates.');

        $rate = (float)$veh['rate_per_day']; $base = round($rate * $days, 2);
        $lines = []; $addonTotal = 0.0;
        $sel = array_map('intval', (array)($_POST['addons'] ?? []));
        if ($sel) {
            $a = $pdo->prepare("SELECT addon_id, rate, rate_type FROM addons WHERE status='Active' AND addon_id IN (" . implode(',', array_fill(0, count($sel), '?')) . ')');
            $a->execute($sel);
            foreach ($a->fetchAll() as $ad) {
                $line = round((float)$ad['rate'] * ($ad['rate_type'] === 'Per Day' ? $days : 1), 2);
                $lines[] = [$ad['addon_id'], $ad['rate'], $line]; $addonTotal += $line;
            }
        }
        $total = round($base + $addonTotal, 2);
        $pdo->prepare("INSERT INTO rentals (rental_code, customer_id, vehicle_id, booking_source, rental_status, pickup_datetime, return_datetime,
                pickup_location, dropoff_location, rental_days, daily_rate, base_amount, addon_amount, total_amount, special_requests, terms_accepted, created_by)
            VALUES (?,?,?,'Customer','Pending',?,?,?,?,?,?,?,?,?,?,1,?)")
            ->execute(['TMP-' . bin2hex(random_bytes(6)), $me['id'], $vid, $pick, $ret, $pickup, $drop, $days, $rate, $base, $addonTotal, $total,
                       trim($_POST['special_requests'] ?? '') ?: null, $me['id']]);
        $rid = (int)$pdo->lastInsertId();
        $newId = $rid;
        $code = sprintf('RNT-%s-%04d', date('Y'), $rid);
        $pdo->prepare('UPDATE rentals SET rental_code = ? WHERE rental_id = ?')->execute([$code, $rid]);
        foreach ($lines as [$aid, $ar, $lt]) {
            $pdo->prepare('INSERT INTO rental_addons (rental_id, addon_id, quantity, rate_at_booking, line_total) VALUES (?,?,1,?,?)')->execute([$rid, $aid, $ar, $lt]);
        }
        hold_vehicle($pdo, $vid);   // booked = Rented: nobody else can book it
        audit($pdo, 'RENTAL_CREATE', 'rentals', $rid, "Customer booking request $code: {$veh['brand']} {$veh['model']}, $days day(s), " . peso($total) . '.', null, ['status' => 'Pending', 'total' => $total]);
    }, 'Booking successful! Please complete your payment below.');
    redirect($ok ? 'customer_payments.php?rental=' . $newId : $_SERVER['REQUEST_URI']);
}

$q = trim($_GET['q'] ?? '');
$type = in_array($_GET['type'] ?? '', ['Car', 'Van', 'SUV', 'Motorcycle', 'Truck', 'Other'], true) ? $_GET['type'] : '';
$where = ["status IN ('Available','Rented','Maintenance')"]; $args = [];
if ($q !== '') { $where[] = '(brand LIKE ? OR model LIKE ?)'; array_push($args, like($q), like($q)); }
if ($type) { $where[] = 'vehicle_type = ?'; $args[] = $type; }
$W = implode(' AND ', $where);
$cnt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn(); $per = 12; [$page, $off] = paging($total, $per);
$st = $pdo->prepare("SELECT vehicle_id, brand, model, year, vehicle_type, transmission, seating_capacity, fuel_type, rate_per_day, image, color, status,
                            (SELECT MAX(r.return_datetime) FROM rentals r WHERE r.vehicle_id = vehicles.vehicle_id AND r.rental_status IN ('Pending','Approved','Active','Overdue')) AS back_on
                     FROM vehicles WHERE $W ORDER BY (status = 'Available') DESC, rate_per_day LIMIT $per OFFSET $off");
$st->execute($args);
$vehicles = $st->fetchAll();
$addons = $pdo->query("SELECT addon_id, addon_name, rate, rate_type FROM addons WHERE status='Active'")->fetchAll();

customer_start('Browse Vehicles', 'home');
echo flash_render();
?>
<form class="tb" method="get" data-auto>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search brand or model...">
    <select name="type"><option value="">All Types</option>
        <?php foreach (['Car', 'Van', 'SUV', 'Motorcycle', 'Truck', 'Other'] as $t): ?><option <?= $type === $t ? 'selected' : '' ?>><?= $t ?></option><?php endforeach; ?></select>
</form>
<div class="veh-grid">
<?php foreach ($vehicles as $v): ?>
    <div class="veh-card">
        <?php if ($v['image']): ?><img src="<?= e(vehicle_img($v['image'])) ?>" alt="" loading="lazy" decoding="async" width="360" height="160">
        <?php else: ?><div class="noimg"><i class="bi bi-car-front"></i></div><?php endif; ?>
        <div class="veh-body">
            <h3><?= e($v['brand'] . ' ' . $v['model'] . ' ' . $v['year']) ?></h3>
            <div class="veh-specs"><span><?= e($v['vehicle_type']) ?></span><span><?= e($v['transmission']) ?></span><span><?= e($v['fuel_type']) ?></span>
                <?php if ($v['seating_capacity']): ?><span><?= (int)$v['seating_capacity'] ?> seats</span><?php endif; ?></div>
            <div><span class="status <?= $v['status'] === 'Available' ? 'st-active' : ($v['status'] === 'Rented' ? 'st-overdue' : 'st-pending') ?>"><?= e(strtoupper($v['status'])) ?></span>
                <?php if ($v['status'] === 'Rented' && $v['back_on']): ?><small style="color:#94a3b8"> back on <?= date('M d', strtotime($v['back_on'])) ?></small><?php endif; ?></div>
            <div class="veh-price"><?= peso($v['rate_per_day']) ?> <small style="color:#94a3b8;font-weight:400">/ day</small></div>
            <?php if ($v['status'] === 'Available'): ?>
            <button class="btn btn-primary" type="button" data-add="bookModal"
                data-json="<?= e(json_encode(['vehicle_id' => $v['vehicle_id'], 'rate' => $v['rate_per_day'], 'name' => $v['brand'] . ' ' . $v['model']])) ?>">Book Now</button>
            <?php else: ?>
            <button class="btn btn-ghost" type="button" disabled style="cursor:not-allowed"><?= $v['status'] === 'Rented' ? 'Rented' : 'Unavailable' ?></button>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; if (!$vehicles): ?><p style="color:#64748b">No vehicles found.</p><?php endif; ?>
</div>
<div style="margin-top:16px"><?= pager($total, $page, $per) ?></div>

<div class="modal-overlay" id="bookModal">
  <div class="modal sm"><form method="post"><?= csrf_field() ?><input type="hidden" name="vehicle_id">
    <div class="modal-head"><div><h3 id="bkName">Book vehicle</h3><p>Your request will be confirmed by our staff.</p></div><button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="fg"><label>Start date *</label><input type="date" name="start_date" id="bkStart" required></div>
      <div class="fg"><label>End date *</label><input type="date" name="end_date" id="bkEnd" required></div>
      <div class="fg"><label>Pickup *</label><select name="pickup" required><option value="">Select...</option><?php foreach (LOCS as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></div>
      <div class="fg"><label>Drop-off</label><select name="dropoff"><option>Same as pickup</option><?php foreach (LOCS as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></div>
      <?php foreach ($addons as $ad): ?>
      <label class="fg full" style="flex-direction:row;align-items:center;gap:8px;font-weight:400"><input type="checkbox" name="addons[]" value="<?= (int)$ad['addon_id'] ?>" data-rate="<?= e($ad['rate']) ?>" data-type="<?= e($ad['rate_type']) ?>" style="width:auto;height:auto">
        <?= e($ad['addon_name']) ?> — <?= peso($ad['rate']) ?><?= $ad['rate_type'] === 'Per Day' ? '/day' : '' ?></label>
      <?php endforeach; ?>
      <div class="fg full"><label>Special requests</label><input name="special_requests" maxlength="255"></div>
      <label class="fg full" style="flex-direction:row;align-items:center;gap:8px;font-weight:400"><input type="checkbox" name="terms" value="1" required style="width:auto;height:auto"> I accept the rental terms and conditions.</label>
      <div class="fg full"><div style="display:flex;justify-content:space-between;padding:12px;background:#f1f5f9;border-radius:8px"><span>Estimated total</span><strong id="bkTotal">₱0.00</strong></div></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary">Request Booking</button></div>
  </form></div>
</div>
<script>
(function () {
  var m = document.getElementById('bookModal'), f = m.querySelector('form'), rate = 0;
  var s = document.getElementById('bkStart'), e = document.getElementById('bkEnd'), t = document.getElementById('bkTotal');
  var today = new Date(); today.setMinutes(today.getMinutes() - today.getTimezoneOffset()); s.min = today.toISOString().slice(0, 10);
  function calc() {
    var days = (s.value && e.value) ? Math.round((new Date(e.value) - new Date(s.value)) / 86400000) : 0;
    if (s.value) { var d = new Date(s.value); d.setDate(d.getDate() + 1); e.min = d.toISOString().slice(0, 10); }
    var tot = days > 0 ? rate * days : 0;
    if (days > 0) f.querySelectorAll('input[name="addons[]"]:checked').forEach(function (c) { tot += parseFloat(c.dataset.rate) * (c.dataset.type === 'Per Day' ? days : 1); });
    t.textContent = '₱' + tot.toLocaleString('en-PH', {minimumFractionDigits: 2});
  }
  f.addEventListener('input', calc); f.addEventListener('change', calc);
  document.addEventListener('modal:prepared', function (ev) {
    if (ev.detail.modal !== m || !ev.detail.data) return;
    rate = parseFloat(ev.detail.data.rate); document.getElementById('bkName').textContent = 'Book ' + ev.detail.data.name; calc();
  });
})();
</script>
<?php customer_end(); ?>
