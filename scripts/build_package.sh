#!/usr/bin/env bash
# 配布用の zip を作る（設定の見本・説明書つき。デモの設定・診断結果は入れない）
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check.php
out=outputs/kurage-hyogen-check.zip; tmp=$(mktemp -d); d="$tmp/kurage-hyogen-check"
mkdir -p "$d/khy_data" "$d/khy_assets" "$d/khyogen_rules" "$d/scripts"
cp public/khyogen.php public/khyogen_engine.php public/khyogen_config.php.example "$d/"
cp public/khy_data/.htaccess "$d/khy_data/"
cp public/khyogen_rules/*.json public/khyogen_rules/.htaccess "$d/khyogen_rules/"
cp public/khy_assets/{logo.png,mascot.png} "$d/khy_assets/"
cp scripts/check.php scripts/make_password_hash.php "$d/scripts/"
sed -i "s#__DIR__ . '/../public/khyogen_engine.php'#__DIR__ . '/../khyogen_engine.php'#" "$d/scripts/check.php"
cp README.md INSTALL.md CLAUDE.md AGENTS.md LICENSE "$d/"
grep -rq "FTP_PASS\|aixec/.env\|proto_exbridge_jp" "$d" && { echo "社内の設定の痕跡がある。止める" >&2; exit 1; } || true
(cd "$d" && php scripts/check.php >/dev/null) || { echo "zip の中で検査が通らない" >&2; exit 1; }
mkdir -p outputs; rm -f "$out"; (cd "$tmp" && zip -qr - kurage-hyogen-check) > "$out"; rm -rf "$tmp"
unzip -l "$out"
