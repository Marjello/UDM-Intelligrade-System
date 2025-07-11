-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: udm_class_record_db
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
-- Current Database: `udm_class_record_db`
--

CREATE DATABASE /*!32312 IF NOT EXISTS*/ `udm_class_record_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci */;

USE `udm_class_record_db`;

--
-- Table structure for table `backup_history`
--

DROP TABLE IF EXISTS `backup_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `backup_history` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) NOT NULL,
  `action_timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `action_type` enum('export','import') NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `message` text DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `action_timestamp` (`action_timestamp`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `backup_history`
--

LOCK TABLES `backup_history` WRITE;
/*!40000 ALTER TABLE `backup_history` DISABLE KEYS */;
INSERT INTO `backup_history` VALUES (1,3,'2025-05-28 19:47:52','export','backup_udm_class_record_db_2025-05-28_21-47-52.sql','success','Database successfully exported to Google Drive: G:/My Drive/classrecorddb/backup_udm_class_record_db_2025-05-28_21-47-52.sql'),(2,3,'2025-05-28 19:48:28','import','backup_udm_class_record_db_2025-05-28_21-47-52.sql','success','Database imported successfully.'),(3,3,'2025-05-28 19:48:32','import','backup_udm_class_record_db_2025-05-28_21-47-52.sql','success','Database imported successfully.'),(4,3,'2025-05-29 15:52:14','import','backup_udm_class_record_db_2025-05-29_17-13-42.sql','success','Database imported successfully.'),(5,3,'2025-05-29 18:22:33','import','backup_udm_class_record_db_2025-05-29_17-13-42.sql','success','Database imported successfully.'),(6,3,'2025-05-29 18:22:37','import','backup_udm_class_record_db_2025-05-29_17-13-42.sql','success','Database imported successfully.'),(7,3,'2025-05-30 02:25:48','export','N/A','failed','Google Drive folder not found. Please ensure it is synced locally.'),(8,3,'2025-05-30 02:26:08','export','N/A','failed','Google Drive folder not found. Please ensure it is synced locally.'),(9,3,'2025-05-30 02:26:50','export','backup_udm_class_record_db_2025-05-30_04-26-50.sql','success','Database successfully exported to Google Drive: G:/My Drive/classrecorddb/backup_udm_class_record_db_2025-05-30_04-26-50.sql'),(10,3,'2025-06-01 15:25:51','export','N/A','failed','Google Drive folder not found. Please ensure it is synced locally.');
/*!40000 ALTER TABLE `backup_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `calculated_period_grades`
--

DROP TABLE IF EXISTS `calculated_period_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `calculated_period_grades` (
  `period_grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `period` enum('Preliminary','Mid-Term','Pre-Final') DEFAULT NULL,
  `period_class_standing_grade` decimal(5,2) DEFAULT NULL,
  `period_examination_grade` decimal(5,2) DEFAULT NULL,
  `total_period_grade` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`period_grade_id`),
  UNIQUE KEY `enrollment_id` (`enrollment_id`,`class_id`,`period`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `calculated_period_grades`
--

LOCK TABLES `calculated_period_grades` WRITE;
/*!40000 ALTER TABLE `calculated_period_grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `calculated_period_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `class_calendar_notes`
--

DROP TABLE IF EXISTS `class_calendar_notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `class_calendar_notes` (
  `calendar_note_id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `calendar_note_date` date NOT NULL,
  `calendar_note_title` varchar(255) NOT NULL,
  `calendar_note_description` text DEFAULT NULL,
  `calendar_note_type` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`calendar_note_id`),
  KEY `class_id` (`class_id`),
  KEY `teacher_id` (`teacher_id`),
  CONSTRAINT `class_calendar_notes_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE,
  CONSTRAINT `class_calendar_notes_ibfk_2` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `class_calendar_notes`
--

LOCK TABLES `class_calendar_notes` WRITE;
/*!40000 ALTER TABLE `class_calendar_notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `class_calendar_notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `classes`
--

DROP TABLE IF EXISTS `classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `classes` (
  `class_id` int(11) NOT NULL AUTO_INCREMENT,
  `teacher_id` int(11) DEFAULT NULL,
  `subject_id` int(11) DEFAULT NULL,
  `section_id` int(11) DEFAULT NULL,
  `grading_system_type` enum('numerical','final_only_numerical') DEFAULT 'numerical',
  PRIMARY KEY (`class_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `subject_id` (`subject_id`),
  KEY `section_id` (`section_id`),
  CONSTRAINT `classes_ibfk_1` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`),
  CONSTRAINT `classes_ibfk_2` FOREIGN KEY (`subject_id`) REFERENCES `subjects` (`subject_id`),
  CONSTRAINT `classes_ibfk_3` FOREIGN KEY (`section_id`) REFERENCES `sections` (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `classes`
--

LOCK TABLES `classes` WRITE;
/*!40000 ALTER TABLE `classes` DISABLE KEYS */;
INSERT INTO `classes` VALUES (4,2,3,4,'final_only_numerical'),(5,2,3,3,'numerical'),(6,3,4,6,'final_only_numerical'),(10,1,4,6,'final_only_numerical'),(11,1,6,8,'numerical'),(13,3,5,6,'numerical'),(15,3,4,7,''),(16,3,6,8,'numerical'),(17,3,7,9,'numerical');
/*!40000 ALTER TABLE `classes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `enrollments`
--

DROP TABLE IF EXISTS `enrollments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `enrollments` (
  `enrollment_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`enrollment_id`),
  UNIQUE KEY `student_id` (`student_id`,`class_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `enrollments_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`),
  CONSTRAINT `enrollments_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=880 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `enrollments`
--

LOCK TABLES `enrollments` WRITE;
/*!40000 ALTER TABLE `enrollments` DISABLE KEYS */;
INSERT INTO `enrollments` VALUES (165,1,4),(276,1,6),(498,1,10),(826,1,17),(166,2,4),(277,2,6),(499,2,10),(827,2,17),(167,3,4),(278,3,6),(500,3,10),(828,3,17),(168,4,4),(279,4,6),(501,4,10),(829,4,17),(169,5,4),(280,5,6),(502,5,10),(830,5,17),(170,6,4),(281,6,6),(503,6,10),(831,6,17),(171,7,4),(282,7,6),(504,7,10),(832,7,17),(172,8,4),(283,8,6),(505,8,10),(833,8,17),(173,9,4),(284,9,6),(506,9,10),(834,9,17),(174,10,4),(285,10,6),(507,10,10),(835,10,17),(175,11,4),(286,11,6),(508,11,10),(836,11,17),(176,12,4),(287,12,6),(509,12,10),(837,12,17),(177,13,4),(288,13,6),(510,13,10),(838,13,17),(178,14,4),(289,14,6),(511,14,10),(839,14,17),(179,15,4),(290,15,6),(512,15,10),(840,15,17),(180,16,4),(291,16,6),(513,16,10),(841,16,17),(181,17,4),(292,17,6),(514,17,10),(842,17,17),(182,18,4),(293,18,6),(515,18,10),(843,18,17),(183,19,4),(294,19,6),(516,19,10),(844,19,17),(184,20,4),(295,20,6),(517,20,10),(845,20,17),(185,21,4),(296,21,6),(518,21,10),(846,21,17),(186,22,4),(297,22,6),(519,22,10),(847,22,17),(187,23,4),(298,23,6),(520,23,10),(848,23,17),(188,24,4),(299,24,6),(521,24,10),(849,24,17),(189,25,4),(300,25,6),(522,25,10),(850,25,17),(190,26,4),(301,26,6),(523,26,10),(851,26,17),(191,27,4),(302,27,6),(524,27,10),(852,27,17),(192,28,4),(303,28,6),(525,28,10),(853,28,17),(193,29,4),(304,29,6),(526,29,10),(854,29,17),(194,30,4),(305,30,6),(527,30,10),(855,30,17),(195,31,4),(306,31,6),(528,31,10),(856,31,17),(196,32,4),(307,32,6),(529,32,10),(857,32,17),(197,33,4),(308,33,6),(530,33,10),(858,33,17),(198,34,4),(309,34,6),(531,34,10),(859,34,17),(199,35,4),(310,35,6),(532,35,10),(860,35,17),(200,36,4),(311,36,6),(533,36,10),(861,36,17),(201,37,4),(312,37,6),(534,37,10),(862,37,17),(202,38,4),(313,38,6),(535,38,10),(863,38,17),(203,39,4),(314,39,6),(536,39,10),(662,39,13),(864,39,17),(204,40,4),(315,40,6),(537,40,10),(865,40,17),(205,41,4),(316,41,6),(538,41,10),(866,41,17),(206,42,4),(317,42,6),(539,42,10),(867,42,17),(207,43,4),(318,43,6),(540,43,10),(868,43,17),(208,44,4),(319,44,6),(541,44,10),(869,44,17),(209,45,4),(320,45,6),(542,45,10),(870,45,17),(210,46,4),(321,46,6),(543,46,10),(871,46,17),(211,47,4),(322,47,6),(544,47,10),(872,47,17),(212,48,4),(323,48,6),(545,48,10),(873,48,17),(213,49,4),(324,49,6),(546,49,10),(874,49,17),(214,50,4),(325,50,6),(547,50,10),(875,50,17),(215,51,4),(326,51,6),(548,51,10),(876,51,17),(216,52,4),(327,52,6),(549,52,10),(877,52,17),(217,53,4),(328,53,6),(550,53,10),(878,53,17),(218,54,4),(329,54,6),(551,54,10),(879,54,17),(716,55,15),(717,56,15),(718,57,15),(719,58,15),(720,59,15),(721,60,15),(722,61,15),(723,62,15),(724,63,15),(725,64,15),(726,65,15),(727,66,15),(728,67,15),(729,68,15),(730,69,15),(731,70,15),(732,71,15),(733,72,15),(734,73,15),(735,74,15),(736,75,15),(737,76,15),(738,77,15),(739,78,15),(740,79,15),(741,80,15),(742,81,15),(743,82,15),(744,83,15),(745,84,15),(746,85,15),(747,86,15),(748,87,15),(749,88,15),(750,89,15),(751,90,15),(752,91,15),(753,92,15),(754,93,15),(755,94,15),(756,95,15),(757,96,15),(758,97,15),(759,98,15),(760,99,15),(761,100,15),(762,101,15),(763,102,15),(764,103,15),(765,104,15),(766,105,15),(767,106,15),(768,107,15),(219,108,5),(552,108,11),(769,108,16),(220,109,5),(553,109,11),(770,109,16),(221,110,5),(554,110,11),(771,110,16),(222,111,5),(555,111,11),(772,111,16),(223,112,5),(556,112,11),(773,112,16),(224,113,5),(557,113,11),(774,113,16),(225,114,5),(558,114,11),(775,114,16),(226,115,5),(559,115,11),(776,115,16),(227,116,5),(560,116,11),(777,116,16),(228,117,5),(561,117,11),(778,117,16),(229,118,5),(562,118,11),(779,118,16),(230,119,5),(563,119,11),(780,119,16),(231,120,5),(564,120,11),(781,120,16),(232,121,5),(565,121,11),(782,121,16),(233,122,5),(566,122,11),(783,122,16),(234,123,5),(567,123,11),(784,123,16),(235,124,5),(568,124,11),(785,124,16),(236,125,5),(569,125,11),(786,125,16),(237,126,5),(570,126,11),(787,126,16),(238,127,5),(571,127,11),(788,127,16),(239,128,5),(572,128,11),(789,128,16),(240,129,5),(573,129,11),(790,129,16),(241,130,5),(574,130,11),(791,130,16),(242,131,5),(575,131,11),(792,131,16),(243,132,5),(576,132,11),(793,132,16),(244,133,5),(577,133,11),(794,133,16),(245,134,5),(578,134,11),(795,134,16),(246,135,5),(579,135,11),(796,135,16),(247,136,5),(580,136,11),(797,136,16),(248,137,5),(581,137,11),(798,137,16),(249,138,5),(582,138,11),(799,138,16),(250,139,5),(583,139,11),(800,139,16),(251,140,5),(584,140,11),(801,140,16),(252,141,5),(585,141,11),(802,141,16),(253,142,5),(586,142,11),(803,142,16),(254,143,5),(587,143,11),(804,143,16),(255,144,5),(588,144,11),(805,144,16),(256,145,5),(589,145,11),(806,145,16),(257,146,5),(590,146,11),(807,146,16),(258,147,5),(591,147,11),(808,147,16),(259,148,5),(592,148,11),(809,148,16),(260,149,5),(593,149,11),(810,149,16),(261,150,5),(594,150,11),(811,150,16),(262,151,5),(595,151,11),(812,151,16),(263,152,5),(596,152,11),(813,152,16),(264,153,5),(597,153,11),(814,153,16),(265,154,5),(598,154,11),(815,154,16),(266,155,5),(599,155,11),(816,155,16),(267,156,5),(600,156,11),(817,156,16),(268,157,5),(601,157,11),(818,157,16),(269,158,5),(602,158,11),(819,158,16),(270,159,5),(603,159,11),(820,159,16),(271,160,5),(604,160,11),(821,160,16),(272,161,5),(605,161,11),(822,161,16),(273,162,5),(606,162,11),(823,162,16),(274,163,5),(607,163,11),(824,163,16),(275,164,5),(608,164,11),(825,164,16);
/*!40000 ALTER TABLE `enrollments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `final_grades`
--

DROP TABLE IF EXISTS `final_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `final_grades` (
  `final_grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) DEFAULT NULL,
  `class_id` int(11) DEFAULT NULL,
  `overall_final_grade` decimal(5,2) DEFAULT NULL,
  `remarks` varchar(20) DEFAULT NULL,
  `final_change_timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`final_grade_id`),
  UNIQUE KEY `enrollment_id` (`enrollment_id`,`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=721 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `final_grades`
--

LOCK TABLES `final_grades` WRITE;
/*!40000 ALTER TABLE `final_grades` DISABLE KEYS */;
INSERT INTO `final_grades` VALUES (715,276,6,80.00,'Satisfactory','2025-05-30 01:13:10');
/*!40000 ALTER TABLE `final_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grade_components`
--

DROP TABLE IF EXISTS `grade_components`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_components` (
  `component_id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) DEFAULT NULL,
  `component_name` varchar(100) DEFAULT NULL,
  `period` enum('Preliminary','Mid-Term','Pre-Final') DEFAULT NULL,
  `type` enum('Class Standing','Examination','Attendance','Quiz','Exam','Assignment','Project','Recitation','Participation','Other') DEFAULT NULL,
  `max_score` decimal(5,2) DEFAULT NULL,
  `is_attendance_based` tinyint(1) DEFAULT 0,
  `is_locked` tinyint(1) DEFAULT 0,
  `weight` decimal(5,2) DEFAULT 0.00,
  PRIMARY KEY (`component_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `grade_components_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`)
) ENGINE=InnoDB AUTO_INCREMENT=72 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_components`
--

LOCK TABLES `grade_components` WRITE;
/*!40000 ALTER TABLE `grade_components` DISABLE KEYS */;
INSERT INTO `grade_components` VALUES (15,4,'Attendance - Preliminary','Preliminary','',0.00,1,0,0.00),(16,4,'Attendance - Mid-Term','Mid-Term','',0.00,1,0,0.00),(44,6,'Prelim','Preliminary','',0.00,1,0,0.00),(45,6,'Midterm','Mid-Term','',0.00,1,0,0.00),(46,6,'Final','Pre-Final','Class Standing',100.00,0,0,100.00),(51,13,'Quiz 1','Preliminary','Quiz',100.00,0,0,50.00),(52,13,'Quiz 2','Mid-Term','Quiz',100.00,0,0,50.00),(53,13,'Quiz 3','Pre-Final','Quiz',100.00,0,0,50.00),(57,15,'Attendance - Preliminary','Preliminary','',0.00,1,0,0.00),(58,15,'Attendance - Mid-Term','Mid-Term','',0.00,1,1,0.00),(59,15,'Pre-Final Grade','Pre-Final','',100.00,0,1,0.00),(60,16,'Quiz 1','Preliminary','Quiz',100.00,0,0,100.00),(61,16,'Prelim Exam','Preliminary','Exam',100.00,0,0,100.00),(62,17,'dsaasd','Preliminary','Participation',100.00,1,0,100.00),(63,11,'Quiz 1','Preliminary','Quiz',100.00,0,0,30.00),(64,10,'Prelim','Preliminary','Attendance',0.00,1,0,0.00),(65,10,'Midterm','Mid-Term','Attendance',0.00,1,1,0.00),(66,10,'Final','Pre-Final','Class Standing',100.00,0,1,100.00),(67,11,'prelims exam','Preliminary','Exam',100.00,0,0,100.00),(68,11,'midterms exam','Mid-Term','Exam',100.00,0,0,90.00),(69,11,'Finals Exam','Pre-Final','Exam',100.00,0,0,100.00),(70,11,'Quiz1','Mid-Term','Quiz',100.00,0,0,100.00),(71,11,'Quiz 3','Pre-Final','Quiz',100.00,0,0,100.00);
/*!40000 ALTER TABLE `grade_components` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grade_history`
--

DROP TABLE IF EXISTS `grade_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_history` (
  `history_id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `enrollment_id` int(11) NOT NULL,
  `teacher_id` int(11) NOT NULL,
  `grade_type` varchar(100) NOT NULL,
  `old_value` varchar(255) DEFAULT NULL,
  `new_value` varchar(255) NOT NULL,
  `change_timestamp` datetime DEFAULT current_timestamp(),
  `component_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`history_id`),
  KEY `class_id` (`class_id`),
  KEY `enrollment_id` (`enrollment_id`),
  KEY `teacher_id` (`teacher_id`),
  KEY `fk_grade_history_component` (`component_id`),
  CONSTRAINT `fk_grade_history_component` FOREIGN KEY (`component_id`) REFERENCES `grade_components` (`component_id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_2` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`) ON DELETE CASCADE,
  CONSTRAINT `grade_history_ibfk_3` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`teacher_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3497 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_history`
--

LOCK TABLES `grade_history` WRITE;
/*!40000 ALTER TABLE `grade_history` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_history` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grade_weights`
--

DROP TABLE IF EXISTS `grade_weights`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grade_weights` (
  `weight_id` int(11) NOT NULL AUTO_INCREMENT,
  `class_id` int(11) NOT NULL,
  `component_name` varchar(100) NOT NULL,
  `weight` decimal(5,2) NOT NULL,
  PRIMARY KEY (`weight_id`),
  UNIQUE KEY `class_id` (`class_id`,`component_name`),
  CONSTRAINT `grade_weights_ibfk_1` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grade_weights`
--

LOCK TABLES `grade_weights` WRITE;
/*!40000 ALTER TABLE `grade_weights` DISABLE KEYS */;
/*!40000 ALTER TABLE `grade_weights` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `grades`
--

DROP TABLE IF EXISTS `grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `grades` (
  `grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `class_id` int(11) NOT NULL,
  `assignment_name` varchar(255) DEFAULT NULL,
  `grade` decimal(5,2) DEFAULT NULL,
  `a_na_grade` enum('A','NA') DEFAULT NULL,
  `grade_percentage` decimal(5,2) DEFAULT NULL,
  `midterm_grade` decimal(5,2) DEFAULT NULL,
  `final_grade` decimal(5,2) DEFAULT NULL,
  `category` varchar(100) DEFAULT NULL,
  `date_recorded` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`grade_id`),
  KEY `student_id` (`student_id`),
  KEY `class_id` (`class_id`),
  CONSTRAINT `grades_ibfk_1` FOREIGN KEY (`student_id`) REFERENCES `students` (`student_id`) ON DELETE CASCADE,
  CONSTRAINT `grades_ibfk_2` FOREIGN KEY (`class_id`) REFERENCES `classes` (`class_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `grades`
--

LOCK TABLES `grades` WRITE;
/*!40000 ALTER TABLE `grades` DISABLE KEYS */;
/*!40000 ALTER TABLE `grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `notes`
--

DROP TABLE IF EXISTS `notes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `notes` (
  `note_id` int(6) unsigned NOT NULL AUTO_INCREMENT,
  `note_content` text NOT NULL,
  `reg_date` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `teacher_id` int(11) NOT NULL,
  PRIMARY KEY (`note_id`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `notes`
--

LOCK TABLES `notes` WRITE;
/*!40000 ALTER TABLE `notes` DISABLE KEYS */;
/*!40000 ALTER TABLE `notes` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `email` varchar(100) DEFAULT NULL,
  `token` varchar(255) DEFAULT NULL,
  `expires_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `sections`
--

DROP TABLE IF EXISTS `sections`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sections` (
  `section_id` int(11) NOT NULL AUTO_INCREMENT,
  `section_name` varchar(50) DEFAULT NULL,
  `academic_year` varchar(20) DEFAULT NULL,
  `semester` varchar(20) DEFAULT NULL,
  PRIMARY KEY (`section_id`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `sections`
--

LOCK TABLES `sections` WRITE;
/*!40000 ALTER TABLE `sections` DISABLE KEYS */;
INSERT INTO `sections` VALUES (3,'BSIT-11','',''),(4,'COE-41','',''),(5,'COE-42','',''),(6,'COE-41','2024-2025','2nd Semester'),(7,'COE-42','2024-2025','2nd Semester'),(8,'BSIT-11','2024-2025','1st Semester'),(9,'dasdas','2024-2025','2nd Semester');
/*!40000 ALTER TABLE `sections` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_grades`
--

DROP TABLE IF EXISTS `student_grades`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_grades` (
  `grade_id` int(11) NOT NULL AUTO_INCREMENT,
  `enrollment_id` int(11) DEFAULT NULL,
  `component_id` int(11) DEFAULT NULL,
  `score` decimal(5,2) DEFAULT NULL,
  `attendance_status_prelim` enum('A','NA') DEFAULT NULL,
  `attendance_status_midterm` enum('A','NA') DEFAULT NULL,
  `attendance_status` varchar(10) DEFAULT NULL,
  PRIMARY KEY (`grade_id`),
  UNIQUE KEY `enrollment_id` (`enrollment_id`,`component_id`),
  KEY `component_id` (`component_id`),
  CONSTRAINT `student_grades_ibfk_1` FOREIGN KEY (`enrollment_id`) REFERENCES `enrollments` (`enrollment_id`),
  CONSTRAINT `student_grades_ibfk_2` FOREIGN KEY (`component_id`) REFERENCES `grade_components` (`component_id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=4305 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_grades`
--

LOCK TABLES `student_grades` WRITE;
/*!40000 ALTER TABLE `student_grades` DISABLE KEYS */;
INSERT INTO `student_grades` VALUES (3621,552,67,100.00,NULL,NULL,NULL),(3622,552,63,100.00,NULL,NULL,NULL),(3623,553,67,NULL,NULL,NULL,NULL),(3624,553,63,NULL,NULL,NULL,NULL),(3625,554,67,NULL,NULL,NULL,NULL),(3626,554,63,NULL,NULL,NULL,NULL),(3627,555,67,NULL,NULL,NULL,NULL),(3628,555,63,NULL,NULL,NULL,NULL),(3629,556,67,NULL,NULL,NULL,NULL),(3630,556,63,NULL,NULL,NULL,NULL),(3631,557,67,NULL,NULL,NULL,NULL),(3632,557,63,NULL,NULL,NULL,NULL),(3633,558,67,NULL,NULL,NULL,NULL),(3634,558,63,NULL,NULL,NULL,NULL),(3635,559,67,NULL,NULL,NULL,NULL),(3636,559,63,NULL,NULL,NULL,NULL),(3637,560,67,NULL,NULL,NULL,NULL),(3638,560,63,NULL,NULL,NULL,NULL),(3639,561,67,NULL,NULL,NULL,NULL),(3640,561,63,NULL,NULL,NULL,NULL),(3641,562,67,NULL,NULL,NULL,NULL),(3642,562,63,NULL,NULL,NULL,NULL),(3643,563,67,NULL,NULL,NULL,NULL),(3644,563,63,NULL,NULL,NULL,NULL),(3645,564,67,NULL,NULL,NULL,NULL),(3646,564,63,NULL,NULL,NULL,NULL),(3647,565,67,NULL,NULL,NULL,NULL),(3648,565,63,NULL,NULL,NULL,NULL),(3649,566,67,NULL,NULL,NULL,NULL),(3650,566,63,NULL,NULL,NULL,NULL),(3651,567,67,NULL,NULL,NULL,NULL),(3652,567,63,NULL,NULL,NULL,NULL),(3653,568,67,NULL,NULL,NULL,NULL),(3654,568,63,NULL,NULL,NULL,NULL),(3655,569,67,NULL,NULL,NULL,NULL),(3656,569,63,NULL,NULL,NULL,NULL),(3657,570,67,NULL,NULL,NULL,NULL),(3658,570,63,NULL,NULL,NULL,NULL),(3659,571,67,NULL,NULL,NULL,NULL),(3660,571,63,NULL,NULL,NULL,NULL),(3661,572,67,NULL,NULL,NULL,NULL),(3662,572,63,NULL,NULL,NULL,NULL),(3663,573,67,NULL,NULL,NULL,NULL),(3664,573,63,NULL,NULL,NULL,NULL),(3665,574,67,NULL,NULL,NULL,NULL),(3666,574,63,NULL,NULL,NULL,NULL),(3667,575,67,NULL,NULL,NULL,NULL),(3668,575,63,NULL,NULL,NULL,NULL),(3669,576,67,NULL,NULL,NULL,NULL),(3670,576,63,NULL,NULL,NULL,NULL),(3671,577,67,NULL,NULL,NULL,NULL),(3672,577,63,NULL,NULL,NULL,NULL),(3673,578,67,NULL,NULL,NULL,NULL),(3674,578,63,NULL,NULL,NULL,NULL),(3675,579,67,NULL,NULL,NULL,NULL),(3676,579,63,NULL,NULL,NULL,NULL),(3677,580,67,NULL,NULL,NULL,NULL),(3678,580,63,NULL,NULL,NULL,NULL),(3679,581,67,NULL,NULL,NULL,NULL),(3680,581,63,NULL,NULL,NULL,NULL),(3681,582,67,NULL,NULL,NULL,NULL),(3682,582,63,NULL,NULL,NULL,NULL),(3683,583,67,NULL,NULL,NULL,NULL),(3684,583,63,NULL,NULL,NULL,NULL),(3685,584,67,NULL,NULL,NULL,NULL),(3686,584,63,NULL,NULL,NULL,NULL),(3687,585,67,NULL,NULL,NULL,NULL),(3688,585,63,NULL,NULL,NULL,NULL),(3689,586,67,NULL,NULL,NULL,NULL),(3690,586,63,NULL,NULL,NULL,NULL),(3691,587,67,NULL,NULL,NULL,NULL),(3692,587,63,NULL,NULL,NULL,NULL),(3693,588,67,NULL,NULL,NULL,NULL),(3694,588,63,NULL,NULL,NULL,NULL),(3695,589,67,NULL,NULL,NULL,NULL),(3696,589,63,NULL,NULL,NULL,NULL),(3697,590,67,NULL,NULL,NULL,NULL),(3698,590,63,NULL,NULL,NULL,NULL),(3699,591,67,NULL,NULL,NULL,NULL),(3700,591,63,NULL,NULL,NULL,NULL),(3701,592,67,NULL,NULL,NULL,NULL),(3702,592,63,NULL,NULL,NULL,NULL),(3703,593,67,NULL,NULL,NULL,NULL),(3704,593,63,NULL,NULL,NULL,NULL),(3705,594,67,NULL,NULL,NULL,NULL),(3706,594,63,NULL,NULL,NULL,NULL),(3707,595,67,NULL,NULL,NULL,NULL),(3708,595,63,NULL,NULL,NULL,NULL),(3709,596,67,NULL,NULL,NULL,NULL),(3710,596,63,NULL,NULL,NULL,NULL),(3711,597,67,NULL,NULL,NULL,NULL),(3712,597,63,NULL,NULL,NULL,NULL),(3713,598,67,NULL,NULL,NULL,NULL),(3714,598,63,NULL,NULL,NULL,NULL),(3715,599,67,NULL,NULL,NULL,NULL),(3716,599,63,NULL,NULL,NULL,NULL),(3717,600,67,NULL,NULL,NULL,NULL),(3718,600,63,NULL,NULL,NULL,NULL),(3719,601,67,NULL,NULL,NULL,NULL),(3720,601,63,NULL,NULL,NULL,NULL),(3721,602,67,NULL,NULL,NULL,NULL),(3722,602,63,NULL,NULL,NULL,NULL),(3723,603,67,NULL,NULL,NULL,NULL),(3724,603,63,NULL,NULL,NULL,NULL),(3725,604,67,NULL,NULL,NULL,NULL),(3726,604,63,NULL,NULL,NULL,NULL),(3727,605,67,NULL,NULL,NULL,NULL),(3728,605,63,NULL,NULL,NULL,NULL),(3729,606,67,NULL,NULL,NULL,NULL),(3730,606,63,NULL,NULL,NULL,NULL),(3731,607,67,NULL,NULL,NULL,NULL),(3732,607,63,NULL,NULL,NULL,NULL),(3733,608,67,NULL,NULL,NULL,NULL),(3734,608,63,NULL,NULL,NULL,NULL),(3737,552,68,100.00,NULL,NULL,NULL),(3738,552,69,100.00,NULL,NULL,NULL),(3741,553,68,NULL,NULL,NULL,NULL),(3742,553,69,NULL,NULL,NULL,NULL),(3745,554,68,NULL,NULL,NULL,NULL),(3746,554,69,NULL,NULL,NULL,NULL),(3749,555,68,NULL,NULL,NULL,NULL),(3750,555,69,NULL,NULL,NULL,NULL),(3753,556,68,NULL,NULL,NULL,NULL),(3754,556,69,NULL,NULL,NULL,NULL),(3757,557,68,NULL,NULL,NULL,NULL),(3758,557,69,NULL,NULL,NULL,NULL),(3761,558,68,NULL,NULL,NULL,NULL),(3762,558,69,NULL,NULL,NULL,NULL),(3765,559,68,NULL,NULL,NULL,NULL),(3766,559,69,NULL,NULL,NULL,NULL),(3769,560,68,NULL,NULL,NULL,NULL),(3770,560,69,NULL,NULL,NULL,NULL),(3773,561,68,NULL,NULL,NULL,NULL),(3774,561,69,NULL,NULL,NULL,NULL),(3777,562,68,NULL,NULL,NULL,NULL),(3778,562,69,NULL,NULL,NULL,NULL),(3781,563,68,NULL,NULL,NULL,NULL),(3782,563,69,NULL,NULL,NULL,NULL),(3785,564,68,NULL,NULL,NULL,NULL),(3786,564,69,NULL,NULL,NULL,NULL),(3789,565,68,NULL,NULL,NULL,NULL),(3790,565,69,NULL,NULL,NULL,NULL),(3793,566,68,NULL,NULL,NULL,NULL),(3794,566,69,NULL,NULL,NULL,NULL),(3797,567,68,NULL,NULL,NULL,NULL),(3798,567,69,NULL,NULL,NULL,NULL),(3801,568,68,NULL,NULL,NULL,NULL),(3802,568,69,NULL,NULL,NULL,NULL),(3805,569,68,NULL,NULL,NULL,NULL),(3806,569,69,NULL,NULL,NULL,NULL),(3809,570,68,NULL,NULL,NULL,NULL),(3810,570,69,NULL,NULL,NULL,NULL),(3813,571,68,NULL,NULL,NULL,NULL),(3814,571,69,NULL,NULL,NULL,NULL),(3817,572,68,NULL,NULL,NULL,NULL),(3818,572,69,NULL,NULL,NULL,NULL),(3821,573,68,NULL,NULL,NULL,NULL),(3822,573,69,NULL,NULL,NULL,NULL),(3825,574,68,NULL,NULL,NULL,NULL),(3826,574,69,NULL,NULL,NULL,NULL),(3829,575,68,NULL,NULL,NULL,NULL),(3830,575,69,NULL,NULL,NULL,NULL),(3833,576,68,NULL,NULL,NULL,NULL),(3834,576,69,NULL,NULL,NULL,NULL),(3837,577,68,NULL,NULL,NULL,NULL),(3838,577,69,NULL,NULL,NULL,NULL),(3841,578,68,NULL,NULL,NULL,NULL),(3842,578,69,NULL,NULL,NULL,NULL),(3845,579,68,NULL,NULL,NULL,NULL),(3846,579,69,NULL,NULL,NULL,NULL),(3849,580,68,NULL,NULL,NULL,NULL),(3850,580,69,NULL,NULL,NULL,NULL),(3853,581,68,NULL,NULL,NULL,NULL),(3854,581,69,NULL,NULL,NULL,NULL),(3857,582,68,NULL,NULL,NULL,NULL),(3858,582,69,NULL,NULL,NULL,NULL),(3861,583,68,NULL,NULL,NULL,NULL),(3862,583,69,NULL,NULL,NULL,NULL),(3865,584,68,NULL,NULL,NULL,NULL),(3866,584,69,NULL,NULL,NULL,NULL),(3869,585,68,NULL,NULL,NULL,NULL),(3870,585,69,NULL,NULL,NULL,NULL),(3873,586,68,NULL,NULL,NULL,NULL),(3874,586,69,NULL,NULL,NULL,NULL),(3877,587,68,NULL,NULL,NULL,NULL),(3878,587,69,NULL,NULL,NULL,NULL),(3881,588,68,NULL,NULL,NULL,NULL),(3882,588,69,NULL,NULL,NULL,NULL),(3885,589,68,NULL,NULL,NULL,NULL),(3886,589,69,NULL,NULL,NULL,NULL),(3889,590,68,NULL,NULL,NULL,NULL),(3890,590,69,NULL,NULL,NULL,NULL),(3893,591,68,NULL,NULL,NULL,NULL),(3894,591,69,NULL,NULL,NULL,NULL),(3897,592,68,NULL,NULL,NULL,NULL),(3898,592,69,NULL,NULL,NULL,NULL),(3901,593,68,NULL,NULL,NULL,NULL),(3902,593,69,NULL,NULL,NULL,NULL),(3905,594,68,NULL,NULL,NULL,NULL),(3906,594,69,NULL,NULL,NULL,NULL),(3909,595,68,NULL,NULL,NULL,NULL),(3910,595,69,NULL,NULL,NULL,NULL),(3913,596,68,NULL,NULL,NULL,NULL),(3914,596,69,NULL,NULL,NULL,NULL),(3917,597,68,NULL,NULL,NULL,NULL),(3918,597,69,NULL,NULL,NULL,NULL),(3921,598,68,NULL,NULL,NULL,NULL),(3922,598,69,NULL,NULL,NULL,NULL),(3925,599,68,NULL,NULL,NULL,NULL),(3926,599,69,NULL,NULL,NULL,NULL),(3929,600,68,NULL,NULL,NULL,NULL),(3930,600,69,NULL,NULL,NULL,NULL),(3933,601,68,NULL,NULL,NULL,NULL),(3934,601,69,NULL,NULL,NULL,NULL),(3937,602,68,NULL,NULL,NULL,NULL),(3938,602,69,NULL,NULL,NULL,NULL),(3941,603,68,NULL,NULL,NULL,NULL),(3942,603,69,NULL,NULL,NULL,NULL),(3945,604,68,NULL,NULL,NULL,NULL),(3946,604,69,NULL,NULL,NULL,NULL),(3949,605,68,NULL,NULL,NULL,NULL),(3950,605,69,NULL,NULL,NULL,NULL),(3953,606,68,NULL,NULL,NULL,NULL),(3954,606,69,NULL,NULL,NULL,NULL),(3957,607,68,NULL,NULL,NULL,NULL),(3958,607,69,NULL,NULL,NULL,NULL),(3961,608,68,NULL,NULL,NULL,NULL),(3962,608,69,NULL,NULL,NULL,NULL),(3966,552,70,100.00,NULL,NULL,NULL),(3968,552,71,100.00,NULL,NULL,NULL),(3972,553,70,NULL,NULL,NULL,NULL),(3974,553,71,NULL,NULL,NULL,NULL),(3978,554,70,NULL,NULL,NULL,NULL),(3980,554,71,NULL,NULL,NULL,NULL),(3984,555,70,NULL,NULL,NULL,NULL),(3986,555,71,NULL,NULL,NULL,NULL),(3990,556,70,NULL,NULL,NULL,NULL),(3992,556,71,NULL,NULL,NULL,NULL),(3996,557,70,NULL,NULL,NULL,NULL),(3998,557,71,NULL,NULL,NULL,NULL),(4002,558,70,NULL,NULL,NULL,NULL),(4004,558,71,NULL,NULL,NULL,NULL),(4008,559,70,NULL,NULL,NULL,NULL),(4010,559,71,NULL,NULL,NULL,NULL),(4014,560,70,NULL,NULL,NULL,NULL),(4016,560,71,NULL,NULL,NULL,NULL),(4020,561,70,NULL,NULL,NULL,NULL),(4022,561,71,NULL,NULL,NULL,NULL),(4026,562,70,NULL,NULL,NULL,NULL),(4028,562,71,NULL,NULL,NULL,NULL),(4032,563,70,NULL,NULL,NULL,NULL),(4034,563,71,NULL,NULL,NULL,NULL),(4038,564,70,NULL,NULL,NULL,NULL),(4040,564,71,NULL,NULL,NULL,NULL),(4044,565,70,NULL,NULL,NULL,NULL),(4046,565,71,NULL,NULL,NULL,NULL),(4050,566,70,NULL,NULL,NULL,NULL),(4052,566,71,NULL,NULL,NULL,NULL),(4056,567,70,NULL,NULL,NULL,NULL),(4058,567,71,NULL,NULL,NULL,NULL),(4062,568,70,NULL,NULL,NULL,NULL),(4064,568,71,NULL,NULL,NULL,NULL),(4068,569,70,NULL,NULL,NULL,NULL),(4070,569,71,NULL,NULL,NULL,NULL),(4074,570,70,NULL,NULL,NULL,NULL),(4076,570,71,NULL,NULL,NULL,NULL),(4080,571,70,NULL,NULL,NULL,NULL),(4082,571,71,NULL,NULL,NULL,NULL),(4086,572,70,NULL,NULL,NULL,NULL),(4088,572,71,NULL,NULL,NULL,NULL),(4092,573,70,NULL,NULL,NULL,NULL),(4094,573,71,NULL,NULL,NULL,NULL),(4098,574,70,NULL,NULL,NULL,NULL),(4100,574,71,NULL,NULL,NULL,NULL),(4104,575,70,NULL,NULL,NULL,NULL),(4106,575,71,NULL,NULL,NULL,NULL),(4110,576,70,NULL,NULL,NULL,NULL),(4112,576,71,NULL,NULL,NULL,NULL),(4116,577,70,NULL,NULL,NULL,NULL),(4118,577,71,NULL,NULL,NULL,NULL),(4122,578,70,NULL,NULL,NULL,NULL),(4124,578,71,NULL,NULL,NULL,NULL),(4128,579,70,NULL,NULL,NULL,NULL),(4130,579,71,NULL,NULL,NULL,NULL),(4134,580,70,NULL,NULL,NULL,NULL),(4136,580,71,NULL,NULL,NULL,NULL),(4140,581,70,NULL,NULL,NULL,NULL),(4142,581,71,NULL,NULL,NULL,NULL),(4146,582,70,NULL,NULL,NULL,NULL),(4148,582,71,NULL,NULL,NULL,NULL),(4152,583,70,NULL,NULL,NULL,NULL),(4154,583,71,NULL,NULL,NULL,NULL),(4158,584,70,NULL,NULL,NULL,NULL),(4160,584,71,NULL,NULL,NULL,NULL),(4164,585,70,NULL,NULL,NULL,NULL),(4166,585,71,NULL,NULL,NULL,NULL),(4170,586,70,NULL,NULL,NULL,NULL),(4172,586,71,NULL,NULL,NULL,NULL),(4176,587,70,NULL,NULL,NULL,NULL),(4178,587,71,NULL,NULL,NULL,NULL),(4182,588,70,NULL,NULL,NULL,NULL),(4184,588,71,NULL,NULL,NULL,NULL),(4188,589,70,NULL,NULL,NULL,NULL),(4190,589,71,NULL,NULL,NULL,NULL),(4194,590,70,NULL,NULL,NULL,NULL),(4196,590,71,NULL,NULL,NULL,NULL),(4200,591,70,NULL,NULL,NULL,NULL),(4202,591,71,NULL,NULL,NULL,NULL),(4206,592,70,NULL,NULL,NULL,NULL),(4208,592,71,NULL,NULL,NULL,NULL),(4212,593,70,NULL,NULL,NULL,NULL),(4214,593,71,NULL,NULL,NULL,NULL),(4218,594,70,NULL,NULL,NULL,NULL),(4220,594,71,NULL,NULL,NULL,NULL),(4224,595,70,NULL,NULL,NULL,NULL),(4226,595,71,NULL,NULL,NULL,NULL),(4230,596,70,NULL,NULL,NULL,NULL),(4232,596,71,NULL,NULL,NULL,NULL),(4236,597,70,NULL,NULL,NULL,NULL),(4238,597,71,NULL,NULL,NULL,NULL),(4242,598,70,NULL,NULL,NULL,NULL),(4244,598,71,NULL,NULL,NULL,NULL),(4248,599,70,NULL,NULL,NULL,NULL),(4250,599,71,NULL,NULL,NULL,NULL),(4254,600,70,NULL,NULL,NULL,NULL),(4256,600,71,NULL,NULL,NULL,NULL),(4260,601,70,NULL,NULL,NULL,NULL),(4262,601,71,NULL,NULL,NULL,NULL),(4266,602,70,NULL,NULL,NULL,NULL),(4268,602,71,NULL,NULL,NULL,NULL),(4272,603,70,NULL,NULL,NULL,NULL),(4274,603,71,NULL,NULL,NULL,NULL),(4278,604,70,NULL,NULL,NULL,NULL),(4280,604,71,NULL,NULL,NULL,NULL),(4284,605,70,NULL,NULL,NULL,NULL),(4286,605,71,NULL,NULL,NULL,NULL),(4290,606,70,NULL,NULL,NULL,NULL),(4292,606,71,NULL,NULL,NULL,NULL),(4296,607,70,NULL,NULL,NULL,NULL),(4298,607,71,NULL,NULL,NULL,NULL),(4302,608,70,NULL,NULL,NULL,NULL),(4304,608,71,NULL,NULL,NULL,NULL);
/*!40000 ALTER TABLE `student_grades` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `students`
--

DROP TABLE IF EXISTS `students`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `students` (
  `student_id` int(11) NOT NULL AUTO_INCREMENT,
  `student_number` varchar(20) DEFAULT NULL,
  `last_name` varchar(50) DEFAULT NULL,
  `first_name` varchar(50) DEFAULT NULL,
  `middle_name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`student_id`),
  UNIQUE KEY `student_number` (`student_number`)
) ENGINE=InnoDB AUTO_INCREMENT=165 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `students`
--

LOCK TABLES `students` WRITE;
/*!40000 ALTER TABLE `students` DISABLE KEYS */;
INSERT INTO `students` VALUES (1,'21-14-001','ABESAMIS','KHEN',NULL),(2,'21-14-003','ALAMA','XANDER',NULL),(3,'21-14-097','ALDAVE','ALI LUIS',NULL),(4,'21-14-100','AQUINO','KIM MAYNARD',NULL),(5,'21-14-094','ARCEO','ELIZABETH',NULL),(6,'21-14-080','ARGUILLES','JOSHUA',NULL),(7,'21-14-102','ASUNCION','ISAIAH',NULL),(8,'21-14-018','AUSTRIA','FRIENDRICH ALLAIN',NULL),(9,'21-14-008','BARON','VINCENT JUDE',NULL),(10,'21-14-009','BICALDO','EDUARD JOHN',NULL),(11,'21-14-010','BUENAFE','ARL CHRISTOPHER',NULL),(12,'21-14-011','CAJIGAL','PATRICE MARICO',NULL),(13,'21-14-104','CORMINAL','CHRISTINE JOY',NULL),(14,'21-14-106','DE','DIOS	DAVE JOSE',NULL),(15,'21-14-014','DELA','CRUZ	MARVIN ANGELO',NULL),(16,'21-14-088','DELA','CRUZ	TRECIA MAE',NULL),(17,'21-14-015','DELOS','SANTOS	RISA MAY',NULL),(18,'21-14-020','DOMDOM','AMANDA LOUISE',NULL),(19,'21-14-022','DRIO','RACHEL ADELINE',NULL),(20,'21-14-110','ESTOQUE','MECAELA',NULL),(21,'21-14-111','FADOL','MC NICOLE',NULL),(22,'21-14-112','GALLENERA','JOCELYN',NULL),(23,'21-14-026','GERARDO','AMY ROSE',NULL),(24,'21-14-027','GERMAN','SOFIA',NULL),(25,'21-14-114','GONZALES','EMERSON',NULL),(26,'21-14-089','GUASCH','KYLA',NULL),(27,'21-14-082','HERNANDEZ','IZAK HALEY',NULL),(28,'20-14-2041','ILEDAN','MARIA ELENA',NULL),(29,'21-14-116','ISLAO','JAYBERT',NULL),(30,'21-14-118','LAGUADOR','ROBERT VINCENT',NULL),(31,'21-14-119','LICAYAN','JOHN PHILIP',NULL),(32,'21-14-120','LINO','CARLO JV',NULL),(33,'21-14-091','MABULAC','MARCELINA',NULL),(34,'20-14-8680','MACASILLI','JASMIN MHAICEE',NULL),(35,'21-14-036','MACAYA','NHEIL CHRISTIAN JIREH',NULL),(36,'21-14-139','MENDOZA','MA. JULIA MANUEL',NULL),(37,'21-14-044','ORALLO','CHANEL VINCENT',NULL),(38,'21-14-123','PACLIAN','CHERISH MONIQUE',NULL),(39,'21-14-046','PALLASIGUE','ERIK JOSEF',NULL),(40,'21-14-125','PANGILINAN','JANSEN IVAN',NULL),(41,'21-14-048','PEÑAFLOR','RALPH ACE',NULL),(42,'21-14-050','PERA','JOHN PHILIP',NULL),(43,'21-14-051','PEREZ','JAN MARC',NULL),(44,'21-14-127','PRADO','EDIZON',NULL),(45,'21-14-128','QUIAMBAO','REIMHAR B.',NULL),(46,'21-14-130','RAMOS','KRISTIE MARNI',NULL),(47,'21-14-052','REVILLAS','HARLENE',NULL),(48,'21-14-053','ROGERO','CHRISTIAN ANDREW',NULL),(49,'21-14-133','SALVADOR','AARON ANGELO',NULL),(50,'21-14-057','SAN','JOSE	LYNRYNE',NULL),(51,'21-14-134','SANTIAGO','JR.	LARRY',NULL),(52,'21-14-058','SELES','DIVINE TRIXY',NULL),(53,'21-14-060','TAJO','KARL CEDRIC',NULL),(54,'21-14-072','VARGAS','IVAN CHRISTOPHER',NULL),(55,'21-14-002','AGABON','FRANCIS KHLYE',NULL),(56,'21-14-099','ANDRES','MARK FRODO',NULL),(57,'21-14-005','ANTONIO','ERICK JAMES',NULL),(58,'21-14-201','ARCEO','ALDRIN',NULL),(59,'21-14-101','ASUNCION','KEVIN VICKMAR',NULL),(60,'21-14-006','BABISTA','LLOYD CLARENCE',NULL),(61,'21-14-068','BOLECHE','JOHN PATRICK',NULL),(62,'21-14-103','CALAOAGAN','JOSHUA',NULL),(63,'21-14-081','CAÑETE','DHARHELL',NULL),(64,'21-14-087','CUMAYAS','JAMES ANGELO',NULL),(65,'21-14-069','DANTE','NEIL MICHAEL',NULL),(66,'21-14-108','DEL','ROSARIO	LUCY MAE',NULL),(67,'21-14-039','DELA','CRUZ	MARTIN LEI',NULL),(68,'21-14-030','DEPOSITAR','JOHN DWAIN SHARDEEP',NULL),(69,'21-14-016','DIAZ','JR	RICKY',NULL),(70,'21-14-019','DINEROS','JEREMY LEE',NULL),(71,'21-14-021','DOROIN','CYRUS',NULL),(72,'21-14-023','EVANGELIO','ALECZANDRA NICOLE',NULL),(73,'21-14-067','GABIOSA','RASHEED',NULL),(74,'21-14-096','GALIT','GERALD',NULL),(75,'21-14-073','GARCIA','DANMAR',NULL),(76,'21-14-025','GARCIA','FRANCHESKA',NULL),(77,'21-14-074','GERONIMO','KEITH ALLEN',NULL),(78,'21-14-115','GUTIERREZ','AIRA MAE',NULL),(79,'21-14-142','HERNANDO','RHAYANA EHRIKA',NULL),(80,'21-14-031','IBAÑEZ','JAMES BARON',NULL),(81,'21-14-032','JAVIER','JOHN ABRAHM',NULL),(82,'21-14-033','LABUNGRAY','KRIZZEL',NULL),(83,'21-14-084','LANUZO','JOHN LUIS',NULL),(84,'21-14-034','LO','ADRIAN',NULL),(85,'21-14-070','LOPEZ','II	RENIE MAR',NULL),(86,'21-14-077','MACARAIG','MARK JOSEPH',NULL),(87,'21-14-040','MATIMTIM','JOHN MICHAEL',NULL),(88,'20-14-4607','MISTADES','MARK ANDREI',NULL),(89,'21-14-122','ONOFRE','REDXYRELL',NULL),(90,'21-14-045','PALACIO','R-JAY',NULL),(91,'21-14-092','PALARCA','GILLAINE',NULL),(92,'21-14-079','PANGANIBAN','NEIL ALLEN',NULL),(93,'21-14-047','PANZUELO','LUCKY EMMANUEL',NULL),(94,'21-14-083','PASAGUE','JOHN ADNEL',NULL),(95,'21-14-049','PENOLIO','MICAH',NULL),(96,'21-14-078','PINEDA','JANN JHUDIELLE',NULL),(97,'21-14-140','QUIMPO','PATRICIA',NULL),(98,'21-14-071','QUINTO','KLAYROLL IVAN',NULL),(99,'21-14-131','REATAZAN','CHRISTOPHER',NULL),(100,'21-14-054','ROMULO','KHAIL MICO',NULL),(101,'21-14-076','SAYAT','MARK BRYAN',NULL),(102,'21-14-135','SISON','JOHN ALBERT',NULL),(103,'21-14-062','TAN','AARON VINCE',NULL),(104,'20-14-3742','TAZARTE','REYCHAEL',NULL),(105,'21-14-063','TENORIO','VANESSA',NULL),(106,'21-14-066','UMALI','CAMILLA NATHALIA',NULL),(107,'21-14-095','VALDEZ','LUIS ANTONIO',NULL),(108,'24-22-011','ABUNGAN','RHEIN LASH',NULL),(109,'24-22-039','AMAGO','REYZAH MAY',NULL),(110,'24-22-287','ASPILLAGA','JHEYRONE',NULL),(111,'24-22-267','ATIBAGOS','DEO',NULL),(112,'24-22-282','BALAIS','RACE EION',NULL),(113,'24-22-036','BODOSO','MARIELLE',NULL),(114,'24-22-009','BORROMEO','ASIA LOUISE',NULL),(115,'24-22-025','CALMA','JYLIANA IYA',NULL),(116,'24-22-017','CANTALEJO','JERSEY REI',NULL),(117,'24-22-014','CAPIZ','JOHN CEDRICK',NULL),(118,'24-22-038','CARDENAS','JR.	ZOILO',NULL),(119,'24-22-007','CASINGINAN','JENNY-ROSE',NULL),(120,'24-22-251','CASTRO','KENNETH',NULL),(121,'24-22-252','COMENDADOR','PRESCIOUS JADE',NULL),(122,'24-22-040','COTIAMCO','JUDETHAVINCE',NULL),(123,'24-22-001','CRUZ','CHYRUS',NULL),(124,'24-22-301','DE','CASTRO	JOHN CARLO',NULL),(125,'24-22-020','DEGOMA','JHUSTIN',NULL),(126,'24-22-331','DELACRUZ','VANESSA',NULL),(127,'24-22-004','DONGALLO','FRANCIS',NULL),(128,'24-22-028','DULDULAO','MARK JESSE',NULL),(129,'24-22-272','ESTRADA','NHICKA ERRA',NULL),(130,'24-22-313','FAJARDO','ALESSANDRA',NULL),(131,'24-22-013','FARAON','CHRISTIAN JHAY',NULL),(132,'24-22-029','FERNANDEZ','KING',NULL),(133,'24-22-027','GECOSO','RAFFAELL JOHN',NULL),(134,'24-22-032','INDOLOS','DUSTIN KWERR',NULL),(135,'23-22-136','JASMIN','JENESIS CLAUDETTE',NULL),(136,'24-22-026','LABONG','KHAIZER CHARLES',NULL),(137,'24-22-319','LAPUZ','GERARD',NULL),(138,'24-22-021','LOZADA','JIMBOY',NULL),(139,'24-22-024','LUISTRO','KEVENLY',NULL),(140,'24-22-033','LUNA','CLARENCE JERALD',NULL),(141,'24-22-292','MADIS','JENIVIEVE',NULL),(142,'24-22-023','MANGALUS','JR.	MICHAEL',NULL),(143,'24-22-008','MARCHAN','RUSHA BELLE',NULL),(144,'24-22-010','MIGUEL','LJ JOURIE',NULL),(145,'24-22-337','OBLIGADO','MARK SIMON',NULL),(146,'24-22-035','OLIVEROS','JENMAR',NULL),(147,'24-22-022','PANER','RAPHAEL',NULL),(148,'24-22-031','PANTI','CHRISTIAN PATRICK',NULL),(149,'24-22-034','PLAGATA','AMARU CHRIS JUNIEL',NULL),(150,'24-22-006','PULO','ANDRE',NULL),(151,'24-22-030','PUNO','ASHLEY FAYE',NULL),(152,'24-22-325','ROLLO','CHRISTIAN DALE',NULL),(153,'24-22-018','SACLOLO','SOFIA ANN',NULL),(154,'24-22-002','SANTILLAN','JOHN MHARWIN',NULL),(155,'24-22-003','SAYCON','BJ',NULL),(156,'24-22-015','SENARLO','JUSTIN LLOYD',NULL),(157,'24-22-005','SUNGA','EZEKIEL',NULL),(158,'24-22-277','TAMPUS','JOHN MARC',NULL),(159,'24-22-016','TEJANO','VENZ LORENZE',NULL),(160,'24-22-345','TORRES','CLYDE DAYNELL',NULL),(161,'24-22-344','TREBITA','CLARENCE',NULL),(162,'24-22-037','VALDECANTOS','LANTIS',NULL),(163,'24-22-307','VILLABER','RODMAR',NULL),(164,'24-22-012','VILLARAMA','JASFER',NULL);
/*!40000 ALTER TABLE `students` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `subjects`
--

DROP TABLE IF EXISTS `subjects`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `subjects` (
  `subject_id` int(11) NOT NULL AUTO_INCREMENT,
  `subject_code` varchar(20) DEFAULT NULL,
  `subject_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`subject_id`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `subjects`
--

LOCK TABLES `subjects` WRITE;
/*!40000 ALTER TABLE `subjects` DISABLE KEYS */;
INSERT INTO `subjects` VALUES (1,NULL,'Design Project 2'),(3,'','Introduction to Computing'),(4,'CPE422','Design Project 2'),(5,'CPE112','Computer Engineering As Discipline'),(6,'IT11','Introduction to Computing'),(7,'dasdas','sample');
/*!40000 ALTER TABLE `subjects` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `teachers`
--

DROP TABLE IF EXISTS `teachers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `teachers` (
  `teacher_id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `full_name` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`teacher_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `teachers`
--

LOCK TABLES `teachers` WRITE;
/*!40000 ALTER TABLE `teachers` DISABLE KEYS */;
INSERT INTO `teachers` VALUES (1,'epallasigue','$2y$10$hpQ/K31QYq4zJsLMDkxopuiJeqr4uhx8V9IwJSXHE0sGeUq9Qawsy','Erik Josef Pallasigue','ejaypallasigue@gmail.com'),(2,'marvin.edu','$2y$10$POBuTWXl2Mr24mfgZdbNIOa7prm1gQkCMZsQXh0iDAIY2pRoLK3QW','Marvin Angelo A. Dela Cruz','marvinangelo@gmail.com'),(3,'faculty','$2y$10$/ILWESAtYwUaWeMRXoE9Hu0WPFZElvzMxzU8td5Jai0iu/3o2pTTy','Faculty Tester','facultytesting@gmail.com');
/*!40000 ALTER TABLE `teachers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(255) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `full_name` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'admin','$2y$10$U.1jkDGgCn97e7WYNrkVWO9JI2g89ucLij3aguT.O9q/KtZeazQhq','Admin User','2025-05-29 00:24:48');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Dumping events for database 'udm_class_record_db'
--

--
-- Dumping routines for database 'udm_class_record_db'
--
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-06-02  4:11:21
