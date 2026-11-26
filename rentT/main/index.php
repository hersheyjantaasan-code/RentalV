<?php
require __DIR__ . '/../config/app.php';
if (!empty($_SESSION['admin'])) redirect('dashboard.php');
if (!empty($_SESSION['customer'])) redirect('customer_home.php');

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $u = trim((string)($_POST['username'] ?? ''));
    $p = (string)($_POST['password'] ?? '');

    if (($_SESSION['lock_until'] ?? 0) > time()) {
        $error = 'Too many attempts. Try again in ' . ceil(($_SESSION['lock_until'] - time()) / 60) . ' minute(s).';
    } else {
        $find = $pdo->prepare("SELECT user_id, username, password_hash, first_name, last_name, role
                               FROM users WHERE (username = ? OR email = ?) AND account_status = 'Active' LIMIT 1");
        $find->execute([$u, $u]);
        $row = $find->fetch();

        // First run only: no admin exists yet, so create admin / admin123 (change it afterwards!)
        if (!$row && $u === 'admin' && $p === 'admin123'
            && !$pdo->query("SELECT 1 FROM users WHERE role = 'Admin' LIMIT 1")->fetch()) {
            tx($pdo, function (PDO $pdo) {
                $pdo->prepare("INSERT INTO users (username, password_hash, role, first_name, last_name, email)
                               VALUES ('admin', ?, 'Admin', 'System', 'Administrator', 'admin@vehiclerental.local')")
                    ->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
                audit($pdo, 'ADMIN_CREATE', 'users', (int)$pdo->lastInsertId(), 'First-run administrator account created.');
            });
            $find->execute([$u, $u]);
            $row = $find->fetch();
        }

        if ($row && password_verify($p, $row['password_hash'])) {
            session_regenerate_id(true);
            $key = $row['role'] === 'Admin' ? 'admin' : 'customer';
            $_SESSION[$key] = ['id' => (int)$row['user_id'], 'username' => $row['username'],
                               'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
            unset($_SESSION['fails'], $_SESSION['lock_until']);
            tx($pdo, function (PDO $pdo) use ($row) {
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE user_id = ?')->execute([$row['user_id']]);
                audit($pdo, 'LOGIN', 'users', (int)$row['user_id'], $row['role'] . ' logged in.');
            });
            redirect($key === 'admin' ? 'dashboard.php' : 'customer_home.php');
        }

        $_SESSION['fails'] = ($_SESSION['fails'] ?? 0) + 1;
        if ($_SESSION['fails'] >= 5) { $_SESSION['lock_until'] = time() + 300; $_SESSION['fails'] = 0; }
        audit($pdo, 'LOGIN_FAILED', 'users', null, 'Failed login attempt for username "' . mb_substr($u, 0, 50) . '".');
        $error = 'Incorrect username or password. Please try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vehicle Rental System | Login</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="login-page">
<main class="login-layout">
    <section class="welcome-side">
        <div class="brand">
            <div class="car-line" aria-hidden="true">⌁</div>
            <h1>VEHICLE <span>RENTAL</span></h1>
            <h2>S Y S T E M</h2>
            <p class="tagline">Drive Your Journey, Rent with Us</p>
        </div>
        <div class="features">
            <div class="feature"><span>♢</span><p>Safe<br>Vehicles</p></div>
            <div class="feature"><span>◷</span><p>Flexible<br>Rental Plans</p></div>
            <div class="feature"><span>♧</span><p>24/7<br>Support</p></div>
        </div>
    </section>

    <section class="login-side">
        <div class="login-card">
            <div class="user-icon">♙</div>
            <h2>Welcome Back!</h2>
            <p class="subtitle">Log in to your account to access the<br class="desktop-break"> vehicle rental system.</p>

            <form method="post" autocomplete="off">
                <?= csrf_field() ?>
                <label class="input-wrap">
                    <span class="field-icon">♟</span>
                    <input type="text" name="username" id="username" placeholder="Username or email" autocomplete="username"
                           value="<?= e($_POST['username'] ?? '') ?>" required>
                </label>
                <label class="input-wrap">
                    <span class="field-icon">▣</span>
                    <input type="password" name="password" id="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="toggle-password" id="togglePassword" aria-label="Show password">◉</button>
                </label>
                <p class="error-message" role="alert"><?= e($error) ?></p>
                <button type="submit" class="login-button">Login <span>→</span></button>
            </form>

            <div class="or-divider"><span></span>or<span></span></div>
            <p class="contact-text">New customer? <a href="register.php">Register here</a></p>
            <p class="demo-hint">Customers: log in with your email. Admin first run: <b>admin</b> / <b>admin123</b>.</p>
        </div>
    </section>
</main>
<script src="../js/login.js" defer></script>
</body>
</html>
