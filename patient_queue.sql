/*
 Schema for patient_queue (Psychiatric / Self-harm Alert)
 - โครงสร้างเดียวกับ fracture_queue
 - ใช้กับ patient.php, patient_action.php, patient_ingest.php, patient_flex_preview.php
 - ICD-10 ที่อยู่ในคิว: T71, X60–X69, X70, X84
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for patient_queue
-- ----------------------------
DROP TABLE IF EXISTS `patient_queue`;
CREATE TABLE `patient_queue` (
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
  UNIQUE INDEX `uq_patient_visit`(`visit_vn`) USING BTREE,
  INDEX `idx_status_created`(`status`, `created_at`) USING BTREE,
  INDEX `idx_sent_at`(`sent_at`) USING BTREE,
  INDEX `idx_last_attempt`(`last_attempt_at`) USING BTREE,
  INDEX `idx_pdx_code`(`pdx_code`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Sample test rows (ใช้ทดสอบได้ ลบทิ้งเมื่อเริ่มใช้จริง)
-- ----------------------------
INSERT INTO `patient_queue`
  (`visit_vn`, `hn`, `fullname`, `cid`, `hometel`, `age`, `sex`, `address`,
   `pdx_code`, `pdx_name`, `vstdate`, `mainstation`, `status`, `attempt`)
VALUES
  ('888888888881', 'T000001', 'ทดสอบ จิตเวช', '1111111111111', '0812345678', 28, 'ชาย',
   'ที่อยู่ทดสอบ จิตเวช', 'X84',
   'Intentional self-harm by unspecified means', CURDATE(),
   'โรงพยาบาลเชียงกลาง', 0, 0),
  ('888888888882', 'T000002', 'ทดสอบ self-poisoning', '1111111111112', '0823456789', 45, 'หญิง',
   'ที่อยู่ทดสอบ X68', 'X68',
   'Intentional self-poisoning by and exposure to pesticides', CURDATE(),
   'รพ.สต.บ้านเชียงกลาง', 0, 0);

SET FOREIGN_KEY_CHECKS = 1;
