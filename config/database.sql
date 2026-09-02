-- phpMyAdmin SQL Dump
-- version 4.7.4
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1:3307
-- Generation Time: Aug 07, 2026 at 09:01 PM
-- Server version: 10.1.26-MariaDB
-- PHP Version: 7.1.9

CREATE DATABASE IF NOT EXISTS `farmer_marketplace_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `farmer_marketplace_db`;

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET AUTOCOMMIT = 0;
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `farmer_marketplace_db`
--

-- ================================================================
-- FRESH INSTALL RESET
-- This file rebuilds the project schema. To keep an existing database,
-- use config/migrate_existing_database.sql instead.
-- ================================================================
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `purchase_details`;
DROP TABLE IF EXISTS `vendor_orders`;
DROP TABLE IF EXISTS `purchase_process`;
DROP TABLE IF EXISTS `reviews`;
DROP TABLE IF EXISTS `product_photo`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `events`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `market_photo`;
DROP TABLE IF EXISTS `vendor_markets`;
DROP TABLE IF EXISTS `permission`;
DROP TABLE IF EXISTS `payment_accounts`;
DROP TABLE IF EXISTS `vendors`;
DROP TABLE IF EXISTS `markets`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `cities`;
SET FOREIGN_KEY_CHECKS = 1;

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `market_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`category_id`, `category_name`, `market_id`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Fruits', 1, 'good', '2026-08-06 00:20:17', '2026-08-06 00:20:17'),
(2, 'Fruits & Vegetables', 1, 'good', '2026-08-06 15:58:06', '2026-08-06 15:58:06'),
(3, 'Rice', 2, 'good', '2026-08-06 15:58:57', '2026-08-06 15:58:57'),
(4, 'Vegetables', 2, 'good', '2026-08-06 17:02:10', '2026-08-06 17:02:10');

-- --------------------------------------------------------

--
-- Table structure for table `cities`
--

CREATE TABLE `cities` (
  `city_id` int(11) NOT NULL,
  `city_name` varchar(100) NOT NULL,
  `administrative_division` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `cities`
--

INSERT INTO `cities` (`city_id`, `city_name`, `administrative_division`) VALUES
(2, 'Mandalay', 'Mandalay Division'),
(1, 'Myingyan', 'Mandalay Division');

-- --------------------------------------------------------

--
-- Table structure for table `events`
--

CREATE TABLE `events` (
  `event_id` int(11) NOT NULL,
  `market_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `event_name` varchar(100) NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `events`
--

INSERT INTO `events` (`event_id`, `market_id`, `user_id`, `event_name`, `start_date`, `end_date`, `description`, `created_at`, `updated_at`) VALUES
(1, 1, 1, 'Myingyan Festival', '2026-08-06', '2026-08-31', 'good', '2026-08-06 00:21:02', '2026-08-06 00:21:02');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `attempt_id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `markets`
--

CREATE TABLE `markets` (
  `market_id` int(11) NOT NULL,
  `market_name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city_id` int(11) NOT NULL,
  `opening_hour` time NOT NULL,
  `closing_hour` time NOT NULL,
  `description` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Markets table for farmers and vendors';

--
-- Dumping data for table `markets`
--

INSERT INTO `markets` (`market_id`, `market_name`, `address`, `city_id`, `opening_hour`, `closing_hour`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Myingyan Market', 'Myingyan', 1, '06:00:00', '18:00:00', 'good', '2026-08-06 00:19:37', '2026-08-06 00:19:37'),
(2, 'Yandanarbon Market', '34st bet 69*70', 2, '06:00:00', '18:00:00', 'Can get various products at one place', '2026-08-06 14:56:48', '2026-08-06 14:56:48');

-- --------------------------------------------------------

--
-- Table structure for table `market_photo`
--

CREATE TABLE `market_photo` (
  `photo_id` int(11) NOT NULL,
  `photo_name` varchar(100) NOT NULL,
  `photo_path` varchar(250) NOT NULL,
  `market_id` int(11) NOT NULL,
  `description` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `market_photo`
--

INSERT INTO `market_photo` (`photo_id`, `photo_name`, `photo_path`, `market_id`, `description`, `created_at`, `updated_at`) VALUES
(1, 'Myingyan Market', 'uploads/markets/1456bf739b589ffbd1a77586ada35ca4.jpg', 1, 'good', '2026-08-06 06:50:00', '2026-08-06 06:50:00');

-- --------------------------------------------------------

--
-- Table structure for table `payment_accounts`
--

CREATE TABLE `payment_accounts` (
  `payment_account_id` int(11) NOT NULL,
  `payment_method` enum('kbzpay','wave_money') COLLATE utf8mb4_unicode_ci NOT NULL,
  `account_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `account_phone` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qr_image_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `payment_accounts`
--

INSERT INTO `payment_accounts` (`payment_account_id`, `payment_method`, `account_name`, `account_phone`, `qr_image_path`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'kbzpay', 'Farmers Market KBZPay', NULL, 'uploads/payment_qr/kbzpay.png', 1, '2026-08-07 14:52:01', '2026-08-07 15:49:25'),
(2, 'wave_money', 'Farmers Market Wave Money', NULL, 'uploads/payment_qr/wave_money.png', 1, '2026-08-07 14:52:01', '2026-08-07 15:49:25');

-- --------------------------------------------------------

--
-- Table structure for table `permission`
--

CREATE TABLE `permission` (
  `permission_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `upload_limit` int(11) NOT NULL DEFAULT '10',
  `can_delete` tinyint(1) NOT NULL DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `permission`
--

INSERT INTO `permission` (`permission_id`, `vendor_id`, `upload_limit`, `can_delete`) VALUES
(1, 1, 10, 1),
(2, 2, 10, 1);

-- --------------------------------------------------------

--
-- Table structure for table `products`
--

CREATE TABLE `products` (
  `product_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `product_name` varchar(100) NOT NULL,
  `category_id` int(11) NOT NULL,
  `price` decimal(10,2) NOT NULL,
  `unit` varchar(30) NOT NULL DEFAULT 'piece',
  `description` text NOT NULL,
  `stock_quantity` int(11) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `products`
--

INSERT INTO `products` (`product_id`, `vendor_id`, `product_name`, `category_id`, `price`, `unit`, `description`, `stock_quantity`, `created_at`, `updated_at`) VALUES
(1, 1, 'Apple', 1, '2000.00', 'kg', 'good', 47, '2026-08-06 07:24:40', '2026-08-07 14:08:34'),
(2, 1, 'Grape', 1, '20000.00', 'kg', 'good', 38, '2026-08-06 14:31:36', '2026-08-07 15:47:07'),
(3, 2, 'Mango', 1, '1000.00', 'piece', 'good', 278, '2026-08-06 16:45:48', '2026-08-07 15:50:17'),
(4, 1, 'Cabbage', 4, '3000.00', 'piece', 'good', 2995, '2026-08-06 17:04:46', '2026-08-07 13:46:21');

-- --------------------------------------------------------

--
-- Table structure for table `product_photo`
--

CREATE TABLE `product_photo` (
  `product_photo_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `photo_path` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `product_photo`
--

INSERT INTO `product_photo` (`product_photo_id`, `product_id`, `photo_path`) VALUES
(1, 1, 'uploads/products/product_20260806_092440_d75a2cad54c18391.jpg'),
(2, 2, 'uploads/products/product_20260806_163136_f9972a949c72916b.jpg'),
(3, 3, 'uploads/products/product_20260806_184610_9818159bfe76e9d6.jpg'),
(4, 4, 'uploads/products/product_20260806_190446_9ec43f4e0aa27052.jpg');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_details`
--

CREATE TABLE `purchase_details` (
  `purchase_detail_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `vendor_order_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `quantity` int(11) NOT NULL DEFAULT '1',
  `unit_price` decimal(10,2) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `purchase_details`
--

INSERT INTO `purchase_details` (`purchase_detail_id`, `purchase_id`, `vendor_order_id`, `product_id`, `quantity`, `unit_price`, `subtotal`, `created_at`) VALUES
(3, 3, NULL, 4, 1, '3000.00', '3000.00', '2026-08-07 05:03:14'),
(4, 3, NULL, 3, 1, '1000.00', '1000.00', '2026-08-07 05:03:14'),
(5, 4, NULL, 3, 1, '1000.00', '1000.00', '2026-08-07 05:05:07'),
(6, 5, NULL, 2, 1, '20000.00', '20000.00', '2026-08-07 05:12:17'),
(7, 5, NULL, 1, 1, '2000.00', '2000.00', '2026-08-07 05:12:17'),
(8, 5, NULL, 3, 1, '1000.00', '1000.00', '2026-08-07 05:12:17'),
(9, 6, NULL, 3, 1, '1000.00', '1000.00', '2026-08-07 06:43:26'),
(10, 6, NULL, 2, 1, '20000.00', '20000.00', '2026-08-07 06:43:26'),
(11, 6, NULL, 1, 1, '2000.00', '2000.00', '2026-08-07 06:43:26'),
(12, 7, NULL, 4, 1, '3000.00', '3000.00', '2026-08-07 06:44:48'),
(13, 8, NULL, 2, 1, '20000.00', '20000.00', '2026-08-07 06:45:50'),
(14, 9, 1, 3, 1, '1000.00', '1000.00', '2026-08-07 07:06:41'),
(15, 9, 2, 4, 1, '3000.00', '3000.00', '2026-08-07 07:06:41'),
(16, 10, 3, 1, 1, '2000.00', '2000.00', '2026-08-07 07:19:21'),
(17, 11, 4, 2, 1, '20000.00', '20000.00', '2026-08-07 07:24:24'),
(18, 12, 5, 3, 1, '1000.00', '1000.00', '2026-08-07 07:34:38'),
(19, 13, 6, 3, 1, '1000.00', '1000.00', '2026-08-07 07:36:28'),
(20, 14, 7, 3, 1, '1000.00', '1000.00', '2026-08-07 08:11:47'),
(21, 15, 8, 4, 1, '3000.00', '3000.00', '2026-08-07 12:46:20'),
(22, 16, 9, 2, 4, '20000.00', '80000.00', '2026-08-07 12:51:20'),
(23, 16, 10, 3, 1, '1000.00', '1000.00', '2026-08-07 12:51:20'),
(24, 17, 11, 2, 1, '20000.00', '20000.00', '2026-08-07 12:57:28'),
(25, 18, 12, 2, 2, '20000.00', '40000.00', '2026-08-07 13:45:08'),
(26, 18, 13, 3, 2, '1000.00', '2000.00', '2026-08-07 13:45:08'),
(27, 19, 14, 4, 1, '3000.00', '3000.00', '2026-08-07 13:46:21'),
(28, 20, 15, 1, 1, '2000.00', '2000.00', '2026-08-07 14:08:34'),
(29, 21, 16, 3, 1, '1000.00', '1000.00', '2026-08-07 14:11:48'),
(30, 22, 17, 3, 1, '1000.00', '1000.00', '2026-08-07 15:19:01'),
(31, 23, 18, 3, 4, '1000.00', '4000.00', '2026-08-07 15:29:02'),
(32, 24, 19, 2, 1, '20000.00', '20000.00', '2026-08-07 15:47:07'),
(33, 25, 20, 3, 5, '1000.00', '5000.00', '2026-08-07 15:50:17');

-- --------------------------------------------------------

--
-- Table structure for table `purchase_process`
--

CREATE TABLE `purchase_process` (
  `purchase_id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `total_amount` decimal(10,2) NOT NULL DEFAULT '0.00',
  `payment_method` enum('kbzpay','wave_money') DEFAULT NULL,
  `payment_status` enum('unpaid','pending_verification','paid','rejected') NOT NULL DEFAULT 'unpaid',
  `transaction_id` varchar(100) DEFAULT NULL,
  `payment_slip` varchar(255) DEFAULT NULL,
  `payment_submitted_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `verified_by` int(11) DEFAULT NULL,
  `payment_rejection_reason` varchar(255) DEFAULT NULL,
  `fulfillment_type` enum('pickup','delivery') NOT NULL DEFAULT 'pickup',
  `delivery_name` varchar(100) DEFAULT NULL,
  `delivery_phone` varchar(30) DEFAULT NULL,
  `delivery_address` varchar(255) DEFAULT NULL,
  `customer_note` varchar(255) DEFAULT NULL,
  `order_status` enum('pending','confirmed','completed','rejected') NOT NULL DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `purchase_process`
--

INSERT INTO `purchase_process` (`purchase_id`, `user_id`, `total_amount`, `payment_method`, `payment_status`, `transaction_id`, `payment_slip`, `payment_submitted_at`, `paid_at`, `verified_at`, `verified_by`, `payment_rejection_reason`, `order_status`, `created_at`, `updated_at`) VALUES
(3, 3, '4000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 05:03:14', '2026-08-07 05:03:14'),
(4, 3, '1000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 05:05:07', '2026-08-07 05:05:07'),
(5, 3, '23000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 05:12:17', '2026-08-07 05:12:17'),
(6, 3, '23000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 06:43:26', '2026-08-07 06:43:26'),
(7, 3, '3000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 06:44:48', '2026-08-07 06:44:48'),
(8, 3, '20000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 06:45:50', '2026-08-07 06:45:50'),
(9, 3, '4000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'completed', '2026-08-07 07:06:41', '2026-08-07 08:14:41'),
(10, 3, '2000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'rejected', '2026-08-07 07:19:21', '2026-08-07 07:19:53'),
(11, 3, '20000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'completed', '2026-08-07 07:24:24', '2026-08-07 12:47:55'),
(12, 3, '1000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'completed', '2026-08-07 07:34:38', '2026-08-07 08:14:47'),
(13, 3, '1000.00', 'kbzpay', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'completed', '2026-08-07 07:36:28', '2026-08-07 08:15:01'),
(14, 3, '1000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 08:11:47', '2026-08-07 08:11:47'),
(15, 3, '3000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 12:46:20', '2026-08-07 12:46:20'),
(16, 3, '81000.00', 'kbzpay', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 12:51:20', '2026-08-07 12:51:20'),
(17, 3, '20000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'completed', '2026-08-07 12:57:28', '2026-08-07 12:58:05'),
(18, 3, '42000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'pending', '2026-08-07 13:45:08', '2026-08-07 13:45:08'),
(19, 3, '3000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'confirmed', '2026-08-07 13:46:21', '2026-08-07 13:46:47'),
(20, 3, '2000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'confirmed', '2026-08-07 14:08:34', '2026-08-07 14:08:52'),
(21, 3, '1000.00', 'kbzpay', 'paid', NULL, NULL, NULL, '2026-08-07 20:46:51', NULL, NULL, NULL, 'completed', '2026-08-07 14:11:48', '2026-08-07 14:20:55'),
(22, 3, '1000.00', NULL, 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'confirmed', '2026-08-07 15:19:01', '2026-08-07 15:19:17'),
(23, 3, '4000.00', 'kbzpay', 'paid', NULL, NULL, NULL, '2026-08-07 22:04:40', NULL, NULL, NULL, 'completed', '2026-08-07 15:29:02', '2026-08-07 15:35:10'),
(24, 3, '20000.00', 'kbzpay', 'paid', NULL, NULL, NULL, '2026-08-07 22:18:29', NULL, NULL, NULL, 'confirmed', '2026-08-07 15:47:07', '2026-08-07 15:48:29'),
(25, 3, '5000.00', 'kbzpay', 'unpaid', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 'confirmed', '2026-08-07 15:50:17', '2026-08-07 16:20:23');

-- --------------------------------------------------------

--
-- Table structure for table `reviews`
--

CREATE TABLE `reviews` (
  `review_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `rating` tinyint(3) UNSIGNED NOT NULL,
  `comment` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `user_name` varchar(100) NOT NULL,
  `phone_number` varchar(20) NOT NULL,
  `email` varchar(100) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `user_password` varchar(255) NOT NULL,
  `role` enum('admin','vendor','user') NOT NULL DEFAULT 'user',
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `must_change_password` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `users`
--
-- DEFAULT ADMIN LOGIN FOR FIRST SETUP ONLY
-- Email: admin@gmail.com
-- Temporary password: Admin@12345
-- The application forces the Admin to change this password after Sign In.
--

INSERT INTO `users` (`user_id`, `user_name`, `phone_number`, `email`, `profile_image`, `user_password`, `role`, `status`, `must_change_password`, `created_at`, `last_login`) VALUES
(1, 'Administrator', '09123456789', 'admin@gmail.com', NULL, '$2y$12$MJgX808aKrSRzIoAEeB2zeOnrp3a7p1T0pQmqigipX1JNFUOFKfQS', 'admin', 'active', 1, '2026-08-04 19:11:11', NULL),
(2, 'Shune Shune', '0923456789', 'shune@gmail.com', NULL, '$2y$10$vCiXW8eTAk1HGnuK2k.9Kue4saUOYDmzp8TZa.ugBWN75kB790U8S', 'vendor', 'active', 0, '2026-08-06 05:30:54', '2026-08-07 15:47:36'),
(3, 'Su Su', '09259190620', 'su@gmail.com', 'uploads/profiles/user_3_20260806_155117_57cd42197a.png', '$2y$10$cX6x3Rmi2hV99jbzLdXAs.i2sg/qPYCbe73QMJC6qKjAcuzWqqjAK', 'user', 'active', 0, '2026-08-06 11:09:56', '2026-08-07 12:33:33'),
(4, 'Nyein Nyein Nway', '0932344546', 'nyein@gmail.com', NULL, '$2y$10$4JuRW88PwVAqDtaGXeeBi.YbQKY7emrNl7GxfXGJvLWlwyyzDH8QG', 'vendor', 'active', 0, '2026-08-06 15:42:15', '2026-08-07 16:25:00'),
(5, 'Thansin', '0932344548', 'than@gmail.com', NULL, '$2y$10$Dq1YqqCw5a6QUtVHV24AeO/QGX6jodTepykI4gI.EFSM3nBDrdqhu', 'user', 'active', 0, '2026-08-06 17:50:45', '2026-08-06 17:51:00');

-- --------------------------------------------------------

--
-- Table structure for table `vendors`
--

CREATE TABLE `vendors` (
  `vendor_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `vendor_name` varchar(100) NOT NULL,
  `address` varchar(255) NOT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` text,
  `reviewed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `vendors`
--

INSERT INTO `vendors` (`vendor_id`, `user_id`, `vendor_name`, `address`, `profile_image`, `status`, `rejection_reason`, `reviewed_at`, `created_at`, `updated_at`) VALUES
(1, 2, 'Shune Shune', 'Myingyan', 'uploads/profiles/vendor_1_20260806_125957_85eb9d0cecc7.png', 'accepted', NULL, '2026-08-06 12:01:48', '2026-08-06 05:30:54', '2026-08-06 11:00:48'),
(2, 4, 'Green Vally Farm', 'mandalay', NULL, 'accepted', NULL, '2026-08-06 22:13:57', '2026-08-06 15:42:15', '2026-08-06 15:43:57');

-- --------------------------------------------------------

--
-- Table structure for table `vendor_markets`
--

CREATE TABLE `vendor_markets` (
  `vendor_market_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `market_id` int(11) NOT NULL,
  `assigned_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `vendor_markets`
--

INSERT INTO `vendor_markets` (`vendor_market_id`, `vendor_id`, `market_id`, `assigned_at`) VALUES
(6, 2, 2, '2026-08-06 16:44:59'),
(7, 2, 1, '2026-08-06 16:45:00'),
(8, 1, 2, '2026-08-06 17:03:37'),
(9, 1, 1, '2026-08-06 17:03:37');

-- --------------------------------------------------------

--
-- Table structure for table `vendor_orders`
--

CREATE TABLE `vendor_orders` (
  `vendor_order_id` int(11) NOT NULL,
  `purchase_id` int(11) NOT NULL,
  `vendor_id` int(11) NOT NULL,
  `subtotal` decimal(10,2) NOT NULL DEFAULT '0.00',
  `order_status` enum('pending','confirmed','completed','rejected') NOT NULL DEFAULT 'pending',
  `rejection_reason` varchar(255) DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `rejected_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `vendor_orders`
--

INSERT INTO `vendor_orders` (`vendor_order_id`, `purchase_id`, `vendor_id`, `subtotal`, `order_status`, `rejection_reason`, `confirmed_at`, `rejected_at`, `completed_at`, `created_at`, `updated_at`) VALUES
(1, 9, 2, '1000.00', 'completed', NULL, '2026-08-07 14:22:05', NULL, '2026-08-07 14:44:41', '2026-08-07 07:06:41', '2026-08-07 08:14:41'),
(2, 9, 1, '3000.00', 'completed', NULL, '2026-08-07 13:47:26', NULL, '2026-08-07 14:40:24', '2026-08-07 07:06:41', '2026-08-07 08:10:24'),
(3, 10, 1, '2000.00', 'rejected', 'stock is out of now', NULL, '2026-08-07 13:49:53', NULL, '2026-08-07 07:19:21', '2026-08-07 07:19:53'),
(4, 11, 1, '20000.00', 'completed', NULL, '2026-08-07 19:17:30', NULL, '2026-08-07 19:17:55', '2026-08-07 07:24:24', '2026-08-07 12:47:55'),
(5, 12, 2, '1000.00', 'completed', NULL, '2026-08-07 14:22:12', NULL, '2026-08-07 14:44:47', '2026-08-07 07:34:38', '2026-08-07 08:14:47'),
(6, 13, 2, '1000.00', 'completed', NULL, '2026-08-07 14:22:18', NULL, '2026-08-07 14:45:01', '2026-08-07 07:36:28', '2026-08-07 08:15:01'),
(7, 14, 2, '1000.00', 'pending', NULL, NULL, NULL, NULL, '2026-08-07 08:11:47', '2026-08-07 08:11:47'),
(8, 15, 1, '3000.00', 'pending', NULL, NULL, NULL, NULL, '2026-08-07 12:46:20', '2026-08-07 12:46:20'),
(9, 16, 1, '80000.00', 'confirmed', NULL, '2026-08-07 19:24:04', NULL, NULL, '2026-08-07 12:51:20', '2026-08-07 12:54:04'),
(10, 16, 2, '1000.00', 'pending', NULL, NULL, NULL, NULL, '2026-08-07 12:51:20', '2026-08-07 12:51:20'),
(11, 17, 1, '20000.00', 'completed', NULL, '2026-08-07 19:27:39', NULL, '2026-08-07 19:28:05', '2026-08-07 12:57:28', '2026-08-07 12:58:05'),
(12, 18, 1, '40000.00', 'pending', NULL, NULL, NULL, NULL, '2026-08-07 13:45:08', '2026-08-07 13:45:08'),
(13, 18, 2, '2000.00', 'pending', NULL, NULL, NULL, NULL, '2026-08-07 13:45:08', '2026-08-07 13:45:08'),
(14, 19, 1, '3000.00', 'confirmed', NULL, '2026-08-07 20:16:47', NULL, NULL, '2026-08-07 13:46:21', '2026-08-07 13:46:47'),
(15, 20, 1, '2000.00', 'confirmed', NULL, '2026-08-07 20:38:52', NULL, NULL, '2026-08-07 14:08:34', '2026-08-07 14:08:52'),
(16, 21, 2, '1000.00', 'completed', NULL, '2026-08-07 20:42:45', NULL, '2026-08-07 20:50:55', '2026-08-07 14:11:48', '2026-08-07 14:20:55'),
(17, 22, 2, '1000.00', 'confirmed', NULL, '2026-08-07 21:49:17', NULL, NULL, '2026-08-07 15:19:01', '2026-08-07 15:19:17'),
(18, 23, 2, '4000.00', 'completed', NULL, '2026-08-07 21:59:25', NULL, '2026-08-07 22:05:10', '2026-08-07 15:29:02', '2026-08-07 15:35:10'),
(19, 24, 1, '20000.00', 'confirmed', NULL, '2026-08-07 22:17:45', NULL, NULL, '2026-08-07 15:47:07', '2026-08-07 15:47:45'),
(20, 25, 2, '5000.00', 'confirmed', NULL, '2026-08-07 22:20:46', NULL, NULL, '2026-08-07 15:50:17', '2026-08-07 15:50:46');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `idx_category_market` (`category_name`,`market_id`),
  ADD KEY `idx_category_market_id` (`market_id`);

--
-- Indexes for table `cities`
--
ALTER TABLE `cities`
  ADD PRIMARY KEY (`city_id`),
  ADD UNIQUE KEY `idx_city_name_division` (`city_name`,`administrative_division`);

--
-- Indexes for table `events`
--
ALTER TABLE `events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `idx_event_market` (`market_id`),
  ADD KEY `idx_event_admin` (`user_id`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`attempt_id`),
  ADD KEY `idx_login_attempt_client` (`email`,`ip_address`,`attempted_at`),
  ADD KEY `idx_login_attempt_time` (`attempted_at`);

--
-- Indexes for table `markets`
--
ALTER TABLE `markets`
  ADD PRIMARY KEY (`market_id`),
  ADD KEY `idx_market_city` (`city_id`);

--
-- Indexes for table `market_photo`
--
ALTER TABLE `market_photo`
  ADD PRIMARY KEY (`photo_id`),
  ADD KEY `idx_market_photo_market` (`market_id`);

--
-- Indexes for table `payment_accounts`
--
ALTER TABLE `payment_accounts`
  ADD PRIMARY KEY (`payment_account_id`),
  ADD UNIQUE KEY `uq_payment_account_method` (`payment_method`);

--
-- Indexes for table `permission`
--
ALTER TABLE `permission`
  ADD PRIMARY KEY (`permission_id`),
  ADD UNIQUE KEY `idx_permission_vendor` (`vendor_id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`product_id`),
  ADD KEY `idx_product_vendor` (`vendor_id`),
  ADD KEY `idx_product_category` (`category_id`);

--
-- Indexes for table `product_photo`
--
ALTER TABLE `product_photo`
  ADD PRIMARY KEY (`product_photo_id`),
  ADD KEY `idx_product_photo_product` (`product_id`);

--
-- Indexes for table `purchase_details`
--
ALTER TABLE `purchase_details`
  ADD PRIMARY KEY (`purchase_detail_id`),
  ADD KEY `idx_purchase_detail_purchase` (`purchase_id`),
  ADD KEY `idx_purchase_detail_product` (`product_id`),
  ADD KEY `fk_purchase_detail_vendor_order` (`vendor_order_id`);

--
-- Indexes for table `purchase_process`
--
ALTER TABLE `purchase_process`
  ADD PRIMARY KEY (`purchase_id`),
  ADD KEY `idx_purchase_user` (`user_id`),
  ADD KEY `idx_purchase_status` (`order_status`),
  ADD KEY `fk_purchase_payment_verified_by` (`verified_by`),
  ADD KEY `idx_purchase_payment_status` (`payment_status`),
  ADD KEY `idx_purchase_fulfillment_type` (`fulfillment_type`);

--
-- Indexes for table `reviews`
--
ALTER TABLE `reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD UNIQUE KEY `idx_user_product_review` (`user_id`,`product_id`),
  ADD KEY `idx_review_product` (`product_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `idx_user_email` (`email`),
  ADD KEY `idx_user_role` (`role`),
  ADD KEY `idx_user_status` (`status`);

--
-- Indexes for table `vendors`
--
ALTER TABLE `vendors`
  ADD PRIMARY KEY (`vendor_id`),
  ADD UNIQUE KEY `idx_vendor_user_id` (`user_id`),
  ADD KEY `idx_vendor_status` (`status`);

--
-- Indexes for table `vendor_markets`
--
ALTER TABLE `vendor_markets`
  ADD PRIMARY KEY (`vendor_market_id`),
  ADD UNIQUE KEY `idx_vendor_market` (`vendor_id`,`market_id`),
  ADD KEY `idx_vendor_market_vendor` (`vendor_id`),
  ADD KEY `idx_vendor_market_market` (`market_id`);

--
-- Indexes for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  ADD PRIMARY KEY (`vendor_order_id`),
  ADD KEY `idx_vendor_order_purchase` (`purchase_id`),
  ADD KEY `idx_vendor_order_vendor` (`vendor_id`),
  ADD KEY `idx_vendor_order_status` (`order_status`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `cities`
--
ALTER TABLE `cities`
  MODIFY `city_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `events`
--
ALTER TABLE `events`
  MODIFY `event_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `attempt_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `markets`
--
ALTER TABLE `markets`
  MODIFY `market_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `market_photo`
--
ALTER TABLE `market_photo`
  MODIFY `photo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payment_accounts`
--
ALTER TABLE `payment_accounts`
  MODIFY `payment_account_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `permission`
--
ALTER TABLE `permission`
  MODIFY `permission_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `product_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `product_photo`
--
ALTER TABLE `product_photo`
  MODIFY `product_photo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `purchase_details`
--
ALTER TABLE `purchase_details`
  MODIFY `purchase_detail_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=34;

--
-- AUTO_INCREMENT for table `purchase_process`
--
ALTER TABLE `purchase_process`
  MODIFY `purchase_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=26;

--
-- AUTO_INCREMENT for table `reviews`
--
ALTER TABLE `reviews`
  MODIFY `review_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vendors`
--
ALTER TABLE `vendors`
  MODIFY `vendor_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `vendor_markets`
--
ALTER TABLE `vendor_markets`
  MODIFY `vendor_market_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  MODIFY `vendor_order_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `categories`
--
ALTER TABLE `categories`
  ADD CONSTRAINT `fk_categories_market` FOREIGN KEY (`market_id`) REFERENCES `markets` (`market_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `events`
--
ALTER TABLE `events`
  ADD CONSTRAINT `fk_events_admin` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_events_market` FOREIGN KEY (`market_id`) REFERENCES `markets` (`market_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `markets`
--
ALTER TABLE `markets`
  ADD CONSTRAINT `fk_markets_city` FOREIGN KEY (`city_id`) REFERENCES `cities` (`city_id`) ON UPDATE CASCADE;

--
-- Constraints for table `market_photo`
--
ALTER TABLE `market_photo`
  ADD CONSTRAINT `fk_market_photo_market` FOREIGN KEY (`market_id`) REFERENCES `markets` (`market_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `permission`
--
ALTER TABLE `permission`
  ADD CONSTRAINT `fk_permission_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `products`
--
ALTER TABLE `products`
  ADD CONSTRAINT `fk_products_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`category_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_products_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `product_photo`
--
ALTER TABLE `product_photo`
  ADD CONSTRAINT `fk_product_photo_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `purchase_details`
--
ALTER TABLE `purchase_details`
  ADD CONSTRAINT `fk_details_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_details_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchase_process` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_detail_vendor_order` FOREIGN KEY (`vendor_order_id`) REFERENCES `vendor_orders` (`vendor_order_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `purchase_process`
--
ALTER TABLE `purchase_process`
  ADD CONSTRAINT `fk_purchase_payment_verified_by` FOREIGN KEY (`verified_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_purchase_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `reviews`
--
ALTER TABLE `reviews`
  ADD CONSTRAINT `fk_reviews_product` FOREIGN KEY (`product_id`) REFERENCES `products` (`product_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_reviews_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vendors`
--
ALTER TABLE `vendors`
  ADD CONSTRAINT `fk_vendor_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vendor_markets`
--
ALTER TABLE `vendor_markets`
  ADD CONSTRAINT `fk_vendor_markets_market` FOREIGN KEY (`market_id`) REFERENCES `markets` (`market_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vendor_markets_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `vendor_orders`
--
ALTER TABLE `vendor_orders`
  ADD CONSTRAINT `fk_vendor_order_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchase_process` (`purchase_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_vendor_order_vendor` FOREIGN KEY (`vendor_id`) REFERENCES `vendors` (`vendor_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;




CREATE TABLE IF NOT EXISTS contact_messages (
    contact_message_id INT NOT NULL AUTO_INCREMENT,
    user_id INT NULL,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(190) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read', 'replied') NOT NULL DEFAULT 'new',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (contact_message_id),
    INDEX idx_contact_status (status),
    INDEX idx_contact_created_at (created_at),
    INDEX idx_contact_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;




/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
