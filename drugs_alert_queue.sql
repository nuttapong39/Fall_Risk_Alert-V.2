/*
  drugs_alert_queue.sql — คิวแจ้งเตือน Drug Alert (ยาเฝ้าระวังที่เภสัชเลือกเอง) (MedAlert_DB · MySQL เสมอ)

  deploy อัตโนมัติโดย db_migrate.php (glob "*_queue.sql") — ตัว migrate จะตัด
  DROP TABLE / INSERT ออกและเติม IF NOT EXISTS ให้ จึงรันซ้ำได้ไม่ลบข้อมูลเดิม

  UNIQUE KEY = (hn, icode, vstdate)
    แพทเทิร์นเดียวกับ had_queue — คนไข้ 1 รายรับยาเฝ้าระวังต่างชนิดกันในวันเดียวกันได้
    จึงต้องมี icode อยู่ในคีย์ด้วย ไม่งั้น upsert จะทับกันเหลือแค่ชนิดเดียว
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `drugs_alert_queue`;
CREATE TABLE `drugs_alert_queue` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,

  -- ── ข้อมูลผู้ป่วย (จาก HOSxP) ──
  `hn`          varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
  `fullname`    varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `cid`         varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `hometel`     varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `age`         int(11)      NULL DEFAULT NULL,
  `sex`         varchar(10)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `address`     text         CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL,

  -- ── รายการยา ──
  `icode`       varchar(20)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `drug_name`   varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `strength`    varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `units`       varchar(50)  CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NULL DEFAULT NULL,
  `vstdate`     date         NULL DEFAULT NULL,
  `qty`         decimal(10,2) NULL DEFAULT NULL,
  `sum_price`   decimal(10,2) NULL DEFAULT NULL,

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
  UNIQUE INDEX `uq_drugs_alert`     (`hn`,`icode`,`vstdate`) USING BTREE,
  INDEX        `idx_status_created` (`status`,`created_at`)  USING BTREE,
  INDEX        `idx_sent_at`        (`sent_at`)              USING BTREE,
  INDEX        `idx_last_attempt`   (`last_attempt_at`)      USING BTREE,
  INDEX        `idx_vstdate`        (`vstdate`)              USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
