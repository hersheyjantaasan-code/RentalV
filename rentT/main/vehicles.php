<?php
require __DIR__ . '/../config/app.php';
require __DIR__ . '/../config/layout.php';
require_admin();

const V_TYPES  = ['Car', 'Van', 'SUV', 'Motorcycle', 'Truck', 'Other'];
const V_TRANS  = ['Automatic', 'Manual', 'Other'];
const V_FUELS  = ['Gasoline', 'Diesel', 'Hybrid', 'Electric', 'Other'];
const V_STATUS = ['Available', 'Rented', 'Maintenance', 'Inactive'];
const V_FIELDS = ['plate_number', 'brand', 'model', 'year', 'vehicle_type', 'color', 'mileage', 'transmission',
                  'seating_capacity', 'fuel_type', 'rate_per_day', 'description', 'status'];

function vcode($id): string { return 'VEH-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $op = $_POST['op'] ?? '';
    $id = (int)($_POST['id'] ?? 0);

    /* ======================= DELETE ======================= */
    if ($op === 'delete' && $id) {
        $oldImg = '';
        run_tx($pdo, function (PDO $pdo) use ($id, &$oldImg) {
            $st = $pdo->prepare('SELECT * FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
            $st->execute([$id]);
            $v = $st->fetch();
            if (!$v) throw new UserError('Vehicle not found.');
            $used = $pdo->prepare('SELECT COUNT(*) FROM rentals WHERE vehicle_id = ?');
            $used->execute([$id]);
            if ($used->fetchColumn() > 0) throw new UserError('This vehicle has rental history and cannot be deleted. Set its status to Inactive instead.');
            $pdo->prepare('DELETE FROM vehicles WHERE vehicle_id = ?')->execute([$id]);
            audit($pdo, 'VEHICLE_DELETE', 'vehicles', $id, 'Deleted vehicle ' . vcode($id) . " ({$v['brand']} {$v['model']}, {$v['plate_number']}).", array_intersect_key($v, array_flip(V_FIELDS)));
            $oldImg = $v['image'];
        }, 'Vehicle deleted.', function () use (&$oldImg) { delete_vehicle_files($oldImg); });
        redirect_self();
    }

    /* ======================= ADD / UPDATE ======================= */
    if ($op === 'save') {
        $in = [
            'plate_number' => strtoupper(trim($_POST['plate_number'] ?? '')),
            'brand' => trim($_POST['brand'] ?? ''), 'model' => trim($_POST['model'] ?? ''),
            'year' => (int)($_POST['year'] ?? 0), 'vehicle_type' => $_POST['vehicle_type'] ?? '',
            'color' => trim($_POST['color'] ?? '') ?: null, 'mileage' => max(0, (int)($_POST['mileage'] ?? 0)),
            'transmission' => $_POST['transmission'] ?? '',
            'seating_capacity' => ($_POST['seating_capacity'] ?? '') === '' ? null : (int)$_POST['seating_capacity'],
            'fuel_type' => $_POST['fuel_type'] ?? '', 'rate_per_day' => round((float)($_POST['rate_per_day'] ?? 0), 2),
            'description' => trim($_POST['description'] ?? '') ?: null, 'status' => $_POST['status'] ?? '',
        ];
        $newImg = $oldImg = '';

        run_tx($pdo, function (PDO $pdo) use ($id, $in, &$newImg, &$oldImg) {
            if ($in['brand'] === '' || $in['model'] === '') throw new UserError('Brand and model are required.');
            if (!preg_match('/^[A-Z0-9][A-Z0-9 -]{1,14}$/', $in['plate_number'])) throw new UserError('Enter a valid plate number (letters, numbers, dash).');
            if ($in['year'] < 1980 || $in['year'] > (int)date('Y') + 1) throw new UserError('Enter a valid model year.');
            if (!in_array($in['vehicle_type'], V_TYPES, true) || !in_array($in['transmission'], V_TRANS, true)
                || !in_array($in['fuel_type'], V_FUELS, true) || !in_array($in['status'], V_STATUS, true)) throw new UserError('Invalid selection.');
            if ($in['rate_per_day'] <= 0) throw new UserError('Daily rate must be greater than zero.');
            if ($in['seating_capacity'] !== null && ($in['seating_capacity'] < 1 || $in['seating_capacity'] > 60)) throw new UserError('Invalid seating capacity.');

            $newImg = save_vehicle_image($_FILES['image'] ?? ['error' => UPLOAD_ERR_NO_FILE]);   // rolled back (file deleted) on failure

            if ($id) {
                $st = $pdo->prepare('SELECT * FROM vehicles WHERE vehicle_id = ? FOR UPDATE');
                $st->execute([$id]);
                $old = $st->fetch();
                if (!$old) throw new UserError('Vehicle not found.');
                if ($in['status'] !== $old['status']) {
                    if ($in['status'] === 'Rented') throw new UserError('"Rented" is set automatically when a rental starts.');
                    if ($old['status'] === 'Rented') {
                        $act = $pdo->prepare("SELECT COUNT(*) FROM rentals WHERE vehicle_id = ? AND rental_status IN ('Pending','Approved','Active','Overdue')");
                        $act->execute([$id]);
                        if ($act->fetchColumn() > 0) throw new UserError('Finish or cancel this vehicle’s open rental before changing its status.');
                    }
                }
                $sets = implode(', ', array_map(fn($f) => "$f = :$f", V_FIELDS));
                $args = $in;
                if ($newImg) { $sets .= ', image = :image'; $args['image'] = $newImg; $oldImg = $old['image']; }
                $pdo->prepare("UPDATE vehicles SET $sets WHERE vehicle_id = :id")->execute($args + ['id' => $id]);
                [$o, $n] = diff_fields($old, $in, V_FIELDS);
                if ($newImg) { $o['image'] = $old['image']; $n['image'] = $newImg; }
                if ($o) audit($pdo, 'VEHICLE_UPDATE', 'vehicles', $id, 'Updated vehicle ' . vcode($id) . ' (' . $in['plate_number'] . '): ' . implode(', ', array_keys($o)), $o, $n);
            } else {
                if ($in['status'] === 'Rented') throw new UserError('A new vehicle cannot start as Rented.');
                $cols = array_merge(V_FIELDS, ['image']);
                $pdo->prepare('INSERT INTO vehicles (' . implode(',', $cols) . ') VALUES (:' . implode(',:', $cols) . ')')
                    ->execute($in + ['image' => $newImg ?: null]);
                $id = (int)$pdo->lastInsertId();
                audit($pdo, 'VEHICLE_CREATE', 'vehicles', $id, 'Added vehicle ' . vcode($id) . " ({$in['brand']} {$in['model']}, {$in['plate_number']}).", null, $in);
            }
            if ($newImg) {
                $pdo->prepare('DELETE FROM vehicle_images WHERE vehicle_id = ?')->execute([$id]);
                $pdo->prepare('INSERT INTO vehicle_images (vehicle_id, image_path, is_primary) VALUES (?,?,1)')->execute([$id, $newImg]);
            }
        }, $id ? 'Vehicle updated successfully!' : 'Vehicle added successfully!',
           function () use (&$oldImg) { delete_vehicle_files($oldImg); },        // after COMMIT
           function () use (&$newImg) { delete_vehicle_files($newImg); });       // after ROLLBACK
    }
    redirect_self();
}

/* ======================= LIST ======================= */
$q = trim($_GET['q'] ?? '');
$status = in_array($_GET['status'] ?? '', V_STATUS, true) ? $_GET['status'] : '';
$type = in_array($_GET['type'] ?? '', V_TYPES, true) ? $_GET['type'] : '';
$where = ['1=1']; $args = [];
if ($q !== '') { $where[] = '(brand LIKE ? OR model LIKE ? OR plate_number LIKE ? OR color LIKE ? OR CONCAT(brand," ",model) LIKE ?)'; array_push($args, ...array_fill(0, 5, like($q))); }
if ($status) { $where[] = 'status = ?'; $args[] = $status; }
if ($type)   { $where[] = 'vehicle_type = ?'; $args[] = $type; }
$W = implode(' AND ', $where);

$cnt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE $W"); $cnt->execute($args);
$total = (int)$cnt->fetchColumn();
$per = 10; [$page, $off] = paging($total, $per);
$st = $pdo->prepare("SELECT vehicle_id, plate_number, brand, model, year, vehicle_type, color, mileage, transmission, seating_capacity,
                            fuel_type, rate_per_day, description, image, status
                     FROM vehicles WHERE $W ORDER BY vehicle_id DESC LIMIT $per OFFSET $off");
$st->execute($args);
$vehicles = $st->fetchAll();

page_start('Vehicles', 'vehicles', ['vehicles']);
$sel = fn($cur, $opts) => implode('', array_map(fn($o) => '<option' . ($cur === $o ? ' selected' : '') . '>' . e($o) . '</option>', $opts));
?>
<section class="content-area">
    <?= flash_render() ?>
    <div class="vehicle-toolbar">
        <form class="toolbar-left" method="get" data-auto>
            <div class="search-box"><i class="bi bi-search"></i><input type="search" name="q" value="<?= e($q) ?>" placeholder="Search vehicles..."></div>
            <select name="status"><option value="">All Status</option><?= $sel($status, V_STATUS) ?></select>
            <select name="type"><option value="">All Categories</option><?= $sel($type, V_TYPES) ?></select>
        </form>
        <button class="add-vehicle-btn" type="button" data-add="vehicleModal"><i class="bi bi-plus-lg"></i> Add Vehicle</button>
    </div>

    <div class="table-container">
        <table class="vehicle-table">
            <thead><tr><th>PHOTO</th><th>VEHICLE ID</th><th>MAKE &amp; MODEL</th><th>YEAR</th><th>PLATE NO.</th><th>CATEGORY</th><th>COLOR</th><th>DAILY RATE</th><th>MILEAGE</th><th>STATUS</th><th>ACTIONS</th></tr></thead>
            <tbody>
            <?php foreach ($vehicles as $v):
                $json = $v + ['id' => $v['vehicle_id'], 'image_url' => vehicle_img($v['image'], false)];
                unset($json['image']); ?>
                <tr>
                    <td><?php if ($v['image']): ?><img class="thumb" src="<?= e(vehicle_img($v['image'])) ?>" alt="" loading="lazy" decoding="async" width="56" height="40">
                        <?php else: ?><span class="thumb ph"><i class="bi bi-image"></i></span><?php endif; ?></td>
                    <td class="vehicle-id"><?= vcode($v['vehicle_id']) ?></td>
                    <td class="vehicle-name"><?= e($v['brand'] . ' ' . $v['model']) ?></td>
                    <td><?= e($v['year']) ?></td>
                    <td class="plate-number"><?= e($v['plate_number']) ?></td>
                    <td><span class="category-badge <?= $v['vehicle_type'] === 'SUV' ? 'suv' : ($v['vehicle_type'] === 'Truck' ? 'truck' : 'sedan') ?>"><?= e($v['vehicle_type']) ?></span></td>
                    <td><?= e($v['color'] ?: '—') ?></td>
                    <td class="daily-rate"><?= peso($v['rate_per_day']) ?>/day</td>
                    <td class="mileage"><?= number_format($v['mileage']) ?> km</td>
                    <td><span class="status-badge <?= strtolower($v['status']) ?>">● <?= strtoupper($v['status']) ?></span></td>
                    <td class="actions">
                        <div class="row-actions">
                            <?php if ($v['status'] === 'Available'): ?><a class="mini green" href="rent_vehicle.php?vehicle=<?= (int)$v['vehicle_id'] ?>">Rent</a><?php endif; ?>
                            <button class="edit-btn" type="button" data-edit="vehicleModal" data-json="<?= e(json_encode($json)) ?>">Edit</button>
                            <form method="post" data-confirm="Delete <?= e(vcode($v['vehicle_id'])) ?>? This cannot be undone.">
                                <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$v['vehicle_id'] ?>">
                                <button class="delete-btn" type="submit">Delete</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; if (!$vehicles): ?>
                <tr><td colspan="11" class="empty-row">No vehicles found.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?= pager($total, $page, $per) ?>
</section>

<div class="modal-overlay" id="vehicleModal">
  <div class="modal">
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="id">
      <div class="modal-head"><div><h3 data-title data-add="Add Vehicle" data-edit="Edit Vehicle">Add Vehicle</h3><p>Fill in the vehicle details and upload a photo.</p></div>
        <button type="button" class="modal-x" data-close><i class="bi bi-x-lg"></i></button></div>
      <div class="modal-body"><div class="form-grid">
        <div class="fg full"><label>Vehicle Photo (JPG/PNG/WEBP, max 3 MB)</label>
          <div class="img-field">
            <img id="vehPrev" class="img-prev" data-img data-empty="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='80'%3E%3Crect fill='%23e2e8f0' width='120' height='80'/%3E%3C/svg%3E" src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='120' height='80'%3E%3Crect fill='%23e2e8f0' width='120' height='80'/%3E%3C/svg%3E" alt="Preview">
            <input type="file" name="image" accept="image/jpeg,image/png,image/webp" data-preview="vehPrev" style="height:auto;padding:8px">
          </div></div>
        <div class="fg"><label>Brand *</label><input name="brand" maxlength="80" required></div>
        <div class="fg"><label>Model *</label><input name="model" maxlength="100" required></div>
        <div class="fg"><label>Year *</label><input type="number" name="year" min="1980" max="<?= date('Y') + 1 ?>" value="<?= date('Y') ?>" required></div>
        <div class="fg"><label>Plate Number *</label><input name="plate_number" maxlength="15" style="text-transform:uppercase" required></div>
        <div class="fg"><label>Category *</label><select name="vehicle_type"><?= $sel('', V_TYPES) ?></select></div>
        <div class="fg"><label>Color</label><input name="color" maxlength="40"></div>
        <div class="fg"><label>Daily Rate (₱) *</label><input type="number" name="rate_per_day" min="1" step="0.01" required></div>
        <div class="fg"><label>Mileage (km)</label><input type="number" name="mileage" min="0" value="0"></div>
        <div class="fg"><label>Transmission</label><select name="transmission"><?= $sel('', V_TRANS) ?></select></div>
        <div class="fg"><label>Fuel Type</label><select name="fuel_type"><?= $sel('', V_FUELS) ?></select></div>
        <div class="fg"><label>Seats</label><input type="number" name="seating_capacity" min="1" max="60"></div>
        <div class="fg"><label>Status</label><select name="status"><?= $sel('Available', V_STATUS) ?></select></div>
        <div class="fg full"><label>Description</label><textarea name="description" maxlength="500"></textarea></div>
      </div></div>
      <div class="modal-foot"><button type="button" class="btn btn-ghost" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Save Vehicle</button></div>
    </form>
  </div>
</div>
<?php page_end(); ?>
