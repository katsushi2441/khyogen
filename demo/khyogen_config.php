<?php
// デモ（https://proto.exbridge.jp/khyogen/）の設定。配布物の khyogen_config.php.example は触らない。
define('KHY_TITLE', 'Kurage 広告表現チェック');
define('KHY_LITE', true);                 // デモ版は点数と分類ごとの件数だけ（該当箇所・根拠・直し方・書き出しは出さない）
define('KHY_MAX_PAGES', 20);
define('KHY_RATE_PER_HOUR', 5);
define('KHY_CACHE_HOURS', 6);
define('KHY_CANONICAL', 'https://proto.exbridge.jp/khyogen/khyogen.php');
define('KHY_OG_IMAGE', 'https://proto.exbridge.jp/khyogen/khyogen-ogp.png');
define('KHY_DEMO_FOOTER', (($_SERVER['HTTP_HOST'] ?? '') === 'proto.exbridge.jp')
  ? '<div class="box" style="margin-top:24px"><b>これはデモです。</b>'
  . 'デモ版の結果は、点数と分類ごとの件数だけに制限しています。どのページのどの表現か、根拠の条文・ガイドライン、直し方、結果の書き出しは、有料の導入版でご覧いただけます。'
  . 'このシステムは、御社のレンタルサーバーにPHPを置くだけで動きます（データベース不要）。自社のサイト、制作したお客様のサイト、取引先の点検に、社内で使えます。'
  . '<p style="display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 0"><a class="btn" href="https://kappstore.exbridge.jp/app.php?id=cb0b3c587a75fc8a&amp;ref=khyogen-demo">導入版（Kurage App Store）</a><a class="btn ghost" href="https://exbridge.jp/contact.php?ref=khyogen-demo&amp;subject=' . rawurlencode('Kurage 広告表現チェックについて') . '">導入について相談する</a>'
  . '<a class="btn ghost" href="https://exbridge.jp/ai-it-komon.html#ref=khyogen-demo">AI-IT顧問</a>'
  . '<a class="btn ghost" href="https://exbridge.jp/ai-sns-komon.html#ref=khyogen-demo">AI-SNS顧問（ホームページとX）</a></p></div>'
  . '<script src=https://kurage.exbridge.jp/partner-bar.js defer></script>'
  . "<script>(function(){var s=document.createElement('script');s.src='https://kurage.exbridge.jp/simpletrack.php?url='+encodeURIComponent(location.href)+'&ref='+encodeURIComponent(document.referrer);s.async=true;document.head.appendChild(s)})();</script>"
  : '');
