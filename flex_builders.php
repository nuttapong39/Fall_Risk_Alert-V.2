<?php
/**
 * flex_builders.php — ตัวประกอบ Flex ต่อ module (config-driven, layout เดียวกันทุกตัว)
 *   นิยาม buildXxxPayload() แบบ thin → เรียก flex_render_card()
 *   ถูก require ที่หัวไฟล์ flex_*.php แต่ละตัว (ก่อน guarded definition เดิม → เวอร์ชันนี้ชนะ)
 *   ดีไซน์/สี/ข้อความ มาจาก flex_theme() (แก้ผ่าน flex_editor.php)
 *
 * NOTE: ตัว buildXxxPayload เดิมในไฟล์ flex_*.php ถูกข้าม (function_exists guard) — คือ legacy
 */
require_once __DIR__ . '/flex_card.php';

/* ── FRACTURE ─────────────────────────────────────────────────────────── */
if (!function_exists('buildFracturePayload')) {
  function buildFracturePayload(array $row): array {
    if (function_exists('row_to_utf8')) $row = row_to_utf8($row);
    $hn = (string)($row['hn'] ?? '-'); $fn = (string)($row['fullname'] ?? '-'); $icd = (string)($row['pdx_code'] ?? '-');
    return flex_render_card('fracture', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($row['age']??'',$row['sex']??''),'cid'=>$row['cid']??'-'],
      'mid' => [['label'=>'การวินิจฉัย','items'=>[
        ['big','ICD-10',$icd],
        ['kv','การวินิจฉัย',$row['pdx_name']??'-'],
        ['kvlight','วันที่รับบริการ',flex_thai_date($row['vstdate']??'')],
        ['kvlight','หน่วยบริการ',$row['mainstation']??'-'],
      ]]],
      'contact' => ['address'=>$row['address']??'-','phone'=>$row['hometel']??'-'],
      'alt' => "[แจ้งเตือน] หกล้ม/กระดูกหัก HN {$hn} {$fn} ({$icd})",
    ]);
  }
}

/* ── PHARM LAB (Lab วิกฤต ห้องยา) ─────────────────────────────────────── */
if (!function_exists('buildPharmPayload')) {
  function buildPharmPayload(array $r): array {
    if (function_exists('row_to_utf8')) $r = row_to_utf8($r);
    $hn = (string)($r['hn'] ?? '-'); $fn = (string)($r['fullname'] ?? '-');
    $lab = (string)($r['lab_name'] ?? '-'); $res = (string)($r['result'] ?? '-');
    return flex_render_card('pharm_lab', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($r['age']??'',''),'cid'=>$r['cid']??'-'],
      'mid' => [['label'=>'ผลตรวจวิกฤต','items'=>[
        ['kv','รายการตรวจ',$lab],
        ['pill','ผล',$res],
        ['kvlight','วันที่',flex_thai_date($r['lab_date']??'')],
        ['kvlight','แพทย์',$r['doctor']??'-'],
        ['kvlight','ประเภท',$r['patient_type']??'-'],
      ]]],
      'contact' => [],
      'alt' => "[แจ้งเตือน] Lab วิกฤต HN {$hn} {$fn} ({$lab} {$res})",
    ]);
  }
}

/* ── LAB HEMATO (ค่าความเข้มข้นเลือดผิดปกติ) ──────────────────────────── */
if (!function_exists('buildLabHematoPayload')) {
  function buildLabHematoPayload(array $r): array {
    if (function_exists('row_to_utf8')) $r = row_to_utf8($r);
    $hn   = (string)($r['hn'] ?? '-');
    $fn   = (string)($r['fullname'] ?? '-');
    $code = (string)($r['lab_items_code'] ?? '-');
    $res  = (string)($r['result'] ?? '-');
    return flex_render_card('lab_hemato', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($r['age']??'',$r['sex']??''),'cid'=>$r['cid']??'-'],
      'mid' => [['label'=>'ผลตรวจ','items'=>[
        // big = ค่าเด่นตัวใหญ่ — ค่าผลคือสิ่งที่ผู้รับต้องเห็นก่อนอย่างอื่น
        ['big','ค่าที่ตรวจได้',$res],
        ['kv','รหัสรายการตรวจ',$code],
        ['kvlight','วันที่ตรวจ',flex_thai_date($r['lab_date']??'')],
        ['kvlight','แพทย์',$r['doctor']??'-'],
        ['kvlight','ประเภท',$r['patient_type']??'-'],
      ]]],
      'contact' => ['address'=>'','phone'=>$r['hometel'] ?? ''],
      'alt' => "[แจ้งเตือน] ค่าความเข้มข้นเลือดผิดปกติ HN {$hn} {$fn} (รหัส {$code} = {$res})",
    ]);
  }
}

/* ── PATIENT (จิตเวช/ทำร้ายตนเอง) ─────────────────────────────────────── */
if (!function_exists('buildPatientPayload')) {
  function buildPatientPayload(array $row): array {
    if (function_exists('row_to_utf8')) $row = row_to_utf8($row);
    $hn = (string)($row['hn'] ?? '-'); $fn = (string)($row['fullname'] ?? '-'); $icd = (string)($row['pdx_code'] ?? '-');
    return flex_render_card('patient', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($row['age']??'',$row['sex']??''),'cid'=>$row['cid']??'-'],
      'mid' => [['label'=>'การวินิจฉัย','items'=>[
        ['big','ICD-10',$icd],
        ['kv','การวินิจฉัย',$row['pdx_name']??'-'],
        ['kvlight','วันที่รับบริการ',flex_thai_date($row['vstdate']??'')],
      ]]],
      'contact' => ['address'=>$row['address']??'-','phone'=>$row['hometel']??'-'],
      'alt' => "[แจ้งเตือน] จิตเวช/ทำร้ายตนเอง HN {$hn} {$fn} ({$icd})",
    ]);
  }
}

/* ── DRUG (ยาอันตราย High-Alert) ──────────────────────────────────────── */
if (!function_exists('buildDrugPayload')) {
  function buildDrugPayload(array $row): array {
    if (function_exists('row_to_utf8')) $row = row_to_utf8($row);
    $hn = (string)($row['hn'] ?? '-'); $fn = (string)($row['fullname'] ?? '-'); $dn = (string)($row['drug_name'] ?? '-');
    return flex_render_card('drug', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($row['age']??'',$row['sex']??''),'cid'=>$row['cid']??'-'],
      'mid' => [['label'=>'รายการยาอันตราย','items'=>[
        ['kv','ยา',$dn],
        ['kvlight','แผนก',$row['department']??'-'],
        ['kvlight','วันที่',flex_thai_date($row['vstdate']??'')],
      ]]],
      'contact' => ['address'=>$row['address']??'-','phone'=>$row['hometel']??'-'],
      'alt' => "[แจ้งเตือน] ยาอันตราย HN {$hn} {$fn} ({$dn})",
    ]);
  }
}

/* ── ACCIDENT (พ.ร.บ.) ────────────────────────────────────────────────── */
if (!function_exists('buildAccidentPayload')) {
  function buildAccidentPayload(array $r): array {
    if (function_exists('row_to_utf8')) $r = row_to_utf8($r);
    $hn = (string)($r['hn'] ?? '-'); $fn = (string)($r['fullname'] ?? '-'); $ptt = (string)($r['pttname'] ?? '-');
    return flex_render_card('accident', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($r['age']??'',$r['sex']??''),'cid'=>$r['cid']??'-'],
      'mid' => [['label'=>'การรับบริการ','items'=>[
        ['kv','สิทธิการรักษา',$ptt],
        ['kvlight','ประเภท (pttype)',$r['pttype']??'-'],
        ['kvlight','วันที่',flex_thai_date($r['regdate']??'')],
      ]]],
      'contact' => ['address'=>$r['address']??'','phone'=>$r['hometel']??''],
      'alt' => "[แจ้งเตือน] อุบัติเหตุ HN {$hn} {$fn} ({$ptt})",
    ]);
  }
}

/* ── COVID-19 (builder ชื่อ covid_buildMophPayload) ───────────────────── */
if (!function_exists('covid_buildMophPayload')) {
  function covid_buildMophPayload(array $row): array {
    if (function_exists('row_to_utf8')) $row = row_to_utf8($row);
    $hn = (string)($row['hn'] ?? '-'); $fn = (string)($row['fullname'] ?? '-');
    $res = (string)($row['lab_order_result'] ?? ($row['result'] ?? '-'));
    return flex_render_card('covid', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($row['age']??'',$row['sex']??''),'cid'=>$row['cid']??'-'],
      'mid' => [['label'=>'ผลตรวจ','items'=>[
        ['pill','ผล COVID-19',$res],
        ['kvlight','ICD-10',$row['pdx']??'-'],
        ['kvlight','วันที่',flex_thai_date($row['vstdate']??'')],
        ['kvlight','แพทย์',$row['doctor']??'-'],
      ]]],
      'contact' => ['address'=>$row['address']??($row['informaddr']??'-'),'phone'=>$row['hometel']??''],
      'alt' => "[แจ้งเตือน] COVID-19 HN {$hn} {$fn}",
    ]);
  }
}

/* ── SEXUAL (ความรุนแรงทางเพศ) ────────────────────────────────────────── */
if (!function_exists('buildSexualPayload')) {
  function buildSexualPayload(array $row): array {
    if (function_exists('row_to_utf8')) $row = row_to_utf8($row);
    $hn = (string)($row['hn'] ?? '-'); $fn = (string)($row['fullname'] ?? '-');
    $lab = (string)($row['lab_items_name_ref'] ?? '-'); $res = (string)($row['lab_order_result'] ?? '-');
    return flex_render_card('sexual', [
      'patient' => ['hn'=>$hn,'fullname'=>$fn,'agesex'=>flex_agesex($row['age']??'',$row['sex']??''),'cid'=>$row['cid']??'-'],
      'mid' => [['label'=>'การตรวจ','items'=>[
        ['kv','รายการ',$lab],
        ['pill','ผล',$res],
        ['kvlight','วันที่',flex_thai_date($row['order_date']??($row['lab_date']??''))],
      ]]],
      'contact' => ['address'=>$row['address']??'-','phone'=>$row['hometel']??'-'],
      'alt' => "[แจ้งเตือน] ความรุนแรงทางเพศ HN {$hn} {$fn}",
    ]);
  }
}
