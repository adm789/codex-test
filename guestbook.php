<?php
error_reporting(0);
date_default_timezone_set('Asia/Taipei');

$dataFile = 'some.htm';
$oldFile  = 'guest_data.txt';

function linkify($text) {
    $imgExts = 'jpe?g|png|gif|webp|svg|bmp';
    $parts    = preg_split('/(<[^>]+>)/s', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
    $result   = '';
    $inAnchor = false;
    foreach ($parts as $part) {
        if (preg_match('/^<[^>]+>$/s', $part)) {
            if (preg_match('/^<a[\s>]/i',  $part)) $inAnchor = true;
            if (preg_match('/^<\/a\s*>/i', $part)) $inAnchor = false;
            $result .= $part;
        } elseif ($inAnchor) {
            $result .= $part;
        } else {
            $result .= preg_replace_callback(
                '/(https?:\/\/[^\s<>"\'\`，、《》。！？“”]+)/i',
                function($m) use ($imgExts) {
                    $url      = $m[1];
                    $cleanUrl = preg_replace('/\?.*$/', '', $url);
                    if (preg_match('/\.(' . $imgExts . ')$/i', $cleanUrl)) {
                        $esc = htmlspecialchars($url, ENT_QUOTES);
                        return '<a target="_blank" href="'.$esc.'">'
                             . '<img src="'.$esc.'" style="max-height:80px;max-width:160px;'
                             . 'display:inline-block;margin:2px 4px 2px 0;'
                             . 'border:1px solid #ddd;border-radius:3px;cursor:pointer;" alt="img">'
                             . '</a>';
                    }
                    return '<a target="_blank" href="' . htmlspecialchars($url, ENT_QUOTES)
                         . '">' . htmlspecialchars($url, ENT_QUOTES) . '</a>';
                },
                $part
            );
        }
    }
    return $result;
}

function shouldPackLongText($text) {
    $normalized = str_replace("\r\n", "\n", str_replace("\r", "\n", (string)$text));
    $lines = explode("\n", $normalized);
    $charCount = preg_match_all('/\S/u', $normalized, $m);
    return count($lines) >= 8 || (count($lines) >= 3 && $charCount > 300);
}

function makeLongTextBlock($longText) {
    $longText = trim($longText);
    $id = 'lt_' . md5($longText) . '_' . substr(str_replace('.', '', uniqid('', true)), -10);
    $lineCount = substr_count($longText, "\n") + 1;
    $escapedText = htmlspecialchars($longText, ENT_QUOTES, 'UTF-8');
    $previewLines = array_slice(explode("\n", $longText), 0, 2);
    $preview = implode("\n", $previewLines);
    if ($lineCount > 2) $preview .= " …";
    $escapedPreview = htmlspecialchars($preview, ENT_QUOTES, 'UTF-8');
    $labelCollapsed = '📄 ' . $lineCount . ' 行長文（點擊展開）';
    $labelExpanded  = '📄 收起長文';

    return '<div class="long-text-wrapper">'
         . '<div class="long-text-preview" data-preview-for="' . $id . '">' . $escapedPreview . '</div>'
         . '<button class="btn-expand" onclick="toggleLongText(this)" data-target="' . $id . '"'
         . ' data-label-collapsed="' . htmlspecialchars($labelCollapsed, ENT_QUOTES, 'UTF-8') . '"'
         . ' data-label-expanded="' . htmlspecialchars($labelExpanded, ENT_QUOTES, 'UTF-8') . '">'
         . $labelCollapsed
         . '</button>'
         . '<div id="' . $id . '" class="long-text-content" style="display:none;margin-top:6px;padding:8px 12px;background:#f8f8f8;border-radius:4px;white-space:pre-wrap;border-left:3px solid #667eea;font-size:13px;line-height:1.6;">'
         . $escapedText
         . '</div>'
         . '</div>';
}

function processMsg($msg, $packLongText = true) {
    $btnBlocks = [];
    $msg = preg_replace_callback(
        '/<button\b([^>]*)>(.*?)<\/button>/is',
        function($bm) use (&$btnBlocks) {
            $key = '%%BTN'.count($btnBlocks).'%%';
            $html = $bm[0];
            $html = preg_replace('/\(\)=(?!>)/', '()=>', $html);
            $btnBlocks[$key] = $html;
            return $key;
        },
        $msg
    );
    $msg = preg_replace('/<\/button>/i', '', $msg);

    $msg = preg_replace_callback(
        '/<(\w+)((?:\s[^>]*)?)>/s',
        function($tm) {
            $tag   = $tm[1];
            $attrs = isset($tm[2]) ? $tm[2] : '';
            $attrs = preg_replace('/(\w[\w-]*)=(?!["\'])([^\s>]+)/', '$1="$2"', $attrs);
            $attrs = preg_replace_callback(
                '/((?:src|href)=")([^"]*?)(")/i',
                function($am) {
                    $val = $am[2];
                    if (preg_match('/^file:\/\//i', $val))         return $am[1].'#local'.$am[3];
                    if (preg_match('/^[a-zA-Z]:[\\\\\/]/', $val))  return $am[1].'#local'.$am[3];
                    $val = preg_replace('/^[a-z]{1,6}(https?:\/\/)/i', '$1', $val);
                    $val = preg_replace('/@\w+$/', '', $val);
                    return $am[1].$val.$am[3];
                },
                $attrs
            );
            
            if (strtolower($tag) === 'button') {
                return '<' . $tag . $tm[2] . '>';
            }
            
            if (strtolower($tag) === 'img') {
                $attrs = preg_replace('/\s+(?:target|rel)="[^"]*"/', '', $attrs);
            } else {
                $attrs = preg_replace('/\s+rel="[^"]*"/', '', $attrs);
                if (preg_match('/(\s+href="[^"]*")(.*?)(\s+target="[^"]*")/s', $attrs, $tm)) {
                    $attrs = str_replace($tm[0], $tm[3].$tm[2].$tm[1], $attrs);
                }
            }
            return '<'.$tag.$attrs.'>';
        },
        $msg
    );
    
    // ===== 長文本打包（新增） =====
    if ($packLongText) {
        $msg = preg_replace_callback(
            '/((?:[^\n]+\n?){8,})/s',  // 8 行以上觸發打包
            function($m) {
                $longText = trim($m[1]);
                // 避免重複處理已打包的內容
                if (strpos($longText, 'long-text-wrapper') !== false) return $m[0];
                if (strpos($longText, 'data-raw') !== false) return $m[0];
                
                return makeLongTextBlock($longText);
            },
            $msg
        );
        if (strpos($msg, 'long-text-wrapper') === false && shouldPackLongText($msg)) {
            $msg = makeLongTextBlock($msg);
        }
    }
    
    $allowed = '<a><b><i><u><s><em><strong><br><p><img><ul><ol><li>'
             . '<h1><h2><h3><h4><blockquote><code><pre><span><div>'
             . '<table><tr><td><th><button>';
    $msg = strip_tags($msg, $allowed);
    
    $msg = preg_replace('/<img([^>]*)>\s*https?:\/\/[^\s<]*\s*<\/a>/i', '<img$1>', $msg);
    $msg = preg_replace(
        '/<img\s[^>]*src="#local"[^>]*>/i',
        '<span style="color:#999;font-size:12px;font-style:italic">[本機圖片，網頁無法顯示]</span>',
        $msg
    );
    
    $msg = linkify($msg);
    
    foreach ($btnBlocks as $key => $btnHtml) {
        $msg = str_replace($key, $btnHtml, $msg);
    }
    if (!empty($btnBlocks)) {
        foreach (array_keys($btnBlocks) as $key) {
            $msg = preg_replace('/^[^<%%]*(?='.preg_quote($key,'/').')/s', '', $msg);
        }
        $msg = trim($msg);
    }
    return $msg;
}

function importOldTxt($path) {
    if (!file_exists($path)) return [];
    $raw    = file_get_contents($path);
    $blocks = array_filter(explode("[[BEGIN]]\n", $raw));
    $entries = [];
    foreach ($blocks as $block) {
        $block = str_replace("[[END]]\n", '', $block);
        $block = trim($block);
        if ($block === '') continue;
        $lines   = explode("\n", $block, 2);
        $header  = isset($lines[0]) ? $lines[0] : '';
        $msgBody = isset($lines[1]) ? trim($lines[1]) : '';
        $msgBody = html_entity_decode($msgBody, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $msgBody = processMsg($msgBody);
        $entries[] = '<div class="msg-box">'.$header."\n".$msgBody.'</div><!--MSG-->';
    }
    return $entries;
}

function readHtm($path) {
    if (!file_exists($path)) return [];
    $raw = file_get_contents($path);
    // 捕獲 data-raw 屬性（原本此處只是非捕獲群組，讀回後會把使用者原始輸入直接丟掉）
    preg_match_all('/<div class="msg-box"( data-raw="[^"]*")?>(.*?)<\/div><!--MSG-->/s', $raw, $m, PREG_SET_ORDER);
    $entries = [];
    foreach ($m as $match) {
        $rawAttr = isset($match[1]) ? $match[1] : '';
        $inner   = $match[2];
        $entries[] = '<div class="msg-box"' . $rawAttr . '>' . $inner . '</div><!--MSG-->';
    }
    return $entries;
}

function writeHtm($path, array $entries) {
    $body = implode("\n", $entries);
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Guestbook Messages</title><style>'
          . 'body{font-family:sans-serif;max-width:620px;margin:auto;padding:20px;line-height:1.8;}'
          . '.msg-box{border-bottom:2px solid #eee;padding:12px 14px;white-space:pre-wrap;word-wrap:break-word;position:relative;}'
          . '.msg-box h1,.msg-box h2,.msg-box h3,.msg-box h4{font-size:1em;color:inherit;margin:0;}'
          . '.msg-box *{max-width:100%;box-sizing:border-box;}'
          . 'b{color:#e44;}a{color:#07c;}img{max-width:100%;}'
          . '.long-text-wrapper{margin:4px 0;}'
          . '.btn-expand{font-size:12px;padding:3px 12px;background:#f0f0f0;color:#333;border:1px solid #ddd;border-radius:4px;cursor:pointer;transition:all 0.2s;display:inline-block;}'
          . '.btn-expand:hover{background:#e8e8e8;border-color:#667eea;}'
          . '.long-text-content{max-height:400px;overflow-y:auto;}'
          . '</style></head><body>' . "\n" . $body . "\n" . '</body></html>';
    file_put_contents($path, $html, LOCK_EX);
}

if (!file_exists($dataFile) && file_exists($oldFile)) {
    writeHtm($dataFile, importOldTxt($oldFile));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (isset($_POST['action']) && $_POST['action'] === 'delete') {
        $idx      = isset($_POST['idx']) ? intval($_POST['idx']) : -1;
        $existing = readHtm($dataFile);
        if ($idx >= 0 && $idx < count($existing)) {
            array_splice($existing, $idx, 1);
            writeHtm($dataFile, $existing);
        }
        header("Location: 1gbk.php"); exit;
    }

    $name = isset($_POST['name'])    ? trim($_POST['name'])    : '訪客';
    $msg  = isset($_POST['message']) ? trim($_POST['message']) : '';
    if ($msg !== '') {
        $time     = date('Y-m-d H:i:s');
        $safeName   = strip_tags($name);
        $hasCopyBtn = isset($_POST['has_copy_btn']) && $_POST['has_copy_btn'] === '1';
        $packLongText = isset($_POST['pack_long_text']) && $_POST['pack_long_text'] === '1';
        
        $originalMsg = $msg;  // 儲存原始內容
        $safeMsg    = processMsg($msg, $packLongText);
        
        if ($hasCopyBtn) {
            $safeMsg .= "\n" . '<button class="btn-cc" onclick="copyContent(this)">📋 複製內容</button>';
        }
        $header = "[{$time}] <b>{$safeName}</b>:";
        
        // 使用 data-raw 儲存原始內容
        $entry = '<div class="msg-box" data-raw="' . htmlspecialchars($originalMsg, ENT_QUOTES, 'UTF-8') . '">'
               . $header . "\n" . $safeMsg . "\n"
               . '</div><!--MSG-->';
        
        $existing = readHtm($dataFile);
        array_unshift($existing, $entry);
        writeHtm($dataFile, $existing);
    }
    header("Location: 1gbk.php"); exit;
}

$entries = readHtm($dataFile);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Guestbook</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            max-width: 640px;
            margin: 0 auto;
            padding: 20px;
            line-height: 1.8;
            background: #fafafa;
        }
        
        .form-area {
            background: white;
            padding: 16px 20px 18px;
            border-radius: 12px;
            margin-bottom: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            border: 1px solid #e8e8e8;
        }
        .form-row {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .form-row label {
            font-weight: 500;
            font-size: 14px;
            color: #333;
            min-width: 50px;
        }
        .form-row input[type="text"] {
            flex: 1;
            padding: 6px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        .form-row input[type="text"]:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-area textarea {
            width: 100%;
            height: 80px;
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-family: 'Consolas', 'Monaco', monospace;
            font-size: 13px;
            resize: vertical;
            transition: border-color 0.2s;
            box-sizing: border-box;
        }
        .form-area textarea:focus {
            border-color: #667eea;
            outline: none;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .form-bottom {
            display: flex;
            align-items: center;
            gap: 16px;
            margin-top: 8px;
            flex-wrap: wrap;
        }
        .form-bottom label {
            font-size: 13px;
            color: #555;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 4px;
        }
        .form-bottom label input[type="checkbox"] {
            width: 15px;
            height: 15px;
            cursor: pointer;
        }
        .btn-submit {
            padding: 6px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(102, 126, 234, 0.4);
        }
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .list-title{font-size:14px;font-weight:600;margin:4px 0 2px;color:#555;}
        .list-title span{background:#667eea;color:#fff;border-radius:10px;padding:1px 7px;font-size:12px;margin-left:5px;}
        
        .msg-box {
            background: white;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 10px;
            box-shadow: 0 1px 4px rgba(0,0,0,0.06);
            border: 1px solid #f0f0f0;
            white-space: pre-wrap;
            word-wrap: break-word;
            overflow-wrap: anywhere;
            position: relative;
            transition: box-shadow 0.2s;
        }
        .msg-box:hover {
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
        }
        .msg-box h1,.msg-box h2,.msg-box h3,.msg-box h4 {
            font-size: 1em;
            color: inherit;
            margin: 0;
        }
        .msg-box * {
            max-width: 100%;
            box-sizing: border-box;
        }
        .msg-box b {
            color: #e44;
        }
        .msg-box a {
            color: #07c;
            text-decoration: none;
        }
        .msg-box a:hover {
            text-decoration: underline;
        }
        .msg-box img {
            max-width: 100%;
            border-radius: 4px;
        }
        
        .msg-actions {
            position: absolute;
            top: 6px;
            right: 6px;
            display: flex;
            gap: 2px;
            opacity: 0;
            transition: opacity 0.2s;
            background: rgba(255,255,255,0.9);
            padding: 2px;
            border-radius: 6px;
        }
        .msg-box:hover .msg-actions {
            opacity: 1;
        }
        .msg-actions button {
            border: none;
            background: none;
            cursor: pointer;
            font-size: 14px;
            padding: 3px 5px;
            border-radius: 4px;
            line-height: 1;
            transition: background 0.15s;
        }
        .msg-actions button:hover {
            background: #f0f0f0;
        }
        .btn-copy { color: #07c; }
        .btn-del { color: #c44; }
        
        .btn-cc {
            display: inline-block;
            margin: 6px 0 2px;
            padding: 4px 14px;
            background: #2d2d2d;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            transition: background 0.2s;
        }
        .btn-cc:hover { background: #444; }
        .btn-cc.ok { background: #4CAF50; }
        
        .bottom-del {
            margin-top: 4px;
            padding-top: 4px;
            border-top: 1px dashed #eee;
        }
        .bottom-del button {
            font-size: 12px;
            color: #c44;
            background: none;
            border: none;
            cursor: pointer;
            padding: 2px 0;
        }
        .bottom-del button:hover {
            text-decoration: underline;
        }
        
        .long-text-wrapper {
            margin: 4px 0;
        }
        .long-text-preview {
            margin: 4px 0 6px;
            padding: 6px 10px;
            background: #fafafa;
            border-left: 3px solid #ddd;
            border-radius: 4px;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            color: #555;
            font-size: 13px;
            line-height: 1.6;
            max-height: 3.2em;
            overflow: hidden;
        }
        .btn-expand {
            font-size: 12px;
            padding: 3px 12px;
            background: #f0f0f0;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-block;
        }
        .btn-expand:hover {
            background: #e8e8e8;
            border-color: #667eea;
        }
        .btn-expand.active {
            background: #667eea;
            color: #fff;
            border-color: #667eea;
        }
        .long-text-content {
            max-height: 400px;
            overflow-y: auto;
            overflow-wrap: anywhere;
        }
        
        #toast {
            position: fixed;
            bottom: 30px;
            left: 50%;
            transform: translateX(-50%) translateY(16px);
            background: rgba(34,34,34,0.92);
            color: #fff;
            padding: 8px 24px;
            border-radius: 24px;
            font-size: 14px;
            opacity: 0;
            transition: opacity 0.3s, transform 0.3s;
            pointer-events: none;
            z-index: 9999;
            backdrop-filter: blur(4px);
        }
        
        .empty-state{color:#999;font-size:13px;padding:4px 0;}
    </style>
</head>
<body>
<div class="form-area">
    <form method="POST" id="guestForm">
        <div class="form-row">
            <label for="fName">Name</label>
            <input type="text" name="name" id="fName" required>
        </div>
        <textarea name="message" id="fMsg" required placeholder="請輸入留言內容..."></textarea>
        <div class="form-bottom">
            <label><input type="checkbox" name="has_copy_btn" value="1"> 📋 複製按鈕</label>
            <label><input type="checkbox" name="pack_long_text" value="1" checked> 📄 自動打包長文</label>
            <button type="submit" class="btn-submit">送出留言</button>
        </div>
    </form>
</div>
<div class="list-title">所有留言 <span><?php echo count($entries); ?></span></div>
<?php if (empty($entries)): ?>
    <div class="empty-state">（尚無留言）</div>
<?php else: ?>
    <?php foreach ($entries as $i => $e):
        // 提取 data-raw 屬性（如果存在），並拿掉最外層的 <div ...>...</div><!--MSG-->
        $rawAttr = '';
        if (preg_match('/^<div class="msg-box"(?: data-raw="([^"]*)")?>/', $e, $matches)) {
            if (!empty($matches[1])) {
                $rawAttr = ' data-raw="' . $matches[1] . '"';
            }
        }
        $inner = preg_replace('/^<div class="msg-box"(?: data-raw="[^"]*")?>/', '', $e, 1);
        $inner = preg_replace('/<\/div><!--MSG-->$/', '', $inner, 1);

        $delForm = '<form method="POST" style="display:inline" onsubmit="return confirmDel(this)">'
            . '<input type="hidden" name="action" value="delete">'
            . '<input type="hidden" name="idx" value="'.$i.'">';
        $actions = '<div class="msg-actions">'
            . '<button class="btn-copy" onclick="copyMsg(this)" title="複製留言">📋</button>'
            . $delForm
            . '<button class="btn-del" type="submit" title="刪除留言">🗑</button>'
            . '</form></div>';

        // 計算行數（包含換行和 <br>）
        $lineCount = substr_count($inner, "\n") + substr_count($inner, '<br');
        $bottomDel = ($lineCount > 10)
            ? '<div class="bottom-del" data-min-lines="10" data-max-screen="0.8">' . $delForm . '<button type="submit">🗑 刪除此則留言</button></form></div>'
            : '';

        // 重新組裝：開頭 tag（保留 data-raw）+ 動作按鈕 + 原內容 + （可選）底部刪除
        $out = '<div class="msg-box"' . $rawAttr . '>' . $actions . $inner;
        if ($bottomDel) {
            $out .= $bottomDel;
        }
        $out .= '</div><!--MSG-->';
        echo $out;
    endforeach; ?>
<?php endif; ?>

<div id="toast"></div>

<script>
// ===== Name + Msg 自動儲存到 localStorage =====
(function(){
    var n = document.getElementById('fName');
    var m = document.getElementById('fMsg');
    if (!n || !m) return;

    // 還原儲存的內容
    var savedName = localStorage.getItem('gb_name');
    var savedMsg  = localStorage.getItem('gb_msg');
    if (savedName) n.value = savedName;
    if (savedMsg)  m.value = savedMsg;

    // 即時儲存
    n.addEventListener('input', function(){ localStorage.setItem('gb_name', n.value); });
    m.addEventListener('input', function(){ localStorage.setItem('gb_msg',  m.value); });

    // 送出留言表單：成功後只清 Msg，Name 保留
    var form = document.getElementById('guestForm');
    if (form) {
        form.addEventListener('submit', function(){
            setTimeout(function(){ localStorage.removeItem('gb_msg'); }, 100);
        });
    }
})();

// ===== Toast 提示 =====
var _toastTimer = null;
function showToast(msg) {
    var t = document.getElementById('toast');
    if (!t) return;
    t.textContent = msg;
    t.style.opacity = '1';
    t.style.transform = 'translateX(-50%) translateY(0)';
    if (_toastTimer) clearTimeout(_toastTimer);
    _toastTimer = setTimeout(function(){
        t.style.opacity = '0';
        t.style.transform = 'translateX(-50%) translateY(20px)';
    }, 2000);
}

// ===== 刪除確認 =====
function confirmDel(form) {
    if (!confirm('確定要刪除這則留言嗎？\n（此操作無法復原）')) {
        return false;
    }
    return true;
}

// ===== 長文本展開/收合 =====
function toggleLongText(btn) {
    var targetId = btn.getAttribute('data-target');
    var content = document.getElementById(targetId);
    if (!content) return;

    var collapsedLabel = btn.getAttribute('data-label-collapsed') || '📄 展開長文（點擊展開）';
    var expandedLabel  = btn.getAttribute('data-label-expanded')  || '📄 收起長文';

    if (content.style.display === 'none' || content.style.display === '') {
        content.style.display = 'block';
        var preview = content.parentNode.querySelector('.long-text-preview');
        if (preview) preview.style.display = 'none';
        btn.textContent = expandedLabel;
        btn.classList.add('active');
        syncBottomDelete(btn.closest('.msg-box'));
    } else {
        content.style.display = 'none';
        var preview = content.parentNode.querySelector('.long-text-preview');
        if (preview) preview.style.display = 'block';
        btn.textContent = collapsedLabel;
        btn.classList.remove('active');
        syncBottomDelete(btn.closest('.msg-box'));
    }
}


function syncBottomDelete(box) {
    if (!box) return;
    var bottom = box.querySelector('.bottom-del');
    if (!bottom) return;
    var lineHeight = parseFloat(getComputedStyle(box).lineHeight) || 18;
    var lines = Math.ceil(box.scrollHeight / lineHeight);
    var minLines = parseInt(bottom.getAttribute('data-min-lines') || '10', 10);
    var maxScreen = parseFloat(bottom.getAttribute('data-max-screen')) || 0.8;
    var shouldShow = lines > minLines && box.scrollHeight > window.innerHeight * maxScreen;
    bottom.style.display = shouldShow ? '' : 'none';
}

function syncAllBottomDeletes() {
    document.querySelectorAll('.msg-box').forEach(syncBottomDelete);
}
// ===== 複製功能 =====
function copyPlainText(text, callback) {
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text).then(callback).catch(function(){ 
            fallbackCopy(text); 
            if(callback) callback();
        });
    } else { 
        fallbackCopy(text); 
        if(callback) callback(); 
    }
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.focus();
    ta.select();
    try { document.execCommand('copy'); } catch(e){}
    document.body.removeChild(ta);
}

function getCleanMessageFromBox(boxElement) {
    var clone = boxElement.cloneNode(true);
    clone.querySelectorAll('.msg-actions, .btn-cc, .bottom-del').forEach(function(el){ el.remove(); });
    // 長文包裝：拿掉「N行長文（點擊展開）」按鈕，只留下裡面真正的內容（不論目前是否收合）
    clone.querySelectorAll('.long-text-wrapper').forEach(function(wrapper){
        var content = wrapper.querySelector('.long-text-content');
        var textNode = document.createTextNode(content ? content.textContent : '');
        wrapper.replaceWith(textNode);
    });
    var html = clone.innerHTML;
    html = html.replace(/<b[^>]*>(.*?)<\/b>/gi, '$1');
    html = html.replace(/<br\s*\/?>/gi, '\n');
    html = html.replace(/<a[^>]*href="([^"]*)"[^>]*>\s*<img[^>]*>\s*<\/a>/gi, '$1');
    html = html.replace(/<img[^>]*src="([^"]*)"[^>]*>/gi, '$1');
    html = html.replace(/<a[^>]*>(.*?)<\/a>/gi, '$1');
    html = html.replace(/<[^>]+>/g, '');
    html = html.replace(/&amp;/g, '&').replace(/&lt;/g, '<')
               .replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ')
               .replace(/&#39;/g, "'").replace(/&quot;/g, '"');
    // 移除時間戳行（第一行），和 copyContent 保持一致
    var lines = html.split('\n');
    if (lines.length > 0 && /^\[\d{4}-\d{2}-\d{2}/.test(lines[0].trim())) {
        lines.shift();
    }
    return lines.join('\n').replace(/\n{3,}/g, '\n\n').trim();
}

function copyMsg(btn) {
    var box = btn.closest('.msg-box');
    
    // 嘗試使用 data-raw（原始內容）
    var rawContent = box.getAttribute('data-raw');
    if (rawContent) {
        copyPlainText(rawContent, function(){
            btn.textContent = '✅';
            showToast('已複製原始內容！');
            setTimeout(function(){ btn.textContent = '📋'; }, 1500);
        });
        return;
    }
    
    // 降級方案：從 HTML 解析
    var text = getCleanMessageFromBox(box);
    copyPlainText(text, function(){
        btn.textContent = '✅';
        showToast('已複製留言內容！');
        setTimeout(function(){ btn.textContent = '📋'; }, 1500);
    });
}

function copyContent(btn) {
    var box = btn.closest('.msg-box');
    
    // 優先使用 data-raw（原始內容）
    var rawContent = box.getAttribute('data-raw');
    if (rawContent) {
        copyPlainText(rawContent, function(){
            btn.textContent = '✅ 已複製原始內容！';
            btn.classList.add('ok');
            showToast('已複製原始內容！');
            setTimeout(function(){
                btn.textContent = '📋 複製內容';
                btn.classList.remove('ok');
            }, 2000);
        });
        return;
    }
    
    // 降級方案：從 HTML 解析
    var clone = box.cloneNode(true);
    clone.querySelectorAll('.msg-actions, .btn-cc, .bottom-del').forEach(function(el){ el.remove(); });
    clone.querySelectorAll('.long-text-wrapper').forEach(function(wrapper){
        var content = wrapper.querySelector('.long-text-content');
        var textNode = document.createTextNode(content ? content.textContent : '');
        wrapper.replaceWith(textNode);
    });
    var html = clone.innerHTML;
    html = html.replace(/<b[^>]*>(.*?)<\/b>/gi, '$1');
    html = html.replace(/<br\s*\/?>/gi, '\n');
    html = html.replace(/<a[^>]*href="([^"]*)"[^>]*>\s*<img[^>]*>\s*<\/a>/gi, '$1');
    html = html.replace(/<img[^>]*src="([^"]*)"[^>]*>/gi, '$1');
    html = html.replace(/<a[^>]*>(.*?)<\/a>/gi, '$1');
    html = html.replace(/<[^>]+>/g, '');
    html = html.replace(/&amp;/g,'&').replace(/&lt;/g,'<').replace(/&gt;/g,'>').replace(/&nbsp;/g,' ').replace(/&#39;/g,"'").replace(/&quot;/g,'"');
    var lines = html.trim().split('\n');
    if (/^\[\d{4}-\d{2}-\d{2}/.test(lines[0].trim())) {
        lines.shift();
    }
    var text = lines.join('\n').replace(/\n{3,}/g,'\n\n').trim();
    copyPlainText(text, function(){
        btn.textContent = '✅ 已複製！';
        btn.classList.add('ok');
        showToast('已複製內容！');
        setTimeout(function(){
            btn.textContent = '📋 複製內容';
            btn.classList.remove('ok');
        }, 2000);
    });
}

// ===== URL 縮短顯示 =====
(function(){
    document.querySelectorAll('.msg-box a').forEach(function(a){
        var txt = a.textContent.trim();
        if (/^https?:\/\//i.test(txt)) {
            a.title = txt;
            try {
                var u = new URL(txt);
                var base = u.pathname.split('/').filter(Boolean).pop() || u.hostname;
                a.textContent = base + u.search;
            } catch(e){}
        }
    });
})();

// ===== 初始化：確保長文按鈕文字與內容的收合狀態一致 =====
document.addEventListener('DOMContentLoaded', function() {
    syncAllBottomDeletes();
    window.addEventListener('resize', syncAllBottomDeletes);
    document.querySelectorAll('.long-text-wrapper').forEach(function(wrapper){
        var content = wrapper.querySelector('.long-text-content');
        var btn = wrapper.querySelector('.btn-expand');
        if (!content || !btn) return;

        var isCollapsed = (content.style.display === 'none');
        var preview = wrapper.querySelector('.long-text-preview');
        if (isCollapsed) {
            if (preview) preview.style.display = 'block';
            btn.textContent = btn.getAttribute('data-label-collapsed') || btn.textContent;
            btn.classList.remove('active');
        } else {
            if (preview) preview.style.display = 'none';
            btn.textContent = btn.getAttribute('data-label-expanded') || '📄 收起長文';
            btn.classList.add('active');
        }
    });
});
</script>

</body>
</html>