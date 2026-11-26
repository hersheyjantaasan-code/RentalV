<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
$admin = require_admin();

// Only these tables/columns can ever be reverted (whitelist = no SQL injection through column names)
const REVERTABLE = [
    'VEHICLE_UPDATE'  => ['table' => 'vehicles', 'pk' => 'vehicle_id', 'extra' => '',
        'cols' => ['plate_number', 'brand', 'model', 'year', 'vehicle_type', 'color', 'mileage', 'transmission', 'seating_capacity', 'fuel_type', 'rate_per_day', 'description', 'status']],
    'CUSTOMER_UPDATE' => ['table' => 'users', 'pk' => 'user_id', 'extra' => " AND role = 'Customer'",
        'cols' => ['first_name', 'last_name', 'email', 'phone', 'license_number', 'account_status']],
];
const ACTIONS = ['LOGIN', 'LOGOUT', 'LOGIN_FAILED', 'VEHICLE_CREATE', 'VEHICLE_UPDATE', 'VEHICLE_DELETE', 'CUSTOMER_CREATE', 'CUSTOMER_UPDATE',
                 'RENTAL_CREATE', 'RENTAL_STATUS', 'RENTAL_OVERDUE', 'PAYMENT_RECORD', 'EXPORT', 'REVERT'];

/* ======================= REVERT = ROLLBACK OF A SAVED CHANGE (transaction) ======================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $logId = (int)($_POST['log_id'] ?? 0);
    run_tx($pdo, function (PDO $pdo) use ($logId) {
        $st = $pdo->prepare('SELECT * FROM audit_logs WHERE log_id = ? FOR UPDATE');
        $st->execute([$logId]);
        $log = $st->fetch();
        $cfg = $log ? (REVERTABLE[$log['action']] ?? null) : null;
        if (!$log || !$cfg || !$log['old_values']) throw new UserError('This log entry cannot be reverted.');
        if ($log['reverted_by']) throw new UserError('This change was already reverted.');

        $old = array_intersect_key(json_decode($log['old_values'], true) ?: [], array_flip($cfg['cols']));
        $before = $pdo->prepare("SELECT * FROM {$cfg['table']} WHERE {$cfg['pk']} = ?{$cfg['extra']} FOR UPDATE");
        $before->execute([$log['record_id']]);
        $current = $before->fetch();
        if (!$current) throw new UserError('The record no longer exists.');
        if (!$old) throw new UserError('Nothing to revert.');

        $sets = implode(', ', array_map(fn($c) => "$c = :$c", array_keys($old)));
        $pdo->prepare("UPDATE {$cfg['table']} SET $sets WHERE {$cfg['pk']} = :__id{$cfg['extra']}")->execute($old + ['__id' => $log['record_id']]);

        $now = array_intersect_key($current, $old);
        $newLog = audit($pdo, 'REVERT', $log['table_name'], (int)$log['record_id'], "Reverted log #{$logId} (" . implode(', ', array_keys($old)) . ').', $now, $old);
        $pdo->prepare('UPDATE audit_logs SET reverted_by = ? WHERE log_id = ?')->execute([$newLog, $logId]);
    }, 'Change reverted successfully.');
    redirect_self();
}

/* ======================= LIST ======================= */
$q = trim($_GET['q'] ?? '');
$act = in_array($_GET['action'] ?? '', ACTIONS, true) ? $_GET['action'] : '';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '';
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '';
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(l.description LIKE ? OR u.username LIKE ? OR l.ip_address LIKE ?)'; array_push($args, ...array_fill(0, 3, like($q))); }
if ($act)  { $where[] = 'l.action = ?'; $args[] = $act; }
if ($from) { $where[] = 'l.created_at >= ?'; $args[] = $from . ' 00:00:00'; }
if ($to)   { $where[] = 'l.created_at < ? + INTERVAL 1 DAY'; $args[] = $to; }
$W = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$per = 20; [$page, $off] = paging($total, $per);
$st = $pdo->prepare("SELECT l.*, u.username FROM audit_logs l LEFT JOIN users u ON u.user_id = l.user_id WHERE $W ORDER BY l.log_id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$logs = $st->fetchAll();

function act_class(string $a): string {
    foreach (['CREATE' => 'create', 'UPDATE' => 'update', 'STATUS' => 'status', 'DELETE' => 'delete', 'FAILED' => 'failed', 'OVERDUE' => 'overdue', 'REVERT' => 'revert', 'PAYMENT' => 'payment'] as $k => $c) {
        if (str_contains($a, $k)) return $c;
    }
    return '';
}

page_start('Audit Logs', 'audit');
?>
<section class="content-area">
    <?= flash_render() ?>
    <form class="tb" method="get" data-auto>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search description, user or IP...">
        <select name="action"><option value="">All Actions</option>
            <?php foreach (ACTIONS as $a): ?><option <?= $act === $a ? 'selected' : '' ?>><?= $a ?></option><?php endforeach; ?></select>
        <label style="font-size:12px;color:#64748b">From <input type="date" name="from" value="<?= e($from) ?>"></label>
        <label style="font-size:12px;color:#64748b">To <input type="date" name="to" value="<?= e($to) ?>"></label>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>#</th><th>DATE &amp; TIME</th><th>USER</th><th>ACTION</th><th>RECORD</th><th>DESCRIPTION</th><th>IP</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($logs as $l):
                $old = $l['old_values'] ? json_decode($l['old_values'], true) : null;
                $new = $l['new_values'] ? json_decode($l['new_values'], true) : null;
                $can = isset(REVERTABLE[$l['action']]) && $old && !$l['reverted_by']; ?>
                <tr>
                    <td style="color:#94a3b8"><?= (int)$l['log_id'] ?></td>
                    <td style="white-space:nowrap"><?= date('M d, Y h:i A', strtotime($l['created_at'])) ?></td>
                    <td><?= e($l['username'] ?: 'system') ?></td>
                    <td><span class="act <?= act_class($l['action']) ?>"><?= e($l['action']) ?></span></td>
                    <td style="white-space:nowrap"><?= $l['table_name'] ? e($l['table_name']) . ($l['record_id'] ? ' #' . (int)$l['record_id'] : '') : '—' ?></td>
                    <td><?= e($l['description']) ?>
                        <?php if ($old || $new): ?>
                        <details><summary>Details</summary><div class="chg">
                            <?php foreach (array_unique(array_merge(array_keys($old ?: []), array_keys($new ?: []))) as $k): ?>
                                <div><b><?= e($k) ?></b>:
                                    <?php if ($old && array_key_exists($k, $old)): ?><del><?= e(is_scalar($old[$k]) || $old[$k] === null ? $old[$k] : json_encode($old[$k])) ?></del> → <?php endif; ?>
                                    <ins><?= e(is_scalar($new[$k] ?? null) || !isset($new[$k]) ? ($new[$k] ?? '') : json_encode($new[$k])) ?></ins></div>
                            <?php endforeach; ?>
                        </div></details>
                        <?php endif; ?>
                        <?php if ($l['reverted_by']): ?><div class="chg">↩ Reverted by log #<?= (int)$l['reverted_by'] ?></div><?php endif; ?>
                    </td>
                    <td style="color:#64748b"><?= e($l['ip_address'] ?: '—') ?></td>
                    <td><?php if ($can): ?>
                        <form method="post" data-confirm="Revert this change and restore the previous values?"><?= csrf_field() ?><input type="hidden" name="log_id" value="<?= (int)$l['log_id'] ?>">
                            <button class="mini red" type="submit"><i class="bi bi-arrow-counterclockwise"></i> Revert</button></form>
                        <?php endif; ?></td>
                </tr>
            <?php endforeach; if (!$logs): ?>
                <tr><td colspan="8" class="empty-row">No audit entries found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager($total, $page, $per) ?>
</section>
<?php page_end(); ?>
