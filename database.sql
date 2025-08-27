-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Aug 27, 2025 at 10:24 AM
-- Server version: 10.3.39-MariaDB-0ubuntu0.20.04.2
-- PHP Version: 8.0.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `test2_audiensi`
--

-- --------------------------------------------------------

--
-- Table structure for table `checks`
--

CREATE TABLE `checks` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `url` text NOT NULL,
  `method` enum('GET','POST') DEFAULT 'GET',
  `request_body` text DEFAULT NULL,
  `request_headers` text DEFAULT NULL,
  `expected_status` int(11) DEFAULT 200,
  `expected_headers` text DEFAULT NULL,
  `interval_seconds` int(11) NOT NULL,
  `timeout_seconds` int(11) DEFAULT 30,
  `max_redirects` int(11) DEFAULT 5,
  `alert_emails` text DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `last_state` enum('UP','DOWN') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `next_run_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `checks`
--

INSERT INTO `checks` (`id`, `name`, `url`, `method`, `request_body`, `request_headers`, `expected_status`, `expected_headers`, `interval_seconds`, `timeout_seconds`, `max_redirects`, `alert_emails`, `enabled`, `last_state`, `created_at`, `updated_at`, `next_run_at`) VALUES
(2, 'Test Check - Google', 'https://www.googlefffff.com', 'GET', '', '', 200, '', 300, 30, 5, 'admin@example.com', 1, 'DOWN', '2025-08-27 08:37:58', '2025-08-27 10:06:21', '2025-08-27 17:11:21'),
(4, 'Topupgim', 'https://topupgim.com/', 'GET', '', '', 200, '', 60, 30, 5, '', 1, 'UP', '2025-08-27 08:57:23', '2025-08-27 10:06:21', '2025-08-27 17:07:21');

-- --------------------------------------------------------

--
-- Table structure for table `check_results`
--

CREATE TABLE `check_results` (
  `id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime NOT NULL,
  `duration_ms` int(11) NOT NULL,
  `http_status` int(11) NOT NULL,
  `response_headers` text DEFAULT NULL,
  `body_sample` text DEFAULT NULL,
  `is_up` tinyint(1) NOT NULL,
  `error_message` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `check_results`
--

INSERT INTO `check_results` (`id`, `check_id`, `started_at`, `ended_at`, `duration_ms`, `http_status`, `response_headers`, `body_sample`, `is_up`, `error_message`) VALUES
(1, 2, '2025-08-27 16:28:53', '2025-08-27 16:28:53', 204, 200, '{}', '<!doctype html><html itemscope=\"\" itemtype=\"http://schema.org/WebPage\" lang=\"de\"><head><meta content=\"text/html; charset=UTF-8\" http-equiv=\"Content-Type\"><meta content=\"/images/branding/googleg/1x/googleg_standard_color_128dp.png\" itemprop=\"image\"><title>Google</title><script nonce=\"Txznu2YEAvnBt5GvTeJxRw\">(function(){var _g={kEI:\'2c-uaPq-C_i1wN4Prqy2wQo\',kEXPI:\'0,202854,2,3497408,678,442,538661,48791,46127,78218,266578,290044,5241681,457,165,36812021,25228681,138268,14109,57131,8044,6750,23879,9140,4598,328,6226,1117,63048,15049,48,8156,889,6541,58713,54209,352,18471,409,5870,1878,1,2,1,2,5830,5774,27611,4719,11805,6251,35,3420,2117,1473,1765,2,8127,12107,3705,1978,3605,17771,17999,1,1280,4225,3,6635,2,6194,1856,2982,1,7653,7,480,1491,1032,1579,7568,3,4011,3018,2646,101,2,4,1,321,1189,2933,977,670,5183,7315,519,355,4676,915,566,2,10045,311,17,2161,1280,408,183,4,259,1119,136,1279,1765,311,195,342,520,2301,538,404,151,2,225,1,7,1324,2,771,4,507,65,512,175,128,3401,24,412,8,843,570,261,', 1, NULL),
(2, 4, '2025-08-27 16:28:53', '2025-08-27 16:28:55', 1698, 200, '{}', '<!doctype html>\n<html lang=\"id-ID\">\n\n<head>\n    <meta charset=\"utf-8\" />\n    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, shrink-to-fit=no\" />\n    \n    <!-- Title Of Site -->\n    <!-- HTML Meta Tags -->\n    <title>TopUpGim - Top Up Game Murah dan Instan</title>\n    <meta name=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\" />\n    \n    <!-- Google / Search Engine Tags -->\n    <meta itemprop=\"name\" content=\"TopUpGim - Top Up Game Murah dan Instan\">\n    <meta itemprop=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\">\n    <meta itemprop=\"image\" content=\"https://cdn.topupgim.com/assets/media/favicons/site-thumbnail.png\">\n    \n ', 1, NULL),
(3, 4, '2025-08-27 16:29:59', '2025-08-27 16:29:59', 438, 200, '{}', '<!doctype html>\n<html lang=\"id-ID\">\n\n<head>\n    <meta charset=\"utf-8\" />\n    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, shrink-to-fit=no\" />\n    \n    <!-- Title Of Site -->\n    <!-- HTML Meta Tags -->\n    <title>TopUpGim - Top Up Game Murah dan Instan</title>\n    <meta name=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\" />\n    \n    <!-- Google / Search Engine Tags -->\n    <meta itemprop=\"name\" content=\"TopUpGim - Top Up Game Murah dan Instan\">\n    <meta itemprop=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\">\n    <meta itemprop=\"image\" content=\"https://cdn.topupgim.com/assets/media/favicons/site-thumbnail.png\">\n    \n ', 1, NULL),
(4, 4, '2025-08-27 16:32:05', '2025-08-27 16:32:05', 339, 200, '{}', '<!doctype html>\n<html lang=\"id-ID\">\n\n<head>\n    <meta charset=\"utf-8\" />\n    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, shrink-to-fit=no\" />\n    \n    <!-- Title Of Site -->\n    <!-- HTML Meta Tags -->\n    <title>TopUpGim - Top Up Game Murah dan Instan</title>\n    <meta name=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\" />\n    \n    <!-- Google / Search Engine Tags -->\n    <meta itemprop=\"name\" content=\"TopUpGim - Top Up Game Murah dan Instan\">\n    <meta itemprop=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\">\n    <meta itemprop=\"image\" content=\"https://cdn.topupgim.com/assets/media/favicons/site-thumbnail.png\">\n    \n ', 1, NULL),
(5, 2, '2025-08-27 17:06:21', '2025-08-27 17:06:21', 45, 0, '{}', '', 0, 'Could not resolve host: www.googlefffff.com'),
(6, 4, '2025-08-27 17:06:21', '2025-08-27 17:06:21', 362, 200, '{}', '<!doctype html>\n<html lang=\"id-ID\">\n\n<head>\n    <meta charset=\"utf-8\" />\n    <meta http-equiv=\"X-UA-Compatible\" content=\"IE=edge\">\n    <meta name=\"viewport\" content=\"width=device-width, initial-scale=1.0, shrink-to-fit=no\" />\n    \n    <!-- Title Of Site -->\n    <!-- HTML Meta Tags -->\n    <title>TopUpGim - Top Up Game Murah dan Instan</title>\n    <meta name=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\" />\n    \n    <!-- Google / Search Engine Tags -->\n    <meta itemprop=\"name\" content=\"TopUpGim - Top Up Game Murah dan Instan\">\n    <meta itemprop=\"description\" content=\"TopUpGim adalah situs Top Up Game resmi dengan harga termurah. Top Up Game Mobile Legends, Free Fire, Honor of Kings, dan ratusan game lainnya lebih hemat di TopUpGim!\">\n    <meta itemprop=\"image\" content=\"https://cdn.topupgim.com/assets/media/favicons/site-thumbnail.png\">\n    \n ', 1, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `incidents`
--

CREATE TABLE `incidents` (
  `id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `started_at` datetime NOT NULL,
  `ended_at` datetime DEFAULT NULL,
  `opened_by_result_id` int(11) NOT NULL,
  `closed_by_result_id` int(11) DEFAULT NULL,
  `status` enum('OPEN','CLOSED') DEFAULT 'OPEN'
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `migrations`
--

CREATE TABLE `migrations` (
  `id` int(11) NOT NULL,
  `version` varchar(50) NOT NULL,
  `executed_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `migrations`
--

INSERT INTO `migrations` (`id`, `version`, `executed_at`) VALUES
(1, '001_initial_schema', '2025-08-27 07:38:49');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password_hash`, `created_at`) VALUES
(2, 'admin@example.com', '$2y$12$OpQcY/axQG/kHyfNeW/2nuaFSmu6qeuQU1ln3aH0oua00M0XWRnxG', '2025-08-27 08:15:55');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `checks`
--
ALTER TABLE `checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enabled_next_run` (`enabled`,`next_run_at`),
  ADD KEY `idx_last_state` (`last_state`);

--
-- Indexes for table `check_results`
--
ALTER TABLE `check_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_check_started` (`check_id`,`started_at`),
  ADD KEY `idx_started_at` (`started_at`);

--
-- Indexes for table `incidents`
--
ALTER TABLE `incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `opened_by_result_id` (`opened_by_result_id`),
  ADD KEY `closed_by_result_id` (`closed_by_result_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_check_status` (`check_id`,`status`);

--
-- Indexes for table `migrations`
--
ALTER TABLE `migrations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `checks`
--
ALTER TABLE `checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `check_results`
--
ALTER TABLE `check_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `incidents`
--
ALTER TABLE `incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `check_results`
--
ALTER TABLE `check_results`
  ADD CONSTRAINT `check_results_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `incidents`
--
ALTER TABLE `incidents`
  ADD CONSTRAINT `incidents_ibfk_1` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `incidents_ibfk_2` FOREIGN KEY (`opened_by_result_id`) REFERENCES `check_results` (`id`),
  ADD CONSTRAINT `incidents_ibfk_3` FOREIGN KEY (`closed_by_result_id`) REFERENCES `check_results` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
