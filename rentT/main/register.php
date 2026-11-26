<?php
require __DIR__ . '/../config/app.php';

$errors = [];
$old = ['first_name' => '', 'last_name' => '', 'email' => '', 'phone' => '', 'license_number' => ''];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    foreach ($old as $k => $_) $old[$k] = trim((string)($_POST[$k] ?? ''));
    $pw = (string)($_POST['password'] ?? '');

    if ($old['first_name'] === '' || $old['last_name'] === '') $errors[] = 'First and last name are required.';
    if (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email address.';
    if ($old['license_number'] === '') $errors[] = 'Driver’s license number is required.';
    if (strlen($pw) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($pw !== (string)($_POST['password2'] ?? '')) $errors[] = 'Passwords do not match.';
    if (($_SESSION['reg_count'] ?? 0) >= 5) $errors[] = 'Too many registrations from this session. Please try again later.';

    if (!$errors) {
        try {
            tx($pdo, function (PDO $pdo) use ($old, $pw) {
                $base = strtolower(preg_replace('/[^a-z0-9]/i', '', strstr($old['email'], '@', true))) ?: 'customer';
                $pdo->prepare("INSERT INTO users (username, password_hash, role, first_name, last_name, email, phone, license_number, account_status)
                               VALUES (?,?,'Customer',?,?,?,?,?,'Active')")
                    ->execute([$base . '_' . bin2hex(random_bytes(4)), password_hash($pw, PASSWORD_DEFAULT),
                               $old['first_name'], $old['last_name'], $old['email'], $old['phone'] ?: null, $old['license_number']]);
                audit($pdo, 'CUSTOMER_REGISTER', 'users', (int)$pdo->lastInsertId(), "Customer self-registered: {$old['first_name']} {$old['last_name']} ({$old['email']}).");
            });
            $_SESSION['reg_count'] = ($_SESSION['reg_count'] ?? 0) + 1;
            $done = true;
        } catch (PDOException $ex) {
            error_log($ex->getMessage());
            $errors[] = ($ex->errorInfo[1] ?? 0) === 1062 ? 'This email address is already registered.'
                : (APP_DEBUG ? $ex->getMessage() : 'Registration failed. Please try again.');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customer Registration | Vehicle Rental System</title>
    <link rel="stylesheet" href="../css/style.css">
    <style>
        .reg-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:30px 16px;background:linear-gradient(135deg,#f5f8fd,#d9eaff)}
        .reg-card{width:100%;max-width:560px;background:#fff;border-radius:18px;padding:36px;box-shadow:0 15px 45px rgba(20,67,120,.12)}
        .reg-card h2{font-size:28px;margin-bottom:6px}.reg-card .sub{color:#60718c;margin-bottom:22px}
        .reg-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.reg-grid .full{grid-column:1/-1}
        .reg-grid label{display:block;font-size:13px;font-weight:600;margin-bottom:6px}
        .reg-grid input{width:100%;height:46px;padding:0 14px;border:1.5px solid #cbd8e9;border-radius:10px;font-size:15px;outline:0}
        .reg-grid input:focus{border-color:#0860b5;box-shadow:0 0 0 3px rgba(8,96,181,.1)}
        .reg-err{background:#fee2e2;color:#b91c1c;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:14px}
        .reg-ok{background:#d1fae5;color:#047857;padding:16px;border-radius:8px;font-size:15px;line-height:1.5}
        .reg-card .login-button{height:52px;font-size:18px;margin-top:18px}
        .reg-card .back{display:block;text-align:center;margin-top:18px;color:#0860d0;font-size:14px}
        @media(max-width:520px){.reg-grid{grid-template-columns:1fr}}
    </style>
</head>
<body>
<div class="reg-wrap">
  <div class="reg-card">
    <h2>Customer Registration</h2>
    <p class="sub">Create your account to rent a vehicle with us.</p>

    <?php if ($done): ?>
        <div class="reg-ok">✔ Registration successful! Your account has been saved. Our staff can now create rentals for you.</div>
        <a class="back" href="register.php">Register another customer</a>
        <a class="back" href="index.php">← Back to login</a>
    <?php else: ?>
        <?php if ($errors): ?><div class="reg-err" role="alert"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <form method="post" autocomplete="off">
            <?= csrf_field() ?>
            <div class="reg-grid">
                <div><label>First Name *</label><input name="first_name" maxlength="80" value="<?= e($old['first_name']) ?>" required></div>
                <div><label>Last Name *</label><input name="last_name" maxlength="80" value="<?= e($old['last_name']) ?>" required></div>
                <div class="full"><label>Email Address *</label><input type="email" name="email" maxlength="150" value="<?= e($old['email']) ?>" required></div>
                <div><label>Phone Number</label><input name="phone" maxlength="30" value="<?= e($old['phone']) ?>"></div>
                <div><label>Driver’s License No. *</label><input name="license_number" maxlength="100" value="<?= e($old['license_number']) ?>" required></div>
                <div><label>Password *</label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div>
                <div><label>Confirm Password *</label><input type="password" name="password2" minlength="8" autocomplete="new-password" required></div>
            </div>
            <button type="submit" class="login-button">Register</button>
        </form>
        <a class="back" href="index.php">← Back to login</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
