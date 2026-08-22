<?php
/**
 * partials/filter_modal.php — ปุ่ม + modal แก้ "เงื่อนไขดึงข้อมูล" ราย module
 * ใช้ในหน้า *_queue_ui.php:
 *   require_once __DIR__ . '/partials/filter_modal.php';
 *   echo filter_flash_html();                 // แถบผลลัพธ์ (หลังบันทึก)
 *   echo filter_edit_button('dengue');        // ปุ่มเปิด modal (วางใน toolbar)
 *   render_filter_modal('dengue');            // ตัว modal (วางท้ายหน้า ก่อน footer)
 * ต้องมี Bootstrap 5 JS (bootstrap.bundle) โหลดแล้ว (partials/footer.php)
 */

if (!function_exists('filter_flash_html')) {
  function filter_flash_html(): string {
    $map = [
      'saved'    => ['success', 'บันทึกเงื่อนไขแล้ว ✓ (มีผลกับการดึงข้อมูลครั้งถัดไป ทั้ง auto และ Import)'],
      'reset'    => ['success', 'รีเซ็ตกลับค่าเริ่มต้นแล้ว ✓'],
      'err'      => ['danger',  'บันทึกไม่สำเร็จ — ตรวจสิทธิ์โฟลเดอร์ secrets/'],
      'badtoken' => ['danger',  'Token ไม่ถูกต้อง — refresh หน้าแล้วลองใหม่'],
      'badmod'   => ['danger',  'module ไม่ถูกต้อง'],
    ];
    $f = $_GET['flt'] ?? '';
    if (!isset($map[$f])) return '';
    [$cls, $msg] = $map[$f];
    return '<div class="alert alert-' . $cls . ' alert-dismissible fade show d-flex align-items-center gap-2" '
         . 'role="alert" style="border-radius:10px;font-size:.9rem">'
         . '<span class="msi">' . ($cls === 'success' ? 'check_circle' : 'error') . '</span>'
         . htmlspecialchars($msg)
         . '<button type="button" class="btn-close ms-auto" data-bs-dismiss="alert" aria-label="Close"></button></div>';
  }
}

if (!function_exists('filter_edit_button')) {
  function filter_edit_button(string $mod): string {
    return '<button type="button" class="btn btn-outline-secondary btn-sm" '
         . 'data-bs-toggle="modal" data-bs-target="#filterModal_' . htmlspecialchars($mod) . '">'
         . '<span class="msi me-1">tune</span>แก้ไขเงื่อนไขดึงข้อมูล</button>';
  }
}

if (!function_exists('render_filter_modal')) {
  function render_filter_modal(string $mod, ?string $back = null): void {
    $schema = module_filter_schema($mod);
    if (!$schema) return;
    $cur   = module_filter($mod);
    $back  = $back ?: basename((string)($_SERVER['PHP_SELF'] ?? 'index.php'));
    $token = defined('UI_ACTION_TOKEN') ? UI_ACTION_TOKEN : '';
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="modal fade" id="filterModal_<?= $e($mod) ?>" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
          <form method="post" action="module_filter_action.php" autocomplete="off">
            <input type="hidden" name="module" value="<?= $e($mod) ?>">
            <input type="hidden" name="token"  value="<?= $e($token) ?>">
            <input type="hidden" name="back"   value="<?= $e($back) ?>">
            <div class="modal-header">
              <h5 class="modal-title"><span class="msi me-2" style="color:var(--blue)">tune</span>เงื่อนไขดึงข้อมูล — <?= $e($schema['label'] ?? $mod) ?></h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="alert alert-info py-2 d-flex align-items-start gap-2" style="font-size:.83rem;border-radius:10px">
                <span class="msi" style="font-size:1rem">info</span>
                <span>แก้แล้วมีผลกับ<b>ทั้งการส่งอัตโนมัติและปุ่ม Import</b> · เฉพาะเคสที่ดึงเข้ามา<b>ใหม่</b> (ของเดิมในคิวไม่เปลี่ยน)</span>
              </div>
              <?php foreach ($schema['fields'] as $f): $k = $f['key']; $v = $cur[$k] ?? null; ?>
                <div class="mb-3">
                  <label class="form-label fw-semibold" style="font-size:.85rem"><?= $e($f['label']) ?></label>
                  <?php if ($f['type'] === 'codes'): ?>
                    <input type="text" name="f_<?= $e($k) ?>" class="form-control"
                           value="<?= $e(implode(', ', is_array($v) ? $v : [])) ?>"
                           placeholder="คั่นด้วย , เช่น 3066, 3082, 3084">
                  <?php elseif ($f['type'] === 'results'): ?>
                    <input type="text" name="f_<?= $e($k) ?>" class="form-control"
                           value="<?= $e(implode(', ', is_array($v) ? $v : [])) ?>"
                           placeholder="คั่นด้วย , เช่น Positive, Weakly Positive">
                  <?php elseif ($f['type'] === 'single'): ?>
                    <input type="text" name="f_<?= $e($k) ?>" class="form-control" style="max-width:220px"
                           value="<?= $e((string)$v) ?>">
                  <?php elseif ($f['type'] === 'int'): ?>
                    <input type="number" name="f_<?= $e($k) ?>" class="form-control" style="max-width:140px"
                           value="<?= $e((string)$v) ?>" min="0" max="150">
                  <?php elseif ($f['type'] === 'patterns'): ?>
                    <input type="text" name="f_<?= $e($k) ?>" class="form-control"
                           value="<?= $e(mf_patterns_to_text(is_array($v) ? $v : [])) ?>"
                           placeholder="เช่น T71, X60*, A90-A99">
                  <?php elseif ($f['type'] === 'rules'): ?>
                    <textarea name="f_<?= $e($k) ?>" class="form-control font-monospace" rows="5"
                              placeholder="ชื่อ | รหัส Lab | >= หรือ > | ค่า | yes/no"
                              ><?= $e(mf_rules_to_text(is_array($v) ? $v : [])) ?></textarea>
                  <?php else: ?>
                    <div class="text-muted" style="font-size:.83rem">ฟิลด์ประเภทนี้ยังไม่รองรับการแก้ไข</div>
                  <?php endif; ?>
                  <?php if (!empty($f['hint'])): ?>
                    <div class="form-text" style="font-size:.75rem"><?= $e($f['hint']) ?></div>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
            <div class="modal-footer justify-content-between">
              <button type="submit" name="action" value="reset" class="btn btn-outline-danger btn-sm"
                      onclick="return confirm('รีเซ็ตกลับค่าเริ่มต้นของ module นี้?')">
                <span class="msi me-1">restart_alt</span>รีเซ็ตค่าเริ่มต้น</button>
              <div class="d-flex gap-2">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="submit" name="action" value="save" class="btn btn-primary"><span class="msi me-1">save</span>บันทึก</button>
              </div>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
  }
}
