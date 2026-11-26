<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$admin = require_admin();

const LOCATIONS = ['Main Office', 'Iloilo City', 'Iloilo Airport', 'Customer Address'];
const METHODS   = ['Cash' => 'bi-cash', 'GCash' => 'bi-phone', 'Credit Card' => 'bi-credit-card', 'Debit Card' => 'bi-credit-card-2-front', 'Bank Transfer' => 'bi-bank'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $newCode = '';
    $ok = run_tx($pdo, function (PDO $pdo) use ($admin, &$newCode) {
        $cid = (int)($_POST['customer_id'] ?? 0);
        $vid = (int)($_POST['vehicle_id'] ?? 0);
        $start = $_POST['start_date'] ?? '';
        $end = $_POST['end_date'] ?? '';
        $pickup = $_POST['pickup'] ?? '';
        $drop = $_POST['dropoff'] ?? '';
        $method = $_POST['payment_method'] ?? 'Cash';
        $paidNow = round(max(0, (float)($_POST['amount_paid'] ?? 0)), 2);
        $notes = trim($_POST['special_requests'] ?? '') ?: null;

        $d1 = DateTime::createFromFormat('!Y-m-d', $start);
        $d2 = DateTime::createFromFormat('!Y-m-d', $end);
        if (!$d1 || !$d2) throw new UserError('Please choose valid start and end dates.');
        if ($d1 < new DateTime('today')) throw new UserError('Start date cannot be in the past.');
        $days = (int)$d1->diff($d2)->format('%r%a');
        if ($days < 1) throw new UserError('End date must be after the start date.');
        if (!in_array($pickup, LOCATIONS, true)) throw new UserError('Please choose a pickup location.');
        if ($drop === 'Same as pickup' || $drop === '') $drop = $pickup;
        if (!in_array($drop, LOCATIONS, true)) throw new UserError('Invalid drop-off location.');
        if (!isset(METHODS[$method])) throw new UserError('Invalid payment method.');

        $c = $pdo->prepare("SELECT user_id, first_name, last_name FROM users WHERE user_id = ? AND role = 'Customer' AND account_status = 'Active'");
        $c->execute([$cid]);
        $cust = $c->fetch();
        if (!$cust) throw new UserError('Please select an active customer.');

        $v = $pdo->prepare('SELECT vehicle_id, brand, model, plate_number, rate_per_day, status FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
        $v->execute([$vid]);
        $veh = $v->fetch();
        if (!$veh || $veh['status'] !== 'Available') throw new UserError('That vehicle is not available.');

        $pickDT = $start . ' 09:00:00';
        $retDT  = $end . ' 09:00:00';
        $clash = $pdo->prepare("SELECT rental_code FROM rentals WHERE vehicle_id = ? AND rental_status IN ('Pending','Approved','Active','Overdue')
                                AND pickup_datetime < ? AND return_datetime > ? LIMIT 1 FOR UPDATE");
        $clash->execute([$vid, $retDT, $pickDT]);
        if ($row = $clash->fetch()) throw new UserError("The vehicle is already booked for those dates ({$row['rental_code']}).");

        // prices are always recomputed on the server
        $rate = (float)$veh['rate_per_day'];
        $base = round($rate * $days, 2);
        $lines = [];
        $addonTotal = 0.0;
        $selected = array_map('intval', (array)($_POST['addons'] ?? []));
        if ($selected) {
            $in = implode(',', array_fill(0, count($selected), '?'));
            $a = $pdo->prepare("SELECT addon_id, addon_name, rate, rate_type FROM addons WHERE status = 'Active' AND addon_id IN ($in)");
            $a->execute($selected);
            foreach ($a->fetchAll() as $ad) {
                $line = round((float)$ad['rate'] * ($ad['rate_type'] === 'Per Day' ? $days : 1), 2);
                $lines[] = [$ad['addon_id'], $ad['rate'], $line];
                $addonTotal += $line;
            }
        }
        $total = round($base + $addonTotal, 2);
        if ($paidNow > $total) throw new UserError('Amount paid cannot be more than the rental total.');

        $startsToday = $d1 <= new DateTime('today');
        $status = $startsToday ? 'Active' : 'Approved';

        $pdo->prepare('INSERT INTO rentals (rental_code, customer_id, vehicle_id, booking_source, rental_status, pickup_datetime, return_datetime,
                pickup_location, dropoff_location, rental_days, daily_rate, base_amount, addon_amount, total_amount, special_requests,
                terms_accepted, created_by, approved_by, approved_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,?,NOW())')
            ->execute(['TMP-' . bin2hex(random_bytes(6)), $cid, $vid, 'Admin', $status, $pickDT, $retDT, $pickup, $drop, $days, $rate,
                       $base, $addonTotal, $total, $notes, $admin['id'], $admin['id']]);
        $rid = (int)$pdo->lastInsertId();
        $newCode = sprintf('RNT-%s-%04d', date('Y'), $rid);
        $pdo->prepare('UPDATE rentals SET rental_code = ? WHERE rental_id = ?')->execute([$newCode, $rid]);

        foreach ($lines as [$aid, $arate, $line]) {
            $pdo->prepare('INSERT INTO rental_addons (rental_id, addon_id, quantity, rate_at_booking, line_total) VALUES (?,?,1,?,?)')
                ->execute([$rid, $aid, $arate, $line]);
        }
        hold_vehicle($pdo, $vid);   // booked = Rented, nobody else can book it

        audit($pdo, 'RENTAL_CREATE', 'rentals', $rid, "Created rental $newCode: {$cust['first_name']} {$cust['last_name']} → {$veh['brand']} {$veh['model']} ({$veh['plate_number']}), $days day(s), " . peso($total) . '.',
              null, ['status' => $status, 'days' => $days, 'total' => $total]);

        if ($paidNow > 0) {
            $pdo->prepare('INSERT INTO payments (payment_code, rental_id, amount, payment_method, payment_status, recorded_by, notes)
                           VALUES (?,?,?,?,?,?,?)')
                ->execute(['TMP-' . bin2hex(random_bytes(6)), $rid, $paidNow, $method, $paidNow >= $total ? 'Paid' : 'Partial', $admin['id'], 'Paid at booking']);
            $pid = (int)$pdo->lastInsertId();
            $pcode = sprintf('PAY-%s-%04d', date('Y'), $pid);
            $pdo->prepare('UPDATE payments SET payment_code = ? WHERE payment_id = ?')->execute([$pcode, $pid]);
            audit($pdo, 'PAYMENT_RECORD', 'payments', $pid, "Recorded $pcode of " . peso($paidNow) . " ($method) for $newCode.");
        }
    }, 'Rental created successfully.');
    if ($ok) redirect('rental_records.php?q=' . urlencode($newCode));
    redirect_self();
}

$customers = $pdo->query("SELECT user_id, first_name, last_name FROM users WHERE role='Customer' AND account_status='Active' ORDER BY first_name, last_name")->fetchAll();
$vehicles = $pdo->query("SELECT vehicle_id, brand, model, year, plate_number, rate_per_day FROM vehicles WHERE status='Available' ORDER BY brand, model")->fetchAll();
$addons = $pdo->query("SELECT addon_id, addon_name, description, rate, rate_type FROM addons WHERE status='Active' ORDER BY addon_id")->fetchAll();
$preVehicle = (int)($_GET['vehicle'] ?? 0);

page_start('Rent a Vehicle', 'rent', ['rent_vehicle']);
?>
<section class="content-area">
<?= flash_render() ?>
<form id="rentalForm" method="post">
<?= csrf_field() ?>
<div class="rental-layout">
  <div class="rental-main">
    <section class="rental-card">
      <div class="section-heading"><h2>Rental Information</h2></div>
      <div class="rental-form-grid">
        <div class="form-group"><label for="customer">Customer <span>*</span></label>
          <select id="customer" name="customer_id" required><option value="">Select customer...</option>
            <?php foreach ($customers as $c): ?><option value="<?= (int)$c['user_id'] ?>"><?= e($c['first_name'] . ' ' . $c['last_name']) ?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group"><label for="vehicle">Vehicle <span>*</span></label>
          <select id="vehicle" name="vehicle_id" required><option value="">Select vehicle...</option>
            <?php foreach ($vehicles as $v): $nm = $v['brand'] . ' ' . $v['model'] . ' ' . $v['year']; ?>
              <option value="<?= (int)$v['vehicle_id'] ?>" data-rate="<?= e($v['rate_per_day']) ?>" data-name="<?= e($nm) ?>" <?= $preVehicle === (int)$v['vehicle_id'] ? 'selected' : '' ?>>
                <?= e($nm . ' (' . $v['plate_number'] . ') - ' . peso($v['rate_per_day'])) ?>/day</option>
            <?php endforeach; ?>
          </select></div>
        <div class="form-group"><label for="startDate">Start Date <span>*</span></label><input type="date" id="startDate" name="start_date" required></div>
        <div class="form-group"><label for="endDate">End Date <span>*</span></label><input type="date" id="endDate" name="end_date" required></div>
        <div class="form-group"><label for="pickup">Pickup Location <span>*</span></label>
          <select id="pickup" name="pickup" required><option value="">Select location...</option>
            <?php foreach (LOCATIONS as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group"><label for="dropoff">Drop-off Location</label>
          <select id="dropoff" name="dropoff"><option>Same as pickup</option><?php foreach (LOCATIONS as $l): ?><option><?= e($l) ?></option><?php endforeach; ?></select></div>
        <div class="form-group" style="grid-column:1/-1"><label for="req">Special Requests</label><input id="req" name="special_requests" maxlength="255" placeholder="Optional"></div>
      </div>
    </section>

    <section class="rental-card">
      <div class="section-heading"><h2>Add-ons &amp; Options</h2></div>
      <div class="addons-grid">
        <?php foreach ($addons as $ad): ?>
        <label class="addon-option">
          <input type="checkbox" class="addon-checkbox" name="addons[]" value="<?= (int)$ad['addon_id'] ?>" data-rate="<?= e($ad['rate']) ?>" data-type="<?= e($ad['rate_type']) ?>">
          <span class="addon-icon <?= stripos($ad['addon_name'], 'gps') !== false ? 'gps-icon' : 'insurance-icon' ?>"><i class="bi <?= stripos($ad['addon_name'], 'gps') !== false ? 'bi-map-fill' : 'bi-shield-fill-check' ?>"></i></span>
          <span class="addon-details"><strong><?= e($ad['addon_name']) ?></strong>
            <small><?= peso($ad['rate']) ?><?= $ad['rate_type'] === 'Per Day' ? '/day' : ' per rental' ?> — <?= e($ad['description']) ?></small></span>
        </label>
        <?php endforeach; if (!$addons): ?><p style="color:#94a3b8;font-size:12px">No add-ons configured.</p><?php endif; ?>
      </div>
    </section>

    <section class="rental-card">
      <div class="section-heading"><h2>Payment</h2></div>
      <div class="payment-options">
        <?php $i = 0; foreach (METHODS as $m => $icon): ?>
        <label class="payment-option<?= $i === 0 ? ' selected-payment' : '' ?>"><input type="radio" name="payment_method" value="<?= e($m) ?>" <?= $i++ === 0 ? 'checked' : '' ?>><i class="bi <?= $icon ?>"></i> <?= e($m) ?></label>
        <?php endforeach; ?>
      </div>
      <div class="form-group" style="margin-top:14px;max-width:260px"><label for="amountPaid">Amount paid now (₱)</label>
        <input type="number" id="amountPaid" name="amount_paid" min="0" step="0.01" value="0"></div>
    </section>
  </div>

  <aside class="rental-sidebar">
    <section class="summary-card">
      <h2>Rental Summary</h2>
      <div class="summary-details">
        <div class="summary-row"><span>Vehicle</span><strong id="summaryVehicle">—</strong></div>
        <div class="summary-row"><span>Customer</span><strong id="summaryCustomer">—</strong></div>
        <div class="summary-row"><span>Duration</span><strong id="summaryDuration">—</strong></div>
        <div class="summary-row"><span>Daily Rate</span><strong id="summaryRate">₱0.00</strong></div>
      </div>
      <div class="summary-breakdown">
        <div class="summary-row"><span>Base amount</span><strong id="summaryBase">₱0.00</strong></div>
        <div class="summary-row"><span>Add-ons</span><strong id="summaryAddons">₱0.00</strong></div>
      </div>
      <div class="summary-total"><span>Total</span><strong id="summaryTotal">₱0.00</strong></div>
      <div class="summary-row"><span>Balance after payment</span><strong id="summaryBalance">₱0.00</strong></div>
    </section>
    <button type="submit" class="confirm-btn"><i class="bi bi-check-circle"></i> Confirm Rental</button>
    <button type="reset" class="reset-btn"><i class="bi bi-arrow-counterclockwise"></i> Reset</button>
  </aside>
</div>
</form>
</section>
<?php page_end(['rent']); ?>
