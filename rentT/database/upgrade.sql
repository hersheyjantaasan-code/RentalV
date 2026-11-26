-- Run this ONCE after importing vehicle_rental_db.sql (safe to run again).
USE `vehicle_rental_db`;

-- Transactions (COMMIT / ROLLBACK) need InnoDB
ALTER TABLE `rentals`  ENGINE=InnoDB;
ALTER TABLE `payments` ENGINE=InnoDB;

-- Audit log: keep before/after values and allow reverting a change
ALTER TABLE `audit_logs`
  ADD COLUMN IF NOT EXISTS `old_values`  LONGTEXT NULL AFTER `description`,
  ADD COLUMN IF NOT EXISTS `new_values`  LONGTEXT NULL AFTER `old_values`,
  ADD COLUMN IF NOT EXISTS `reverted_by` BIGINT UNSIGNED NULL AFTER `ip_address`;

-- Fields shown on the Vehicles page
ALTER TABLE `vehicles`
  ADD COLUMN IF NOT EXISTS `color`   VARCHAR(40) NULL AFTER `category`,
  ADD COLUMN IF NOT EXISTS `mileage` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `color`;

-- Speed: indexes for the queries the pages run most
CREATE INDEX IF NOT EXISTS `idx_audit_table_record`    ON `audit_logs` (`table_name`, `record_id`);
CREATE INDEX IF NOT EXISTS `idx_rentals_status_return` ON `rentals`    (`rental_status`, `return_datetime`);
CREATE INDEX IF NOT EXISTS `idx_payments_rental_stat`  ON `payments`   (`rental_id`, `payment_status`);
CREATE INDEX IF NOT EXISTS `idx_vehicles_created`      ON `vehicles`   (`created_at`);
CREATE INDEX IF NOT EXISTS `idx_users_created`         ON `users`      (`created_at`);

-- Vehicles that already have an open booking must show as Rented
UPDATE `vehicles` v SET v.status = 'Rented'
 WHERE v.status = 'Available'
   AND EXISTS (SELECT 1 FROM `rentals` r WHERE r.vehicle_id = v.vehicle_id AND r.rental_status IN ('Pending','Approved','Active','Overdue'));
