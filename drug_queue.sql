/*
  Schema: drug_queue — คิวแจ้งเตือนผู้ป่วยกลุ่มเสี่ยงยาอันตราย (High-Alert Medications)
  ใช้กับ drugitems01.php, drug_queue_action.php, flex_drug.php

  ขั้นตอน:
    1. Import ไฟล์นี้เข้าฐานข้อมูล HOSxP (Slave) หรือ DB เดียวกับ fracture_queue
    2. คลิก "Sync จาก HOSxP" ในหน้า drugitems01.php เพื่อดึงข้อมูลคนไข้ครั้งแรก
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------
-- Table structure for drug_queue
-- ----------------------------
DROP TABLE IF EXISTS `drug_queue`;
CREATE TABLE `drug_queue` (
  `id`              int(11)      NOT NULL AUTO_INCREMENT,
  `visit_vn`        varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL COMMENT 'VN จาก HOSxP (opitemrece.vn)',
  `hn`              varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fullname`        varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cid`             varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `hometel`         varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `age`             int(11)      NULL DEFAULT NULL,
  `sex`             varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `address`         text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `drug_code`       varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'icode ของยา',
  `drug_name`       varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'ชื่อยา (drugitems.name)',
  `vstdate`         date         NULL DEFAULT NULL,
  `department`      varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL COMMENT 'แผนก / สถานะผู้ป่วย',
  `mainstation`     varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `status`          tinyint(1)   NOT NULL DEFAULT 0 COMMENT '0=ค้างส่ง 1=ส่งแล้ว',
  `attempt`         int(11)      NOT NULL DEFAULT 0,
  `last_error`      text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_attempt_at` datetime     NULL DEFAULT NULL,
  `sent_at`         datetime     NULL DEFAULT NULL,
  `created_at`      timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `out_ref`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `line_message_id` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE  INDEX `uq_drug_visit`        (`visit_vn`)            USING BTREE,
  INDEX          `idx_status_created`  (`status`, `created_at`) USING BTREE,
  INDEX          `idx_sent_at`         (`sent_at`)              USING BTREE,
  INDEX          `idx_last_attempt`    (`last_attempt_at`)      USING BTREE,
  INDEX          `idx_drug_code`       (`drug_code`)            USING BTREE,
  INDEX          `idx_hn`              (`hn`)                   USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Compact;

-- ----------------------------
-- Sample test rows (ลบทิ้งเมื่อเริ่มใช้จริง)
-- ----------------------------
INSERT INTO `drug_queue`
  (`visit_vn`, `hn`, `fullname`, `cid`, `hometel`, `age`, `sex`, `address`,
   `drug_code`, `drug_name`, `vstdate`, `department`, `mainstation`, `status`, `attempt`)
VALUES
  ('777777777771', 'D000001', 'ทดสอบ ผู้ป่วยยา ก', '1111111111111', '0812345678', 65, 'ชาย',
   'ที่อยู่ทดสอบ 1',
   '1483860', 'ยาทดสอบอันตราย (กลุ่ม A)', CURDATE(),
   'OPD : ห้องตรวจ 1', 'โรงพยาบาลเชียงกลาง', 0, 0),
  ('777777777772', 'D000002', 'ทดสอบ ผู้ป่วยยา ข', '1111111111112', '0898765432', 45, 'หญิง',
   'ที่อยู่ทดสอบ 2',
   '1483860', 'ยาทดสอบอันตราย (กลุ่ม A)', CURDATE(),
   'IPD : หอผู้ป่วย 2', 'โรงพยาบาลเชียงกลาง', 0, 0);

SET FOREIGN_KEY_CHECKS = 1;
