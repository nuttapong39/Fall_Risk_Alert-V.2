/*
  lab_hemato_queue.sql — คิวแจ้งเตือน Hematocrit Alert (MedAlert_DB · MySQL เสมอ)

  deploy อัตโนมัติโดย db_migrate.php (glob "*_queue.sql") — ตัว migrate จะตัด
  DROP TABLE / INSERT ออกและเติม IF NOT EXISTS ให้ จึงรันซ้ำได้ไม่ลบข้อมูลเดิม

  UNIQUE KEY = (hn, lab_order_number, lab_items_code)
    1 ใบสั่ง lab มีได้หลายรายการตรวจ จึงต้องมี lab_items_code อยู่ในคีย์ด้วย
    ไม่งั้นการ upsert จะทับรายการตรวจอื่นในใบเดียวกันทิ้ง
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `lab_hemato_queue`;
CREATE TABLE `lab_hemato_queue` (
  `id`               int(11)      NOT NULL AUTO_INCREMENT,

  -- ── ข้อมูลผู้ป่วย (จาก HOSxP) ──
  `hn`               varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `vn`               varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `fullname`         varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `age`              int(11)      NULL DEFAULT NULL,
  `sex`              varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `hometel`          varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,

  -- ── ผลตรวจ ──
  `lab_order_number` varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `lab_items_code`   varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `result`           varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `lab_date`         date         NULL DEFAULT NULL,
  `lab_time`         time         NULL DEFAULT NULL,
  `doctor`           varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `patient_type`     varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,

  -- ── สถานะคิว (คอลัมน์มาตรฐานเหมือนทุก module) ──
  `status`           tinyint(1)   NOT NULL DEFAULT 0,
  `attempt`          int(11)      NOT NULL DEFAULT 0,
  `last_error`       text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,
  `last_attempt_at`  datetime     NULL DEFAULT NULL,
  `sent_at`          datetime     NULL DEFAULT NULL,
  `out_ref`          varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `line_message_id`  varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `created_at`       timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`) USING BTREE,
  UNIQUE INDEX `uq_lab_hemato`      (`hn`,`lab_order_number`,`lab_items_code`) USING BTREE,
  INDEX        `idx_status_created` (`status`,`created_at`)                    USING BTREE,
  INDEX        `idx_sent_at`        (`sent_at`)                                USING BTREE,
  INDEX        `idx_last_attempt`   (`last_attempt_at`)                        USING BTREE,
  INDEX        `idx_lab_date`       (`lab_date`)                               USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
