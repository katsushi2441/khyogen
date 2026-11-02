<?php
declare(strict_types=1);
/**
 * Kurage 広告表現チェック（khyogen）— 判定の本体。
 *
 * サイトを巡回して本文を取り出し、業種を判定し、業種ごとの決まり（khyogen_rules/*.json）で
 * 「確かめたほうがよい表現」を根拠つきで拾う。
 *
 * 判定はすべて規則で行い、AIは使わない。同じサイトなら、いつ診断しても同じ結果になる。
 * 「違反です」とは言わない。法的な判断は弁護士・行政の仕事で、ここが出すのは点検の結果と根拠まで。
 *
 * 決まりを足すときは khyogen_rules/ に JSON を足すだけにする（このファイルは触らない）。
 * 規則の型:
 *   near_missing      … match の言葉があり、その前後 near_chars 字に near の言葉が1つも無い
 *   presence          … match の言葉があれば拾う
 *   site_missing / site_need_when … サイトのどこかに when の言葉があり、どこにも need の言葉が無い
 *   site_missing_each … items の need が、サイトのどこにも無いものを拾う
 * 年数の照合（設立・創業より前の実績）だけは、計算が要るのでここに書く（khy_check_years）。
 */

const KHY_UA = 'KurageHyogenCheck/1.0 (+https://kappstore.exbridge.jp/)';

/** 公開のIPアドレスを1つ返す。社内・予約済みのアドレスなら null（社内の機械を読みに行かせないため）。 */
function khy_public_ip(string $host): ?string
{
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) { return null; }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return null; }
    }
    return $ips[0];
}

/**
 * 1ページ取る。リダイレクトは5回まで、本文は2MBまで。
 * リダイレクトは curl に任せず1回ごとに行き先を確かめ、確かめたIPに CURLOPT_RESOLVE で固定する
 * （外のサイトから社内のアドレスへ飛ばされたり、確かめた後に名前の向き先を変えられたりしないように）。
 */
function khy_fetch(string $url, int $timeout = 12): array
{
    for ($hop = 0; $hop <= 5; $hop++) {
        $p = parse_url($url);
        $host = strtolower((string)($p['host'] ?? ''));
        $scheme = strtolower((string)($p['scheme'] ?? ''));
        if ($host === '' || !in_array($scheme, ['http', 'https'], true)) { break; }
        $ip = khy_public_ip($host);
        if ($ip === null) { return ['code' => 0, 'url' => $url, 'body' => '', 'ctype' => '']; }
        $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
        $r = khy_fetch_once($url, $timeout, "$host:$port:$ip");
        if ($r['code'] >= 300 && $r['code'] < 400 && $r['location'] !== '') {
            $next = khy_abs($r['location'], $url);
            if ($next === null) { break; }
            $url = $next;
            continue;
        }
        return $r;
    }
    return ['code' => 0, 'url' => $url, 'body' => '', 'ctype' => ''];
}

function khy_fetch_once(string $url, int $timeout, string $resolve): array
{
    $ch = curl_init($url);
    $buf = '';
    $loc = '';
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_RESOLVE => [$resolve],
        CURLOPT_HEADERFUNCTION => static function ($ch, $h) use (&$loc) {
            if (stripos($h, 'location:') === 0) { $loc = trim(substr($h, 9)); }
            return strlen($h);
        },
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => KHY_UA,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.5', 'Accept-Language: ja'],
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_ENCODING => '',
        CURLOPT_WRITEFUNCTION => static function ($ch, $data) use (&$buf) {
            $buf .= $data;
            return strlen($buf) > 2 * 1024 * 1024 ? 0 : strlen($data);
        },
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $final = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    return ['code' => $code, 'url' => $final !== '' ? $final : $url, 'body' => $buf, 'ctype' => $ctype, 'location' => $loc];
}

/** 外から入れられたURLを検める。社内の機械（127.0.0.1・192.168…）は診断しない。 */
function khy_url_ok(string $url): ?string
{
    $url = trim($url);
    if ($url === '') { return null; }
    if (!preg_match('#^https?://#i', $url)) { $url = 'https://' . $url; }
    $p = parse_url($url);
    if (!$p || empty($p['host'])) { return null; }
    $host = strtolower($p['host']);
    if ($host === 'localhost' || !str_contains($host, '.')) { return null; }
    $ips = @gethostbynamel($host) ?: [];
    if (!$ips) { return null; }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) { return null; }
    }
    return $url;
}

/** robots.txt の Disallow（* と自分の名前の節）。 */
function khy_robots(string $origin): array
{
    $r = khy_fetch($origin . '/robots.txt', 8);
    $dis = []; $maps = [];
    if ($r['code'] !== 200) { return ['disallow' => [], 'sitemaps' => []]; }
    $applies = false;
    foreach (preg_split('/\R/', $r['body']) as $ln) {
        $ln = trim(preg_replace('/#.*/', '', $ln));
        if ($ln === '') { continue; }
        if (preg_match('/^user-agent:\s*(.+)$/i', $ln, $m)) {
            $ua = strtolower(trim($m[1]));
            $applies = ($ua === '*' || str_contains($ua, 'kuragehyogencheck'));
        } elseif ($applies && preg_match('/^disallow:\s*(.*)$/i', $ln, $m) && trim($m[1]) !== '') {
            $dis[] = trim($m[1]);
        } elseif (preg_match('/^sitemap:\s*(\S+)/i', $ln, $m)) {
            $maps[] = $m[1];
        }
    }
    return ['disallow' => $dis, 'sitemaps' => $maps];
}

function khy_allowed(string $path, array $disallow): bool
{
    foreach ($disallow as $d) {
        $re = '#^' . str_replace(['\*', '\$'], ['.*', '$'], preg_quote($d, '#')) . '#';
        if (preg_match($re, $path)) { return false; }
    }
    return true;
}

/** HTML から題名・本文・リンクを取り出す。 */
function khy_extract(string $html, string $base): array
{
    $title = preg_match('#<title[^>]*>(.*?)</title>#is', $html, $m) ? trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')) : '';
    $scripts = preg_match_all('#<script\b#i', $html);
    $body = preg_replace('#<(script|style|noscript|template|svg)\b.*?</\1>#is', ' ', $html);
    $links = [];
    if (preg_match_all('#<a\b[^>]*href\s*=\s*["\']([^"\'\#]+)[^>]*>(.*?)</a>#is', $body, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $a) {
            $u = khy_abs(html_entity_decode($a[1], ENT_QUOTES, 'UTF-8'), $base);
            if ($u !== null) { $links[$u] = trim(strip_tags($a[2])); }
        }
    }
    $body = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/dd|/dt|/section|/article)\b[^>]*>#i', "\n", $body);
    $text = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = khy_norm($text);
    return ['title' => $title, 'text' => $text, 'links' => $links, 'scripts' => (int)$scripts];
}

/** 全角英数を半角に、空白を1つに。照合と抜き出しを同じ文字列で行う。 */
function khy_norm(string $t): string
{
    $t = mb_convert_kana($t, 'as', 'UTF-8');
    $t = preg_replace('/[ \t\x{3000}\x{00A0}]+/u', ' ', $t);
    $t = preg_replace('/\s*\n\s*/u', "\n", $t);
    return trim($t);
}

function khy_abs(string $href, string $base): ?string
{
    $href = trim($href);
    if ($href === '' || preg_match('#^(mailto|tel|javascript|data):#i', $href)) { return null; }
    if (preg_match('#^https?://#i', $href)) { $u = $href; }
    else {
        $b = parse_url($base);
        if (!$b || empty($b['host'])) { return null; }
        $origin = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
        if (str_starts_with($href, '//')) { $u = $b['scheme'] . ':' . $href; }
        elseif (str_starts_with($href, '/')) { $u = $origin . $href; }
        else {
            $dir = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
            $u = $origin . $dir . $href;
        }
    }
    $u = preg_replace('/#.*$/', '', $u);
    // ./ と ../ を畳む
    $p = parse_url($u);
    if (!$p || empty($p['host'])) { return null; }
    $segs = [];
    foreach (explode('/', $p['path'] ?? '/') as $s) {
        if ($s === '..') { array_pop($segs); } elseif ($s !== '.') { $segs[] = $s; }
    }
    $path = implode('/', $segs);
    if ($path === '' || $path[0] !== '/') { $path = '/' . ltrim($path, '/'); }
    return $p['scheme'] . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . $p['port'] : '') . $path . (isset($p['query']) ? '?' . $p['query'] : '');
}

/** 巡回する順番。料金・会社概要・特商法のページを先に見る。 */
function khy_priority(string $url, string $label): int
{
    $s = strtolower(rawurldecode($url)) . ' ' . $label;
    $p = 0;
    foreach (['特定商取引', 'tokusho', 'law', 'legal', '会社概要', 'company', 'about', 'corporate', '料金', '価格', 'price', 'fee', 'plan', '費用', 'よくある', 'faq', '口コミ', 'voice', 'review', 'お客様の声', 'service', 'サービス', '許可', 'license', '情報提供', '情報公開', 'マージン', 'disclosure', '派遣法', '職業安定法', '労使協定'] as $k) {
        if (str_contains($s, $k)) { $p += 10; }
    }
    return $p - substr_count(parse_url($url, PHP_URL_PATH) ?? '/', '/');
}

/** 同じドメインを最大 $max ページ巡回する。 */
function khy_crawl(string $start, int $max = 30, int $budget_sec = 70): array
{
    $t0 = microtime(true);
    $first = khy_fetch($start);
    $pages = []; $notes = [];
    if ($first['code'] < 200 || $first['code'] >= 400 || $first['body'] === '') {
        return ['pages' => [], 'notes' => ['最初のページを読めませんでした（HTTP ' . $first['code'] . '）'], 'host' => parse_url($start, PHP_URL_HOST)];
    }
    $host = strtolower((string)parse_url($first['url'], PHP_URL_HOST));
    $scheme = (string)parse_url($first['url'], PHP_URL_SCHEME);
    $origin = $scheme . '://' . $host;
    $robots = khy_robots($origin);
    // 入れたURLがフォルダの下なら、そのフォルダの中だけを巡回する（同じドメインに別の会社・別のサイトが同居していることがある。
    // 2026-10-31 proto.exbridge.jp/khyogen/sample/ を診断したら、サイトマップから他のデモまで読み、そこにある「許可」で許可の表示ありと誤判定した）
    $sp = (string)(parse_url($first['url'], PHP_URL_PATH) ?? '/');
    $prefix = str_ends_with($sp, '/') ? $sp : preg_replace('#/[^/]*$#', '/', $sp);
    if ($prefix === '') { $prefix = '/'; }
    $queue = []; $seen = [];
    $add = static function (string $u, string $label) use (&$queue, &$seen, $host, $robots, $prefix) {
        $h = strtolower((string)parse_url($u, PHP_URL_HOST));
        if ($h !== $host && $h !== 'www.' . $host && 'www.' . $h !== $host) { return; }
        if (preg_match('#\.(pdf|jpe?g|png|gif|webp|svg|zip|mp4|mp3|css|js|xml|ico|woff2?)(\?|$)#i', $u)) { return; }
        $path = (string)(parse_url($u, PHP_URL_PATH) ?? '/');
        if (!str_starts_with($path, $prefix)) { return; }
        if (!khy_allowed($path, $robots['disallow'])) { return; }
        $k = rtrim(preg_replace('/\?.*$/', '', $u), '/');
        if (isset($seen[$k])) { return; }
        $seen[$k] = true;
        $queue[] = [$u, khy_priority($u, $label)];
    };
    $seen[rtrim(preg_replace('/\?.*$/', '', $first['url']), '/')] = true;
    $ex = khy_extract($first['body'], $first['url']);
    $pages[] = ['url' => $first['url'], 'title' => $ex['title'], 'text' => $ex['text'], 'scripts' => $ex['scripts']];
    foreach ($ex['links'] as $u => $label) { $add($u, $label); }
    // サイトマップからも拾う（JSで描画するサイトは、ここからしかページが分からない）
    $maps = $robots['sitemaps'] ?: [$origin . '/sitemap.xml'];
    foreach (array_slice($maps, 0, 2) as $sm) {
        $r = khy_fetch($sm, 8);
        if ($r['code'] === 200 && preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $r['body'], $mm)) {
            foreach (array_slice($mm[1], 0, 200) as $u) {
                $u = html_entity_decode($u, ENT_QUOTES, 'UTF-8');
                if (preg_match('#\.xml(\?|$)#i', $u)) { continue; }
                $add($u, '');
            }
        }
    }
    while ($queue && count($pages) < $max && microtime(true) - $t0 < $budget_sec) {
        usort($queue, static fn($a, $b) => $b[1] <=> $a[1]);
        [$u] = array_shift($queue);
        $r = khy_fetch($u, 10);
        if ($r['code'] !== 200 || ($r['ctype'] !== '' && !str_contains(strtolower($r['ctype']), 'html'))) { continue; }
        $ex = khy_extract($r['body'], $r['url']);
        $pages[] = ['url' => $r['url'], 'title' => $ex['title'], 'text' => $ex['text'], 'scripts' => $ex['scripts']];
        foreach ($ex['links'] as $lu => $label) { $add($lu, $label); }
    }
    if (microtime(true) - $t0 >= $budget_sec) { $notes[] = '時間の上限に達したため、' . count($pages) . 'ページで打ち切りました'; }
    return ['pages' => $pages, 'notes' => $notes, 'host' => $host];
}

/** 決まりのファイルを読む。 */
function khy_rules(string $industry): array
{
    $dir = __DIR__ . '/khyogen_rules';
    $out = [];
    foreach (array_unique(['common', $industry]) as $name) {
        $f = $dir . '/' . preg_replace('/[^a-z0-9_-]/', '', $name) . '.json';
        if (!is_file($f)) { continue; }
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d)) { $out[$name] = $d; }
    }
    return $out;
}

function khy_industries(): array
{
    $out = [];
    foreach (glob(__DIR__ . '/khyogen_rules/*.json') ?: [] as $f) {
        $d = json_decode((string)file_get_contents($f), true);
        if (is_array($d) && ($d['industry'] ?? '') !== 'common') { $out[$d['industry']] = $d; }
    }
    return $out;
}

/** 業種を判定する。detect の言葉が detect_min 種類以上あれば、その業種。 */
function khy_detect(array $pages): array
{
    $all = implode("\n", array_column($pages, 'text'));
    $best = ['id' => 'common', 'name' => '業種を判定できませんでした（全業種共通の決まりだけで点検）', 'hits' => []];
    $bestn = 0;
    foreach (khy_industries() as $id => $d) {
        $hits = [];
        foreach ($d['detect'] ?? [] as $w) { if (mb_strpos($all, khy_norm($w)) !== false) { $hits[] = $w; } }
        if (count($hits) >= (int)($d['detect_min'] ?? 3) && count($hits) > $bestn) {
            $bestn = count($hits);
            $best = ['id' => $id, 'name' => $d['name'], 'hits' => $hits];
        }
    }
    return $best;
}

function khy_re(string $p): string { return '/' . str_replace('/', '\/', $p) . '/u'; }

function khy_any(string $text, array $pats): bool
{
    foreach ($pats as $p) { if (preg_match(khy_re($p), $text)) { return true; } }
    return false;
}

/** 抜き出し（前後 $n 字）。 */
function khy_snip(string $text, int $byte_pos, int $len, int $n = 45): string
{
    $pre = mb_substr(substr($text, 0, $byte_pos), -$n);
    $hit = substr($text, $byte_pos, $len);
    $post = mb_substr(substr($text, $byte_pos + $len), 0, $n);
    return str_replace("\n", ' ', $pre . '【' . $hit . '】' . $post);
}

/** 規則を当てる。1つの規則につき、ページごとに2件・全体で8件まで拾う（同じ指摘の繰り返しで一覧を埋めない）。 */
function khy_apply(array $rule, array $pages): array
{
    $hits = [];
    $type = $rule['type'] ?? '';
    $all = implode("\n", array_column($pages, 'text'));
    if ($type === 'near_missing' || $type === 'presence') {
        foreach ($pages as $pg) {
            $per = 0;
            foreach ($rule['match'] as $p) {
                if (!preg_match_all(khy_re($p), $pg['text'], $mm, PREG_OFFSET_CAPTURE)) { continue; }
                foreach ($mm[0] as [$w, $pos]) {
                    if ($type === 'near_missing') {
                        $n = (int)($rule['near_chars'] ?? 120);
                        $win = mb_substr(substr($pg['text'], 0, $pos), -$n) . $w . mb_substr(substr($pg['text'], $pos + strlen($w)), 0, $n);
                        if (khy_any($win, $rule['near'] ?? [])) { continue; }
                    }
                    $hits[] = ['url' => $pg['url'], 'snippet' => khy_snip($pg['text'], $pos, strlen($w))];
                    if (++$per >= 2 || count($hits) >= 8) { break 3; }
                }
            }
        }
    } elseif ($type === 'site_missing' || $type === 'site_need_when') {
        $whenHit = null;
        foreach ($pages as $pg) {
            foreach ($rule['when'] as $p) {
                if (preg_match(khy_re($p), $pg['text'], $m, PREG_OFFSET_CAPTURE)) { $whenHit = ['url' => $pg['url'], 'snippet' => khy_snip($pg['text'], $m[0][1], strlen($m[0][0]))]; break 2; }
            }
        }
        if ($whenHit && !khy_any($all, $rule['need'])) { $hits[] = $whenHit; }
    } elseif ($type === 'site_missing_each') {
        // when があれば、その話をしているサイトだけを見る（派遣だけの会社に、紹介の公開項目を求めない）
        if (!empty($rule['when']) && !khy_any($all, $rule['when'])) { return []; }
        // skip_if があれば、別の形（PDF・公的サイト）で公開していると見なして点検しない。PDFの中までは読まないため
        if (!empty($rule['skip_if']) && khy_any($all, $rule['skip_if'])) { return []; }
        $miss = [];
        foreach ($rule['items'] as $it) { if (!khy_any($all, $it['need'])) { $miss[] = $it['label']; } }
        if ($miss) { $hits[] = ['url' => $pages[0]['url'], 'snippet' => 'サイトの中で見当たらないもの：' . implode('、', $miss)]; }
    }
    return $hits;
}

/** 和暦・西暦の年を西暦の整数に。 */
function khy_year(string $s): ?int
{
    if (preg_match('/(19|20)\d{2}/', $s, $m)) { return (int)$m[0]; }
    if (preg_match('/(令和|平成|昭和)\s?(元|\d{1,2})/u', $s, $m)) {
        $n = $m[2] === '元' ? 1 : (int)$m[2];
        return ['令和' => 2018, '平成' => 1988, '昭和' => 1925][$m[1]] + $n;
    }
    return null;
}

/**
 * 設立・創業より前の実績をうたっていないか。
 * 例：設立2023年の会社が「累計10,000件超（2015-2025）」「創業15年」と書いている。
 * 前身の事業の実績なら問題ないことが多いが、そう書かないと根拠を確かめられない。
 */
function khy_check_years(array $pages, int $now_year): array
{
    $all = implode("\n", array_column($pages, 'text'));
    $Y = '(?:(?:19|20)\d{2}|(?:令和|平成|昭和)\s?(?:元|\d{1,2}))';
    $setsu = null; $sogyo = null;
    if (preg_match('/設立\s*[:：]?\s*(' . $Y . ')\s*年/u', $all, $m)) { $setsu = khy_year($m[1]); }
    if (preg_match('/創業\s*[:：]?\s*(' . $Y . ')\s*年/u', $all, $m)) { $sogyo = khy_year($m[1]); }
    $claims = [];
    foreach ($pages as $pg) {
        $t = $pg['text'];
        // 期間：2015-2025 / 2015年〜2025年
        if (preg_match_all('/((?:19|20)\d{2})\s?年?\s?[-‐－〜～~]\s?((?:19|20)\d{2})\s?年?/u', $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $m) {
                $from = (int)$m[1][0];
                // 前後を文字単位で切る（バイトで切ると全角の途中で割れて、/u の照合が失敗する）
                $win = mb_substr(substr($t, 0, $m[0][1]), -60) . mb_substr(substr($t, $m[0][1]), 0, 100);
                if (!preg_match('/累計|実績|件数|施工|対応|件/u', $win)) { continue; }
                $claims[] = ['from' => $from, 'url' => $pg['url'], 'snippet' => khy_snip($t, $m[0][1], strlen($m[0][0]))];
            }
        }
        // 年数：創業15年 / 実績20年 / 20年の実績
        if (preg_match_all('/(?:創業|実績|業歴|経験)\s?(\d{1,3})\s?年|(\d{1,3})\s?年(?:の|以上の)?\s?(?:実績|歴史|経験|ノウハウ)/u', $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $m) {
                $n = (int)(($m[1][0] ?? '') !== '' ? $m[1][0] : $m[2][0]);
                if ($n < 1 || $n > 150) { continue; }
                $claims[] = ['from' => $now_year - $n, 'url' => $pg['url'], 'snippet' => khy_snip($t, $m[0][1], strlen($m[0][0]))];
            }
        }
    }
    $known = array_filter([$setsu, $sogyo], static fn($v) => $v !== null);
    if (!$known) {
        // 設立・創業の年がサイトに無いのに、期間つきの実績を書いている → 根拠を確かめられない（注意）
        $ranged = array_values(array_filter($claims, static fn($c) => preg_match('/(?:19|20)\d{2}\s?年?\s?[-‐－〜～~]/u', $c['snippet'])));
        return $ranged ? ['nobase' => array_slice($ranged, 0, 2)] : [];
    }
    $base = min($known);
    $out = [];
    foreach ($claims as $c) {
        if ($c['from'] < $base - 1) {   // 1年の幅は見る（決算期・数え方の違い）
            $c['snippet'] .= '（サイト内の' . ($setsu !== null ? '設立：' . $setsu . '年' : '') . ($setsu !== null && $sogyo !== null ? '・' : '') . ($sogyo !== null ? '創業：' . $sogyo . '年' : '') . '）';
            $out[] = $c;
            if (count($out) >= 4) { break; }
        }
    }
    return $out;
}

const KHY_YEARS_RULE = [
    'id' => 'years',
    'level' => 'warning',
    'category' => '優良誤認（景品表示法）',
    'title' => '会社の設立・創業より前からの実績に読める表示がある',
    'law' => '不当景品類及び不当表示防止法 第5条第1号（優良誤認）',
    'sources' => [['label' => '景品表示法（e-Gov 法令検索）', 'url' => 'https://laws.e-gov.go.jp/law/337AC0000000134']],
    'why' => '実績の件数や年数が、サイトに書かれた設立（創業）の年より前から数えているように読めます。前身の事業や代表者個人の実績を含めている場合は問題ないことが多いのですが、そう書かれていないと、根拠を確かめられない実績の表示として問われることがあります。',
    'fix' => '実績の数え方を書いてください（例：「前身の◯◯（2015年創業）からの累計」「代表の個人事業時代を含む」）。数え方を示せない場合は、設立からの実績に直してください。',
];

const KHY_YEARS_NOBASE_RULE = [
    'id' => 'years_nobase',
    'level' => 'caution',
    'category' => '優良誤認（景品表示法）',
    'title' => '期間つきの実績があるのに、会社の設立・創業の年がサイトに見当たらない',
    'law' => '不当景品類及び不当表示防止法 第5条第1号（優良誤認）',
    'sources' => [['label' => '景品表示法（e-Gov 法令検索）', 'url' => 'https://laws.e-gov.go.jp/law/337AC0000000134']],
    'why' => '「累計◯件（2015-2025）」のように期間つきの実績を書いていても、会社がいつからあるかが書かれていないと、お客様は実績の根拠を確かめられません。別ブランドのサイトや、事業を引き継いだ会社でよく起きます。',
    'fix' => '会社概要に設立・創業の年を書き、実績の数え方（前身の事業を含む など）を実績の近くに書いてください。',
];

const KHY_THIN_RULE = [
    'id' => 'thin',
    'level' => 'warning',
    'category' => '点検できなかったこと',
    'title' => 'サーバーが返すHTMLに本文がほとんど無く、表現を点検できない',
    'law' => '（法令の問題ではありません）',
    'sources' => [],
    'why' => 'このサイトは、ブラウザがJavaScriptを動かしてから本文を表示する作りです。この診断と同じく、検索エンジンの一部やChatGPTなどのAIも、JavaScriptを動かさずにHTMLだけを読むため、御社の説明・料金・連絡先がほとんど読めていない可能性があります。',
    'fix' => 'サーバーが本文をHTMLで返す作り（静的なHTML、サーバー側の描画）に変えるか、制作ツールの「SEO向けの事前描画」の設定を有効にしてください。そのあとでもう一度診断すると、表現の点検ができます。',
];

/** 点数。規則ごとに1回だけ引く（同じ指摘が何件あっても、1つの直しで済むため）。 */
function khy_score(array $findings): array
{
    $w = 0; $c = 0;
    foreach ($findings as $f) { if ($f['level'] === 'warning') { $w++; } elseif ($f['level'] === 'caution') { $c++; } }
    $score = max(0, 100 - 12 * $w - 5 * $c);
    $grade = $score >= 90 ? 'A' : ($score >= 75 ? 'B' : ($score >= 60 ? 'C' : 'D'));
    return ['score' => $score, 'grade' => $grade, 'warning' => $w, 'caution' => $c];
}

/** 診断する。$industry は 'auto' か業種ID。 */
function khy_diagnose(string $url, string $industry = 'auto', int $max_pages = 30): array
{
    $cr = khy_crawl($url, $max_pages);
    $pages = $cr['pages'];
    $r = ['url' => $url, 'host' => $cr['host'], 'at' => date('c'), 'notes' => $cr['notes'], 'pages' => [], 'findings' => [], 'thin' => false];
    if (!$pages) { $r['error'] = $cr['notes'][0] ?? '読めませんでした'; return $r; }
    $chars = 0;
    foreach ($pages as $pg) { $r['pages'][] = ['url' => $pg['url'], 'title' => $pg['title'], 'chars' => mb_strlen($pg['text'])]; $chars += mb_strlen($pg['text']); }
    $r['chars'] = $chars;
    // JSで描画するサイトは、サーバーが返すHTMLに本文がほとんど無い。点検できる文字が少ないことを先に知らせる
    if (mb_strlen($pages[0]['text']) < 200 && $pages[0]['scripts'] > 0) { $r['thin'] = true; }
    $det = khy_detect($pages);
    if ($industry !== 'auto' && isset(khy_industries()[$industry])) {
        $det = ['id' => $industry, 'name' => khy_industries()[$industry]['name'], 'hits' => $det['hits'], 'chosen' => true];
    }
    $r['industry'] = $det;
    foreach (khy_rules($det['id']) as $set) {
        foreach ($set['rules'] ?? [] as $rule) {
            $hits = khy_apply($rule, $pages);
            if (!$hits) { continue; }
            $r['findings'][] = [
                'id' => $rule['id'], 'level' => $rule['level'], 'category' => $rule['category'], 'title' => $rule['title'],
                'law' => $rule['law'], 'sources' => $rule['sources'], 'why' => $rule['why'], 'fix' => $rule['fix'], 'hits' => $hits,
            ];
        }
    }
    $yh = khy_check_years($pages, (int)date('Y'));
    if (isset($yh['nobase'])) { $r['findings'][] = KHY_YEARS_NOBASE_RULE + ['hits' => $yh['nobase']]; }
    elseif ($yh) { $r['findings'][] = KHY_YEARS_RULE + ['hits' => $yh]; }
    // 本文がHTMLにほとんど無いサイトは、表現を点検できない。点数を出すと「問題なし」に見えるので出さない
    if ($r['thin']) {
        $r['findings'] = [KHY_THIN_RULE + ['hits' => [['url' => $pages[0]['url'], 'snippet' => 'サーバーが返すHTMLから読めた本文：' . mb_strlen($pages[0]['text']) . '字']]]];
        $r['score'] = null;
        return $r;
    }
    usort($r['findings'], static fn($a, $b) => ($a['level'] === 'warning' ? 0 : 1) <=> ($b['level'] === 'warning' ? 0 : 1));
    $r['score'] = khy_score($r['findings']);
    return $r;
}
