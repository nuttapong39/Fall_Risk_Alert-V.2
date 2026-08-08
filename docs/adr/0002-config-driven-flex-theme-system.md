---
status: accepted
---

# Flex Message แบบ config-driven ด้วย renderer กลางตัวเดียว

เดิม Alert Module ทั้ง 10 ตัวมี Flex builder ของตัวเอง (`flex_fracture.php`, `flex_pharm.php`, `flex_disease.php`, `covid_lib.php` ฯลฯ) แต่ละไฟล์ **hardcode สี/ข้อความ/layout** เป็นสำเนาที่คล้ายแต่ไม่เหมือนกัน ทำให้การ์ดแต่ละโรคหน้าตาไม่สม่ำเสมอ, แก้สี/ข้อความต้องเข้าไปแก้โค้ดทีละไฟล์, และเจ้าหน้าที่แก้เองไม่ได้

เราจึงเปลี่ยนเป็น **config-driven**: **layout กลางตัวเดียว** `flex_render_card($module, $data)` ใน `flex_card.php` (gradient header โค้ง + section เว้นวรรค + accent + bubble mega); **theme ต่อ module** (title/subtitle/urgency/สี gradient/accent/มุม/bg_icon_url + global footer/labels) เก็บใน `secrets/flex_themes.json` โหลดผ่าน `flex_theme.php` (มี default ฝังในตัว); **thin builder** ต่อ module ใน `flex_builders.php` map ข้อมูลแถว → renderer; และหน้า admin `flex_editor.php` แก้ theme พร้อม live preview + validate hex เขียนกลับ JSON

## Considered Options

- **renderer กลาง + JSON config + editor (เลือก)** — การ์ดสม่ำเสมอทุก module, แก้สี/ข้อความผ่าน UI ได้โดยไม่แตะโค้ด, แก้ layout ที่เดียว
- คง builder hardcode ต่อ module — **ปฏิเสธ**: หน้าตาไม่สม่ำเสมอ, แก้ยาก, เจ้าหน้าที่แก้เองไม่ได้
- theme เก็บใน DB + editor ลาก-วาง layout เต็ม — **ปฏิเสธ**: YAGNI + เสี่ยง (ต้อง validate LINE ทุกครั้ง) JSON + แก้ text/สี พอสำหรับความต้องการจริง
- เก็บ theme เป็น constant/PHP — **ปฏิเสธ**: editor เขียนกลับไม่ได้ JSON แก้ผ่าน UI + fallback default ในโค้ดได้ทั้งคู่

## Consequences

- **สีต้องเป็น hex เท่านั้น (`#RRGGBB`/`#RRGGBBAA`)** — LINE Flex ปฏิเสธ bubble ทั้งใบเงียบ ๆ ถ้าเจอ `rgba()`/ชื่อสี ขณะที่ MOPH Alert ยังตอบ `HTTP 200 {"status":200,"Succesfully"}` → `flex_theme()` sanitize ทุกสีเป็น hex, editor บังคับ hex. **บทเรียน: MOPH 200 ≠ LINE render สำเร็จ ต้องเช็คใน LINE จริง**
- **Watermark ต้องเป็น PNG/JPEG บน HTTPS สาธารณะ** — LINE `image` ไม่รับ SVG (SVG ขึ้นบน desktop LINE แต่ **มือถือ native ไม่ render** ซึ่งเป็นช่องทางดูหลัก) และ **ไม่มี property opacity** → ต้องอบความจางลงในไฟล์ PNG เอง (แปลง SVG→PNG ขาวโปร่งใส ~60% ด้วย `@resvg/resvg-js`, ต้นฉบับ SVG อยู่ `assets/flex_icons/`, host ที่ `ckhospital.net/home/PDF/`). วางเป็น watermark ด้วย image `position:absolute` ในหัว gradient (พิสูจน์แล้วว่า render)
- **กล่อง `flex:0` ยุบจนตัวอักษรถูก clip ใน LINE** — badge/pill ที่เป็น box มีพื้นหลังจะโชว์แต่กล่องสีไม่มีตัวอักษร → ค่าผลตรวจใช้ **ข้อความสี accent ตัวหนา** แทน (เหมือน ICD ที่ flex:0 **text** render ได้เสมอ)
- **กลไก override builder:** build function เดิมส่วนใหญ่ไม่มี `function_exists` guard และ PHP hoist top-level function ตอน compile → ถ้าปล่อยไว้จะ redeclare fatal. แก้โดย `require flex_builders.php` ที่หัวไฟล์ `flex_*.php` + **rename ตัวเดิมเป็น `buildXxxPayload_legacy`** (dead code) ให้เวอร์ชัน config-driven ชนะ
- `flex_themes.json` เป็น runtime config (แก้ผ่าน `flex_editor.php`) builder อ่านค่าใหม่ทันทีในการยิงครั้งถัดไป — ไม่ต้อง deploy โค้ด
- gradient หัวการ์ดใช้ `background: {type:linearGradient}` (LINE รองรับ); การ์ดใช้ bubble `mega` + header โค้งลอยในการ์ดขาว เพื่อขอบมนขึ้น (bubble ตัวนอก LINE ล็อกรัศมี)
- ดู term "Flex Theme" ใน CONTEXT.md · signature `buildXxxPayload()` เดิมคงไว้ caller เดิมไม่ต้องแก้
