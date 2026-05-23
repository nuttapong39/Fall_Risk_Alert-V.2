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

 Date: 25/12/2025 00:20:23
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for fracture_queue
-- ----------------------------
DROP TABLE IF EXISTS `fracture_queue`;
CREATE TABLE `fracture_queue`  (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `visit_vn` varchar(15) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `hn` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fullname` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cid` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `hometel` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `age` int(11) NULL DEFAULT NULL,
  `sex` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `address` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `pdx_code` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `pdx_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `vstdate` date NULL DEFAULT NULL,
  `mainstation` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `attempt` int(11) NOT NULL DEFAULT 0,
  `last_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_attempt_at` datetime NULL DEFAULT NULL,
  `sent_at` datetime NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `out_ref` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `line_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_visit`(`visit_vn`) USING BTREE,
  UNIQUE INDEX `uq_fracture_visit`(`visit_vn`) USING BTREE,
  INDEX `idx_status_created`(`status`, `created_at`) USING BTREE,
  INDEX `idx_sent_at`(`sent_at`) USING BTREE,
  INDEX `idx_last_attempt`(`last_attempt_at`) USING BTREE
) ENGINE = InnoDB AUTO_INCREMENT = 88 CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Records of fracture_queue
-- ----------------------------

INSERT INTO `fracture_queue` VALUES (29, '999999999998', '0000001', 'ทดสอบ ผู้หญิง', '9999999999999', '0812345678', 70, 'หญิง', 'ที่อยู่ทดสอบ', 'S72109', 'Closed Intertrochanteric fracture of femur, stable type (TM)', '2026-01-08', 'โรงพยาบาลเชียงกลาง', 1, 1, NULL, '2025-12-24 10:09:51', '2025-12-24 10:09:51', '2025-08-23 11:54:02', 'status:200', 'status:200');
INSERT INTO `fracture_queue` VALUES (30, '999999999999', '0000002', 'ทดสอบ ผู้ชาย', '9999999999999', '0615635814', 62, 'ชาย', 'ที่อยู่ทดสอบ', 'S72109', 'Closed Intertrochanteric fracture of femur, stable type (TM)', '2026-01-08', 'โรงพยาบาลเชียงกลาง', 1, 1, NULL, '2025-08-26 09:24:45', '2025-08-26 09:24:45', '2025-08-26 09:24:44', 'status:200', 'status:200');

SET FOREIGN_KEY_CHECKS = 1;
