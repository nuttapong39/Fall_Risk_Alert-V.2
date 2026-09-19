<?php
/**
 * partials/filter_drugpicker.php — field type "drugpicker" ของ filter modal
 *
 * ค้นยาจากตาราง drugitems (HOSxP) แบบ real-time ผ่าน search_drug.php แล้วให้ผู้ใช้กด
 * เพิ่มเป็น tag เอง (เหมือน tag-input) แทนการพิมพ์ icode คั่น , เอง — ค่าที่เก็บจริงยังเป็น
 * array ของ icode ล้วน (ผ่าน mf_codes()) ชนิดเดียวกับ field type 'codes'
 *
 * ชื่อ input ของแต่ละ tag: f_<key>[] (ค่า = icode) — ฝั่ง server อ่านเป็น array ธรรมดา
 * (module_filters_loader.php เพิ่ม case 'drugpicker' ให้ผ่าน mf_codes() เหมือน 'codes')
 *
 * DOM เป็นของ JS ทั้งก้อน (ค้นหา/เพิ่ม/ลบ tag แบบ real-time) — PHP ส่งค่าเริ่มต้นผ่าน data-init
 * การ์ดเด่น "รายการยาที่เฝ้าระวัง" บนหน้า drugs_alert.php ก็เรียก dp_render_field()/dp_render_assets()
 * ชุดเดียวกันนี้ (ใช้ id widget คนละตัวได้ในหน้าเดียวกัน)
 */

if (!function_exists('dp_render_field')) {
  function dp_render_field(string $mod, string $key, $value, string $widgetId = ''): void {
    $init = array_values(is_array($value) ? $value : []);
    $wid  = $widgetId !== '' ? $widgetId : ('dp_' . $mod . '_' . $key);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    ?>
    <div class="dp-wrap" id="<?= $e($wid) ?>"
         data-field-name="f_<?= $e($key) ?>[]"
         data-init='<?= $e(json_encode($init, JSON_UNESCAPED_UNICODE)) ?>'>
      <div class="dp-searchbox mb-2">
        <div class="input-group input-group-sm">
          <span class="input-group-text"><span class="msi" style="font-size:1rem">search</span></span>
          <input type="text" class="form-control dp-q" placeholder="ค้นชื่อยาหรือรหัสยา (icode) — พิมพ์อย่างน้อย 2 ตัวอักษร">
        </div>
        <div class="form-check mt-1">
          <input type="checkbox" class="form-check-input dp-inactive" id="<?= $e($wid) ?>_inactive">
          <label class="form-check-label text-muted" style="font-size:.78rem" for="<?= $e($wid) ?>_inactive">
            รวมยาที่เลิกใช้แล้ว
          </label>
        </div>
        <div class="dp-results list-group mt-1" style="display:none;max-height:260px;overflow-y:auto;position:relative;z-index:5"></div>
      </div>
      <div class="dp-tags d-flex flex-wrap gap-2"></div>
    </div>
    <?php
  }
}

if (!function_exists('dp_render_assets')) {
  /** พิมพ์ CSS+JS ครั้งเดียวต่อหน้า แม้จะมี widget หลายตัว (modal + การ์ดเด่น) */
  function dp_render_assets(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    ?>
    <style>
      .dp-result-item { cursor: pointer; font-size: .82rem; }
      .dp-result-item:hover { background: var(--blue-50, #eff6ff); }
      .dp-result-item .dp-badge-inactive {
        font-size: .68rem; background: #e5e7eb; color: #4b5563; border-radius: 999px; padding: 1px 7px;
      }
      .dp-tag {
        display: flex; align-items: center; gap: 6px;
        background: var(--card-bg, #fff); border: 1px solid var(--card-border, #e2e8f0);
        border-radius: 999px; padding: 5px 6px 5px 12px; font-size: .82rem;
      }
      .dp-tag .dp-tag-name { font-weight: 600; }
      .dp-tag .dp-tag-meta { color: var(--muted, #64748b); font-size: .74rem; }
      .dp-tag .dp-tag-count { color: #0e7490; font-size: .72rem; font-weight: 600; }
      .dp-tag.dp-tag-inactive { opacity: .65; }
      .dp-tag-del {
        border: none; background: transparent; color: #dc2626; cursor: pointer;
        display: grid; place-items: center; width: 22px; height: 22px; border-radius: 50%;
      }
      .dp-tag-del:hover { background: #fee2e2; }
      .dp-empty { color: var(--muted, #64748b); font-size: .82rem; }
    </style>
    <script>
    (function () {
      if (window.__dpReady) return;
      window.__dpReady = true;

      function debounce(fn, ms) {
        var t = null;
        return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); };
      }

      function fmtDrug(d) {
        var parts = [];
        if (d.strength) parts.push(d.strength + (d.units ? (' ' + d.units) : ''));
        if (d.dosageform) parts.push(d.dosageform);
        if (d.drugcategory) parts.push(d.drugcategory);
        return parts.join(' · ');
      }

      function makeResultItem(d) {
        var row = document.createElement('div');
        row.className = 'dp-result-item list-group-item d-flex justify-content-between align-items-center gap-2';
        row.dataset.icode = d.icode;

        var left = document.createElement('div');
        var name = document.createElement('div');
        name.className = 'fw-semibold';
        name.textContent = (d.name || '-') + '  (' + d.icode + ')';
        var meta = document.createElement('div');
        meta.className = 'text-muted';
        meta.style.fontSize = '.74rem';
        meta.textContent = fmtDrug(d) + (d.therapeutic ? ' · ' + d.therapeutic : '');
        left.appendChild(name); left.appendChild(meta);
        row.appendChild(left);

        if (d.istatus === 'N') {
          var b = document.createElement('span');
          b.className = 'dp-badge-inactive';
          b.textContent = 'เลิกใช้';
          row.appendChild(b);
        }
        return row;
      }

      function makeTag(wrap, d) {
        var tag = document.createElement('span');
        tag.className = 'dp-tag' + (d.istatus === 'N' ? ' dp-tag-inactive' : '');
        tag.dataset.icode = d.icode;

        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = wrap.dataset.fieldName;
        input.value = d.icode;
        tag.appendChild(input);

        var nameEl = document.createElement('span');
        nameEl.className = 'dp-tag-name';
        nameEl.textContent = d.name || d.icode;
        tag.appendChild(nameEl);

        var metaEl = document.createElement('span');
        metaEl.className = 'dp-tag-meta';
        metaEl.textContent = fmtDrug(d);
        tag.appendChild(metaEl);

        var countEl = document.createElement('span');
        countEl.className = 'dp-tag-count';
        countEl.textContent = '…';
        tag.appendChild(countEl);

        var del = document.createElement('button');
        del.type = 'button'; del.className = 'dp-tag-del'; del.title = 'ลบยานี้ออกจากรายการ';
        del.innerHTML = '<span class="msi" style="font-size:.9rem">close</span>';
        del.addEventListener('click', function () {
          tag.remove();
          refreshEmptyState(wrap);
        });
        tag.appendChild(del);

        return tag;
      }

      function refreshEmptyState(wrap) {
        var tagsHost = wrap.querySelector('.dp-tags');
        if (!tagsHost.querySelector('.dp-tag') && !tagsHost.querySelector('.dp-empty')) {
          var e = document.createElement('div');
          e.className = 'dp-empty';
          e.textContent = 'ยังไม่มีรายการยา — ค้นหาแล้วกดเพิ่มด้านบน';
          tagsHost.appendChild(e);
        } else if (tagsHost.querySelector('.dp-tag')) {
          var empty = tagsHost.querySelector('.dp-empty');
          if (empty) empty.remove();
        }
      }

      function currentIcodes(wrap) {
        return Array.prototype.map.call(wrap.querySelectorAll('.dp-tag'), function (t) { return t.dataset.icode; });
      }

      function refreshCounts(wrap) {
        var icodes = currentIcodes(wrap);
        if (!icodes.length) return;
        var today = new Date();
        var yday  = new Date(today.getTime() - 86400000);
        function ymd(d) { return d.toISOString().slice(0, 10); }
        fetch('search_drug.php?action=count&icodes=' + encodeURIComponent(icodes.join(',')) +
              '&start=' + ymd(yday) + '&end=' + ymd(today))
          .then(function (r) { return r.json(); })
          .then(function (j) {
            if (!j.ok) return;
            Array.prototype.forEach.call(wrap.querySelectorAll('.dp-tag'), function (tag) {
              var n = j.counts[tag.dataset.icode];
              var el = tag.querySelector('.dp-tag-count');
              if (el) el.textContent = 'จ่ายเมื่อวาน+วันนี้ ' + (n || 0) + ' ราย';
            });
          })
          .catch(function () {});
      }

      function addTag(wrap, d) {
        if (currentIcodes(wrap).indexOf(d.icode) !== -1) return; // กันเพิ่มซ้ำ
        var tagsHost = wrap.querySelector('.dp-tags');
        var empty = tagsHost.querySelector('.dp-empty');
        if (empty) empty.remove();
        tagsHost.appendChild(makeTag(wrap, d));
        refreshCounts(wrap);
      }

      function initWrap(wrap) {
        var q         = wrap.querySelector('.dp-q');
        var results   = wrap.querySelector('.dp-results');
        var inactive  = wrap.querySelector('.dp-inactive');
        var tagsHost  = wrap.querySelector('.dp-tags');

        var init = [];
        try { init = JSON.parse(wrap.getAttribute('data-init') || '[]'); } catch (e) { init = []; }

        function renderResults(items) {
          results.innerHTML = '';
          if (!items.length) { results.style.display = 'none'; return; }
          items.forEach(function (d) {
            var row = makeResultItem(d);
            row.addEventListener('click', function () {
              addTag(wrap, d);
              q.value = '';
              results.style.display = 'none';
            });
            results.appendChild(row);
          });
          results.style.display = 'block';
        }

        var doSearch = debounce(function () {
          var term = q.value.trim();
          if (term.length < 2) { results.style.display = 'none'; return; }
          fetch('search_drug.php?action=search&q=' + encodeURIComponent(term) +
                (inactive.checked ? '&inactive=1' : ''))
            .then(function (r) { return r.json(); })
            .then(function (j) { renderResults(j.ok ? j.items : []); })
            .catch(function () { renderResults([]); });
        }, 300);

        q.addEventListener('input', doSearch);
        inactive.addEventListener('change', doSearch);
        document.addEventListener('click', function (ev) {
          if (!wrap.contains(ev.target)) results.style.display = 'none';
        });

        // เติมชื่อยา/รายละเอียดให้ tag ที่บันทึกไว้แล้ว (ค่าเริ่มต้นมีแค่ icode)
        if (init.length) {
          fetch('search_drug.php?action=lookup&icodes=' + encodeURIComponent(init.join(',')))
            .then(function (r) { return r.json(); })
            .then(function (j) {
              var byIcode = {};
              (j.ok ? j.items : []).forEach(function (d) { byIcode[d.icode] = d; });
              init.forEach(function (ic) {
                tagsHost.appendChild(makeTag(wrap, byIcode[ic] || { icode: ic, name: ic }));
              });
              refreshEmptyState(wrap);
              refreshCounts(wrap);
            })
            .catch(function () { refreshEmptyState(wrap); });
        } else {
          refreshEmptyState(wrap);
        }
      }

      function boot() {
        Array.prototype.forEach.call(document.querySelectorAll('.dp-wrap'), function (w) {
          if (w.__dpInit) return;
          w.__dpInit = true;
          initWrap(w);
        });
      }
      if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
      else boot();
    })();
    </script>
    <?php
  }
}
