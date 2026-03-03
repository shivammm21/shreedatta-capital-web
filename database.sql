-- phpMyAdmin SQL Dump
-- Generation Time: Project Base Schema
-- Server version: 10.4.x (MySQL/MariaDB)

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+05:30";

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `u497309930_shreedatta`
--
CREATE DATABASE IF NOT EXISTS `u497309930_shreedatta` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `u497309930_shreedatta`;

-- --------------------------------------------------------

--
-- Table structure for table `admin`
--

CREATE TABLE IF NOT EXISTS `admin` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `type` varchar(50) NOT NULL DEFAULT 'SUB',
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `admin`
--

INSERT IGNORE INTO `admin` (`id`, `username`, `password`, `type`) VALUES
(1, 'superadmin', 'password', 'SUPER');

-- --------------------------------------------------------

--
-- Table structure for table `all-submissions`
--

CREATE TABLE IF NOT EXISTS `all-submissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `forms_aggri_id` int(11) DEFAULT NULL,
  `form_data_id` int(11) DEFAULT NULL,
  `first_name` varchar(255) DEFAULT NULL,
  `last_name` varchar(255) DEFAULT NULL,
  `mobileno` varchar(50) DEFAULT NULL,
  `language` varchar(10) DEFAULT 'en',
  `token_no` varchar(100) DEFAULT NULL,
  `draw_name` varchar(255) DEFAULT NULL,
  `date_time` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `forms_aggri_id` (`forms_aggri_id`),
  KEY `form_data_id` (`form_data_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forms_aggri`
--

CREATE TABLE IF NOT EXISTS `forms_aggri` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `catogory_id` int(11) DEFAULT NULL,
  `draw_names` text DEFAULT NULL,
  `languages` text DEFAULT NULL,
  `form_name` varchar(255) DEFAULT NULL,
  `form_link` varchar(255) DEFAULT NULL,
  `startDate` datetime DEFAULT NULL,
  `endDate` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `forms_data`
--

CREATE TABLE IF NOT EXISTS `forms_data` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `category` varchar(255) DEFAULT NULL,
  `catogory` varchar(255) DEFAULT NULL,
  `forms-aggri` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_docs`
--

CREATE TABLE IF NOT EXISTS `user_docs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `live_photo` LONGBLOB DEFAULT NULL,
  `front_addhar` LONGBLOB DEFAULT NULL,
  `back_addhar` LONGBLOB DEFAULT NULL,
  `front_adddhar` LONGBLOB DEFAULT NULL,
  `back_adddhar` LONGBLOB DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Constraints for dumped tables
--

-- Enable foreign keys if required; skipped for maximum compatibility 
-- due to lack of explicit definition.

COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
