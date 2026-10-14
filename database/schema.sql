-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: csc_system
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `app_settings`
--

DROP TABLE IF EXISTS `app_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `app_settings` (
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text NOT NULL,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `attendance_records`
--

DROP TABLE IF EXISTS `attendance_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `attendance_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `officer_id` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `position` varchar(100) NOT NULL,
  `log_date` date NOT NULL,
  `time_in` datetime DEFAULT NULL,
  `time_out` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `officer_id` (`officer_id`),
  CONSTRAINT `attendance_records_ibfk_1` FOREIGN KEY (`officer_id`) REFERENCES `officers` (`officer_id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecopuntos_balance`
--

DROP TABLE IF EXISTS `ecopuntos_balance`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecopuntos_balance` (
  `student_no` varchar(30) NOT NULL,
  `total_points` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`student_no`),
  CONSTRAINT `ecopuntos_balance_ibfk_1` FOREIGN KEY (`student_no`) REFERENCES `students` (`student_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecopuntos_records`
--

DROP TABLE IF EXISTS `ecopuntos_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecopuntos_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_no` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `program` varchar(100) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `bottles_count` int(11) NOT NULL DEFAULT 0,
  `points_earned` int(11) NOT NULL DEFAULT 0,
  `reward_item` varchar(100) DEFAULT NULL,
  `points_redeemed` int(11) NOT NULL DEFAULT 0,
  `date_time` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `student_no` (`student_no`),
  CONSTRAINT `ecopuntos_records_ibfk_1` FOREIGN KEY (`student_no`) REFERENCES `students` (`student_no`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ecopuntos_rewards`
--

DROP TABLE IF EXISTS `ecopuntos_rewards`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ecopuntos_rewards` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(100) NOT NULL,
  `points_cost` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `import_log`
--

DROP TABLE IF EXISTS `import_log`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `import_log` (
  `import_id` int(11) NOT NULL AUTO_INCREMENT,
  `filename` varchar(255) NOT NULL,
  `imported_by` varchar(100) NOT NULL,
  `imported_at` datetime NOT NULL DEFAULT current_timestamp(),
  `total_rows` int(11) NOT NULL DEFAULT 0,
  `added` int(11) NOT NULL DEFAULT 0,
  `updated` int(11) NOT NULL DEFAULT 0,
  `skipped` int(11) NOT NULL DEFAULT 0,
  `rejected` int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (`import_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `iskolend_borrow`
--

DROP TABLE IF EXISTS `iskolend_borrow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `iskolend_borrow` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_no` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `program` varchar(100) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `item_id` int(11) NOT NULL,
  `item_name` varchar(150) NOT NULL,
  `qty_borrowed` int(11) NOT NULL DEFAULT 1,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `date_borrowed` datetime DEFAULT current_timestamp(),
  `due_date` date DEFAULT NULL,
  `officer` varchar(100) DEFAULT NULL,
  `date_returned` datetime DEFAULT NULL,
  `condition_on_return` varchar(50) DEFAULT NULL,
  `status` enum('Borrowed','Returned') NOT NULL DEFAULT 'Borrowed',
  PRIMARY KEY (`id`),
  KEY `student_no` (`student_no`),
  KEY `item_id` (`item_id`),
  CONSTRAINT `iskolend_borrow_ibfk_1` FOREIGN KEY (`student_no`) REFERENCES `students` (`student_no`),
  CONSTRAINT `iskolend_borrow_ibfk_2` FOREIGN KEY (`item_id`) REFERENCES `iskolend_items` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `iskolend_items`
--

DROP TABLE IF EXISTS `iskolend_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `iskolend_items` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `item_name` varchar(150) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `image_path` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `total_qty` int(11) NOT NULL DEFAULT 0,
  `available_qty` int(11) NOT NULL DEFAULT 0,
  `borrow_days` int(11) NOT NULL DEFAULT 7,
  `date_added` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `item_categories`
--

DROP TABLE IF EXISTS `item_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `item_categories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category_name` varchar(100) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `category_name` (`category_name`)
) ENGINE=InnoDB AUTO_INCREMENT=3365 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `officer_events`
--

DROP TABLE IF EXISTS `officer_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `officer_events` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(200) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `event_type` enum('meeting','event','agenda','deadline','other') NOT NULL DEFAULT 'meeting',
  `description` text DEFAULT NULL,
  `start_at` datetime NOT NULL,
  `end_at` datetime DEFAULT NULL,
  `all_day` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`event_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `officers`
--

DROP TABLE IF EXISTS `officers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `officers` (
  `officer_id` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `position` varchar(100) NOT NULL,
  `birthday` date DEFAULT NULL,
  `date_registered` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`officer_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `printan_expenses`
--

DROP TABLE IF EXISTS `printan_expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `printan_expenses` (
  `expense_id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `recorded_by` varchar(100) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`expense_id`),
  KEY `idx_printan_expenses_date` (`expense_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `printan_records`
--

DROP TABLE IF EXISTS `printan_records`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `printan_records` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_no` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `program` varchar(100) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `is_colored` tinyint(1) NOT NULL DEFAULT 0,
  `own_paper` tinyint(1) NOT NULL DEFAULT 0,
  `back_to_back` tinyint(1) NOT NULL DEFAULT 0,
  `num_pages` int(11) NOT NULL DEFAULT 1,
  `rate_per_page` decimal(6,2) NOT NULL DEFAULT 0.00,
  `amount_paid` decimal(8,2) NOT NULL DEFAULT 0.00,
  `date_time` datetime DEFAULT current_timestamp(),
  `service_type` enum('print','xerox','scan') NOT NULL DEFAULT 'print',
  `copies` int(11) NOT NULL DEFAULT 1,
  `reason` text DEFAULT NULL,
  `officer` varchar(100) NOT NULL DEFAULT '',
  `status` enum('completed','pending','cancelled') NOT NULL DEFAULT 'completed',
  PRIMARY KEY (`id`),
  KEY `student_no` (`student_no`),
  CONSTRAINT `printan_records_ibfk_1` FOREIGN KEY (`student_no`) REFERENCES `students` (`student_no`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `student_blacklist`
--

DROP TABLE IF EXISTS `student_blacklist`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_blacklist` (
  `blacklist_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(30) NOT NULL,
  `reason` text NOT NULL,
  `offense_type` enum('unpaid_fee','item_damage_or_loss','overdue_items','misconduct','other') NOT NULL DEFAULT 'other',
  `blacklisted_by` varchar(100) DEFAULT NULL,
  `blacklisted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `lifted_at` datetime DEFAULT NULL,
  `lifted_by` varchar(100) DEFAULT NULL,
  `lift_note` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`blacklist_id`),
  UNIQUE KEY `blacklist_id` (`blacklist_id`),
  KEY `idx_student_blacklist_active` (`student_number`,`is_active`,`blacklisted_at`),
  CONSTRAINT `fk_student_blacklist_student` FOREIGN KEY (`student_number`) REFERENCES `students` (`student_no`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_no` varchar(30) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `program` varchar(100) NOT NULL,
  `year_level` varchar(20) NOT NULL,
  `section` varchar(20) NOT NULL DEFAULT '',
  `contact_number` varchar(30) NOT NULL DEFAULT '',
  `date_registered` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`student_no`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `full_name` varchar(150) NOT NULL,
  `role` varchar(50) DEFAULT 'Officer',
  `date_created` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping routines for database 'csc_system'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-10-14 15:01:46
