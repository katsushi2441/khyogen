# 設置手順（Kurage 広告表現チェック）

AIエージェントに頼む場合も、人が手で置く場合も、この順で進め、各手順の「確認」を通してから次へ進んでください。

## 1. 動く環境を確かめる

PHP 8.0 以上で、curl 拡張が使えるサーバー（一般的なレンタルサーバーで可）。データベースは要りません。

確認：サーバーに `<?php echo PHP_VERSION, ' ', extension_loaded('curl') ? 'curl OK' : 'curl なし';` を置いて開き、8.x と curl OK が出る。

## 2. ファイルを置く

この一式のうち、`khyogen.php`・`khyogen_engine.php`・`khyogen_rules/`・`khy_data/`・`khy_assets/` を、公開するフォルダ（例：`/khyogen/`）に置く。

確認：`https://御社のドメイン/khyogen/khyogen.php` が開き、入力欄が出る。

## 3. 結果と決まりが外から読めないことを確かめる

確認：`/khyogen/khy_data/` と `/khyogen/khyogen_rules/common.json` を開くと 403（読めない）になる。Apache 以外のサーバーでは、この2つのフォルダへの直接のアクセスを、サーバーの設定で止めてください。

## 4. 設定（任意）

`khyogen_config.php.example` を `khyogen_config.php` にコピーして、必要な行だけコメントを外す。社内だけで使うなら、パスワードを設定できます（`php scripts/make_password_hash.php` で作った値を `KHY_PASSWORD_HASH` に）。

## 5. 診断してみる

確認：自社のサイトのURLを入れて「診断する」を押し、1〜2分で点数と一覧が出る。

## 決まりを足す・直す

`khyogen_rules/<業種>.json` を足すか直し、`php scripts/check.php` に「拾うべき見本」と「拾ってはいけない見本」を足して、すべて通ることを確かめてください。
