<?php
declare(strict_types=1);
/**
 * Kurage 広告表現チェック（khyogen）— 入口の画面。
 *
 * URLを入れると、サイトを巡回して業種を判定し、その業種に関わる法律・ガイドラインに照らして
 * 「確かめたほうがよい表現」を根拠つきで一覧にする。判定の中身は khyogen_engine.php。
 * ここは受け付け・保存・結果ページ・Markdownの書き出しだけ。
 *
 * 「違反です」とは言わない。法的な判断は弁護士・行政の仕事なので、出すのは点検の結果と根拠まで。
 * 結果ページの URL は推測できない番号にする（他社のサイトの結果を、URLを知らない人に見られないように）。
 *
 * PHP 8.0 以上 / データベース不要 / Composer・npm 不要 / curl 拡張が要る
 */

if (is_file(__DIR__ . '/khyogen_config.php')) { require_once __DIR__ . '/khyogen_config.php'; }
if (!defined('KHY_TITLE'))         { define('KHY_TITLE', 'Kurage 広告表現チェック'); }
if (!defined('KHY_PASSWORD_HASH')) { define('KHY_PASSWORD_HASH', ''); }    // 空なら鍵なし
if (!defined('KHY_LITE'))          { define('KHY_LITE', false); }          // true で点数と件数だけ（該当箇所・根拠・直し方・書き出しを出さない。デモ版用）
if (!defined('KHY_MAX_PAGES'))     { define('KHY_MAX_PAGES', 30); }        // 1回の診断で見るページ数の上限
if (!defined('KHY_CACHE_HOURS'))   { define('KHY_CACHE_HOURS', 6); }       // 同じURLの結果を使い回す時間
if (!defined('KHY_RATE_PER_HOUR')) { define('KHY_RATE_PER_HOUR', 10); }    // 1つのIPから1時間に新しく診断できる回数（0で無制限）
if (!defined('KHY_DATA_DIR'))      { define('KHY_DATA_DIR', __DIR__ . '/khy_data'); }
if (!defined('KHY_KEEP_DAYS'))     { define('KHY_KEEP_DAYS', 30); }        // 結果を残す日数
if (!defined('KHY_CANONICAL'))     { define('KHY_CANONICAL', ''); }        // 公開URL（設定するとcanonical・OGPを出す）
if (!defined('KHY_OG_IMAGE'))      { define('KHY_OG_IMAGE', ''); }
if (!defined('KHY_DEMO_FOOTER'))   { define('KHY_DEMO_FOOTER', ''); }      // デモだけで出す案内（HTML）

require_once __DIR__ . '/khyogen_engine.php';

date_default_timezone_set('Asia/Tokyo');
@set_time_limit(150);
session_start();

function khy_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function khy_self(): string { return basename((string)($_SERVER['SCRIPT_NAME'] ?? 'khyogen.php')); }
function khy_base_url(): string
{
    if (KHY_CANONICAL !== '') { return KHY_CANONICAL; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . (string)($_SERVER['SCRIPT_NAME'] ?? '/khyogen.php');
}

// --- 鍵（任意）
$locked = KHY_PASSWORD_HASH !== '';
if ($locked && isset($_POST['password']) && password_verify((string)$_POST['password'], KHY_PASSWORD_HASH)) {
    $_SESSION['khy_ok'] = 1; header('Location: ' . khy_self()); exit;
}
$authed = !$locked || !empty($_SESSION['khy_ok']);

// --- 保存
function khy_dir(): string { if (!is_dir(KHY_DATA_DIR)) { @mkdir(KHY_DATA_DIR, 0755, true); } return KHY_DATA_DIR; }
function khy_load(string $token): ?array
{
    $token = preg_replace('/[^0-9a-f]/', '', $token);
    if ($token === '') { return null; }
    $f = khy_dir() . '/r-' . $token . '.json';
    if (!is_file($f)) { return null; }
    $d = json_decode((string)file_get_contents($f), true);
    return is_array($d) ? $d : null;
}
function khy_save(array $r): string
{
    $token = bin2hex(random_bytes(12));
    file_put_contents(khy_dir() . '/r-' . $token . '.json', json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $token;
}
function khy_cache_get(string $key): ?string
{
    $f = khy_dir() . '/c-' . $key . '.txt';
    if (is_file($f) && time() - filemtime($f) < KHY_CACHE_HOURS * 3600) {
        $t = trim((string)file_get_contents($f));
        if (khy_load($t)) { return $t; }
    }
    return null;
}
function khy_rate_ok(): bool
{
    if (KHY_RATE_PER_HOUR <= 0) { return true; }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0');
    $f = khy_dir() . '/rate.json';
    $d = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $now = time();
    foreach ($d as $k => $ts) { $d[$k] = array_values(array_filter($ts, static fn($t) => $now - $t < 3600)); if (!$d[$k]) { unset($d[$k]); } }
    $mine = $d[$ip] ?? [];
    if (count($mine) >= KHY_RATE_PER_HOUR) { return false; }
    $mine[] = $now; $d[$ip] = $mine;
    file_put_contents($f, json_encode($d), LOCK_EX);
    return true;
}
function khy_cleanup(): void
{
    if (mt_rand(1, 20) !== 1) { return; }
    foreach (glob(khy_dir() . '/[rc]-*') ?: [] as $f) { if (time() - filemtime($f) > KHY_KEEP_DAYS * 86400) { @unlink($f); } }
}

// --- 診断を受け付ける
$error = '';
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['url'])) {
    $url = khy_url_ok((string)$_POST['url']);
    $ind = preg_replace('/[^a-z0-9_-]/', '', (string)($_POST['industry'] ?? 'auto')) ?: 'auto';
    if ($url === null) {
        $error = 'URLを読めませんでした。https:// から始まる、公開しているサイトのURLを入れてください。';
    } else {
        $key = substr(sha1('khy|' . strtolower(rtrim($url, '/')) . '|' . $ind), 0, 16);
        $token = khy_cache_get($key);
        if ($token === null) {
            if (!khy_rate_ok()) {
                $error = '続けて診断できる回数の上限に達しました。1時間ほどおいてからお試しください。';
            } else {
                $r = khy_diagnose($url, $ind, KHY_MAX_PAGES);
                $token = khy_save($r);
                file_put_contents(khy_dir() . '/c-' . $key . '.txt', $token, LOCK_EX);
                khy_cleanup();
            }
        }
        if ($error === '') { header('Location: ' . khy_self() . '?r=' . $token); exit; }
    }
}

$token = preg_replace('/[^0-9a-f]/', '', (string)($_GET['r'] ?? ''));
$result = $token !== '' ? khy_load($token) : null;

// --- Markdown の書き出し（製品版だけ）
if ($result && !KHY_LITE && ($_GET['fmt'] ?? '') === 'md') {
    header('Content-Type: text/markdown; charset=UTF-8');
    header('Content-Disposition: attachment; filename="khyogen-' . preg_replace('/[^a-z0-9.-]/', '', (string)$result['host']) . '.md"');
    echo khy_markdown($result);
    exit;
}

function khy_markdown(array $r): string
{
    $o = "# 広告表現チェックの結果：{$r['host']}\n\n";
    $o .= "- 診断した日時：" . date('Y年n月j日 H:i', strtotime($r['at'])) . "\n- 見たページ：" . count($r['pages']) . "ページ\n";
    $o .= "- 業種：" . ($r['industry']['name'] ?? '') . "\n";
    $o .= $r['score'] ? "- 点数：{$r['score']['score']}点（確かめたほうがよい {$r['score']['warning']}件・注意 {$r['score']['caution']}件）\n\n" : "- 点数：点検できなかったため、出していません\n\n";
    $o .= "> この結果は表現の点検で、法的な判断ではありません。最終的な判断は弁護士や所管の行政機関にご確認ください。\n\n";
    foreach ($r['findings'] as $i => $f) {
        $o .= '## ' . ($i + 1) . '. ' . ($f['level'] === 'warning' ? '【確かめたほうがよい】' : '【注意】') . $f['title'] . "\n\n";
        $o .= "- 分類：{$f['category']}\n- 根拠：{$f['law']}\n\n";
        foreach ($f['hits'] as $h) { $o .= "- {$h['url']}\n  - {$h['snippet']}\n"; }
        $o .= "\n**なぜ確かめるか**　{$f['why']}\n\n**直し方**　{$f['fix']}\n\n";
        foreach ($f['sources'] as $s) { $o .= "- 参考：[{$s['label']}]({$s['url']})\n"; }
        $o .= "\n";
    }
    return $o;
}

$base = khy_base_url();
$share_text = '【' . KHY_TITLE . '】URLを入れると、ホームページの「No.1」「〇〇円〜」「見積無料」などの表現を、景品表示法・特定商取引法や業種ごとの許可の決まりに照らして点検します。';
$page_title = $result ? '広告表現チェックの結果：' . $result['host'] . '｜' . KHY_TITLE : KHY_TITLE . '｜ホームページの広告表現を景品表示法・特定商取引法で点検';
$desc = 'ホームページのURLを入れると、サイトを巡回して業種を判定し、「No.1」「最安」「〇〇円〜」「見積無料」「設立前からの実績」などの表現を、景品表示法・特定商取引法・業種ごとの許可の決まりに照らして、確かめたほうがよい箇所を根拠つきで一覧にします。';
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= khy_h($page_title) ?></title>
<meta name="description" content="<?= khy_h($desc) ?>">
<?php if ($result): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<?php if (KHY_CANONICAL !== '' && !$result): ?>
<link rel="canonical" href="<?= khy_h(KHY_CANONICAL) ?>">
<meta property="og:type" content="website"><meta property="og:url" content="<?= khy_h(KHY_CANONICAL) ?>">
<meta property="og:title" content="<?= khy_h($page_title) ?>"><meta property="og:description" content="<?= khy_h($desc) ?>">
<?php if (KHY_OG_IMAGE !== ''): ?><meta property="og:image" content="<?= khy_h(KHY_OG_IMAGE) ?>"><meta name="twitter:card" content="summary_large_image"><?php endif; ?>
<?php endif; ?>
<style>
:root{--ink:#14232f;--sub:#516170;--line:#d8e3e8;--bg:#f6fafb;--card:#fff;--teal:#0a8f85;--teal-d:#07756d;--warn:#b54708;--warn-bg:#fff4e8;--caut:#8a6d00;--caut-bg:#fffbe6}
*{box-sizing:border-box}
body{margin:0;font-family:"Hiragino Sans","Noto Sans JP","Yu Gothic",Meiryo,sans-serif;color:var(--ink);background:var(--bg);line-height:1.8;font-size:15.5px}
a{color:var(--teal-d)}
header{background:#fff;border-bottom:1px solid var(--line)}
header .in{max-width:880px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:10px}
header img.logo{height:30px;width:auto}
header a.brand{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit;font-weight:800}
main{max-width:880px;margin:0 auto;padding:22px 16px 40px}
h1{font-size:25px;line-height:1.45;margin:6px 0 8px}
h2{font-size:19px;margin:28px 0 10px}
.lead{color:var(--sub);margin:0 0 14px}
.share{display:flex;gap:8px;flex-wrap:wrap;margin:6px 0 16px}
.share button,.share a{font:inherit;font-size:13px;font-weight:700;border:1px solid var(--line);background:#fff;color:var(--teal-d);border-radius:999px;padding:6px 14px;cursor:pointer;text-decoration:none}
.box{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:18px;margin:0 0 16px}
form .row{display:flex;gap:8px;flex-wrap:wrap}
input[type=url],input[type=text],input[type=password],select{font:inherit;padding:11px 12px;border:1px solid #b9cbd3;border-radius:10px;min-width:0}
input[type=url]{flex:1 1 320px}
.btn{display:inline-block;font:inherit;font-weight:800;border:0;border-radius:10px;padding:11px 20px;background:var(--teal);color:#fff;cursor:pointer;text-decoration:none}
.btn.ghost{background:#fff;color:var(--teal-d);border:1px solid #bfe0e6}
.note{font-size:13px;color:var(--sub)}
.err{background:#fdecec;border:1px solid #f3b9b9;border-radius:10px;padding:10px 14px;margin:0 0 14px}
.score{display:flex;gap:18px;align-items:center;flex-wrap:wrap}
.score .num{font-size:52px;font-weight:900;line-height:1}
.score .num small{font-size:18px}
.grade{display:inline-block;border-radius:10px;padding:4px 12px;font-weight:900;color:#fff;background:#6b7c86}
.grade.A{background:#0a8f85}.grade.B{background:#4c9a2a}.grade.C{background:#c98a00}.grade.D{background:#c2410c}
.pill{display:inline-block;border-radius:999px;padding:2px 10px;font-size:12px;font-weight:800;margin-right:6px}
.pill.warning{background:var(--warn-bg);color:var(--warn);border:1px solid #f5c79a}
.pill.caution{background:var(--caut-bg);color:var(--caut);border:1px solid #ecd98a}
.f{background:#fff;border:1px solid var(--line);border-left:5px solid #ecd98a;border-radius:12px;padding:14px 16px;margin:0 0 12px}
.f.warning{border-left-color:#f59e0b}
.f h3{font-size:16.5px;margin:4px 0 6px;line-height:1.5}
.f .law{font-size:13px;color:var(--sub);margin:0 0 8px}
.f .hit{background:#f6f8f9;border-radius:8px;padding:8px 10px;margin:0 0 6px;font-size:13.5px;overflow-wrap:anywhere}
.f .hit a{font-size:12px;display:block;color:var(--sub)}
.f p{margin:8px 0 0}
.cat{display:flex;justify-content:space-between;gap:10px;border-top:1px solid var(--line);padding:8px 0}
.cat:first-child{border-top:0}
.disc{font-size:13px;color:var(--sub);background:#fff;border:1px dashed var(--line);border-radius:10px;padding:10px 14px;margin:18px 0}
.mascot{width:72px;height:auto;flex:none}
@media(max-width:520px){h1{font-size:21px}.score .num{font-size:44px}}
</style>
</head>
<body>
<header><div class="in"><a class="brand" href="<?= khy_h(khy_self()) ?>"><img class="logo" src="khy_assets/logo.png" alt="Kurage"><span><?= khy_h(KHY_TITLE) ?></span></a></div></header>
<main>
<?php if (!$authed): ?>
  <h1><?= khy_h(KHY_TITLE) ?></h1>
  <form method="post" class="box"><p>このページは鍵がかかっています。</p><div class="row"><input type="password" name="password" placeholder="パスワード" required><button class="btn">開く</button></div></form>
<?php elseif ($result): ?>
  <h1>広告表現チェックの結果：<?= khy_h($result['host']) ?></h1>
  <div class="share">
    <button type="button" data-copy="<?= khy_h($share_text . "\n" . $base) ?>" onclick="navigator.clipboard.writeText(this.dataset.copy).then(()=>{this.textContent='コピーしました'})">このシステムを紹介する文をコピー</button>
    <a href="https://x.com/intent/post?text=<?= rawurlencode($share_text) ?>&amp;url=<?= rawurlencode($base) ?>" target="_blank" rel="noopener">Xで共有</a>
  </div>
  <p class="lead"><?= khy_h(date('Y年n月j日 H:i', strtotime((string)$result['at']))) ?> に診断／見たページ <?= count($result['pages']) ?>ページ／業種：<?= khy_h($result['industry']['name'] ?? '—') ?></p>
<?php if (!empty($result['error'])): ?>
  <div class="err"><?= khy_h($result['error']) ?></div>
<?php else: ?>
  <div class="box score">
    <img class="mascot" src="khy_assets/mascot.png" alt="">
    <?php if ($result['score']): $s = $result['score']; ?>
      <div><div class="num"><?= (int)$s['score'] ?><small>点</small></div><span class="grade <?= khy_h($s['grade']) ?>"><?= khy_h($s['grade']) ?></span></div>
      <div><span class="pill warning">確かめたほうがよい <?= (int)$s['warning'] ?>件</span><span class="pill caution">注意 <?= (int)$s['caution'] ?>件</span>
        <p class="note" style="margin:6px 0 0">100点から、確かめたほうがよい表現1種類につき12点、注意1種類につき5点を引いています。同じ種類の表現が何か所あっても、1種類として数えます。</p></div>
    <?php else: ?>
      <div><b>点数は出していません。</b><p class="note" style="margin:4px 0 0">このサイトは本文がHTMLにほとんど無く、表現を点検できませんでした。点数を出すと「問題なし」に見えてしまうためです。</p></div>
    <?php endif; ?>
  </div>
<?php if (KHY_LITE): ?>
  <h2>見つかったもの（分類ごとの件数）</h2>
  <div class="box">
  <?php $cats = []; foreach ($result['findings'] as $f) { $cats[$f['category']][$f['level']] = ($cats[$f['category']][$f['level']] ?? 0) + 1; } ?>
  <?php if (!$cats): ?><p>この診断で見る決まりの範囲では、確かめたほうがよい表現は見つかりませんでした。</p><?php endif; ?>
  <?php foreach ($cats as $c => $lv): ?>
    <div class="cat"><span><?= khy_h($c) ?></span><span><?php if (!empty($lv['warning'])): ?><span class="pill warning">確かめたほうがよい <?= (int)$lv['warning'] ?></span><?php endif; ?><?php if (!empty($lv['caution'])): ?><span class="pill caution">注意 <?= (int)$lv['caution'] ?></span><?php endif; ?></span></div>
  <?php endforeach; ?>
  </div>
  <p class="note">デモ版は、点数と分類ごとの件数だけをお見せしています。どのページのどの表現か、根拠の条文・ガイドライン、直し方、結果の書き出しは、導入版でご覧いただけます。</p>
<?php else: ?>
  <?php if (!$result['findings']): ?><div class="box">この診断で見る決まりの範囲では、確かめたほうがよい表現は見つかりませんでした。</div><?php endif; ?>
  <?php foreach ($result['findings'] as $f): ?>
    <div class="f <?= khy_h($f['level']) ?>">
      <span class="pill <?= khy_h($f['level']) ?>"><?= $f['level'] === 'warning' ? '確かめたほうがよい' : '注意' ?></span><span class="note"><?= khy_h($f['category']) ?></span>
      <h3><?= khy_h($f['title']) ?></h3>
      <p class="law">根拠：<?= khy_h($f['law']) ?></p>
      <?php foreach ($f['hits'] as $h): ?><div class="hit"><?= khy_h($h['snippet']) ?><a href="<?= khy_h($h['url']) ?>" target="_blank" rel="noopener nofollow"><?= khy_h($h['url']) ?></a></div><?php endforeach; ?>
      <p><b>なぜ確かめるか</b>　<?= khy_h($f['why']) ?></p>
      <p><b>直し方</b>　<?= khy_h($f['fix']) ?></p>
      <?php if ($f['sources']): ?><p class="note"><?php foreach ($f['sources'] as $s): ?>参考：<a href="<?= khy_h($s['url']) ?>" target="_blank" rel="noopener"><?= khy_h($s['label']) ?></a>　<?php endforeach; ?></p><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <p><a class="btn ghost" href="?r=<?= khy_h($token) ?>&amp;fmt=md">結果をMarkdownで書き出す</a></p>
<?php endif; ?>
<?php endif; ?>
  <div class="disc">この結果は、決まった規則による表現の点検で、法的な判断ではありません。「違反」かどうかの判断は、弁護士や所管の行政機関（消費者庁・都道府県など）にご確認ください。見たのはサーバーが返すHTMLの本文で、画像の中の文字やPDFは見ていません。</div>
  <p><a class="btn ghost" href="<?= khy_h(khy_self()) ?>">別のURLを診断する</a></p>
<?php else: ?>
  <h1>ホームページの広告表現を、景品表示法と業種の決まりで点検する</h1>
  <div class="share">
    <button type="button" data-copy="<?= khy_h($share_text . "\n" . $base) ?>" onclick="navigator.clipboard.writeText(this.dataset.copy).then(()=>{this.textContent='コピーしました'})">タイトルとURLをコピー</button>
    <a href="https://x.com/intent/post?text=<?= rawurlencode($share_text) ?>&amp;url=<?= rawurlencode($base) ?>" target="_blank" rel="noopener">Xで共有</a>
  </div>
  <p class="lead">URLを入れると、サイトを最大<?= (int)KHY_MAX_PAGES ?>ページ巡回して業種を判定し、「No.1」「地域最安」「〇〇円〜」「見積無料」「設立より前からの実績」などの表現を、景品表示法・特定商取引法・業種ごとの許可の決まりに照らして点検します。</p>
  <?php if ($error): ?><div class="err"><?= khy_h($error) ?></div><?php endif; ?>
  <form method="post" class="box" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='診断しています（1〜2分）…'">
    <div class="row">
      <input type="url" name="url" placeholder="https://example.co.jp/" required>
      <select name="industry" aria-label="業種">
        <option value="auto">業種：自動で判定</option>
        <?php foreach (khy_industries() as $id => $d): ?><option value="<?= khy_h($id) ?>"><?= khy_h($d['name']) ?></option><?php endforeach; ?>
      </select>
      <button class="btn">診断する</button>
    </div>
    <p class="note" style="margin:8px 0 0">自社のサイトか、点検を頼まれたサイトを入れてください。結果のページは推測できない番号のURLで、入れた人にだけ見えます。</p>
  </form>
  <h2>点検すること</h2>
  <div class="box">
    <div class="cat"><span><b>No.1・最上級の表示</b>（地域No.1・最安・業界初）に、調査の根拠が書いてあるか</span></div>
    <div class="cat"><span><b>料金の表示</b>（〇〇円〜・見積無料・出張費0円・通常価格との比較）に、追加料金や条件が書いてあるか</span></div>
    <div class="cat"><span><b>実績の数字</b>（累計◯件・創業◯年）が、会社の設立・創業の年と食い違っていないか</span></div>
    <div class="cat"><span><b>効果の言い切り</b>（完全駆除・二度と出ない・100%）</span></div>
    <div class="cat"><span><b>許可・登録の表示</b>（不用品回収の一般廃棄物の許可、古物商、水道局指定の自治体、建設業許可）</span></div>
    <div class="cat"><span><b>事業者の表示</b>（名称・所在地・電話、ネットで申し込みを受けるときの特定商取引法の表記）</span></div>
  </div>
  <p class="note">いま対応している業種：<?= khy_h(implode('／', array_map(static fn($d) => $d['name'], khy_industries()))) ?>。そのほかの業種は、全業種共通の決まりで点検します。</p>
  <div class="disc">この診断は、決まった規則による表現の点検で、法的な判断ではありません。判定にAIは使っていないので、同じサイトなら同じ結果になります。</div>
<?php endif; ?>
<?= KHY_DEMO_FOOTER ?>
</main>
</body>
</html>
