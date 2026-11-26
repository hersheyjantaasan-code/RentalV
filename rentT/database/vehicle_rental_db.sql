-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 24, 2026 at 04:55 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `vehicle_rental_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `addons`
--

CREATE TABLE `addons` (
  `addon_id` int(10) UNSIGNED NOT NULL,
  `addon_name` varchar(100) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  `rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `rate_type` enum('Per Rental','Per Day') NOT NULL DEFAULT 'Per Rental',
  `status` enum('Active','Inactive') NOT NULL DEFAULT 'Active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `addons`
--

INSERT INTO `addons` (`addon_id`, `addon_name`, `description`, `rate`, `rate_type`, `status`, `created_at`) VALUES
(1, 'Collision Insurance', 'Optional collision insurance coverage', 450.00, 'Per Day', 'Active', '2026-11-23 21:50:22'),
(2, 'GPS', 'GPS navigation device', 150.00, 'Per Day', 'Active', '2026-11-23 21:50:22');

-- --------------------------------------------------------

--
-- Table structure for table `admins`
--

CREATE TABLE `admins` (
  `admin_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `log_id` bigint(20) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `table_name` varchar(100) DEFAULT NULL,
  `record_id` bigint(20) UNSIGNED DEFAULT NULL,
  `description` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `customer_id` int(11) NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `contact_number` varchar(20) NOT NULL,
  `license_number` varchar(50) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `date_registered` date NOT NULL,
  `status` enum('Active','Inactive') DEFAULT 'Active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`customer_id`, `full_name`, `contact_number`, `license_number`, `address`, `date_registered`, `status`) VALUES
(1, 'ROSE MAESTRAL', '097-2314072', '6789076353535335', 'Brgy.Malitbog Ilaya Bingawan', '2026-08-31', 'Active');

-- --------------------------------------------------------

--
-- Table structure for table `payments`
--

CREATE TABLE `payments` (
  `payment_id` int(10) UNSIGNED NOT NULL,
  `payment_code` varchar(30) NOT NULL,
  `rental_id` int(10) UNSIGNED NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `payment_method` enum('Cash','Credit Card','Debit Card','Bank Transfer','GCash','Other') NOT NULL,
  `payment_status` enum('Pending','Paid','Partial','Failed','Refunded') NOT NULL DEFAULT 'Pending',
  `reference_number` varchar(100) DEFAULT NULL,
  `payment_date` datetime NOT NULL DEFAULT current_timestamp(),
  `notes` varchar(255) DEFAULT NULL,
  `recorded_by` int(10) UNSIGNED DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `rentals`
--

CREATE TABLE `rentals` (
  `rental_id` int(10) UNSIGNED NOT NULL,
  `rental_code` varchar(30) NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `booking_source` enum('Customer','Admin') NOT NULL DEFAULT 'Customer',
  `rental_status` enum('Pending','Approved','Rejected','Cancelled','Active','Completed','Overdue') NOT NULL DEFAULT 'Pending',
  `pickup_datetime` datetime NOT NULL,
  `return_datetime` datetime NOT NULL,
  `pickup_location` varchar(255) DEFAULT NULL,
  `dropoff_location` varchar(255) DEFAULT NULL,
  `rental_days` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `daily_rate` decimal(10,2) NOT NULL,
  `base_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `addon_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `total_amount` decimal(10,2) NOT NULL DEFAULT 0.00,
  `special_requests` text DEFAULT NULL,
  `terms_accepted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_by` int(10) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `rental_addons`
--

CREATE TABLE `rental_addons` (
  `rental_addon_id` int(10) UNSIGNED NOT NULL,
  `rental_id` int(10) UNSIGNED NOT NULL,
  `addon_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `rate_at_booking` decimal(10,2) NOT NULL,
  `line_total` decimal(10,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('Admin','Customer') NOT NULL DEFAULT 'Customer',
  `first_name` varchar(80) NOT NULL,
  `last_name` varchar(80) NOT NULL,
  `email` varchar(150) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `license_number` varchar(100) DEFAULT NULL,
  `birthday` date DEFAULT NULL,
  `gender` enum('Male','Female','Prefer not to say') DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `account_status` enum('Active','Inactive','Suspended') NOT NULL DEFAULT 'Active',
  `last_login` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `password_hash`, `role`, `first_name`, `last_name`, `email`, `phone`, `license_number`, `birthday`, `gender`, `city`, `address`, `account_status`, `last_login`, `created_at`, `updated_at`) VALUES
(1, 'roseannemaestral_106f0bc2', '$2y$10$qPI8IKaIuJ.jvuOaqauDrun6kndNUP2JUx0XJzSYv5Ou7SczJNoTK', 'Customer', 'ROSE', 'MAESTRAL', 'roseanne.maestral@wvsu.edu.ph', '097-2314072', '4255522662272727', NULL, NULL, NULL, NULL, 'Active', NULL, '2026-11-23 23:09:10', '2026-11-23 23:09:10'),
(2, 'hersheyjantaasan_bf349a9b', '$2y$10$hdcYPPsZ8SCRy5sVmnvpBevWmCpiJmqPEQtdtyrz5e36Cm7ViGquC', 'Customer', 'Hershey Jan', 'Taasan', 'hersheyjan.taasan@wvsu.edu.ph', '09702314072', '9876536374849', NULL, NULL, NULL, NULL, 'Active', NULL, '2026-11-24 02:46:04', '2026-11-24 02:46:04'),
(4, 'johnmaestral_a6f8ab54', '$2y$10$WHhVK6xq5IGi2K1KY8Y0Runlp9.YOUTeoMGz8LLG2.NeWmJigso2e', 'Customer', 'JOHN', 'MAESTRAL', 'john.maestral@wvsu.edu.ph', '1233', '4678990000000', NULL, NULL, NULL, NULL, 'Active', NULL, '2026-11-24 03:01:46', '2026-11-24 03:01:46'),
(5, 'hersheyjantaasan_18014610', '$2y$10$tKAuVAyvtfHZ2seWcVK/ke82tQrn..UNdVNkVNE26a3FxF4/EBaC.', 'Customer', 'REZA', 'SARAD', 'hersheyjantaasan@gmail.com', '0978690656', '4335678', NULL, NULL, NULL, NULL, 'Active', NULL, '2026-11-24 03:09:49', '2026-11-24 03:09:49');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `plate_number` varchar(30) NOT NULL,
  `brand` varchar(80) NOT NULL,
  `model` varchar(100) NOT NULL,
  `year` year(4) NOT NULL,
  `vehicle_type` enum('Car','Van','SUV','Motorcycle','Truck','Other') NOT NULL DEFAULT 'Car',
  `category` varchar(80) DEFAULT NULL,
  `transmission` enum('Automatic','Manual','Other') NOT NULL DEFAULT 'Automatic',
  `seating_capacity` tinyint(3) UNSIGNED DEFAULT NULL,
  `fuel_type` enum('Gasoline','Diesel','Hybrid','Electric','Other') DEFAULT 'Gasoline',
  `rate_per_day` decimal(10,2) NOT NULL,
  `description` text DEFAULT NULL,
  `image` varchar(255) DEFAULT NULL,
  `status` enum('Available','Rented','Maintenance','Inactive') NOT NULL DEFAULT 'Available',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_images`
--

CREATE TABLE `vehicle_images` (
  `vehicle_image_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT 0,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `addons`
--
ALTER TABLE `addons`
  ADD PRIMARY KEY (`addon_id`),
  ADD UNIQUE KEY `addon_name` (`addon_name`);

--
-- Indexes for table `admins`
--
ALTER TABLE `admins`
  ADD PRIMARY KEY (`admin_id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_audit_user` (`user_id`),
  ADD KEY `idx_audit_action` (`action`),
  ADD KEY `idx_audit_created` (`created_at`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`customer_id`),
  ADD UNIQUE KEY `license_number` (`license_number`);

--
-- Indexes for table `payments`
--
ALTER TABLE `payments`
  ADD PRIMARY KEY (`payment_id`),
  ADD UNIQUE KEY `payment_code` (`payment_code`),
  ADD KEY `fk_payments_recorded_by` (`recorded_by`),
  ADD KEY `idx_payments_rental` (`rental_id`),
  ADD KEY `idx_payments_status` (`payment_status`),
  ADD KEY `idx_payments_date` (`payment_date`);

--
-- Indexes for table `rentals`
--
ALTER TABLE `rentals`
  ADD PRIMARY KEY (`rental_id`),
  ADD UNIQUE KEY `rental_code` (`rental_code`),
  ADD KEY `fk_rentals_created_by` (`created_by`),
  ADD KEY `fk_rentals_approved_by` (`approved_by`),
  ADD KEY `idx_rentals_customer` (`customer_id`),
  ADD KEY `idx_rentals_vehicle_dates` (`vehicle_id`,`pickup_datetime`,`return_datetime`),
  ADD KEY `idx_rentals_status` (`rental_status`),
  ADD KEY `idx_rentals_created` (`created_at`);

--
-- Indexes for table `rental_addons`
--
ALTER TABLE `rental_addons`
  ADD PRIMARY KEY (`rental_addon_id`),
  ADD KEY `fk_rental_addons_addon` (`addon_id`),
  ADD KEY `idx_rental_addons_rental` (`rental_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `idx_users_role_status` (`role`,`account_status`),
  ADD KEY `idx_users_name` (`last_name`,`first_name`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`vehicle_id`),
  ADD UNIQUE KEY `plate_number` (`plate_number`),
  ADD KEY `idx_vehicles_status` (`status`),
  ADD KEY `idx_vehicles_type` (`vehicle_type`),
  ADD KEY `idx_vehicles_brand_model` (`brand`,`model`);

--
-- Indexes for table `vehicle_images`
--
ALTER TABLE `vehicle_images`
  ADD PRIMARY KEY (`vehicle_image_id`),
  ADD KEY `idx_vehicle_images_vehicle` (`vehicle_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `addons`
--
ALTER TABLE `addons`
  MODIFY `addon_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `admins`
--
ALTER TABLE `admins`
  MODIFY `admin_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `log_id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `customer_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `payments`
--
ALTER TABLE `payments`
  MODIFY `payment_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rentals`
--
ALTER TABLE `rentals`
  MODIFY `rental_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `rental_addons`
--
ALTER TABLE `rental_addons`
  MODIFY `rental_addon_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `vehicle_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `vehicle_images`
--
ALTER TABLE `vehicle_images`
  MODIFY `vehicle_image_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_logs_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `payments`
--
ALTER TABLE `payments`
  ADD CONSTRAINT `fk_payments_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_payments_rental` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`rental_id`) ON UPDATE CASCADE;

--
-- Constraints for table `rentals`
--
ALTER TABLE `rentals`
  ADD CONSTRAINT `fk_rentals_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rentals_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rentals_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rentals_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON UPDATE CASCADE;

--
-- Constraints for table `rental_addons`
--
ALTER TABLE `rental_addons`
  ADD CONSTRAINT `fk_rental_addons_addon` FOREIGN KEY (`addon_id`) REFERENCES `addons` (`addon_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_rental_addons_rental` FOREIGN KEY (`rental_id`) REFERENCES `rentals` (`rental_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vehicle_images`
--
ALTER TABLE `vehicle_images`
  ADD CONSTRAINT `fk_vehicle_images_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
