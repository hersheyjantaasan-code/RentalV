VEHICLE RENTAL SYSTEM  (PHP + MySQL, runs on XAMPP)

SETUP
1. Copy this folder to  C:\xampp\htdocs\vehicle_rental_system
2. Start Apache + MySQL in XAMPP.
3. phpMyAdmin: create database `vehicle_rental_db`, import  database/vehicle_rental_db.sql
4. Then import  database/upgrade.sql  (adds audit old/new values, vehicle color/mileage, speed indexes).
5. Customers can self-register at  main/register.php  (they then appear in Admin > Customers).
   Admin login:  http://localhost/vehicle_rental_system/main/index.php
   First login:  admin / admin123  (the admin account is created automatically on first login — change it).
6. Optional: put your login background image at  main/assets/vehicle-scene.jpg

WHAT'S INSIDE
- Vehicles: add / edit / delete with photo upload (validated, thumbnail made automatically)
- Rent a Vehicle: server-side price calculation, double-booking protection, optional payment at booking
- Rental Records: approve -> start -> complete / cancel, CSV export
- Payments: balance per rental, partial / full payments
- Customer portal: customers log in with their EMAIL, browse available vehicles, request bookings (Pending) and cancel them
- Customer payments: Pay Now after booking (GCash, card, bank), balances and payment history
- Availability: a booked vehicle becomes Rented at once; it returns to Available on Return, Cancel or Reject
- Audit Logs: every action, with before/after values and a REVERT button for vehicle & customer edits

COMMIT / ROLLBACK
Every save runs inside a database transaction (config/app.php -> tx() / run_tx()):
success = COMMIT, any error = ROLLBACK (data, audit entry and any uploaded image file are all undone).
Audit Logs -> "Revert" restores the old values of an earlier edit in a new transaction.

SPEED
- Server-side search + paging (10 rows per page), debounced live filters
- Dashboard numbers come from one SQL query; indexes added in upgrade.sql
- Thumbnails for vehicle photos, lazy-loaded; persistent DB connection
- No Bootstrap CSS download; gzip + browser caching via .htaccess
