/*
 Schema for pharm_lab_queue (Pharm Lab Alert)
 - ใช้กับ pharm_lab.php, pharm_lab_queue_ui.php, pharm_lab_queue_action.php, pharm_flex_preview.php
 - แจ้งเตือน Lab วิกฤต: INR ≥5/≥3.5, Depakin >150, Lithium >1.2, Phenytoin >20
 - โครงสร้างอิงสไตล์เดียวกับ fracture_queue / patient_queue
   แต่เพิ่มฟิลด์เฉพาะ pharm: lab_name / result / patient_type / lab_order_number /
   reported_by_* (สำหรับเก็บผลการรายงานย้อนจากปุ่ม CTA ในLINE)

 หมายเหตุ UNIQUE KEY:
  - ใช้ (hn, lab_order_number, lab_name) เพื่อกันซ้ำระดับ "ผล lab ราย HN ของ order เดียวกัน"
    ซึ่งตรงกับพฤติกรรมของ ingest (ON DUPLICATE KEY UPDATE)
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for pharm_lab_queue
-- ----------------------------
DROP TABLE IF EXISTS `pharm_lab_queue`;
CREATE TABLE `pharm_lab_queue` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,

  -- ข้อมูลผู้ป่วย
  `hn`               varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fullname`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `age`              int(11)      NULL DEFAULT NULL,

  -- ผลตรวจ Lab
  `lab_date`         date         NULL DEFAULT NULL,
  `lab_time`         time         NULL DEFAULT NULL,
  `doctor`           varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `lab_name`         varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `result`           varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `patient_type`     varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `lab_order_number` varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,

  -- การรายงานย้อนกลับจากเภสัชกร (ผ่านปุ่ม CTA ใน LINE → pharm_lab_report.php)
  `reported_by_id`   int(11)      NULL DEFAULT 0,
  `reported_by_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `reported_date`    date         NULL DEFAULT NULL,
  `reported_time`    time         NULL DEFAULT NULL,
  `reported_at`      datetime     NULL DEFAULT NULL,

  -- สถานะคิว (เหมือน fracture_queue / patient_queue)
  `status`           tinyint(1)   NOT NULL DEFAULT 0,
  `attempt`          int(11)      NOT NULL DEFAULT 0,
  `last_error`       text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_attempt_at`  datetime     NULL DEFAULT NULL,
  `sent_at`          datetime     NULL DEFAULT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `out_ref`          varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `line_message_id`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,

  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_pharm_lab`        (`hn`,`lab_order_number`,`lab_name`) USING BTREE,
  INDEX        `idx_status_created`  (`status`,`created_at`)              USING BTREE,
  INDEX        `idx_sent_at`         (`sent_at`)                          USING BTREE,
  INDEX        `idx_last_attempt`    (`last_attempt_at`)                  USING BTREE,
  INDEX        `idx_lab_name`        (`lab_name`)                         USING BTREE,
  INDEX        `idx_lab_date`        (`lab_date`)                         USING BTREE,
  INDEX        `idx_reported_at`     (`reported_at`)                      USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Table structure for pharm_reporters (ใช้กับ pharm_lab_report.php)
--   - dropdown ผู้รายงานผล Lab ในหน้า CTA
-- ----------------------------
DROP TABLE IF EXISTS `pharm_reporters`;
CREATE TABLE `pharm_reporters` (
  `id`         int(11)      NOT NULL AUTO_INCREMENT,
  `name`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `created_at` timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`) USING BTREE,
  INDEX `idx_name` (`name`) USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- Sample reporters (ลบทิ้งเมื่อเริ่มใช้จริง)
INSERT INTO `pharm_reporters` (`name`) VALUES
  ('เภสัชกร ทดสอบ ระบบ'),
  ('เภสัชกร ตัวอย่าง สอง');

-- ----------------------------
-- Sample test rows (ทดสอบหน้า UI / flex — ลบทิ้งเมื่อเริ่มใช้จริง)
-- ----------------------------
INSERT INTO `pharm_lab_queue`
  (`hn`, `fullname`, `age`, `lab_date`, `lab_time`, `doctor`,
   `lab_name`, `result`, `patient_type`, `lab_order_number`,
   `status`, `attempt`)
VALUES
  ('T000001', 'ทดสอบ INR วิกฤต', 72, CURDATE(), '10:32:00', 'นพ.ทดสอบ ระบบ',
   'INR', '5.8', 'OPD', 'LO-TEST-INR-01',
   0, 0),
  ('T000002', 'ทดสอบ Depakin สูง', 54, CURDATE(), '09:10:00', 'พญ.ทดสอบ สอง',
   'Depakin level', '172.5', 'IPD', 'LO-TEST-DEP-01',
   0, 0),
  ('T000003', 'ทดสอบ Lithium สูง', 41, CURDATE(), '08:45:00', 'นพ.ทดสอบ สาม',
   'Lithium level', '1.35', 'OPD', 'LO-TEST-LIT-01',
   0, 0),
  ('T000004', 'ทดสอบ Phenytoin สูง', 66, CURDATE(), '11:05:00', 'นพ.ทดสอบ สี่',
   'Phenytoin level', '24.2', 'OPD', 'LO-TEST-PHE-01',
   0, 0);

SET FOREIGN_KEY_CHECKS = 1;
