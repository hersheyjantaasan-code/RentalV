<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$admin = require_admin();
refresh_rentals($pdo);

const R_STATUS = ['Pending', 'Approved', 'Active', 'Completed', 'Overdue', 'Cancelled', 'Rejected'];

/* ======================= STATUS CHANGES (each one is a transaction) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rid = (int)($_POST['id'] ?? 0);
    $op = $_POST['op'] ?? '';
    $reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255) ?: null;

    run_tx($pdo, function (PDO $pdo) use ($rid, $op, $reason, $admin) {
        $st = $pdo->prepare('SELECT rental_id, rental_code, vehicle_id, rental_status FROM rentals WHERE rental_id = ? FOR UPDATE');
        $st->execute([$rid]);
        $r = $st->fetch();
        if (!$r) throw new UserError('Rental not found.');
        $from = $r['rental_status'];
        $veh = $pdo->prepare('SELECT status, mileage FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
        $veh->execute([$r['vehicle_id']]);
        $vRow = $veh->fetch();
        $vStatus = $vRow['status'];
        $extra = [];
        $setVehicle = fn(string $s) => $pdo->prepare('UPDATE vehicles SET status = ? WHERE vehicle_id = ?')->execute([$s, $r['vehicle_id']]);

        switch ($op) {
            case 'approve':
                if ($from !== 'Pending') throw new UserError('Only pending rentals can be approved.');
                $pdo->prepare("UPDATE rentals SET rental_status='Approved', approved_by=?, approved_at=NOW() WHERE rental_id=?")->execute([$admin['id'], $rid]);
                $to = 'Approved';
                break;
            case 'reject':
                if ($from !== 'Pending') throw new UserError('Only pending rentals can be rejected.');
                $pdo->prepare("UPDATE rentals SET rental_status='Rejected' WHERE rental_id=?")->execute([$rid]);
                release_vehicle($pdo, (int)$r['vehicle_id'], $rid);          // vehicle is Available again
                $to = 'Rejected';
                break;
            case 'start':
                if ($from !== 'Approved') throw new UserError('Only approved rentals can be started.');
                if (!in_array($vStatus, ['Available', 'Rented'], true)) throw new UserError("The vehicle is currently $vStatus and cannot be handed out.");
                $pdo->prepare("UPDATE rentals SET rental_status='Active' WHERE rental_id=?")->execute([$rid]);
                hold_vehicle($pdo, (int)$r['vehicle_id']);
                $to = 'Active';
                break;
            case 'complete':                                                  // ---- RETURN PROCESS
                if (!in_array($from, ['Active', 'Overdue'], true)) throw new UserError('Only active or overdue rentals can be returned.');
                $mileage = ($_POST['return_mileage'] ?? '') === '' ? null : (int)$_POST['return_mileage'];
                if ($mileage !== null) {
                    if ($mileage < (int)$vRow['mileage']) throw new UserError('Return mileage cannot be lower than the vehicle’s current mileage (' . number_format($vRow['mileage']) . ' km).');
                    $pdo->prepare('UPDATE vehicles SET mileage = ? WHERE vehicle_id = ?')->execute([$mileage, $r['vehicle_id']]);
                }
                $pdo->prepare("UPDATE rentals SET rental_status='Completed' WHERE rental_id=?")->execute([$rid]);
                release_vehicle($pdo, (int)$r['vehicle_id'], $rid);          // Rented -> Available automatically
                $to = 'Completed';
                $extra = ['returned_at' => date('Y-m-d H:i:s'), 'return_mileage' => $mileage, 'return_notes' => mb_substr(trim($_POST['return_notes'] ?? ''), 0, 200)];
                break;
            case 'cancel':
                if (!in_array($from, ['Pending', 'Approved', 'Active', 'Overdue'], true)) throw new UserError('This rental can no longer be cancelled.');
                $pdo->prepare("UPDATE rentals SET rental_status='Cancelled', cancelled_at=NOW(), cancellation_reason=? WHERE rental_id=?")->execute([$reason, $rid]);
                release_vehicle($pdo, (int)$r['vehicle_id'], $rid);
                $to = 'Cancelled';
                break;
            default:
                throw new UserError('Unknown action.');
        }
        audit($pdo, 'RENTAL_STATUS', 'rentals', $rid, "{$r['rental_code']}: $from → $to" . ($op === 'cancel' && $reason ? " ($reason)" : ''), ['status' => $from], ['status' => $to] + $extra);
    }, 'Rental updated.');
    redirect_self();
}

/* ======================= LIST / EXPORT ======================= */
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', R_STATUS, true) ? $_GET['status'] : '';
$where = ['1=1']; $args = [];
if ($q !== '') {
    $where[] = "(r.rental_code LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR v.plate_number LIKE ? OR CONCAT(v.brand,' ',v.model) LIKE ?)";
    array_push($args, ...array_fill(0, 4, like($q)));
}
if ($status) { $where[] = 'r.rental_status = ?'; $args[] = $status; }
$W = implode(' AND ', $where);
$FROM = "FROM rentals r JOIN users u ON u.user_id = r.customer_id JOIN vehicles v ON v.vehicle_id = r.vehicle_id";
$COLS = "r.rental_id, r.rental_code, r.pickup_datetime, r.return_datetime, r.pickup_location, r.dropoff_location, r.rental_days, r.daily_rate,
         r.base_amount, r.addon_amount, r.total_amount, r.rental_status, r.cancellation_reason,
         CONCAT(u.first_name,' ',u.last_name) AS customer, CONCAT(v.brand,' ',v.model,' ',v.year) AS vehicle, v.plate_number, v.mileage AS vehicle_mileage,
         (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.rental_id = r.rental_id AND p.payment_status IN ('Paid','Partial')) AS paid";

if (isset($_GET['export'])) {
    $ex = $pdo->prepare("SELECT $COLS $FROM WHERE $W ORDER BY r.rental_id DESC LIMIT 5000");
    $ex->execute($args);
    $rows = $ex->fetchAll();
    audit($pdo, 'EXPORT', 'rentals', null, 'Exported ' . count($rows) . ' rental record(s) to CSV.');
    while (ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="rental_records_' . date('Ymd_His') . '.csv"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Rental ID', 'Customer', 'Vehicle', 'Plate', 'Start', 'End', 'Days', 'Rate', 'Total', 'Paid', 'Status']);
    foreach ($rows as $r) fputcsv($o, [$r['rental_code'], $r['customer'], $r['vehicle'], $r['plate_number'], $r['pickup_datetime'], $r['return_datetime'], $r['rental_days'], $r['daily_rate'], $r['total_amount'], $r['paid'], $r['rental_status']]);
    exit;
}

$cnt = $pdo->prepare("SELECT COUNT(*) $FROM WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$per = 10; [$page, $off] = paging($total, $per);
$st = $pdo->prepare("SELECT $COLS $FROM WHERE $W ORDER BY r.rental_id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$rows = $st->fetchAll();

page_start('Rental Records', 'records', ['rental_records']);
?>
<div class="rental-records-content">
    <?= flash_render() ?>
    <form class="records-toolbar" method="get" data-auto>
        <div class="records-search-box"><i class="bi bi-search"></i>
            <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search records..." aria-label="Search rental records"></div>
        <select name="status" class="records-filter" aria-label="Filter rental status">
            <option value="">All Status</option>
            <?php foreach (R_STATUS as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
        </select>
        <a class="records-export-btn" href="<?= e(qs(['export' => 1, 'page' => null])) ?>"><i class="bi bi-download"></i> Export CSV</a>
    </form>

    <section class="records-table-card">
        <div class="records-table-wrapper">
            <table class="rental-records-table">
                <thead><tr><th>RENTAL ID</th><th>CUSTOMER</th><th>VEHICLE</th><th>PLATE NO.</th><th>START</th><th>END</th><th>DAYS</th><th>RATE</th><th>TOTAL</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $r):
                    $s = $r['rental_status'];
                    $detail = $r + ['balance' => max(0, $r['total_amount'] - $r['paid'])]; ?>
                    <tr>
                        <td class="rental-id"><?= e($r['rental_code']) ?></td>
                        <td class="customer-name"><?= e($r['customer']) ?></td>
                        <td><?= e($r['vehicle']) ?></td>
                        <td class="plate-number"><?= e($r['plate_number']) ?></td>
                        <td><?= date('M d, Y', strtotime($r['pickup_datetime'])) ?></td>
                        <td><?= date('M d, Y', strtotime($r['return_datetime'])) ?></td>
                        <td class="days-value"><?= (int)$r['rental_days'] ?></td>
                        <td><?= peso($r['daily_rate']) ?>/day</td>
                        <td class="total-amount"><?= peso($r['total_amount']) ?></td>
                        <td><?= rental_badge($s) ?></td>
                        <td><div class="record-actions">
                            <button class="view-btn" type="button" data-view="viewModal" data-json="<?= e(json_encode($detail)) ?>">View</button>
                            <?php if ($s === 'Pending'):
                                foreach ([['approve', 'Approve'], ['reject', 'Reject']] as [$op, $lbl]): ?>
                            <form method="post" data-confirm="<?= e($lbl . ' ' . $r['rental_code'] . '?') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>"><input type="hidden" name="op" value="<?= $op ?>">
                                <button class="edit-btn" type="submit"><?= $lbl ?></button></form>
                            <?php endforeach;
                            elseif ($s === 'Approved'): ?>
                            <form method="post" data-confirm="<?= e('Start ' . $r['rental_code'] . '?') ?>"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>"><input type="hidden" name="op" value="start">
                                <button class="edit-btn" type="submit">Start</button></form>
                            <?php elseif (in_array($s, ['Active', 'Overdue'], true)): ?>
                            <button class="edit-btn" type="button" data-add="returnModal"
                                data-json="<?= e(json_encode(['id' => $r['rental_id'], 'rental_code' => $r['rental_code'], 'return_mileage' => $r['vehicle_mileage']])) ?>">Return</button>
                            <?php endif;
                            if (in_array($s, ['Pending', 'Approved', 'Active', 'Overdue'], true)): ?>
                            <form method="post" data-prompt="Reason for cancelling <?= e($r['rental_code']) ?> (optional):"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>"><input type="hidden" name="op" value="cancel"><input type="hidden" name="reason">
                                <button class="edit-btn" type="submit" style="color:#dc2626">Cancel</button></form>
                            <?php endif; ?>
                        </div></td>
                    </tr>
                <?php endforeach; if (!$rows): ?>
                    <tr><td colspan="11" class="empty-row">No rental records found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?= pager($total, $page, $per) ?>
    </section>
</div>

<div class="modal-overlay" id="returnModal">
  <div class="modal sm"><form method="post"><?= csrf_field() ?><input type="hidden" name="id"><input type="hidden" name="op" value="complete">
    <div class="modal-head"><div><h3>Process Vehicle Return</h3><p>The vehicle becomes Available again once you confirm.</p></div><button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="fg full"><label>Rental</label><input name="rental_code" readonly></div>
      <div class="fg full"><label>Mileage at return (km)</label><input type="number" name="return_mileage" min="0"><small>Optional. Updates the vehicle’s mileage.</small></div>
      <div class="fg full"><label>Condition / notes</label><input name="return_notes" maxlength="200" placeholder="e.g. returned clean, no damage"></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Confirm Return</button></div>
  </form></div>
</div>

<div class="modal-overlay" id="viewModal">
  <div class="modal sm"><div class="modal-head"><div><h3 id="vTitle">Rental</h3><p>Rental details</p></div><button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><dl class="kv" id="vBody"></dl></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Close</button></div></div>
</div>
<script>
document.addEventListener('modal:view', function (ev) {
  var d = ev.detail.data, m = function (n) { return '₱' + Number(n).toLocaleString('en-PH', {minimumFractionDigits: 2}); };
  document.getElementById('vTitle').textContent = d.rental_code;
  var rows = [['Customer', d.customer], ['Vehicle', d.vehicle + ' (' + d.plate_number + ')'], ['Pickup', d.pickup_datetime + ' — ' + (d.pickup_location || '')],
    ['Return', d.return_datetime + ' — ' + (d.dropoff_location || '')], ['Days', d.rental_days], ['Base amount', m(d.base_amount)], ['Add-ons', m(d.addon_amount)],
    ['Total', m(d.total_amount)], ['Paid', m(d.paid)], ['Balance', m(d.balance)], ['Status', d.rental_status]];
  if (d.cancellation_reason) rows.push(['Cancel reason', d.cancellation_reason]);
  var b = document.getElementById('vBody'); b.textContent = '';
  rows.forEach(function (r) { var t = document.createElement('dt'), v = document.createElement('dd'); t.textContent = r[0]; v.textContent = r[1]; b.appendChild(t); b.appendChild(v); });
});
</script>
<?php page_end(); ?>
