<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$admin = require_admin();

const P_METHODS = ['Cash', 'GCash', 'Credit Card', 'Debit Card', 'Bank Transfer', 'Other'];
$PAID = "(SELECT rental_id, SUM(amount) AS paid, MAX(payment_date) AS last_date FROM payments WHERE payment_status IN ('Paid','Partial') GROUP BY rental_id)";

/* ======================= RECORD PAYMENT (transaction: lock rental, check balance, insert, audit) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    run_tx($pdo, function (PDO $pdo) use ($admin) {
        $rid = (int)($_POST['rental_id'] ?? 0);
        $amount = round((float)($_POST['amount'] ?? 0), 2);
        $method = $_POST['payment_method'] ?? '';
        $ref = trim($_POST['reference_number'] ?? '') ?: null;
        $notes = trim($_POST['notes'] ?? '') ?: null;
        if (!in_array($method, P_METHODS, true)) throw new UserError('Choose a payment method.');
        if ($amount <= 0) throw new UserError('Amount must be greater than zero.');

        $r = $pdo->prepare('SELECT rental_id, rental_code, total_amount, rental_status FROM rentals WHERE rental_id = ? FOR UPDATE');
        $r->execute([$rid]);
        $rent = $r->fetch();
        if (!$rent) throw new UserError('Rental not found.');
        if (in_array($rent['rental_status'], ['Cancelled', 'Rejected'], true)) throw new UserError('Cannot record a payment for a cancelled or rejected rental.');

        $p = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM payments WHERE rental_id = ? AND payment_status IN ('Paid','Partial')");
        $p->execute([$rid]);
        $paid = (float)$p->fetchColumn();
        $balance = round((float)$rent['total_amount'] - $paid, 2);
        if ($balance <= 0) throw new UserError('This rental is already fully paid.');
        if ($amount > $balance) throw new UserError('Amount exceeds the remaining balance of ' . peso($balance) . '.');

        $status = $amount >= $balance ? 'Paid' : 'Partial';
        $pdo->prepare('INSERT INTO payments (payment_code, rental_id, amount, payment_method, payment_status, reference_number, notes, recorded_by)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute(['TMP-' . bin2hex(random_bytes(6)), $rid, $amount, $method, $status, $ref, $notes, $admin['id']]);
        $pid = (int)$pdo->lastInsertId();
        $code = sprintf('PAY-%s-%04d', date('Y'), $pid);
        $pdo->prepare('UPDATE payments SET payment_code = ? WHERE payment_id = ?')->execute([$code, $pid]);
        audit($pdo, 'PAYMENT_RECORD', 'payments', $pid, "Recorded $code of " . peso($amount) . " ($method) for {$rent['rental_code']} — $status.", null,
              ['amount' => $amount, 'method' => $method, 'status' => $status]);
    }, 'Payment recorded successfully.');
    redirect_self();
}

/* ======================= SUMMARY (one query) ======================= */
$sum = $pdo->query("SELECT
    COALESCE(SUM(CASE WHEN COALESCE(p.paid,0)=0 THEN r.total_amount END),0) AS unpaid_amt,
    COALESCE(SUM(COALESCE(p.paid,0)=0),0) AS unpaid_cnt,
    COALESCE(SUM(CASE WHEN p.paid>0 AND p.paid<r.total_amount THEN r.total_amount-p.paid END),0) AS partial_bal,
    COALESCE(SUM(p.paid>0 AND p.paid<r.total_amount),0) AS partial_cnt
  FROM rentals r LEFT JOIN $PAID p ON p.rental_id = r.rental_id
  WHERE r.rental_status NOT IN ('Rejected','Cancelled')")->fetch();
$collected = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status IN ('Paid','Partial') AND payment_date >= DATE_FORMAT(NOW(),'%Y-%m-01')")->fetchColumn();

/* ======================= LIST ======================= */
$q = trim($_GET['q'] ?? '');
$f = in_array($_GET['f'] ?? '', ['paid', 'partial', 'unpaid'], true) ? $_GET['f'] : '';
$where = ["r.rental_status NOT IN ('Rejected','Cancelled')"]; $args = [];
if ($q !== '') {
    $where[] = "(r.rental_code LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR CONCAT(v.brand,' ',v.model) LIKE ?)";
    array_push($args, ...array_fill(0, 3, like($q)));
}
if ($f === 'paid')    $where[] = 'COALESCE(p.paid,0) >= r.total_amount';
if ($f === 'partial') $where[] = 'COALESCE(p.paid,0) > 0 AND COALESCE(p.paid,0) < r.total_amount';
if ($f === 'unpaid')  $where[] = 'COALESCE(p.paid,0) = 0';
$W = implode(' AND ', $where);
$FROM = "FROM rentals r JOIN users u ON u.user_id = r.customer_id JOIN vehicles v ON v.vehicle_id = r.vehicle_id LEFT JOIN $PAID p ON p.rental_id = r.rental_id";

$cnt = $pdo->prepare("SELECT COUNT(*) $FROM WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$per = 10; [$page, $off] = paging($total, $per);
$st = $pdo->prepare("SELECT r.rental_id, r.rental_code, r.total_amount, CONCAT(u.first_name,' ',u.last_name) AS customer,
        CONCAT(v.brand,' ',v.model) AS vehicle, COALESCE(p.paid,0) AS paid, p.last_date,
        (SELECT x.payment_method FROM payments x WHERE x.rental_id = r.rental_id ORDER BY x.payment_id DESC LIMIT 1) AS last_method
    $FROM WHERE $W ORDER BY r.rental_id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$rows = $st->fetchAll();

// rentals that still owe money (for the modal dropdown)
$owing = $pdo->query("SELECT r.rental_id, r.rental_code, CONCAT(u.first_name,' ',u.last_name) AS customer, r.total_amount - COALESCE(p.paid,0) AS balance
    FROM rentals r JOIN users u ON u.user_id = r.customer_id LEFT JOIN $PAID p ON p.rental_id = r.rental_id
    WHERE r.rental_status NOT IN ('Rejected','Cancelled') AND r.total_amount - COALESCE(p.paid,0) > 0
    ORDER BY r.rental_id DESC LIMIT 300")->fetchAll();

page_start('Payments', 'payments', ['payments']);
?>
<section class="payments-content">
    <?= flash_render() ?>
    <div class="payment-summary">
        <div class="summary-card"><div class="summary-info"><span>Total Collected</span><h2><?= peso($collected) ?></h2>
            <small class="positive"><i class="bi bi-arrow-up"></i> This month</small></div>
            <div class="summary-icon collected-icon"><i class="bi bi-cash-stack"></i></div></div>
        <div class="summary-card"><div class="summary-info"><span>Pending Amount</span><h2><?= peso($sum['unpaid_amt']) ?></h2>
            <small class="negative"><i class="bi bi-arrow-down"></i> <?= (int)$sum['unpaid_cnt'] ?> unpaid rental(s)</small></div>
            <div class="summary-icon pending-icon"><i class="bi bi-clock"></i></div></div>
        <div class="summary-card"><div class="summary-info"><span>Partial Payments</span><h2><?= peso($sum['partial_bal']) ?></h2>
            <small class="neutral"><?= (int)$sum['partial_cnt'] ?> rental(s) with balance remaining</small></div>
            <div class="summary-icon partial-icon"><i class="bi bi-credit-card"></i></div></div>
    </div>

    <div class="payment-toolbar">
        <form class="toolbar-left" method="get" data-auto>
            <div class="search-box"><i class="bi bi-search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search payments..."></div>
            <select name="f" class="payment-filter">
                <option value="">All Payments</option>
                <?php foreach (['paid' => 'Paid', 'partial' => 'Partial', 'unpaid' => 'Unpaid'] as $k => $l): ?><option value="<?= $k ?>" <?= $f === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
            </select>
        </form>
        <button class="record-payment-btn" type="button" data-add="payModal"><i class="bi bi-plus-lg"></i> Record Payment</button>
    </div>

    <div class="payment-table-container"><div style="overflow-x:auto">
        <table class="payment-table">
            <thead><tr><th>RENTAL ID</th><th>CUSTOMER</th><th>VEHICLE</th><th>TOTAL</th><th>PAID</th><th>BALANCE</th><th>LAST PAYMENT</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $r):
                $bal = max(0, round($r['total_amount'] - $r['paid'], 2));
                $state = $r['paid'] <= 0 ? 'unpaid' : ($bal > 0 ? 'partial' : 'paid'); ?>
                <tr>
                    <td class="payment-id"><?= e($r['rental_code']) ?></td>
                    <td class="customer-name"><?= e($r['customer']) ?></td>
                    <td><?= e($r['vehicle']) ?></td>
                    <td class="amount"><?= peso($r['total_amount']) ?></td>
                    <td><?= peso($r['paid']) ?></td>
                    <td class="amount"><?= peso($bal) ?></td>
                    <td><?= $r['last_date'] ? e(date('M d, Y', strtotime($r['last_date']))) . ' · ' . e($r['last_method']) : '—' ?></td>
                    <td><span class="status-badge <?= $state ?>"><?= strtoupper($state) ?></span></td>
                    <td><?php if ($bal > 0): ?><button class="action-btn pay-btn" type="button" data-add="payModal" data-json="<?= e(json_encode(['rental_id' => $r['rental_id'], 'amount' => $bal])) ?>">Pay</button>
                        <?php else: ?><span style="color:#94a3b8">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; if (!$rows): ?>
                <tr><td colspan="9" class="empty-row">No payments found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table></div>
    </div>
    <?= pager($total, $page, $per) ?>
</section>

<div class="modal-overlay" id="payModal">
  <div class="modal sm"><form method="post"><?= csrf_field() ?>
    <div class="modal-head"><div><h3>Record Payment</h3><p>Payments cannot exceed the remaining balance.</p></div><button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
    <div class="modal-body"><div class="form-grid">
      <div class="fg full"><label>Rental *</label>
        <select name="rental_id" id="payRental" required><option value="">Select rental...</option>
          <?php foreach ($owing as $o): ?><option value="<?= (int)$o['rental_id'] ?>" data-balance="<?= e($o['balance']) ?>"><?= e($o['rental_code'] . ' — ' . $o['customer']) ?></option><?php endforeach; ?></select></div>
      <div class="fg full"><div class="balance-preview" style="display:flex;justify-content:space-between;padding:12px;background:#f1f5f9;border-radius:8px"><span>Remaining balance</span><strong id="payBalance">₱0.00</strong></div></div>
      <div class="fg"><label>Amount (₱) *</label><input type="number" name="amount" id="payAmount" min="0.01" step="0.01" required></div>
      <div class="fg"><label>Method *</label><select name="payment_method"><?php foreach (P_METHODS as $m): ?><option><?= e($m) ?></option><?php endforeach; ?></select></div>
      <div class="fg"><label>Reference No.</label><input name="reference_number" maxlength="100"></div>
      <div class="fg"><label>Notes</label><input name="notes" maxlength="255"></div>
    </div></div>
    <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Payment</button></div>
  </form></div>
</div>
<script>
(function () {
  var sel = document.getElementById('payRental'), bal = document.getElementById('payBalance'), amt = document.getElementById('payAmount');
  function show() {
    var o = sel.options[sel.selectedIndex], b = parseFloat(o && o.dataset.balance || 0);
    bal.textContent = '₱' + b.toLocaleString('en-PH', {minimumFractionDigits: 2}); amt.max = b || '';
  }
  sel.addEventListener('change', function () { show(); var o = sel.options[sel.selectedIndex]; if (o.dataset.balance) amt.value = o.dataset.balance; });
  document.addEventListener('modal:prepared', function () { show(); });
})();
</script>
<?php page_end(); ?>
