<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$me = require_customer();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $rid = (int)($_POST['id'] ?? 0);
    run_tx($pdo, function (PDO $pdo) use ($rid, $me) {
        $st = $pdo->prepare('SELECT rental_code, rental_status, vehicle_id FROM rentals WHERE rental_id = ? AND customer_id = ? FOR UPDATE');
        $st->execute([$rid, $me['id']]);
        $r = $st->fetch();
        if (!$r) throw new UserError('Rental not found.');
        if ($r['rental_status'] !== 'Pending') throw new UserError('Only pending requests can be cancelled. Please contact our staff.');
        $pdo->prepare("UPDATE rentals SET rental_status='Cancelled', cancelled_at=NOW(), cancellation_reason='Cancelled by customer' WHERE rental_id=?")->execute([$rid]);
        release_vehicle($pdo, (int)$r['vehicle_id'], $rid);   // vehicle is bookable again
        audit($pdo, 'RENTAL_STATUS', 'rentals', $rid, "{$r['rental_code']}: Pending → Cancelled (by customer)", ['status' => 'Pending'], ['status' => 'Cancelled']);
    }, 'Booking request cancelled.');
    redirect_self();
}

$st = $pdo->prepare("SELECT r.rental_id, r.rental_code, r.pickup_datetime, r.return_datetime, r.rental_days, r.total_amount, r.rental_status,
        CONCAT(v.brand,' ',v.model,' ',v.year) AS vehicle, v.plate_number,
        (SELECT COALESCE(SUM(p.amount),0) FROM payments p WHERE p.rental_id = r.rental_id AND p.payment_status IN ('Paid','Partial')) AS paid
    FROM rentals r JOIN vehicles v ON v.vehicle_id = r.vehicle_id WHERE r.customer_id = ? ORDER BY r.rental_id DESC LIMIT 100");
$st->execute([$me['id']]);
$rows = $st->fetchAll();

customer_start('My Rentals', 'rentals');
echo flash_render();
?>
<div class="table-wrap"><table class="data-table">
    <thead><tr><th>RENTAL ID</th><th>VEHICLE</th><th>PICKUP</th><th>RETURN</th><th>DAYS</th><th>TOTAL</th><th>PAID</th><th>STATUS</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
        <tr>
            <td><b><?= e($r['rental_code']) ?></b></td>
            <td><?= e($r['vehicle']) ?> <small style="color:#94a3b8">(<?= e($r['plate_number']) ?>)</small></td>
            <td><?= date('M d, Y', strtotime($r['pickup_datetime'])) ?></td>
            <td><?= date('M d, Y', strtotime($r['return_datetime'])) ?></td>
            <td><?= (int)$r['rental_days'] ?></td>
            <td><?= peso($r['total_amount']) ?></td>
            <td><?= peso($r['paid']) ?></td>
            <td><?= rental_badge($r['rental_status']) ?></td>
            <td style="display:flex;gap:6px"><?php if ($r['total_amount'] - $r['paid'] > 0 && !in_array($r['rental_status'], ['Cancelled', 'Rejected'], true)): ?>
                <a class="mini green" href="customer_payments.php?rental=<?= (int)$r['rental_id'] ?>">Pay</a><?php endif; ?>
                <?php if ($r['rental_status'] === 'Pending'): ?>
                <form method="post" data-confirm="Cancel this booking request?"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['rental_id'] ?>"><button class="mini red" type="submit">Cancel</button></form>
            <?php endif; ?></td>
        </tr>
    <?php endforeach; if (!$rows): ?>
        <tr><td colspan="9" class="empty-row">You have no rentals yet. <a href="customer_home.php">Browse vehicles</a></td></tr>
    <?php endif; ?>
    </tbody>
</table></div>
<?php customer_end(); ?>
