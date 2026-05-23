/*
 Navicat Premium Data Transfer

 Source Server         : 192.168.1.249 (Slave)
 Source Server Type    : MySQL
 Source Server Version : 50553 (5.5.53-38.5)
 Source Host           : 192.168.1.249:3306
 Source Schema         : hosxp

 Target Server Type    : MySQL
 Target Server Version : 50553 (5.5.53-38.5)
 File Encoding         : 65001

 Date: 28/12/2025 15:51:52
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for accident_queue
-- ----------------------------
DROP TABLE IF EXISTS `accident_queue`;
CREATE TABLE `accident_queue`  (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `hn` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `an` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `regdate` date NULL DEFAULT NULL,
  `regtime` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `pttype` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `pttname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `fullname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `attempt` int(11) NOT NULL DEFAULT 0,
  `last_attempt_at` datetime NULL DEFAULT NULL,
  `sent_at` datetime NULL DEFAULT NULL,
  `out_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `line_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uk_an`(`an`) USING BTREE,
  INDEX `idx_status`(`status`) USING BTREE,
  INDEX `idx_last_attempt_at`(`last_attempt_at`) USING BTREE,
  INDEX `idx_created_at`(`created_at`) USING BTREE,
  INDEX `idx_regdate`(`regdate`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 2 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Records of accident_queue
-- ----------------------------
INSERT INTO `accident_queue` VALUES (1, '999999', 'TEST251225102310', '2025-12-25', '10:23', '33', 'พ.ร.บ. (ทดสอบ)', 'ทดสอบ ระบบอุบัติเหตุ', 0, 0, NULL, NULL, NULL, NULL, NULL, '2025-12-25 10:23:10');

SET FOREIGN_KEY_CHECKS = 1;
