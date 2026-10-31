#!/usr/bin/env bash
# デモの公開。https://proto.exbridge.jp/khyogen/
# 設定は demo/ のものを使い、配布物の *.example は触らない。検査が通らなければ置かない。FTPは1接続にまとめる。
set -euo pipefail
cd "$(dirname "$0")/.."
php scripts/check.php
/usr/bin/python3 - <<'PY'
import ftplib, glob, os
for ln in open('/home/kojima/work/aixec/.env', encoding='utf-8'):
    if '=' in ln and not ln.startswith('#'):
        k, v = ln.split('=', 1); os.environ.setdefault(k.strip(), v.strip().strip('"').strip("'"))
f = ftplib.FTP(os.environ['FTP_HOST'], timeout=120); f.login(os.environ['FTP_USER'], os.environ['FTP_PASS'])
root = '/web/proto_exbridge_jp/khyogen'
for d in (root, root + '/khy_data', root + '/khy_assets', root + '/khyogen_rules'):
    try: f.mkd(d)
    except ftplib.error_perm: pass
files = {'public/khyogen.php': 'khyogen.php', 'public/khyogen_engine.php': 'khyogen_engine.php',
         'demo/khyogen_config.php': 'khyogen_config.php', 'demo/index.php': 'index.php', 'demo/.htaccess': '.htaccess',
         'public/khy_data/.htaccess': 'khy_data/.htaccess', 'public/khyogen_rules/.htaccess': 'khyogen_rules/.htaccess',
         'public/khy_assets/logo.png': 'khy_assets/logo.png', 'public/khy_assets/mascot.png': 'khy_assets/mascot.png'}
for p in glob.glob('public/khyogen_rules/*.json'):
    files[p] = 'khyogen_rules/' + os.path.basename(p)
for src, dst in files.items():
    f.storbinary(f'STOR {root}/{dst}', open(src, 'rb')); print('deployed:', dst)
f.quit()
PY
echo "published: https://proto.exbridge.jp/khyogen/"
