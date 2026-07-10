-- ─────────────────────────────────────────────────────────────────────────────
--  sexual_alert_queue.sql
--  Schema: ตารางคิวแจ้งเตือนผู้ถูกกระทำความรุนแรง / ข่มขืน
--  รัน SQL นี้ใน phpMyAdmin หรือ MySQL CLI ก่อนใช้งาน sexual.php (queue mode)
-- ─────────────────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS `sexual_alert_queue` (
  `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
  `vn`                  VARCHAR(30)     NOT NULL DEFAULT '',
  `hn`                  VARCHAR(20)     NOT NULL DEFAULT '',
  `fullname`            VARCHAR(200)    NOT NULL DEFAULT '',
  `cid`                 VARCHAR(20)              DEFAULT NULL,
  `age`                 TINYINT UNSIGNED         DEFAULT NULL,
  `sex`                 VARCHAR(10)              DEFAULT NULL,
  `hometel`             VARCHAR(30)              DEFAULT NULL,
  `address`             TEXT                     DEFAULT NULL,
  `lab_date`            DATE                     DEFAULT NULL   COMMENT 'วันที่สั่ง LAB จาก HOSxP (order_date)',
  `lab_time`            TIME                     DEFAULT NULL,
  `lab_items_name_ref`  VARCHAR(200)             DEFAULT NULL,
  `lab_order_result`    VARCHAR(255)             DEFAULT NULL,
  `lab_order_number`    VARCHAR(30)              DEFAULT NULL,
  `status`              TINYINT(1)      NOT NULL DEFAULT 0      COMMENT '0=pending 1=sent',
  `attempt`             SMALLINT        NOT NULL DEFAULT 0,
  `last_attempt_at`     DATETIME                 DEFAULT NULL,
  `last_error`          TEXT                     DEFAULT NULL,
  `out_ref`             VARCHAR(200)             DEFAULT NULL,
  `line_message_id`     VARCHAR(200)             DEFAULT NULL,
  `sent_at`             DATETIME                 DEFAULT NULL,
  `created_at`          TIMESTAMP       NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vn_lab_order`  (`vn`, `lab_order_number`),
  KEY        `idx_lab_date`     (`lab_date`),
  KEY        `idx_status`       (`status`),
  KEY        `idx_hn`           (`hn`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='คิวแจ้งเตือนผู้ถูกกระทำความรุนแรง/ข่มขืน — sexual.php queue mode';
