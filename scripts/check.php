<?php
declare(strict_types=1);
/**
 * 判定の検査（ネットにつながない）。見本のHTMLで、拾うべきものを拾い、拾ってはいけないものを拾わないかを見る。
 *   php scripts/check.php
 */
require __DIR__ . '/../public/khyogen_engine.php';

$ok = 0; $ng = 0;
function t(string $name, bool $cond): void { global $ok, $ng; if ($cond) { $ok++; } else { $ng++; echo "NG: $name\n"; } }
function page(string $html, string $url = 'https://example.jp/'): array { $e = khy_extract($html, $url); return ['url' => $url, 'title' => $e['title'], 'text' => $e['text'], 'scripts' => $e['scripts']]; }
function rule(string $set, string $id): array { foreach (khy_rules($set)[$set]['rules'] as $r) { if ($r['id'] === $id) { return $r; } } throw new Exception($id); }

// --- No.1：根拠なしは拾い、注記があれば拾わない
t('no1 根拠なし', (bool)khy_apply(rule('common', 'no1'), [page('<p>名古屋で地域No.1の水道屋さん</p>')]));
t('no1 注記あり', !khy_apply(rule('common', 'no1'), [page('<p>地域No.1 ※2025年3月 当社調べ（名古屋市内の水道修理業者10社の件数比較）</p>')]));
t('no1 全角', (bool)khy_apply(rule('common', 'no1'), [page('<p>顧客満足度Ｎｏ．１</p>')]));
// --- 最低価格：注記なしは拾い、追加料金の注記があれば拾わない
t('price_from 注記なし', (bool)khy_apply(rule('common', 'price_from'), [page('<h2>トイレつまり 3,300円〜</h2><p>すぐ伺います</p>')]));
t('price_from 注記あり', !khy_apply(rule('common', 'price_from'), [page('<h2>トイレつまり 3,300円〜</h2><p>※出張費・深夜料金は別途。作業内容によりお見積りします</p>')]));
t('price_from 見積無料は注記ではない', (bool)khy_apply(rule('common', 'price_from'), [page('<p>トイレつまり 2,980円〜</p><p>見積無料！出張費0円！</p>')]));
t('price_from 現地でお見積り', !khy_apply(rule('common', 'price_from'), [page('<p>2,980円〜（作業内容により現地でお見積りします）</p>')]));
// --- 無料の条件
t('free 条件なし', (bool)khy_apply(rule('common', 'free_condition'), [page('<p>見積無料！出張費0円！</p><p>お気軽にお電話を</p><p>' . str_repeat('あ', 150) . '</p>')]));
t('free 条件あり', !khy_apply(rule('common', 'free_condition'), [page('<p>見積無料（ただし対象エリア外は出張費がかかります）</p>')]));
// --- 言い切り
t('absolute', (bool)khy_apply(rule('common', 'absolute'), [page('<p>二度と出ない完全駆除</p>')]));
t('absolute なし', !khy_apply(rule('common', 'absolute'), [page('<p>再発した場合は30日以内なら無料で再施工します</p>')]));
// --- 特商法：決済があるのに表記が無い／ある
t('tokusho なし', (bool)khy_apply(rule('common', 'tokusho_page'), [page('<p>カートに入れる</p>')]));
t('tokusho あり', !khy_apply(rule('common', 'tokusho_page'), [page('<p>カートに入れる</p>'), page('<h1>特定商取引法に基づく表記</h1>', 'https://example.jp/law')]));
// --- 事業者の表示
$ci = khy_apply(rule('common', 'company_info'), [page('<p>株式会社サンプル 愛知県名古屋市中村区椿町1-1 TEL 052-000-0000</p>')]);
t('company_info そろっている', !$ci);
$ci = khy_apply(rule('common', 'company_info'), [page('<p>サンプル水道 お電話ください</p>')]);
t('company_info 欠け', (bool)$ci && str_contains($ci[0]['snippet'], '電話番号'));
// --- 生活関連：不用品回収の許可
t('haikibutsu なし', (bool)khy_apply(rule('seikatsu', 'haikibutsu'), [page('<p>不用品回収 即日対応</p>')]));
t('haikibutsu あり', !khy_apply(rule('seikatsu', 'haikibutsu'), [page('<p>不用品回収</p><p>一般廃棄物収集運搬業許可 名古屋市第1号</p>')]));
// --- 水道局指定：自治体なし／あり
t('suido なし', (bool)khy_apply(rule('seikatsu', 'suido_shitei'), [page('<p>当社は水道局指定業者です。安心してお任せください。' . str_repeat('ー', 50) . '</p>')]));
t('suido あり', !khy_apply(rule('seikatsu', 'suido_shitei'), [page('<p>名古屋市上下水道局 指定給水装置工事事業者（第123号）</p>')]));
// --- 業種の判定
$d = khy_detect([page('<p>蜂の巣駆除・害虫駆除・ネズミ駆除・シロアリ</p>')]);
t('detect seikatsu', $d['id'] === 'seikatsu');
$d = khy_detect([page('<p>税理士事務所です</p>')]);
t('detect common', $d['id'] === 'common');
// --- 年数の照合（アスリンクの形）
$y = khy_check_years([page('<p>会社概要 設立 2023年7月</p><p>累計10,000件超（2015-2025）</p>')], 2026);
t('years 設立より前', count($y) === 1);
$y = khy_check_years([page('<p>設立 2023年 創業 2015年</p><p>累計10,000件超（2015-2025）</p>')], 2026);
t('years 創業が先なら拾わない', count($y) === 0);
$y = khy_check_years([page('<p>設立 令和5年</p><p>創業20年の実績</p>')], 2026);
t('years 和暦と年数', count($y) === 1);
$y = khy_check_years([page('<p>設立 2010年</p><p>累計5,000件（2012-2025）</p>')], 2026);
t('years 問題なし', count($y) === 0);
$y = khy_check_years([page('<p>累計10,000件超（2015-2025）</p>')], 2026);
t('years 設立年が無い', isset($y['nobase']));
// --- 実サイトで出た誤検知（2026-10-31 蜂の巣駆除の窓口）を拾わないこと
t('誤検知 確定価格', !khy_apply(rule('common', 'double_price'), [page('<p>提示された金額がすべてを網羅している確定価格であること</p>')]));
t('誤検知 一般論のクレカ', !khy_apply(rule('common', 'tokusho_page'), [page('<p>クレジットカード決済など多様な支払い方法に対応していることも多いです</p>')]));
t('誤検知 満足度が高い', !khy_apply(rule('common', 'rating'), [page('<p>利用者の満足度が高い業者を選びましょう</p>')]));
t('誤検知 完全駆除を完了', !khy_apply(rule('common', 'absolute'), [page('<p>約55分で完全駆除を完了しました</p>')]));
t('二重価格 本物', (bool)khy_apply(rule('common', 'double_price'), [page('<p>通常価格 15,000円 → 9,800円</p>')]));
t('評価 本物', (bool)khy_apply(rule('common', 'rating'), [page('<p>Google口コミ ★4.9</p>')]));
// --- 点数
$s = khy_score([['level' => 'warning'], ['level' => 'caution']]);
t('score', $s['score'] === 83);
// --- 社内の機械は診断しない
t('url 127', khy_url_ok('http://127.0.0.1/') === null);
t('url localhost', khy_url_ok('http://localhost/') === null);

// --- 人材派遣・職業紹介（2026-11-02 追加）
$d = khy_detect([page('<p>人材派遣・紹介予定派遣・人材紹介。派遣スタッフ募集。派遣先企業さまへ</p>')]);
t('detect haken', $d['id'] === 'haken');
t('haken_kyoka なし', (bool)khy_apply(rule('haken', 'haken_kyoka'), [page('<p>人材派遣のサンプル社です。派遣のお仕事多数</p>')]));
t('haken_kyoka あり', !khy_apply(rule('haken', 'haken_kyoka'), [page('<p>人材派遣</p><p>許可 一般労働者派遣事業 派23－300000</p>')]));
t('haken_kyoka 半角', !khy_apply(rule('haken', 'haken_kyoka'), [page('<p>人材派遣</p><p>労働者派遣事業許可：派23-300000</p>')]));
t('shokai_kyoka なし', (bool)khy_apply(rule('haken', 'shokai_kyoka'), [page('<p>人材紹介で転職をサポート</p>')]));
t('shokai_kyoka あり', !khy_apply(rule('haken', 'shokai_kyoka'), [page('<p>人材紹介</p><p>有料職業紹介事業 23－ユ－300000</p>')]));
$hd = khy_apply(rule('haken', 'haken_joho'), [page('<p>人材派遣のお仕事。派遣スタッフ募集</p>')]);
t('haken_joho 欠け', (bool)$hd && str_contains($hd[0]['snippet'], 'マージン率'));
t('haken_joho そろっている', !khy_apply(rule('haken', 'haken_joho'), [page('<p>人材派遣</p><p>マージン率 25.1%　労使協定方式　教育訓練の内容</p>')]));
t('haken_joho 情報公開ページあり', !khy_apply(rule('haken', 'haken_joho'), [page('<p>人材派遣</p><h1>労働者派遣法第２３条第５項に基づく情報公開</h1><p>札幌オフィス PDF</p>')]));
t('haken_joho 総合サイトの案内', !khy_apply(rule('haken', 'haken_joho'), [page('<p>人材派遣</p><p>情報は厚生労働省の人材サービス総合サイトをご覧ください</p>')]));
t('haken_joho 派遣をしていない', !khy_apply(rule('haken', 'haken_joho'), [page('<p>人材紹介で転職をサポート</p>')]));
$sj = khy_apply(rule('haken', 'shokai_joho'), [page('<p>人材紹介 紹介手数料 年収の30％ 返金規定あり</p>')]);
t('shokai_joho 実績の欠け', (bool)$sj && str_contains($sj[0]['snippet'], '就職者数') && !str_contains($sj[0]['snippet'], '手数料'));
t('shokai_joho 情報提供ページあり', !khy_apply(rule('haken', 'shokai_joho'), [page('<p>人材紹介</p><h1>職業安定法に基づく情報提供</h1>')]));
t('shokai_joho 紹介をしていない', !khy_apply(rule('haken', 'shokai_joho'), [page('<p>人材派遣のお仕事</p>')]));
t('seibetsu 女性歓迎', (bool)khy_apply(rule('haken', 'seibetsu'), [page('<p>【経理事務】女性歓迎！週3日〜</p>')]));
t('seibetsu 営業マン', (bool)khy_apply(rule('haken', 'seibetsu'), [page('<p>ルート営業マン募集</p>')]));
t('seibetsu お客様の声の営業マン', !khy_apply(rule('haken', 'seibetsu'), [page('<p>最前線で活躍する営業マンを支えるために、事務スタッフを採用しました</p>')]));
t('seibetsu 女性が活躍', !khy_apply(rule('haken', 'seibetsu'), [page('<p>女性やシニアが生き生きと働ける場を広げてきました</p>')]));
t('nenrei 上限', (bool)khy_apply(rule('haken', 'nenrei'), [page('<p>事務スタッフ（35歳まで）</p>')]));
t('nenrei 例外の理由あり', !khy_apply(rule('haken', 'nenrei'), [page('<p>35歳未満（長期勤続によるキャリア形成のため）</p>')]));
t('nenrei 60歳以上', !khy_apply(rule('haken', 'nenrei'), [page('<p>60歳以上の方の受け入れ期間の制限はありません</p>')]));
t('nenrei 18歳未満', !khy_apply(rule('haken', 'nenrei'), [page('<p>18歳未満の方はご応募いただけません</p>')]));
t('jizen_mensetsu 派遣先の面接', (bool)khy_apply(rule('haken', 'jizen_mensetsu'), [page('<p>派遣先企業での面接があります</p>' . str_repeat('ー', 90))]));
t('jizen_mensetsu 紹介予定派遣', !khy_apply(rule('haken', 'jizen_mensetsu'), [page('<p>紹介予定派遣は人材派遣と異なり、派遣先企業が派遣開始前に求職者の面接・選考を行うことが可能です</p>')]));
t('jizen_mensetsu 禁止の説明', !khy_apply(rule('haken', 'jizen_mensetsu'), [page('<p>4.派遣社員の事前面接、選考はNG 事前に派遣社員を指名する、派遣就業の開始前に面接を行う等</p>')]));
t('jizen_mensetsu 職場見学', !khy_apply(rule('haken', 'jizen_mensetsu'), [page('<p>ご希望の方は職場見学ができます</p>')]));
t('kyujin_koshunyu 根拠なし', (bool)khy_apply(rule('haken', 'kyujin_koshunyu'), [page('<p>高収入のお仕事多数！</p>' . str_repeat('ー', 90))]));
t('kyujin_koshunyu 金額あり', !khy_apply(rule('haken', 'kyujin_koshunyu'), [page('<p>手厚い人員配置・高収入・賞与年2回＊年収520万円〜</p>')]));

// --- 社内のアドレスは取りに行かない（リダイレクト先も1回ごとに確かめる）
t('fetch 127', khy_fetch('http://127.0.0.1/')['code'] === 0);
t('fetch localhost名', khy_fetch('http://localhost/')['code'] === 0);

echo "ok $ok / ng $ng\n";
exit($ng ? 1 : 0);
