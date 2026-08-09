    </div><!-- /.content-inner -->
  </main><!-- /#ckh-content -->
</div><!-- /#ckh-main -->

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php if (!empty($EXTRA_FOOTER)) echo $EXTRA_FOOTER; ?>

<script>
/* ===== Sidebar toggle ===== */
function ckhToggleSidebar() {
  const sb = document.getElementById('ckh-sidebar');
  const ov = document.getElementById('sidebarOverlay');
  sb.classList.toggle('open');
  ov.classList.toggle('show');
}
function ckhCloseSidebar() {
  document.getElementById('ckh-sidebar').classList.remove('open');
  document.getElementById('sidebarOverlay').classList.remove('show');
}

/* ===== Live clock (Thai format) ===== */
(function () {
  const dateEl = document.getElementById('tbDate');
  const timeEl = document.getElementById('tbTime');
  if (!dateEl || !timeEl) return;

  const thDays = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  const thMonths = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน',
                    'กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];

  function tick() {
    const now = new Date();
    const day   = thDays[now.getDay()];
    const d     = now.getDate();
    const m     = thMonths[now.getMonth()];
    const y     = now.getFullYear() + 543;
    const hh    = String(now.getHours()).padStart(2,'0');
    const mm    = String(now.getMinutes()).padStart(2,'0');
    const ss    = String(now.getSeconds()).padStart(2,'0');
    dateEl.textContent = `${day}ที่ ${d} ${m} พ.ศ. ${y}`;
    timeEl.textContent = `เวลา ${hh}:${mm}:${ss} น.`;
  }
  tick();
  setInterval(tick, 1000);
})();

/* ===== Logout confirm ===== */
document.addEventListener('DOMContentLoaded', function () {
  const btn = document.getElementById('logoutBtn');
  if (!btn) return;
  btn.addEventListener('click', function (e) {
    e.preventDefault();
    Swal.fire({
      title: 'ออกจากระบบ?',
      text: 'คุณต้องการออกจากระบบใช่หรือไม่',
      icon: 'question',
      showCancelButton: true,
      confirmButtonText: '<span class="msi me-1">logout</span> ออกจากระบบ',
      cancelButtonText: 'ยกเลิก',
      reverseButtons: true,
      focusCancel: true,
      confirmButtonColor: '#dc2626',
    }).then(r => { if (r.isConfirmed) window.location.href = 'logout.php'; });
  });

  /* User menu click → logout shortcut */
  const ubtn = document.getElementById('topbarUserBtn');
  if (ubtn) {
    ubtn.addEventListener('click', function () {
      document.getElementById('logoutBtn')?.click();
    });
    ubtn.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); this.click(); }
    });
  }
});

/* ===== Keyboard shortcut: Escape closes sidebar ===== */
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') ckhCloseSidebar();
});
</script>

<!-- ===== Thai datepicker (flatpickr) — แสดง พ.ศ./เดือนไทย, ค่าที่ส่งยังเป็น YYYY-MM-DD ===== -->
<script src="https://cdn.jsdelivr.net/npm/flatpickr@4"></script>
<script>
(function(){
  if (!window.flatpickr) return;
  var M  = ['มกราคม','กุมภาพันธ์','มีนาคม','เมษายน','พฤษภาคม','มิถุนายน','กรกฎาคม','สิงหาคม','กันยายน','ตุลาคม','พฤศจิกายน','ธันวาคม'];
  var Ms = ['ม.ค.','ก.พ.','มี.ค.','เม.ย.','พ.ค.','มิ.ย.','ก.ค.','ส.ค.','ก.ย.','ต.ค.','พ.ย.','ธ.ค.'];
  var D  = ['อาทิตย์','จันทร์','อังคาร','พุธ','พฤหัสบดี','ศุกร์','เสาร์'];
  var Ds = ['อา.','จ.','อ.','พ.','พฤ.','ศ.','ส.'];
  function beYear(inst){                       // แสดงปีในหัวปฏิทินเป็น พ.ศ.
    try { var el = inst.calendarContainer.querySelector('.cur-year');
          if (el) { el.value = inst.currentYear + 543; el.readOnly = true; } } catch(e){}
  }
  flatpickr('input[name="start"], input[name="end"]', {
    dateFormat: 'Y-m-d', altInput: true, altFormat: 'thai',
    disableMobile: true, allowInput: false,
    locale: { firstDayOfWeek: 0, weekdays: { shorthand: Ds, longhand: D },
              months: { shorthand: Ms, longhand: M }, rangeSeparator: ' ถึง ' },
    formatDate: function(date, format){
      if (format === 'thai') return date.getDate() + ' ' + M[date.getMonth()] + ' ' + (date.getFullYear() + 543);
      var y = date.getFullYear(), m = ('0'+(date.getMonth()+1)).slice(-2), d = ('0'+date.getDate()).slice(-2);
      return y + '-' + m + '-' + d;
    },
    onReady:       function(s,v,inst){ beYear(inst); },
    onMonthChange: function(s,v,inst){ beYear(inst); },
    onYearChange:  function(s,v,inst){ beYear(inst); }
  });
})();
</script>
</body>
</html>
