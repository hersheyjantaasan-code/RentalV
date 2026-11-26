<?php
// Shared helpers: session, CSRF, flash, transactions (commit/rollback), audit log, uploads, paging.
if (extension_loaded('zlib') && !ini_get('zlib.output_compression')) { ob_start('ob_gzhandler'); }

require_once __DIR__ . '/db.php';

ini_set('session.use_strict_mode', '1');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax']);
session_start();

class UserError extends RuntimeException {}

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function peso($n): string { return '₱' . number_format((float)$n, 2); }
function redirect(string $url): void { header('Location: ' . $url); exit; }
function redirect_self(): void { redirect($_SERVER['REQUEST_URI'] ?? 'dashboard.php'); }

/* ---------- security ---------- */
function csrf_token(): string {
    if (empty($_SESSION['csrf'])) { $_SESSION['csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['csrf'];
}
function csrf_field(): string { return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">'; }
function csrf_verify(): void {
    $t = $_POST['csrf_token'] ?? '';
    if (!is_string($t) || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(419);
        exit('Invalid request. Please go back and refresh the page.');
    }
}
function require_admin(): array {
    if (empty($_SESSION['admin'])) { redirect('index.php'); }
    return $_SESSION['admin'];
}

function require_customer(): array {
    if (empty($_SESSION['customer'])) { redirect('index.php'); }
    return $_SESSION['customer'];
}

/* ---------- flash messages ---------- */
function flash(string $type, string $text): void { $_SESSION['flash'] = ['type' => $type, 'text' => $text]; }
function flash_render(): string {
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    if (!$f) return '';
    $icon = $f['type'] === 'success' ? 'bi-check-circle-fill' : 'bi-exclamation-circle-fill';
    return '<div class="alert ' . e($f['type']) . '" role="alert"><i class="bi ' . $icon . '"></i> ' . e($f['text']) . '</div>';
}

/* ---------- AUDIT LOG ---------- */
function audit(PDO $pdo, string $action, ?string $table = null, ?int $id = null, string $desc = '', ?array $old = null, ?array $new = null): int {
    $uid = $_SESSION['admin']['id'] ?? $_SESSION['customer']['id'] ?? null;
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $desc = mb_substr($desc, 0, 1000);
    try {
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, table_name, record_id, description, old_values, new_values, ip_address)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$uid, $action, $table, $id, $desc,
                       $old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,
                       $new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null, $ip]);
    } catch (PDOException $ex) {
        // upgrade.sql not imported yet (old_values/new_values missing): log without them instead of failing the save
        if (($ex->errorInfo[1] ?? 0) !== 1054) throw $ex;
        $pdo->prepare('INSERT INTO audit_logs (user_id, action, table_name, record_id, description, ip_address) VALUES (?,?,?,?,?,?)')
            ->execute([$uid, $action, $table, $id, $desc, $ip]);
    }
    return (int)$pdo->lastInsertId();
}

/* ---------- TRANSACTIONS: commit on success, rollback on any error ---------- */
function tx(PDO $pdo, callable $fn) {
    $pdo->beginTransaction();
    try {
        $r = $fn($pdo);
        $pdo->commit();                                    // COMMIT
        return $r;
    } catch (Throwable $t) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }   // ROLLBACK
        throw $t;
    }
}

/** Runs $fn in a transaction and flashes the result. $onOk runs after COMMIT, $onFail after ROLLBACK. */
function run_tx(PDO $pdo, callable $fn, string $okMsg, ?callable $onOk = null, ?callable $onFail = null): bool {
    try {
        tx($pdo, $fn);
        if ($onOk) $onOk();
        flash('success', $okMsg);
        return true;
    } catch (UserError $u) {
        $msg = $u->getMessage();
    } catch (PDOException $p) {
        error_log($p->getMessage());
        $code = $p->errorInfo[1] ?? 0;
        if ($code === 1062 && preg_match("/for key '(?:[^.']+\\.)?([^']+)'/", $p->getMessage(), $m)) {
            $msg = ucfirst(str_replace('_', ' ', $m[1])) . ' already exists.';
        } elseif ($code === 1451) {
            $msg = 'This record is linked to other records and cannot be removed.';
        } else {
            $msg = APP_DEBUG ? $p->getMessage() : 'Database error. All changes were rolled back.';
        }
    } catch (Throwable $t) {
        error_log($t->getMessage());
        $msg = APP_DEBUG ? $t->getMessage() : 'Something went wrong. All changes were rolled back.';
    }
    if ($onFail) $onFail();
    flash('error', $msg);
    return false;
}

function same($a, $b): bool {
    return (is_numeric($a) && is_numeric($b)) ? abs((float)$a - (float)$b) < 0.0001 : (string)$a === (string)$b;
}
/** Returns [oldChanged, newChanged] for the listed fields. */
function diff_fields(array $old, array $new, array $fields): array {
    $o = $n = [];
    foreach ($fields as $f) {
        if (!same($old[$f] ?? null, $new[$f] ?? null)) { $o[$f] = $old[$f] ?? null; $n[$f] = $new[$f] ?? null; }
    }
    return [$o, $n];
}

/* ---------- paging / query-string ---------- */
function qs(array $over = []): string {
    $q = array_merge($_GET, $over);
    foreach ($q as $k => $v) { if ($v === '' || $v === null || $v === 'all' || ($k === 'page' && (int)$v <= 1)) unset($q[$k]); }
    return '?' . http_build_query($q);
}
function paging(int $total, int $per): array {
    $pages = max(1, (int)ceil($total / $per));
    $page  = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    return [$page, ($page - 1) * $per];
}
function pager(int $total, int $page, int $per): string {
    $pages = max(1, (int)ceil($total / $per));
    $from = $total ? ($page - 1) * $per + 1 : 0;
    $to = min($total, $page * $per);
    $h = '<div class="pager"><span>Showing ' . $from . '–' . $to . ' of ' . number_format($total) . '</span><nav>';
    $h .= $page > 1 ? '<a href="' . e(qs(['page' => $page - 1])) . '">&lsaquo;</a>' : '<span class="dis">&lsaquo;</span>';
    $end = min($pages, max(1, $page - 2) + 4);
    $start = max(1, $end - 4);
    for ($i = $start; $i <= $end; $i++) {
        $h .= $i === $page ? '<span class="cur">' . $i . '</span>' : '<a href="' . e(qs(['page' => $i])) . '">' . $i . '</a>';
    }
    $h .= $page < $pages ? '<a href="' . e(qs(['page' => $page + 1])) . '">&rsaquo;</a>' : '<span class="dis">&rsaquo;</span>';
    return $h . '</nav></div>';
}
function like(string $s): string { return '%' . addcslashes($s, '%_\\') . '%'; }

/* ---------- vehicle availability: ONE open rental per vehicle ---------- */
function hold_vehicle(PDO $pdo, int $vehicleId): void {
    $pdo->prepare("UPDATE vehicles SET status = 'Rented' WHERE vehicle_id = ?")->execute([$vehicleId]);
}
/** Makes the vehicle Available again, but only if no other open rental still holds it. */
function release_vehicle(PDO $pdo, int $vehicleId, int $exceptRentalId = 0): void {
    $c = $pdo->prepare("SELECT COUNT(*) FROM rentals WHERE vehicle_id = ? AND rental_id <> ? AND rental_status IN ('Pending','Approved','Active','Overdue')");
    $c->execute([$vehicleId, $exceptRentalId]);
    if ((int)$c->fetchColumn() === 0) {
        $pdo->prepare("UPDATE vehicles SET status = 'Available' WHERE vehicle_id = ? AND status = 'Rented'")->execute([$vehicleId]);
    }
}

/* ---------- rentals: auto-mark overdue (max once per minute) ---------- */
function refresh_rentals(PDO $pdo): void {
    if (($_SESSION['ov_chk'] ?? 0) > time() - 60) return;
    $_SESSION['ov_chk'] = time();
    $n = $pdo->exec("UPDATE rentals SET rental_status='Overdue' WHERE rental_status='Active' AND return_datetime < NOW()");
    if ($n) audit($pdo, 'RENTAL_OVERDUE', 'rentals', null, "System marked $n rental(s) as overdue.");
}
function rental_badge(string $s): string {
    return '<span class="status st-' . strtolower($s) . '">' . e($s) . '</span>';
}

/* ---------- vehicle image upload (validated + small thumbnail for fast pages) ---------- */
function vehicle_dir(): string { return dirname(__DIR__) . '/uploads/vehicles/'; }
function vehicle_img(?string $name, bool $thumb = true): string {
    if (!$name) return '';
    return '../uploads/vehicles/' . (($thumb && is_file(vehicle_dir() . 't_' . $name)) ? 't_' : '') . $name;
}
function save_vehicle_image(array $f): string {
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return '';
    if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 3 * 1024 * 1024) throw new UserError('Image upload failed or is larger than 3 MB.');
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) throw new UserError('Only JPG, PNG or WEBP images are allowed.');
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], vehicle_dir() . $name)) throw new UserError('Could not save the uploaded image.');
    make_thumb(vehicle_dir() . $name, vehicle_dir() . 't_' . $name, $info);
    return $name;
}
function make_thumb(string $src, string $dst, array $info, int $w = 360): void {
    if (!function_exists('imagecreatetruecolor') || $info[0] < 1) return;
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        default        => @imagecreatefromwebp($src),
    };
    if (!$im) return;
    $w2 = min($w, $info[0]);
    $h2 = max(1, (int)round($info[1] * $w2 / $info[0]));
    $t = imagecreatetruecolor($w2, $h2);
    imagealphablending($t, false); imagesavealpha($t, true);
    imagecopyresampled($t, $im, 0, 0, 0, 0, $w2, $h2, $info[0], $info[1]);
    match ($info[2]) {
        IMAGETYPE_JPEG => imagejpeg($t, $dst, 80),
        IMAGETYPE_PNG  => imagepng($t, $dst, 6),
        default        => imagewebp($t, $dst, 80),
    };
}
function delete_vehicle_files(?string $name): void {
    if (!$name || !preg_match('/^[a-f0-9]+\.(jpg|png|webp)$/', $name)) return;
    @unlink(vehicle_dir() . $name);
    @unlink(vehicle_dir() . 't_' . $name);
}
