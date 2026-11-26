<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$me = require_customer();

const CP_METHODS = ['GCash', 'Credit Card', 'Debit Card', 'Bank Transfer'];

/* ======================= PAY (transaction: lock rental, check balance, insert, audit) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    run_tx($pdo, function (PDO $pdo) use ($me) {
        $rid = (int)($_POST['rental_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $method = $_POST['payment_method'] ?? '';
        $ref = trim($_POST['reference_number'] ?? '');
        if (!in_array($method, CP_METHODS, true)) throw new UserError('Choose a payment method.');
        if ($ref === '') throw new UserError('Enter your payment reference / transaction number.');
        if ($amount <= 0) throw new UserError('Amount must be greater than zero.');

        $r = $pdo->prepare('SELECT rental_id, rental_code, total_amount, rental_status FROM rentals WHERE rental_id = ? AND customer_id = ? FOR UPDATE');
        $r->execute([$rid, $me['id']]);
        $rent = $r->fetch();
        if (!$rent) throw new UserError('Rental not found.');
        if (in_array($rent['rental_status'], ['Cancelled', 'Rejected'], true)) throw new UserError('This booking was cancelled or rejected.');

        $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE rental_id = ? AND payment_status IN ('Paid','Partial')");
        $p->execute([$rid]);
        $balance = round((float)$rent['total_amount'] - (float)$p->fetchColumn(), 2);
        if ($balance <= 0) throw new UserError('This rental is already fully paid.');
        if ($amount > $balance) throw new UserError('Amount exceeds your remaining balance of ' . peso($balance) . '.');

        $status = $amount >= $balance ? 'Paid' : 'Partial';
        $pdo->prepare('INSERT INTO payments (payment_code, rental_id, amount, payment_method, payment_status, reference_number, notes, recorded_by)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['TMP-' . bin2hex(random_bytes(6)), $rid, $amount, $method, $status, mb_substr($ref, 0, 100), 'Paid online by customer', $me['id']]);
        $pid = (int)$pdo->lastInsertId();
        $code = sprintf('PAY-%s-%04d', date('Y'), $pid);
        $pdo->prepare('UPDATE payments SET payment_code = ? WHERE payment_id = ?')->execute([$code, $pid]);
        audit($pdo, 'PAYMENT_RECORD', 'payments', $pid, "Customer payment $code of " . peso($amount) . " ($method) for {$rent['rental_code']} — $status.", null,
              ['amount' => $amount, 'method' => $method, 'status' => $status, 'reference' => $ref]);
    }, 'Payment received. Thank you!');
    redirect('customer_payments.php');
}

$st = $pdo->prepare("SELECT r.rental_id, r.rental_code, r.total_amount, r.rental_status, CONCAT(v.brand,' ',v.model) AS vehicle,
        COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.rental_id = r.rental_id AND p.payment_status IN ('Paid','Partial')),0) AS paid
    FROM rentals r JOIN vehicles v ON v.vehicle_id = r.vehicle_id
    WHERE r.customer_id = ? AND r.rental_status NOT IN ('Cancelled','Rejected') ORDER BY r.rental_id DESC LIMIT 100");
$st->execute([$me['id']]);
$rentals = $st->fetchAll();

$hist = $pdo->prepare("SELECT p.payment_code, p.amount, p.payment_method, p.payment_status, p.reference_number, p.payment_date, r.rental_code
    FROM payments p JOIN rentals r ON r.rental_id = p.rental_id WHERE r.customer_id = ? ORDER BY p.payment_id DESC LIMIT 50");
$hist->execute([$me['id']]);
$history = $hist->fetchAll();
$auto = (int)($_GET['rental'] ?? 0);

customer_start('My Payments', 'payments');
echo flash_render();
?>
<h3 style="margin-bottom:10px">Rentals &amp; Balances</h3>
<div class="table-wrap" style="margin-bottom:26px"><table class="data-table">
    <thead><tr><th>RENTAL ID</th><th>VEHICLE</th><th>TOTAL</th><th>PAID</th><th>BALANCE</th><th>STATUS</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rentals as $r):
        $bal = max(0, round($r['total_amount'] - $r['paid'], 2)); ?>
        <tr>
            <td><b><?= e($r['rental_code']) ?></b></td>
            <td><?= e($r['vehicle']) ?></td>
            <td><?= peso($r['total_amount']) ?></td>
            <td><?= peso($r['paid']) ?></td>
            <td><b><?= peso($bal) ?></b></td>
            <td><?= $bal > 0 ? '<span class="status ' . ($r['paid'] > 0 ? 'st-partial' : 'st-unpaid') . '">' . ($r['paid'] > 0 ? 'PARTIAL' : 'UNPAID') . '</span>' : '<span class="status st-paid">PAID</span>' ?></td>
            <td><?php if ($bal > 0): ?><button class="btn btn-primary" style="height:32px" type="button" id="pay<?= (int)$r['rental_id'] ?>" data-add="payModal"
                data-json="<?= e(json_encode(['rental_id' => $r['rental_id'], 'rental_code' => $r['rental_code'], 'amount' => $bal, 'balance' => $bal])) ?>"><i class="bi bi-credit-card"></i> Pay Now</button><?php endif; ?></td>
        </tr>
    <?php endforeach; if (!$rentals): ?>
        <tr><td colspan="7" class="empty-row">No bookings yet. <a href="customer_home.php">Browse vehicles</a></td></tr>
    <?php endif; ?>
    </tbody>
</table></div>

<h3 style="margin-bottom:10px">Payment History</h3>
<div class="table-wrap"><table class="data-table">
    <thead><tr><th>PAYMENT ID</th><th>RENTAL</th><th>AMOUNT</th><th>METHOD</th><th>REFERENCE</th><th>DATE</th><th>STATUS</th></tr></thead>
    <tbody>
    <?php foreach ($history as $h): ?>
        <tr><td><b><?= e($h['payment_code']) ?></b></td><td><?= e($h['rental_code']) ?></td><td><?= peso($h['amount']) ?></td><td><?= e($h['payment_method']) ?></td>
            <td><?= e($h['reference_number'] ?: '—') ?></td><td><?= date('M d, Y h:i A', strtotime($h['payment_date'])) ?></td>
            <td><span class="status st-<?= strtolower($h['payment_status']) === 'paid' ? 'paid' : 'partial' ?>"><?= e(strtoupper($h['payment_status'])) ?></span></td></tr>
    <?php endforeach; if (!$history): ?><tr><td colspan="7" class="empty-row">No payments yet.</td></tr><?php endif; ?>
    </tbody>
</table></div>

<div class="modal-overlay" id="payModal">
  <div class="modal sm"><form method="post"><?= csrf_field() ?><input type="hidden" name="rental_id">
    <div class="modal-head"><div><h3>Make a Payment</h3><p>Pay your rental in full or in part.</p></div><button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="fg"><label>Rental</label><input name="rental_code" readonly></div>
      <div class="fg"><label>Remaining balance (₱)</label><input name="balance" readonly></div>
      <div class="fg"><label>Amount to pay (₱) *</label><input type="number" name="amount" min="0.01" step="0.01" required></div>
      <div class="fg"><label>Method *</label><select name="payment_method"><?php foreach (CP_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div class="fg full"><label>Reference / transaction no. *</label><input name="reference_number" maxlength="100" required><small>Enter the GCash, bank or card approval reference of your payment.</small></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Submit Payment</button></div>
  </form></div>
</div>
<?php if ($auto): ?><script>window.addEventListener('load', function () { var b = document.getElementById('pay<?= $auto ?>'); if (b) b.click(); });</script><?php endif; ?>
<?php customer_end(); ?>
