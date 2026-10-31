#!/usr/bin/env python3
"""Kurage 広告表現チェック の入口のOGP画像 1200x630（マスコット入り・数字は焼き込まない）。
  /usr/bin/python3 scripts/make_ogp.py  → demo/khyogen-ogp.png
"""
import base64
import pathlib

from playwright.sync_api import sync_playwright

ROOT = pathlib.Path(__file__).resolve().parent.parent
import sys
PRO = "--pro" in sys.argv
OUT = ROOT / "demo" / "khyogen-ogp.png"


def data_uri(path, mime):
    return f"data:{mime};base64," + base64.b64encode(pathlib.Path(path).read_bytes()).decode()


MASCOT = data_uri("/home/kojima/work/kurage_web/images/kurage-mascot-cutout.png", "image/png")
LOGO = data_uri("/home/kojima/work/exbridge_jp/images/logo-mark-128.png", "image/png")

HTML = f"""<!doctype html><html lang="ja"><head><meta charset="utf-8"><style>
*{{box-sizing:border-box;margin:0;padding:0}}
body{{width:1200px;height:630px;font-family:"Noto Sans CJK JP",sans-serif;background:#f4f7fb;color:#1b2a3a;position:relative;overflow:hidden}}
.top{{position:absolute;left:0;right:0;top:0;height:10px;background:linear-gradient(90deg,#0a7a72,#f59e0b)}}
.l{{position:absolute;left:64px;top:66px;width:860px}}
.name{{font-size:30px;font-weight:900;color:#0a7a72}}
h1{{font-size:54px;line-height:1.3;font-weight:900;margin:16px 0 24px}}
h1 em{{font-style:normal;color:#0a7a72}}
.chips{{display:flex;gap:12px;flex-wrap:wrap}}
.chips div{{background:#fff;border:2px solid #dde5ef;border-radius:16px;padding:12px 18px;line-height:1.35}}
.chips b{{display:block;font-size:26px;color:#0a7a72}}
.chips span{{font-size:16px;color:#5d6b7a;font-weight:700}}
.sub{{margin-top:22px;font-size:22px;font-weight:700;color:#3d5066}}
.m{{position:absolute;right:40px;bottom:40px;width:250px;height:auto;object-fit:contain}}
.foot{{position:absolute;left:64px;bottom:30px;display:flex;align-items:center;gap:10px;font-size:20px;color:#5d6b7a;font-weight:700}}
.foot img{{width:34px;height:34px}}
</style></head><body><div class="top"></div>
<div class="l">
  <div class="name">Kurage 広告表現チェック</div>
  <h1>URLを入れるだけで、<br><em>ホームページの広告表現</em>を点検</h1>
  <div class="chips">
    <div><b>No.1・最安</b><span>根拠の注記</span></div>
    <div><b>料金の表示</b><span>〇〇円〜・見積無料</span></div>
    <div><b>実績の数字</b><span>設立年と照合</span></div>
    <div><b>許可の表示</b><span>廃棄物・古物・水道</span></div>
  </div>
  <div class="sub">景品表示法・特定商取引法・業種の決まりで／判定にAIを使わない</div>
</div>
<img class="m" src="{MASCOT}">
<div class="foot"><img src="{LOGO}">株式会社エクスブリッジ</div>
</body></html>"""

with sync_playwright() as p:
    b = p.chromium.launch()
    pg = b.new_page(viewport={"width": 1200, "height": 630})
    pg.set_content(HTML, wait_until="load")
    pg.wait_for_timeout(300)
    pg.screenshot(path=str(OUT))
    b.close()
print(OUT)
