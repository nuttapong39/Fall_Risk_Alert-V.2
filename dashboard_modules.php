<?php
/**
 * dashboard_modules.php — registry กลางของ Dashboard (config-driven)
 * ใช้ร่วมโดย dashboard.php และ dashboard_export.php
 *
 * แต่ละ module นิยาม:
 *   label/icon/color/grad — สไตล์การ์ด+ไอคอน (อ้างอิงจาก moph_keys_admin.php ให้ตรงทั้งระบบ)
 *   table                 — ชื่อ table จริง (sexual = sexual_alert_queue !)
 *   date                  — คอลัมน์วันที่คลินิกสำหรับ group รายเดือน (vstdate/regdate/lab_date)
 *   ui                    — ลิงก์หน้า queue_ui เดิมของ module
 *   tops                  — 2 panel: [{label, cols[], icon}]  (group_by + แสดง)
 *   columns               — [db_col => header]  คอลัมน์ตาราง/ผลลัพธ์ export (เหมือน queue_ui)
 *
 * NOTE: ชื่อ table/คอลัมน์ทั้งหมดมาจากไฟล์นี้เท่านั้น (hardcoded) ไม่รับจาก user
 *       → ปลอดภัยจาก SQL injection เมื่อ interpolate ลง query
 */

$DASH_MODULES = [
  'fracture' => [
    'label' => 'พลัดตก / หกล้ม', 'icon' => 'falling',
    'color' => '#059669', 'grad' => '135deg,#10b981,#059669',
    'table' => 'fracture_queue', 'date' => 'vstdate', 'ui' => 'fracture_queue_ui.php',
    'tops' => [
      ['label' => 'Top สถานบริการหลัก', 'cols' => ['mainstation'],        'icon' => 'local_hospital'],
      ['label' => 'Top PDX',            'cols' => ['pdx_code','pdx_name'], 'icon' => 'stethoscope'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'pdx_code'=>'ICD-10','pdx_name'=>'ชื่อโรค','vstdate'=>'วันรับบริการ',
      'mainstation'=>'สถานบริการหลัก','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'patient' => [
    'label' => 'กลุ่มเสี่ยงจิตเวช (Patient)', 'icon' => 'personal_injury',
    'color' => '#0369a1', 'grad' => '135deg,#0ea5e9,#0369a1',
    'table' => 'patient_queue', 'date' => 'vstdate', 'ui' => 'patient.php',
    'tops' => [
      ['label' => 'Top สถานบริการหลัก', 'cols' => ['mainstation'],        'icon' => 'local_hospital'],
      ['label' => 'Top PDX',            'cols' => ['pdx_code','pdx_name'], 'icon' => 'stethoscope'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'pdx_code'=>'ICD-10','pdx_name'=>'ชื่อโรค','vstdate'=>'วันรับบริการ',
      'mainstation'=>'สถานบริการหลัก','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'drug' => [
    'label' => 'ยาอันตราย (Drug Alert)', 'icon' => 'medication_liquid',
    'color' => '#7c3aed', 'grad' => '135deg,#8b5cf6,#7c3aed',
    'table' => 'drug_queue', 'date' => 'vstdate', 'ui' => 'drugitems01.php',
    'tops' => [
      ['label' => 'Top สถานบริการหลัก', 'cols' => ['mainstation'], 'icon' => 'local_hospital'],
      ['label' => 'Top ยาอันตราย',      'cols' => ['drug_name'],   'icon' => 'medication_liquid'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'drug_code'=>'รหัสยา','drug_name'=>'ชื่อยา','department'=>'แผนก',
      'mainstation'=>'สถานบริการหลัก','vstdate'=>'วันรับบริการ','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'accident' => [
    'label' => 'พ.ร.บ. / อุบัติเหตุ', 'icon' => 'car_crash',
    'color' => '#d97706', 'grad' => '135deg,#f59e0b,#d97706',
    'table' => 'accident_queue', 'date' => 'regdate', 'ui' => 'accident_queue_ui.php',
    'tops' => [
      ['label' => 'Top สิทธิการรักษา', 'cols' => ['pttname'], 'icon' => 'badge'],
      ['label' => 'Top ประเภท (pttype)', 'cols' => ['pttype'], 'icon' => 'category'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','an'=>'AN','fullname'=>'ชื่อ-สกุล',
      'regdate'=>'วันที่ Reg','regtime'=>'เวลา','pttype'=>'pttype','pttname'=>'สิทธิ',
      'created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'lab_hemato' => [
    'label' => 'Hematocrit Alert', 'icon' => 'bloodtype',
    'color' => '#9f1239', 'grad' => '135deg,#f43f5e,#9f1239',
    'table' => 'lab_hemato_queue', 'date' => 'lab_date', 'ui' => 'lab_hemato_queue_ui.php',
    'tops' => [
      ['label' => 'Top รหัสรายการตรวจ', 'cols' => ['lab_items_code'], 'icon' => 'science'],
      ['label' => 'Top ค่าที่ตรวจได้',   'cols' => ['result'],         'icon' => 'lab_research'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'lab_items_code'=>'รหัส Lab','result'=>'ผลตรวจ','patient_type'=>'ประเภท',
      'lab_date'=>'วันที่ตรวจ','lab_time'=>'เวลา','doctor'=>'แพทย์',
      'created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'pharm_lab' => [
    'label' => 'เภสัชกรรม / Lab วิกฤต', 'icon' => 'medication',
    'color' => '#0891b2', 'grad' => '135deg,#22d3ee,#0891b2',
    'table' => 'pharm_lab_queue', 'date' => 'lab_date', 'ui' => 'pharm_lab_queue_ui.php',
    'tops' => [
      ['label' => 'Top รายการ Lab', 'cols' => ['lab_name'], 'icon' => 'science'],
      ['label' => 'Top ผลตรวจ',      'cols' => ['result'],   'icon' => 'lab_research'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ',
      'lab_name'=>'Lab','result'=>'ผลตรวจ','patient_type'=>'ประเภท',
      'lab_date'=>'วันที่ออกผล','lab_time'=>'เวลา','doctor'=>'แพทย์',
      'reported_by_name'=>'ผู้รายงาน','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'covid' => [
    'label' => 'COVID-19', 'icon' => 'coronavirus',
    'color' => '#ea580c', 'grad' => '135deg,#f97316,#ea580c',
    'table' => 'covid_queue', 'date' => 'vstdate', 'ui' => 'covid_queue_ui.php',
    'tops' => [
      ['label' => 'Top ICD-10',   'cols' => ['pdx'],              'icon' => 'stethoscope'],
      ['label' => 'Top ผล Lab',    'cols' => ['lab_order_result'], 'icon' => 'lab_research'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ',
      'pdx'=>'ICD-10','lab_order_result'=>'ผล Lab','vstdate'=>'วันรับบริการ','doctor'=>'แพทย์',
      'created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'dengue' => [
    'label' => 'ไข้เลือดออก (Dengue)', 'icon' => 'bug_report',
    'color' => '#dc2626', 'grad' => '135deg,#ef4444,#dc2626',
    'table' => 'dengue_queue', 'date' => 'vstdate', 'ui' => 'dengue_queue_ui.php',
    'tops' => [
      ['label' => 'Top ICD-10', 'cols' => ['icd10_name'], 'icon' => 'stethoscope'],
      ['label' => 'Top แพทย์',   'cols' => ['doctor'],     'icon' => 'badge'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'pdx'=>'ICD-10','icd10_name'=>'ชื่อโรค','lab_order_result'=>'ผล Lab',
      'vstdate'=>'วันรับบริการ','doctor'=>'แพทย์','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'lepto' => [
    'label' => 'เลปโตสไปโรซิส (Lepto)', 'icon' => 'water_drop',
    'color' => '#0f766e', 'grad' => '135deg,#14b8a6,#0f766e',
    'table' => 'lepto_queue', 'date' => 'vstdate', 'ui' => 'Leptospira.php',
    'tops' => [
      ['label' => 'Top ICD-10', 'cols' => ['icd10_name'], 'icon' => 'stethoscope'],
      ['label' => 'Top แพทย์',   'cols' => ['doctor'],     'icon' => 'badge'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'pdx'=>'ICD-10','icd10_name'=>'ชื่อโรค','lab_order_result'=>'ผล Lab',
      'vstdate'=>'วันรับบริการ','doctor'=>'แพทย์','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'scrub' => [
    'label' => 'สครับไทฟัส (Scrub Typhus)', 'icon' => 'pest_control',
    'color' => '#854d0e', 'grad' => '135deg,#d97706,#854d0e',
    'table' => 'scrub_queue', 'date' => 'vstdate', 'ui' => 'scrubtyphus.php',
    'tops' => [
      ['label' => 'Top ICD-10', 'cols' => ['icd10_name'], 'icon' => 'stethoscope'],
      ['label' => 'Top แพทย์',   'cols' => ['doctor'],     'icon' => 'badge'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'pdx'=>'ICD-10','icd10_name'=>'ชื่อโรค','lab_order_result'=>'ผล Lab',
      'vstdate'=>'วันรับบริการ','doctor'=>'แพทย์','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],

  'sexual' => [
    'label' => 'โรคติดต่อทางเพศสัมพันธ์ (STI)', 'icon' => 'health_and_safety',
    'color' => '#be185d', 'grad' => '135deg,#ec4899,#be185d',
    'table' => 'sexual_alert_queue', 'date' => 'lab_date', 'ui' => 'sexual.php',
    'tops' => [
      ['label' => 'Top รายการ Lab', 'cols' => ['lab_items_name_ref'], 'icon' => 'science'],
      ['label' => 'Top ผล',          'cols' => ['lab_order_result'],   'icon' => 'lab_research'],
    ],
    'columns' => [
      'status'=>'สถานะ','hn'=>'HN','fullname'=>'ชื่อ-สกุล','age'=>'อายุ','sex'=>'เพศ',
      'lab_items_name_ref'=>'รายการ Lab','lab_order_result'=>'ผล',
      'lab_date'=>'วันที่ออกผล','lab_time'=>'เวลา','created_at'=>'Created','sent_at'=>'Sent',
    ],
  ],
];

/* ── helpers ────────────────────────────────────────────────────────────── */

/** คืน registry ทั้งหมด (ตามลำดับที่ประกาศ) */
function dash_modules(): array { global $DASH_MODULES; return $DASH_MODULES; }

/** คืน config ของ module เดียว หรือ null ถ้าไม่รู้จัก (ใช้ validate ?module=) */
function dash_module(?string $key): ?array {
  global $DASH_MODULES;
  return ($key !== null && isset($DASH_MODULES[$key])) ? $DASH_MODULES[$key] : null;
}

/** expression วันที่รายเดือน (ใช้ clinical date, fallback created_at) — $date มาจาก registry (trusted) */
function dash_month_expr(array $mod): string {
  return "DATE_FORMAT(COALESCE({$mod['date']}, created_at), '%Y-%m')";
}

/** นับสถานะรวมของ module ในช่วงเดือน :a..:b (inclusive) → [total, sent, pending, failed] */
function dash_status_counts(PDO $db, array $mod, string $a, string $b): array {
  $expr = dash_month_expr($mod);
  $sql = "SELECT COUNT(*) total,
                 SUM(status=1) sent,
                 SUM(status=0 AND last_error IS NULL) pending,
                 SUM(status=0 AND last_error IS NOT NULL) failed
          FROM {$mod['table']} WHERE $expr BETWEEN :a AND :b";
  $st = $db->prepare($sql);
  $st->execute([':a' => $a, ':b' => $b]);
  $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  return [
    'total'   => (int)($r['total']   ?? 0),
    'sent'    => (int)($r['sent']    ?? 0),
    'pending' => (int)($r['pending'] ?? 0),
    'failed'  => (int)($r['failed']  ?? 0),
  ];
}

/** ป้ายสถานะของ 1 แถว → [label, css class] */
function dash_row_status(array $row): array {
  if ((int)($row['status'] ?? 0) === 1)          return ['ส่งสำเร็จ', 'status-ok'];
  if (!empty($row['last_error']))                return ['ล้มเหลว',  'status-fail'];
  return ['ค้างส่ง', 'status-pending'];
}

/** 'YYYY-MM' → 'ส.ค. 2568' (พ.ศ.) */
function dash_thai_month(string $ym): string {
  [$y, $m] = array_map('intval', explode('-', $ym));
  $names = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',
            7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
  return ($names[$m] ?? $m) . ' ' . ($y + 543);
}

/** ไตรมาสปีงบประมาณ (ต.ค.–ก.ย.) ที่ครอบเดือน $ym → [q, fy(พ.ศ.), start, end] (YYYY-MM) */
function dash_fiscal_quarter(string $ym): array {
  [$y, $m] = array_map('intval', explode('-', $ym));
  if     ($m >= 10) { $q=1; $sm=10; $sy=$y; $fyCE=$y+1; } // ต.ค.-ธ.ค.
  elseif ($m <= 3)  { $q=2; $sm=1;  $sy=$y; $fyCE=$y;   } // ม.ค.-มี.ค.
  elseif ($m <= 6)  { $q=3; $sm=4;  $sy=$y; $fyCE=$y;   } // เม.ย.-มิ.ย.
  else              { $q=4; $sm=7;  $sy=$y; $fyCE=$y;   } // ก.ค.-ก.ย.
  $start = sprintf('%04d-%02d', $sy, $sm);
  $end   = date('Y-m', strtotime("$start-01 +2 month"));
  return ['q' => $q, 'fy' => $fyCE + 543, 'start' => $start, 'end' => $end];
}

/** ช่วงเวลาจาก span + anchor เดือน → [span, start, end, label]
 *  span: month | 3m | 6m | 9m | q   (3/6/9 = ย้อนหลังจาก anchor; q = ไตรมาสปีงบที่ครอบ anchor) */
function dash_span_range(string $span, string $anchor): array {
  $back = ['3m' => 2, '6m' => 5, '9m' => 8];
  if ($span === 'q') {
    $fq    = dash_fiscal_quarter($anchor);
    $start = $fq['start']; $end = $fq['end'];
    $label = "ไตรมาส {$fq['q']}/{$fq['fy']} · " . dash_thai_month($start) . '–' . dash_thai_month($end);
  } elseif (isset($back[$span])) {
    $end   = $anchor;
    $start = date('Y-m', strtotime("$anchor-01 -{$back[$span]} month"));
    $n     = $back[$span] + 1;
    $label = "ย้อนหลัง $n เดือน · " . dash_thai_month($start) . '–' . dash_thai_month($end);
  } else {
    $span = 'month'; $start = $end = $anchor;
    $label = 'รายเดือน · ' . dash_thai_month($anchor);
  }
  return ['span' => $span, 'start' => $start, 'end' => $end, 'label' => $label];
}
