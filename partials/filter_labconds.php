<?php
/**
 * partials/filter_labconds.php — field type "labconds" ของ filter modal
 *
 * ฟอร์มเงื่อนไขค่าผลตรวจต่อชุด lab_items_code ที่ "เพิ่ม/ลบได้"
 *   1 ฟอร์ม (group) = lab_items_code หลายรหัส (คั่น ,) + เงื่อนไขหลายแถว
 *   1 แถว           = ติ๊ก  <  >  =  (ติ๊กร่วมกันได้ → <= >= <>) + ค่าที่กรอกเอง
 *   ในฟอร์มรวมกันแบบ OR · ระหว่างฟอร์มก็ OR (ค่าผิดปกติ = ต่ำเกินหรือสูงเกิน)
 *
 * ชื่อ input: f_<key>[gi][codes] · f_<key>[gi][conds][ci][ops][] · [ci][value]
 * → PHP รับเป็น array ซ้อนตรง ๆ (mf_parse_labconds) ไม่ต้อง parse string เอง
 *
 * DOM เป็นของ JS ทั้งก้อน (เพราะเพิ่ม/ลบได้) — PHP ส่งค่าเริ่มต้นมาทาง data-init
 */

if (!function_exists('lc_render_field')) {
  function lc_render_field(string $mod, string $key, $value): void {
    $init = array_values(is_array($value) ? $value : []);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="lc-wrap" data-key="<?= $e($key) ?>"
         data-init='<?= $e(json_encode($init, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION)) ?>'>
      <div class="lc-groups"></div>
      <button type="button" class="btn btn-outline-primary btn-sm mt-2 lc-add-group">
        <span class="msi me-1">add</span>เพิ่มฟอร์ม (lab_items_code ชุดใหม่)
      </button>
    </div>
    <?php
  }
}

if (!function_exists('lc_render_assets')) {
  /** พิมพ์ CSS+JS ครั้งเดียวต่อหน้า แม้จะมีหลาย modal */
  function lc_render_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
      .lc-group { background: var(--surface-2, #f8fafc); }
      .lc-cond .btn-check + .btn { min-width: 42px; font-weight: 700; }
      .lc-preview { font-family: ui-monospace, monospace; }
    </style>
    <script>
    (function () {
      if (window.__lcReady) return;
      window.__lcReady = true;

      var OPS = [
        { v: 'lt', t: '<' },
        { v: 'gt', t: '>' },
        { v: 'eq', t: '=' }
      ];

      function esc(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;'); }

      function condRow(key, gi, ci, cond) {
        var ops = (cond && cond.ops) || [];
        var nm  = 'f_' + key + '[' + gi + '][conds][' + ci + ']';
        var val = (cond && cond.value !== undefined && cond.value !== null) ? cond.value : '';
        var btns = OPS.map(function (o) {
          var id = 'lc_' + key + '_' + gi + '_' + ci + '_' + o.v;
          var ck = ops.indexOf(o.v) >= 0 ? ' checked' : '';
          return '<input type="checkbox" class="btn-check" name="' + nm + '[ops][]" value="' + o.v + '" id="' + id + '"' + ck + '>'
               + '<label class="btn btn-outline-secondary" for="' + id + '">' + o.t + '</label>';
        }).join('');
        return '<div class="lc-cond d-flex align-items-center gap-2 mb-2 flex-wrap">'
             + '<div class="btn-group btn-group-sm">' + btns + '</div>'
             + '<span class="text-muted" style="font-size:.78rem">lab_order_result</span>'
             + '<input type="number" step="any" class="form-control form-control-sm" style="max-width:120px" '
             + 'name="' + nm + '[value]" value="' + esc(val) + '" placeholder="ค่า">'
             + '<button type="button" class="btn btn-sm btn-outline-danger lc-del-cond" title="ลบแถวนี้">'
             + '<span class="msi">close</span></button>'
             + '<span class="lc-preview text-muted" style="font-size:.78rem"></span>'
             + '</div>';
      }

      function groupBox(key, gi, grp) {
        var codes = (grp && grp.codes) ? grp.codes.join(', ') : '';
        var conds = (grp && grp.conds && grp.conds.length) ? grp.conds : [{}];
        var rows  = '';
        for (var i = 0; i < conds.length; i++) rows += condRow(key, gi, i, conds[i]);
        return '<div class="lc-group border rounded p-3 mb-2" data-gi="' + gi + '">'
             + '<div class="d-flex align-items-center gap-2 mb-2">'
             + '<label class="form-label mb-0 fw-semibold" style="font-size:.8rem;white-space:nowrap">lab_items_code</label>'
             + '<input type="text" class="form-control form-control-sm" name="f_' + key + '[' + gi + '][codes]" '
             + 'value="' + esc(codes) + '" placeholder="คั่นด้วย , เช่น 51, 52">'
             + '<button type="button" class="btn btn-sm btn-outline-danger lc-del-group" title="ลบฟอร์มนี้">'
             + '<span class="msi">delete</span></button>'
             + '</div>'
             + '<div class="lc-conds ps-1">' + rows + '</div>'
             + '<button type="button" class="btn btn-outline-secondary btn-sm lc-add-cond">'
             + '<span class="msi me-1">add</span>เพิ่มเงื่อนไข (รวมกันแบบ "หรือ")</button>'
             + '</div>';
      }

      // ติ๊ก < กับ = = <= · ติ๊กครบ 3 = จริงเสมอ ไม่ใช่เงื่อนไข → เตือนไว้ให้เห็นตั้งแต่ตอนกรอก
      function syncPreview(row) {
        var on = Array.prototype.filter.call(row.querySelectorAll('.btn-check'), function (c) { return c.checked; })
                  .map(function (c) { return c.value; });
        var lt = on.indexOf('lt') >= 0, gt = on.indexOf('gt') >= 0, eq = on.indexOf('eq') >= 0;
        var op = '';
        if (lt && gt && eq)   op = '!';
        else if (lt && gt)    op = '<>';
        else if (lt && eq)    op = '<=';
        else if (gt && eq)    op = '>=';
        else if (lt)          op = '<';
        else if (gt)          op = '>';
        else if (eq)          op = '=';
        var v  = row.querySelector('input[type=number]').value;
        var el = row.querySelector('.lc-preview');
        if (op === '!')    el.innerHTML = '<span class="text-danger">ติ๊กครบ 3 = จริงเสมอ ระบบจะข้ามแถวนี้</span>';
        else if (!op)      el.innerHTML = '<span class="text-danger">ยังไม่ได้เลือกเงื่อนไข</span>';
        else if (v === '') el.innerHTML = '<span class="text-danger">ยังไม่ได้กรอกค่า</span>';
        else               el.textContent = '→ ' + op + ' ' + v;
      }

      function initWrap(wrap) {
        var key  = wrap.getAttribute('data-key');
        var host = wrap.querySelector('.lc-groups');
        var gi   = 0;
        var init = [];
        try { init = JSON.parse(wrap.getAttribute('data-init') || '[]'); } catch (e) { init = []; }
        if (!init.length) init = [{}];
        init.forEach(function (g) { host.insertAdjacentHTML('beforeend', groupBox(key, gi++, g)); });

        function refresh() {
          Array.prototype.forEach.call(wrap.querySelectorAll('.lc-cond'), syncPreview);
        }

        wrap.addEventListener('click', function (ev) {
          var t = ev.target.closest ? ev.target.closest('button') : null;
          if (!t || !wrap.contains(t)) return;
          if (t.classList.contains('lc-add-group')) {
            host.insertAdjacentHTML('beforeend', groupBox(key, gi++, {}));
          } else if (t.classList.contains('lc-add-cond')) {
            var grp = t.closest('.lc-group');
            // ci ต้องไม่ชนของเดิมหลังลบแถวกลาง — ใช้ตัวนับที่โตอย่างเดียว
            var ci  = (parseInt(grp.getAttribute('data-nextci') || '0', 10) || grp.querySelectorAll('.lc-cond').length) + 1;
            grp.setAttribute('data-nextci', String(ci));
            grp.querySelector('.lc-conds').insertAdjacentHTML('beforeend', condRow(key, grp.getAttribute('data-gi'), ci, {}));
          } else if (t.classList.contains('lc-del-cond')) {
            var g2 = t.closest('.lc-group');
            if (g2.querySelectorAll('.lc-cond').length > 1) t.closest('.lc-cond').remove();
          } else if (t.classList.contains('lc-del-group')) {
            if (host.querySelectorAll('.lc-group').length > 1) t.closest('.lc-group').remove();
          } else {
            return;
          }
          refresh();
        });

        ['change', 'input'].forEach(function (evt) {
          wrap.addEventListener(evt, function (ev) {
            var r = ev.target.closest ? ev.target.closest('.lc-cond') : null;
            if (r) syncPreview(r);
          });
        });

        refresh();
      }

      function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.lc-wrap'), initWrap);
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();
    })();
    </script>
    <?php
  }
}
