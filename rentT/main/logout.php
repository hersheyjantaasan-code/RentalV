<?php
require __DIR__ . '/../config/app.php';
$u = $_SESSION['admin'] ?? $_SESSION['customer'] ?? null;
if ($u && hash_equals($_SESSION['csrf'] ?? '', (string)($_GET['t'] ?? ''))) {
    tx($pdo, fn(PDO $pdo) => audit($pdo, 'LOGOUT', 'users', $u['id'], (isset($_SESSION['admin']) ? 'Administrator' : 'Customer') . ' logged out.'));
    $_SESSION = [];
    session_destroy();
}
redirect('index.php');
