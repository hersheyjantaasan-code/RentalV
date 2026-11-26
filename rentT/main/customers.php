<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
require_admin();

const CFIELDS = ['first_name', 'last_name', 'email', 'phone', 'license_number', 'account_status'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $id = (int)($_POST['id'] ?? 0);
    $in = [
        'first_name'     => trim($_POST['first_name'] ?? ''),
        'last_name'      => trim($_POST['last_name'] ?? ''),
        'email'          => trim($_POST['email'] ?? ''),
        'phone'          => trim($_POST['phone'] ?? '') ?: null,
        'license_number' => trim($_POST['license_number'] ?? '') ?: null,
        'account_status' => $_POST['account_status'] ?? 'Active',
    ];
    $password = (string)($_POST['customer_password'] ?? '');

    run_tx($pdo, function (PDO $pdo) use ($id, $in, $password) {
        if ($in['first_name'] === '' || $in['last_name'] === '' || !filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            throw new UserError('Please enter valid customer information.');
        }
        if (!in_array($in['account_status'], ['Active', 'Inactive'], true)) throw new UserError('Invalid account status.');

        if (!$id) {                                                    // ---- ADD
            if (strlen($password) < 8) throw new UserError('Password must be at least 8 characters.');
            $base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($in['email'], '@', true))) ?: 'customer';
            $pdo->prepare("INSERT INTO users (username, password_hash, role, first_name, last_name, email, phone, license_number, account_status)
                           VALUES (?,?,'Customer',?,?,?,?,?,?)")
                ->execute([$base . '_' . bin2hex(random_bytes(4)), password_hash($password, PASSWORD_DEFAULT),
                           $in['first_name'], $in['last_name'], $in['email'], $in['phone'], $in['license_number'], $in['account_status']]);
            $new = (int)$pdo->lastInsertId();
            audit($pdo, 'CUSTOMER_CREATE', 'users', $new, "Added customer {$in['first_name']} {$in['last_name']}.", null, $in);
            return;
        }
        // ---- UPDATE
        $st = $pdo->prepare("SELECT * FROM users WHERE user_id = ? AND role = 'Customer' FOR UPDATE");
        $st->execute([$id]);
        $old = $st->fetch();
        if (!$old) throw new UserError('Customer not found.');

        $pdo->prepare('UPDATE users SET first_name=:first_name, last_name=:last_name, email=:email, phone=:phone,
                       license_number=:license_number, account_status=:account_status WHERE user_id=:id')
            ->execute($in + ['id' => $id]);
        [$o, $n] = diff_fields($old, $in, CFIELDS);
        $pwNote = '';
        if ($password !== '') {
            if (strlen($password) < 8) throw new UserError('New password must be at least 8 characters.');
            $pdo->prepare('UPDATE users SET password_hash=? WHERE user_id=?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
            $pwNote = ' (password changed)';
        }
        if ($o || $pwNote) {
            audit($pdo, 'CUSTOMER_UPDATE', 'users', $id, "Updated customer {$in['first_name']} {$in['last_name']}: " . ($o ? implode(', ', array_keys($o)) : 'no field changes') . $pwNote, $o ?: null, $n ?: null);
        }
    }, $id ? 'Customer updated successfully!' : 'Customer added successfully!');
    redirect_self();
}

// ---- list (server-side search + filter + paging = fast with many rows)
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', ['Active', 'Inactive'], true) ? $_GET['status'] : '';
$where = ["u.role = 'Customer'"]; $args = [];
if ($q !== '') {
    $where[] = "(u.first_name LIKE ? OR u.last_name LIKE ? OR CONCAT(u.first_name,' ',u.last_name) LIKE ? OR u.email LIKE ? OR u.phone LIKE ? OR u.license_number LIKE ?)";
    array_push($args, ...array_fill(0, 6, like($q)));
}
if ($status) { $where[] = 'u.account_status = ?'; $args[] = $status; }
$W = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$per = 10; [$page, $off] = paging($total, $per);

$st = $pdo->prepare("SELECT u.user_id, u.first_name, u.last_name, u.email, u.phone, u.license_number, u.account_status, u.created_at,
        (SELECT COUNT(*) FROM rentals r WHERE r.customer_id = u.user_id) AS total_rentals
    FROM users u WHERE $W ORDER BY u.user_id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$customers = $st->fetchAll();

page_start('Customers', 'customers', ['customers']);
?>
<section class="content-area">
    <?= flash_render() ?>
    <div class="customer-toolbar">
        <form class="toolbar-left" method="get" data-auto>
            <div class="search-box"><i class="bi bi-search"></i>
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search customers..."></div>
            <select name="status" class="status-filter">
                <option value="">All Status</option>
                <?php foreach (['Active', 'Inactive'] as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option><?php endforeach; ?>
            </select>
        </form>
        <button class="add-customer-btn" type="button" data-add="customerModal"><i class="bi bi-plus-lg"></i> Add Customer</button>
    </div>

    <div class="customer-table-container">
        <table class="customer-table">
            <thead><tr><th>CUSTOMER ID</th><th>NAME</th><th>EMAIL</th><th>PHONE</th><th>LICENSE NO.</th><th>TOTAL RENTALS</th><th>MEMBER SINCE</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
            <tbody>
            <?php foreach ($customers as $c):
                $active = $c['account_status'] === 'Active'; ?>
                <tr>
                    <td class="customer-id">CUST-<?= str_pad($c['user_id'], 3, '0', STR_PAD_LEFT) ?></td>
                    <td><div class="customer-name"><div class="customer-avatar"><?= e(strtoupper(mb_substr($c['first_name'], 0, 1))) ?></div>
                        <strong><?= e($c['first_name'] . ' ' . $c['last_name']) ?></strong></div></td>
                    <td><?= e($c['email']) ?></td>
                    <td><?= e($c['phone'] ?: 'N/A') ?></td>
                    <td><?= e($c['license_number'] ?: 'N/A') ?></td>
                    <td class="rental-count"><?= (int)$c['total_rentals'] ?></td>
                    <td><?= date('M d, Y', strtotime($c['created_at'])) ?></td>
                    <td><span class="status-badge <?= $active ? 'active-status' : 'inactive-status' ?>"><span></span><?= $active ? 'ACTIVE' : 'INACTIVE' ?></span></td>
                    <td><button class="edit-btn" type="button" data-edit="customerModal"
                        data-json="<?= e(json_encode(['id' => $c['user_id'], 'first_name' => $c['first_name'], 'last_name' => $c['last_name'],
                            'email' => $c['email'], 'phone' => $c['phone'], 'license_number' => $c['license_number'], 'account_status' => $c['account_status']])) ?>">Edit</button></td>
                </tr>
            <?php endforeach; if (!$customers): ?>
                <tr><td colspan="9" class="empty-row"><i class="bi bi-people" style="font-size:28px"></i><p>No customers found.</p></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager($total, $page, $per) ?>
</section>

<div class="modal-overlay" id="customerModal">
  <div class="modal">
    <form method="post">
      <?= csrf_field() ?><input type="hidden" name="id">
      <div class="modal-head"><div><h3 data-title data-add="Add Customer" data-edit="Edit Customer">Add Customer</h3><p>Enter the customer information below.</p></div>
        <button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
      <div class="modal-body"><div class="form-grid">
        <div class="fg"><label>First Name *</label><input name="first_name" maxlength="80" required></div>
        <div class="fg"><label>Last Name *</label><input name="last_name" maxlength="80" required></div>
        <div class="fg full"><label>Email Address *</label><input type="email" name="email" maxlength="150" required></div>
        <div class="fg"><label>Phone Number</label><input name="phone" maxlength="30"></div>
        <div class="fg"><label>License Number</label><input name="license_number" maxlength="100"></div>
        <div class="fg"><label>Account Status</label><select name="account_status"><option>Active</option><option>Inactive</option></select></div>
        <div class="fg"><label>Password <span data-pw-note>*</span></label>
          <input type="password" name="customer_password" minlength="8" autocomplete="new-password" data-req-add required>
          <small>Minimum of 8 characters. Leave blank when editing to keep the current one.</small></div>
      </div></div>
      <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Customer</button></div>
    </form>
  </div>
</div>
<?php page_end(); ?>
