<?php
// Query_Lab.php — viewer สำหรับ Query_Lab.md
// เข้าถึงได้ที่ http://<server>/Fall_Risk_Alert-main/docs/Query_Lab.php
$md = file_get_contents(__DIR__ . '/Query_Lab.md');

// แปลง Markdown → HTML แบบเบา ๆ ไม่ต้องใช้ library
function md2html(string $md): string {
    // code block (``` ... ```)
    $md = preg_replace_callback('/```(\w*)\n(.*?)```/s', function($m) {
        $lang = htmlspecialchars($m[1]);
        $code = htmlspecialchars($m[2]);
        return "<pre class=\"code-block\"><code class=\"language-$lang\">$code</code></pre>";
    }, $md);

    $lines = explode("\n", $md);
    $html  = '';
    $inTable = false;

    foreach ($lines as $i => $line) {
        // headings
        if (preg_match('/^### (.+)/', $line, $m))      { $html .= "<h3>{$m[1]}</h3>\n"; continue; }
        if (preg_match('/^## (.+)/',  $line, $m))      { $html .= "<h2>{$m[1]}</h2>\n"; continue; }
        if (preg_match('/^# (.+)/',   $line, $m))      { $html .= "<h1>{$m[1]}</h1>\n"; continue; }

        // horizontal rule
        if (preg_match('/^---+$/', $line))              { $html .= "<hr>\n"; continue; }

        // table
        if (preg_match('/^\|/', $line)) {
            if (!$inTable) { $html .= "<table class=\"md-table\">\n<thead>\n"; $inTable = true; $isHead = true; }
            else           { $isHead = false; }
            if (preg_match('/^\|[-| ]+\|$/', $line))   { $html .= "</thead>\n<tbody>\n"; continue; }
            $cells = array_slice(explode('|', $line), 1, -1);
            $tag   = $isHead ? 'th' : 'td';
            $html .= '<tr>' . implode('', array_map(fn($c) => "<$tag>".trim(inline($c))."</$tag>", $cells)) . "</tr>\n";
            continue;
        } else {
            if ($inTable) { $html .= "</tbody>\n</table>\n"; $inTable = false; }
        }

        // blockquote
        if (preg_match('/^> (.+)/', $line, $m))        { $html .= "<blockquote>{$m[1]}</blockquote>\n"; continue; }

        // list
        if (preg_match('/^[*\-] (.+)/', $line, $m))    { $html .= "<li>" . inline($m[1]) . "</li>\n"; continue; }

        // empty line
        if (trim($line) === '')                         { $html .= "<br>\n"; continue; }

        // paragraph
        $html .= "<p>" . inline($line) . "</p>\n";
    }
    if ($inTable) $html .= "</tbody>\n</table>\n";
    return $html;
}

function inline(string $s): string {
    $s = htmlspecialchars($s, ENT_NOQUOTES);
    $s = preg_replace('/`([^`]+)`/', '<code>$1</code>', $s);
    $s = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $s);
    $s = preg_replace('/\*([^*]+)\*/',     '<em>$1</em>',         $s);
    return $s;
}

$body = md2html($md);
?><!DOCTYPE html>
<html lang="th">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Query Lab Reference — MedAlert</title>
<link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;600;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
  body { font-family:'Kanit',sans-serif; font-weight:300; background:#f8fafc; color:#1e293b; }
  .page-wrap { max-width:900px; margin:2rem auto; padding:0 1rem 4rem; }
  h1 { font-size:1.8rem; font-weight:700; color:#1A6FBB; border-bottom:3px solid #1A6FBB; padding-bottom:.5rem; margin:1.5rem 0 1rem; }
  h2 { font-size:1.3rem; font-weight:700; color:#1e3a8a; border-left:4px solid #1A6FBB; padding-left:.75rem; margin:1.8rem 0 .8rem; }
  h3 { font-size:1.05rem; font-weight:600; color:#334155; margin:1.4rem 0 .5rem; }
  hr  { border-color:#e2e8f0; margin:1.5rem 0; }
  code { background:#eff6ff; color:#1d4ed8; padding:.15em .4em; border-radius:4px; font-size:.88em; font-family:'Consolas','Courier New',monospace; }
  .code-block { background:#0f172a; color:#e2e8f0; padding:1.1rem 1.25rem; border-radius:10px; overflow-x:auto; font-size:.83rem; line-height:1.65; margin:.75rem 0 1.1rem; }
  .code-block code { background:none; color:inherit; padding:0; font-size:inherit; }
  .md-table { width:100%; border-collapse:collapse; font-size:.9rem; margin:.5rem 0 1.2rem; }
  .md-table th { background:#1e3a8a; color:#fff; padding:.5rem .8rem; text-align:left; font-weight:600; }
  .md-table td { padding:.45rem .8rem; border-bottom:1px solid #e2e8f0; }
  .md-table tr:nth-child(even) td { background:#f1f5f9; }
  blockquote { background:#fefce8; border-left:4px solid #fde047; padding:.6rem 1rem; border-radius:0 8px 8px 0; color:#713f12; margin:.5rem 0; font-size:.9rem; }
  li { margin:.2rem 0; }
  .top-bar { background:linear-gradient(135deg,#1d4ed8,#1A6FBB); color:#fff; padding:.75rem 1.5rem; border-radius:12px; margin-bottom:1.5rem; display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:.5rem; }
  .top-bar h1 { color:#fff; border:none; margin:0; font-size:1.25rem; }
  .back-btn { color:#fff; text-decoration:none; font-size:.85rem; opacity:.85; }
  .back-btn:hover { opacity:1; color:#fff; }
  p:empty, br { display:none; }
</style>
</head>
<body>
<div class="page-wrap">
  <div class="top-bar">
    <h1>📋 Query Lab Reference</h1>
    <a href="../pharm_lab_queue_ui.php" class="back-btn">← กลับหน้าคิว Lab</a>
  </div>
  <?= $body ?>
</div>
</body>
</html>
