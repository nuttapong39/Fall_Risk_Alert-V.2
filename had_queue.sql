/*
  had_queue.sql — คิวแจ้งเตือน HAD Alert (High Alert Drug) (MedAlert_DB · MySQL เสมอ)

  deploy อัตโนมัติโดย db_migrate.php (glob "*_queue.sql") — ตัว migrate จะตัด
  DROP TABLE / INSERT ออกและเติม IF NOT EXISTS ให้ จึงรันซ้ำได้ไม่ลบข้อมูลเดิม

  UNIQUE KEY = (hn, icode, vstdate)
    ยืนยันจากข้อมูลจริง: คนไข้ 1 ราย รับยา HAD ต่างชนิดกันในวันเดียวกันได้ (เช่น warfarin
    สีฟ้า + สีชมพู) จึงต้องมี icode อยู่ในคีย์ด้วย ไม่งั้น upsert จะทับกันเหลือแค่ชนิดเดียว
    ข้อจำกัดที่ทราบ: ถ้ารับ icode เดียวกันซ้ำ 2 รอบวันเดียวกัน (เช้า/เย็น) จะถูกรวมเป็น
    แถวเดียว — เป็นข้อจำกัดของ query ต้นแบบเองที่ไม่มีเลข order รายครั้ง
*/

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS `had_queue`;
CREATE TABLE `had_queue` (
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
  UNIQUE INDEX `uq_had`             (`hn`,`icode`,`vstdate`) USING BTREE,
  INDEX        `idx_status_created` (`status`,`created_at`)  USING BTREE,
  INDEX        `idx_sent_at`        (`sent_at`)              USING BTREE,
  INDEX        `idx_last_attempt`   (`last_attempt_at`)      USING BTREE,
  INDEX        `idx_vstdate`        (`vstdate`)              USING BTREE
) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_general_ci ROW_FORMAT = Dynamic;

SET FOREIGN_KEY_CHECKS = 1;
