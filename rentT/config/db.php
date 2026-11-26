<?php
// Database connection (XAMPP defaults). Persistent connection = faster page loads.
const DB_HOST = '127.0.0.1';
const DB_NAME = 'vehicle_rental_db';
const DB_USER = 'root';
const DB_PASS = '';
const APP_DEBUG = false;   // true = show real error messages while developing

date_default_timezone_set('Asia/Manila');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => true,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'",
        ]
    );
} catch (PDOException $ex) {
    error_log($ex->getMessage());
    http_response_code(500);
    exit('Database connection failed. Start MySQL in XAMPP, import database/vehicle_rental_db.sql, then database/upgrade.sql.');
}
