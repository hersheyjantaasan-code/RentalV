<?php
function asset(string $p): string {
    $f = dirname(__DIR__) . '/' . $p;
    return '../' . $p . '?v=' . (is_file($f) ? filemtime($f) : 1);
}

function page_start(string $title, string $active, array $css = []): void {
    $a = $_SESSION['admin'];
    $nav = [
        'dashboard' => ['dashboard.php',      'bi-house-door',          'Dashboard'],
        'customers' => ['customers.php',      'bi-people-fill',         'Customers'],
        'vehicles'  => ['vehicles.php',       'bi-car-front-fill',      'Vehicles'],
        'rent'      => ['rent_vehicle.php',   'bi-calendar-check-fill', 'Rent a Vehicle'],
        'records'   => ['rental_records.php', 'bi-shield-check',        'Rental Records'],
        'payments'  => ['payments.php',       'bi-credit-card',         'Payments'],
        'audit'     => ['audit_logs.php',     'bi-journal-text',        'Audit Logs'],
    ];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . e($title) . ' | Vehicle Rental System</title>'
       . '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">'
       . '<link rel="stylesheet" href="' . asset('css/dashboard.css') . '">';
    foreach ($css as $c) echo '<link rel="stylesheet" href="' . asset("css/$c.css") . '">';
    echo '<link rel="stylesheet" href="' . asset('css/app.css') . '"></head><body><div class="app-layout dashboard-container">'
       . '<aside class="sidebar"><a href="dashboard.php" class="brand"><i class="bi bi-car-front-fill logo-icon"></i>'
       . '<span class="brand-text"><strong>Vehicle</strong><small>Rental System</small></span></a>'
       . '<div class="menu-label">MAIN MENU</div><nav class="sidebar-nav" aria-label="Main navigation">';
    foreach ($nav as $key => [$href, $icon, $label]) {
        echo '<a href="' . $href . '" class="nav-link' . ($key === $active ? ' active" aria-current="page' : '') . '"><i class="bi ' . $icon . '"></i><span>' . $label . '</span></a>';
    }
    echo '</nav><div class="sidebar-bottom"><div class="sidebar-divider"></div>'
       . '<a href="logout.php?t=' . e(csrf_token()) . '" class="nav-link logout-link"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div></aside>'
       . '<main class="main-content"><header class="app-header"><div><h1>' . e($title) . '</h1>'
       . '<div class="crumbs"><a href="dashboard.php">Home</a><i class="bi bi-chevron-right"></i><span>' . e($title) . '</span></div></div>'
       . '<div class="app-user"><div class="avatar">' . e(strtoupper(mb_substr($a['name'], 0, 1))) . '</div>'
       . '<div class="admin-info"><strong>Welcome, ' . e($a['name']) . '</strong><small>System Administrator</small></div></div></header>';
}

function page_end(array $js = []): void {
    echo '</main></div><script src="' . asset('js/app.js') . '" defer></script>';
    foreach ($js as $j) echo '<script src="' . asset("js/$j.js") . '" defer></script>';
    echo '</body></html>';
}

function customer_start(string $title, string $active): void {
    $c = $_SESSION['customer'];
    $links = ['customer_home.php' => ['home', 'bi-car-front-fill', 'Browse Vehicles'], 'my_rentals.php' => ['rentals', 'bi-calendar-check-fill', 'My Rentals'], 'customer_payments.php' => ['payments', 'bi-credit-card', 'Payments']];
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>' . e($title) . ' | Vehicle Rental System</title>'
       . '<link rel="preconnect" href="https://cdn.jsdelivr.net" crossorigin>'
       . '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">'
       . '<link rel="stylesheet" href="' . asset('css/app.css') . '"></head><body class="cust-body"><header class="cust-nav"><a class="cust-brand" href="customer_home.php"><i class="bi bi-car-front-fill"></i> Vehicle Rental</a><nav>';
    foreach ($links as $href => [$k, $icon, $label]) echo '<a href="' . $href . '" class="' . ($k === $active ? 'on' : '') . '"><i class="bi ' . $icon . '"></i> ' . $label . '</a>';
    echo '</nav><div class="cust-user"><span>' . e($c['name']) . '</span><a href="logout.php?t=' . e(csrf_token()) . '"><i class="bi bi-box-arrow-right"></i> Logout</a></div></header><main class="cust-main">';
}
function customer_end(): void {
    echo '</main><script src="' . asset('js/app.js') . '" defer></script></body></html>';
}
