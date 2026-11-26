<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
require_admin();
refresh_rentals($pdo);

// ONE query for every card/number instead of ten separate ones
$s = $pdo->query("SELECT
    (SELECT COUNT(*) FROM users WHERE role='Customer') AS customers,
    (SELECT COUNT(*) FROM users WHERE role='Customer' AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) AS new_customers,
    (SELECT COUNT(*) FROM vehicles) AS vehicles,
    (SELECT COUNT(*) FROM vehicles WHERE created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) AS new_vehicles,
    (SELECT COUNT(*) FROM vehicles WHERE status='Available') AS av,
    (SELECT COUNT(*) FROM vehicles WHERE status='Rented') AS re,
    (SELECT COUNT(*) FROM vehicles WHERE status='Maintenance') AS ma,
    (SELECT COUNT(*) FROM rentals WHERE rental_status IN ('Active','Overdue')) AS active_rentals,
    (SELECT COUNT(*) FROM rentals WHERE rental_status IN ('Active','Overdue') AND DATE(return_datetime)=CURDATE()) AS due_today,
    (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status IN ('Paid','Partial') AND payment_date >= DATE_FORMAT(NOW(),'%Y-%m-01')) AS rev,
    (SELECT COALESCE(SUM(amount),0) FROM payments WHERE payment_status IN ('Paid','Partial')
        AND payment_date >= DATE_FORMAT(NOW() - INTERVAL 1 MONTH,'%Y-%m-01') AND payment_date < DATE_FORMAT(NOW(),'%Y-%m-01')) AS prev_rev")->fetch();

$recent = $pdo->query("SELECT r.rental_code, r.pickup_datetime, r.return_datetime, r.total_amount, r.rental_status,
        CONCAT(u.first_name,' ',u.last_name) AS customer, CONCAT(v.brand,' ',v.model,' ',v.year) AS vehicle
    FROM rentals r JOIN users u ON u.user_id=r.customer_id JOIN vehicles v ON v.vehicle_id=r.vehicle_id
    ORDER BY r.rental_id DESC LIMIT 5")->fetchAll();

$fleet = max(1, (int)$s['vehicles']);
$pct = fn($n) => round($n / $fleet * 100);
$growth = $s['prev_rev'] > 0 ? round(($s['rev'] - $s['prev_rev']) / $s['prev_rev'] * 100, 1) : null;

page_start('Dashboard', 'dashboard');
?>
<section class="content-area">
    <?= flash_render() ?>

    <section class="stats-grid">
        <article class="stat-card"><div class="stat-info"><p>Total Customers</p><h2><?= number_format($s['customers']) ?></h2>
            <small class="trend"><i class="bi bi-plus-circle"></i> <?= (int)$s['new_customers'] ?> new this month</small></div>
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div></article>
        <article class="stat-card"><div class="stat-info"><p>Total Vehicles</p><h2><?= number_format($s['vehicles']) ?></h2>
            <small class="trend"><i class="bi bi-plus-circle"></i> <?= (int)$s['new_vehicles'] ?> added this month</small></div>
            <div class="stat-icon purple"><i class="bi bi-car-front-fill"></i></div></article>
        <article class="stat-card"><div class="stat-info"><p>Active Rentals</p><h2><?= number_format($s['active_rentals']) ?></h2>
            <small class="neutral"><i class="bi bi-calendar-event"></i> <?= (int)$s['due_today'] ?> due today</small></div>
            <div class="stat-icon mint"><i class="bi bi-key-fill"></i></div></article>
        <article class="stat-card"><div class="stat-info"><p>Monthly Revenue</p><h2><?= peso($s['rev']) ?></h2>
            <small class="<?= ($growth ?? 0) >= 0 ? 'trend' : 'neutral' ?>"><i class="bi bi-arrow-up-right"></i>
                <?= $growth === null ? 'No data last month' : $growth . '% from last month' ?></small></div>
            <div class="stat-icon yellow"><i class="bi bi-cash-stack"></i></div></article>
    </section>

    <section class="dashboard-grid">
        <section class="panel rentals-panel">
            <div class="panel-header">
                <div><h2>Recent Rentals</h2><p>Latest rental transactions</p></div>
                <a href="rental_records.php" class="view-btn">View All <i class="bi bi-arrow-right"></i></a>
            </div>
            <div class="table-container">
                <table class="rentals-table">
                    <thead><tr><th>RENTAL ID</th><th>CUSTOMER</th><th>VEHICLE</th><th>START DATE</th><th>END DATE</th><th>AMOUNT</th><th>STATUS</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="rental-id"><?= e($r['rental_code']) ?></td>
                            <td><?= e($r['customer']) ?></td>
                            <td><?= e($r['vehicle']) ?></td>
                            <td><?= date('M d, Y', strtotime($r['pickup_datetime'])) ?></td>
                            <td><?= date('M d, Y', strtotime($r['return_datetime'])) ?></td>
                            <td><?= peso($r['total_amount']) ?></td>
                            <td><?= rental_badge($r['rental_status']) ?></td>
                        </tr>
                    <?php endforeach; if (!$recent): ?>
                        <tr><td colspan="7" class="empty-row">No rentals yet. <a href="rent_vehicle.php">Create the first one</a>.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <div class="table-footer">Showing <?= count($recent) ?> recent rental record(s)</div>
        </section>

        <div class="right-column">
            <section class="panel fleet-panel">
                <div class="panel-header">
                    <div><h2>Fleet Status</h2><p>Vehicle availability overview</p></div>
                    <i class="bi bi-car-front fleet-heading-icon"></i>
                </div>
                <?php foreach ([['Available', $s['av'], 'available-bar'], ['Rented', $s['re'], 'rented-bar'], ['Maintenance', $s['ma'], 'maintenance-bar']] as [$lbl, $n, $bar]): ?>
                <div class="fleet-item">
                    <div class="fleet-label"><span><?= $lbl ?></span><strong><?= (int)$n ?></strong></div>
                    <div class="progress"><div class="progress-bar <?= $bar ?>" style="width:<?= $pct($n) ?>%"></div></div>
                    <small><?= $pct($n) ?>% of fleet</small>
                </div>
                <?php endforeach; ?>
            </section>

            <section class="panel">
                <div class="panel-header"><div><h2>Quick Actions</h2><p>Jump to common tasks</p></div></div>
                <div class="quick-actions">
                    <a class="quick-action-btn" href="rent_vehicle.php"><i class="bi bi-calendar-check-fill"></i> New Rental</a>
                    <a class="quick-action-btn" href="vehicles.php"><i class="bi bi-car-front-fill"></i> Vehicles</a>
                    <a class="quick-action-btn" href="payments.php"><i class="bi bi-credit-card"></i> Payments</a>
                    <a class="quick-action-btn" href="audit_logs.php"><i class="bi bi-journal-text"></i> Audit Logs</a>
                </div>
            </section>
        </div>
    </section>
</section>
<?php page_end(); ?>
