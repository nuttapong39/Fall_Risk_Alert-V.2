-- covid_queue.sql — Local queue table สำหรับโมดูล COVID-19
-- สร้างครั้งแรก หรือรันซ้ำได้ (IF NOT EXISTS)
-- สกัดจากคอลัมน์ที่ covid_queue_action.php + covid_queue_ui.php ใช้จริง

CREATE TABLE IF NOT EXISTS `covid_queue` (
  `id`               int           NOT NULL AUTO_INCREMENT,
  `lab_order_number` varchar(30)   NOT NULL,
  `hn`               varchar(15)   NOT NULL,
  `fullname`         varchar(120)  DEFAULT NULL,
  `age`              tinyint       DEFAULT NULL,
  `cid`              varchar(13)   DEFAULT NULL,
  `informaddr`       text          DEFAULT NULL,
  `hometel`          varchar(20)   DEFAULT NULL,
  `vstdate`          date          DEFAULT NULL,
  `doctor`           varchar(80)   DEFAULT NULL,
  `pdx`              varchar(8)    DEFAULT NULL,
  `lab_order_result` varchar(50)   DEFAULT NULL,
  `status`           tinyint       NOT NULL DEFAULT 0  COMMENT '0=pending 1=sent',
  `attempt`          tinyint       NOT NULL DEFAULT 0,
  `last_attempt_at`  datetime      DEFAULT NULL,
  `out_ref`          varchar(80)   DEFAULT NULL,
  `line_message_id`  varchar(80)   DEFAULT NULL,
  `last_error`       text          DEFAULT NULL,
  `created_at`       datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `sent_at`          datetime      DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lab_order_number` (`lab_order_number`),
  KEY `idx_vstdate` (`vstdate`),
  KEY `idx_status`  (`status`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='คิวแจ้งเตือนผู้ป่วย COVID-19';
