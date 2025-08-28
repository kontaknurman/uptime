-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Aug 28, 2025 at 10:40 AM
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
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(50) NOT NULL,
  `color` varchar(7) DEFAULT '#6B7280',
  `icon` varchar(50) DEFAULT 'folder',
  `description` text DEFAULT NULL,
  `checks_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`, `color`, `icon`, `description`, `checks_count`, `created_at`, `updated_at`) VALUES
(4, 'API', '#8B5CF6', 'api', 'API endpoints and webhooks', 0, '2025-08-27 10:53:44', '2025-08-27 10:53:44'),
(5, 'Website', '#EC4899', 'globe', 'Website and web applications', 0, '2025-08-27 10:53:44', '2025-08-27 10:53:44'),
(6, 'Database', '#EF4444', 'database', 'Database and data services', 0, '2025-08-27 10:53:44', '2025-08-27 10:53:44'),
(7, 'Third Party', '#6B7280', 'external-link', 'External third-party services', 0, '2025-08-27 10:53:44', '2025-08-27 10:53:44'),
(8, 'Monitoring', '#14B8A6', 'activity', 'Monitoring and health check endpoints', 0, '2025-08-27 10:53:44', '2025-08-27 10:53:44');

-- --------------------------------------------------------

--
-- Table structure for table `checks`
--

CREATE TABLE `checks` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `url` text NOT NULL,
  `method` enum('GET','POST') DEFAULT 'GET',
  `request_body` text DEFAULT NULL,
  `request_headers` text DEFAULT NULL,
  `expected_status` int(11) DEFAULT 200,
  `expected_headers` text DEFAULT NULL,
  `expected_body` text DEFAULT NULL,
  `interval_seconds` int(11) NOT NULL,
  `timeout_seconds` int(11) DEFAULT 30,
  `max_redirects` int(11) DEFAULT 5,
  `alert_emails` text DEFAULT NULL,
  `enabled` tinyint(1) DEFAULT 1,
  `keep_response_data` tinyint(1) DEFAULT 0,
  `last_state` enum('UP','DOWN') DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `next_run_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `checks`
--

INSERT INTO `checks` (`id`, `name`, `category_id`, `url`, `method`, `request_body`, `request_headers`, `expected_status`, `expected_headers`, `expected_body`, `interval_seconds`, `timeout_seconds`, `max_redirects`, `alert_emails`, `enabled`, `keep_response_data`, `last_state`, `created_at`, `updated_at`, `next_run_at`) VALUES
(5, 'Tes POST Request', 4, 'https://httpbin.dev/post', 'POST', '{\"key1\": \"tes\", \"key2\": \"tes2\"}', 'Content-Type: application/json', 200, 'Content-Type: application/json; encoding=utf-8', '\"url\": \"http://httpbin.dev/post\",', 60, 30, 5, 'kontaknurman@gmail.com', 1, 1, 'UP', '2025-08-27 12:42:04', '2025-08-27 16:24:56', '2025-08-27 23:25:56'),
(6, 'Topupgim', 5, 'https://topupgim.com/', 'GET', '', '', 200, '', '', 300, 30, 5, 'kontaknurman@gmail.com, domialfitra@gmail.com', 1, 0, NULL, '2025-08-27 16:28:59', '2025-08-27 16:28:59', '2025-08-27 23:28:59');

-- --------------------------------------------------------

--
-- Table structure for table `check_categories`
--

CREATE TABLE `check_categories` (
  `id` int(11) NOT NULL,
  `check_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `check_categories`
--

INSERT INTO `check_categories` (`id`, `check_id`, `category_id`, `created_at`) VALUES
(2, 6, 5, '2025-08-27 16:47:02'),
(4, 5, 4, '2025-08-27 16:48:07'),
(5, 5, 6, '2025-08-27 16:48:07');

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
(20, 5, '2025-08-27 19:42:16', '2025-08-27 19:42:16', 714, 400, 'HTTP/2 400 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: text/plain; charset=utf-8\r\ndate: Wed, 27 Aug 2025 12:42:20 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 93\r\n\r\n', 'error parsing request body: invalid character \'&\' looking for beginning of object key string\n', 0, NULL),
(22, 5, '2025-08-27 19:48:19', '2025-08-27 19:48:19', 701, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 12:48:22 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 0, NULL),
(25, 5, '2025-08-27 19:53:29', '2025-08-27 19:53:29', 791, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 12:53:33 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 0, NULL),
(27, 5, '2025-08-27 19:57:24', '2025-08-27 19:57:24', 907, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 12:57:28 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 0, 'Body Validation Failed: Expected content not found - \'\"origin\": \"10.0.5.181\",\''),
(29, 5, '2025-08-27 20:00:06', '2025-08-27 20:00:06', 797, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:00:10 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 0, 'Body Validation Failed: Expected content not found - \'\"origin\": \"10.0.18.243\",\''),
(31, 5, '2025-08-27 20:05:19', '2025-08-27 20:05:19', 1891, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:05:23 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(33, 5, '2025-08-27 20:22:56', '2025-08-27 20:22:56', 734, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:23:00 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(35, 5, '2025-08-27 20:45:25', '2025-08-27 20:45:25', 791, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:45:29 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(38, 5, '2025-08-27 20:49:14', '2025-08-27 20:49:14', 662, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:49:16 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(40, 5, '2025-08-27 20:51:09', '2025-08-27 20:51:09', 658, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:51:13 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(43, 5, '2025-08-27 20:58:49', '2025-08-27 20:58:49', 870, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 13:58:36 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(46, 5, '2025-08-27 21:05:00', '2025-08-27 21:05:00', 3143, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:05:03 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(49, 5, '2025-08-27 21:12:18', '2025-08-27 21:12:18', 12095, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:12:22 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(52, 5, '2025-08-27 21:14:59', '2025-08-27 21:14:59', 4072, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:15:02 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(54, 5, '2025-08-27 21:18:11', '2025-08-27 21:18:11', 2024, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:18:15 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(57, 5, '2025-08-27 21:24:59', '2025-08-27 21:24:59', 1633, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:25:03 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(60, 5, '2025-08-27 21:27:22', '2025-08-27 21:27:22', 2119, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:27:22 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(62, 5, '2025-08-27 21:32:45', '2025-08-27 21:32:45', 7594, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:32:49 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(65, 5, '2025-08-27 21:41:38', '2025-08-27 21:41:38', 6360, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:41:42 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(68, 5, '2025-08-27 21:43:23', '2025-08-27 21:43:23', 1026, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:43:26 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(72, 5, '2025-08-27 21:45:50', '2025-08-27 21:45:50', 769, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:45:54 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(74, 5, '2025-08-27 21:47:39', '2025-08-27 21:47:39', 823, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 14:47:40 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(76, 5, '2025-08-27 22:15:22', '2025-08-27 22:15:22', 686, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 15:15:25 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(78, 5, '2025-08-27 22:29:48', '2025-08-27 22:29:48', 680, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 15:29:52 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(80, 5, '2025-08-27 22:52:27', '2025-08-27 22:52:27', 671, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 15:52:30 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(82, 5, '2025-08-27 22:53:59', '2025-08-27 22:53:59', 668, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 15:54:03 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(84, 5, '2025-08-27 22:55:30', '2025-08-27 22:55:30', 693, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 15:55:33 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(86, 5, '2025-08-27 23:05:42', '2025-08-27 23:05:42', 661, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 16:05:45 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL);
INSERT INTO `check_results` (`id`, `check_id`, `started_at`, `ended_at`, `duration_ms`, `http_status`, `response_headers`, `body_sample`, `is_up`, `error_message`) VALUES
(88, 5, '2025-08-27 23:07:00', '2025-08-27 23:07:00', 767, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 16:07:04 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.11.210\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(91, 5, '2025-08-27 23:23:42', '2025-08-27 23:23:42', 659, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 16:23:43 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 529\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.5.181\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL),
(93, 5, '2025-08-27 23:24:56', '2025-08-27 23:24:56', 658, 200, 'HTTP/2 200 \r\naccess-control-allow-credentials: true\r\naccess-control-allow-origin: *\r\nalt-svc: h3=\":443\"; ma=2592000\r\ncontent-security-policy:  frame-ancestors \'self\' *.httpbin.dev; font-src \'self\' *.httpbin.dev; default-src \'self\' *.httpbin.dev; img-src \'self\' *.httpbin.dev https://cdn.scrapfly.io; media-src \'self\' *.httpbin.dev; object-src \'self\' *.httpbin.dev https://web-scraping.dev; script-src \'self\' \'unsafe-inline\' \'unsafe-eval\' *.httpbin.dev; style-src \'self\' \'unsafe-inline\' *.httpbin.dev https://unpkg.com; frame-src \'self\' *.httpbin.dev https://web-scraping.dev; worker-src \'self\' *.httpbin.dev; connect-src \'self\' *.httpbin.dev\r\ncontent-type: application/json; encoding=utf-8\r\ndate: Wed, 27 Aug 2025 16:24:57 GMT\r\npermissions-policy: fullscreen=(self), autoplay=*, geolocation=(), camera=()\r\nreferrer-policy: strict-origin-when-cross-origin\r\nstrict-transport-security: max-age=31536000; includeSubDomains; preload\r\nx-content-type-options: nosniff\r\nx-xss-protection: 1; mode=block\r\ncontent-length: 530\r\n\r\n', '{\n  \"args\": {},\n  \"headers\": {\n    \"Accept\": [\n      \"*/*\"\n    ],\n    \"Accept-Encoding\": [\n      \"gzip\"\n    ],\n    \"Content-Length\": [\n      \"31\"\n    ],\n    \"Content-Type\": [\n      \"application/json\"\n    ],\n    \"Host\": [\n      \"httpbin.dev\"\n    ],\n    \"User-Agent\": [\n      \"UptimeMonitor/1.0\"\n    ]\n  },\n  \"origin\": \"10.0.18.243\",\n  \"url\": \"http://httpbin.dev/post\",\n  \"method\": \"POST\",\n  \"data\": \"{\\\"key1\\\": \\\"tes\\\", \\\"key2\\\": \\\"tes2\\\"}\",\n  \"files\": null,\n  \"form\": null,\n  \"json\": {\n    \"key1\": \"tes\",\n    \"key2\": \"tes2\"\n  }\n}\n', 1, NULL);

-- --------------------------------------------------------

--
-- Stand-in structure for view `check_with_categories`
-- (See below for the actual view)
--
CREATE TABLE `check_with_categories` (
`id` int(11)
,`name` varchar(255)
,`category_id` int(11)
,`url` text
,`method` enum('GET','POST')
,`request_body` text
,`request_headers` text
,`expected_status` int(11)
,`expected_headers` text
,`expected_body` text
,`interval_seconds` int(11)
,`timeout_seconds` int(11)
,`max_redirects` int(11)
,`alert_emails` text
,`enabled` tinyint(1)
,`keep_response_data` tinyint(1)
,`last_state` enum('UP','DOWN')
,`created_at` timestamp
,`updated_at` timestamp
,`next_run_at` datetime
,`category_ids` mediumtext
,`category_names` mediumtext
,`category_colors` mediumtext
);

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

--
-- Dumping data for table `incidents`
--

INSERT INTO `incidents` (`id`, `check_id`, `started_at`, `ended_at`, `opened_by_result_id`, `closed_by_result_id`, `status`) VALUES
(2, 5, '2025-08-27 19:42:16', '2025-08-27 20:05:19', 20, 31, 'CLOSED');

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
(1, '001_initial_schema', '2025-08-27 07:38:49'),
(2, '002_add_body_validation_and_keep_data', '2025-08-27 12:16:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `name` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `name`, `password_hash`, `created_at`) VALUES
(2, 'admin@gmail.com', 'admin', 'asdasdasdas', '2025-08-27 08:15:55');

-- --------------------------------------------------------

--
-- Structure for view `check_with_categories`
--
DROP TABLE IF EXISTS `check_with_categories`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `check_with_categories`  AS SELECT `c`.`id` AS `id`, `c`.`name` AS `name`, `c`.`category_id` AS `category_id`, `c`.`url` AS `url`, `c`.`method` AS `method`, `c`.`request_body` AS `request_body`, `c`.`request_headers` AS `request_headers`, `c`.`expected_status` AS `expected_status`, `c`.`expected_headers` AS `expected_headers`, `c`.`expected_body` AS `expected_body`, `c`.`interval_seconds` AS `interval_seconds`, `c`.`timeout_seconds` AS `timeout_seconds`, `c`.`max_redirects` AS `max_redirects`, `c`.`alert_emails` AS `alert_emails`, `c`.`enabled` AS `enabled`, `c`.`keep_response_data` AS `keep_response_data`, `c`.`last_state` AS `last_state`, `c`.`created_at` AS `created_at`, `c`.`updated_at` AS `updated_at`, `c`.`next_run_at` AS `next_run_at`, group_concat(`cat`.`id` order by `cat`.`name` ASC separator ',') AS `category_ids`, group_concat(`cat`.`name` order by `cat`.`name` ASC separator ', ') AS `category_names`, group_concat(`cat`.`color` order by `cat`.`name` ASC separator ',') AS `category_colors` FROM ((`checks` `c` left join `check_categories` `cc` on(`c`.`id` = `cc`.`check_id`)) left join `categories` `cat` on(`cc`.`category_id` = `cat`.`id`)) GROUP BY `c`.`id` ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `checks`
--
ALTER TABLE `checks`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_enabled_next_run` (`enabled`,`next_run_at`),
  ADD KEY `idx_last_state` (`last_state`),
  ADD KEY `idx_category` (`category_id`);

--
-- Indexes for table `check_categories`
--
ALTER TABLE `check_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_check_category` (`check_id`,`category_id`),
  ADD KEY `idx_check_id` (`check_id`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_check_categories_compound` (`category_id`,`check_id`);

--
-- Indexes for table `check_results`
--
ALTER TABLE `check_results`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_check_started` (`check_id`,`started_at`),
  ADD KEY `idx_started_at` (`started_at`),
  ADD KEY `idx_is_up` (`is_up`),
  ADD KEY `idx_check_is_up` (`check_id`,`is_up`);

--
-- Indexes for table `incidents`
--
ALTER TABLE `incidents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_check_status` (`check_id`,`status`),
  ADD KEY `incidents_ibfk_2` (`opened_by_result_id`),
  ADD KEY `incidents_ibfk_3` (`closed_by_result_id`);

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
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `checks`
--
ALTER TABLE `checks`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `check_categories`
--
ALTER TABLE `check_categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `check_results`
--
ALTER TABLE `check_results`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=94;

--
-- AUTO_INCREMENT for table `incidents`
--
ALTER TABLE `incidents`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `migrations`
--
ALTER TABLE `migrations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `checks`
--
ALTER TABLE `checks`
  ADD CONSTRAINT `checks_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `check_categories`
--
ALTER TABLE `check_categories`
  ADD CONSTRAINT `fk_check_categories_category` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_check_categories_check` FOREIGN KEY (`check_id`) REFERENCES `checks` (`id`) ON DELETE CASCADE;

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
  ADD CONSTRAINT `incidents_ibfk_2` FOREIGN KEY (`opened_by_result_id`) REFERENCES `check_results` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `incidents_ibfk_3` FOREIGN KEY (`closed_by_result_id`) REFERENCES `check_results` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
