#!/usr/bin/env python3
"""Xiangqi tablebase statistics page from .info files.

usage: scripts/tbstats.py INFO_DIR [--metric dtm|dtc] [-o OUT.html]
       scripts/tbstats.py --json INFO_ROOT [-o OUT.json]

With --json, INFO_ROOT holds dtc/ and dtm/ and the output is the explorer's
statistics data: one row per material, in the page's order,

    {"m": material, "z": Chinese name, "c": [win, draw, lose] x [red, black]
     to move, "l": {metric: [[ply, fen] red to move, [ply, fen] black]}}

with win/draw/lose from the side to move's view (the same in both metrics),
and plies as the HTML page shows them (0 and "" where there is no win).

INFO_DIR holds one <material>.info per table (KPK.info, KRNKAA.info, ...).
The metric defaults to INFO_DIR's name. Each .info is ChessTB's EGTB_Info:

    uint64 win[2], lose[2], draw[2], illegal[2]   [red to move, black to move]
    uint16 longest_win[2]
    char   longest_fen[2][120]
    uint64 longest_idx[2]                         (328 bytes with padding)

Rates are Red's: wins are Red-to-move wins plus Black-to-move losses. DTM's
longest wins round an even ply down to the odd one below it; DTC's are
shown as stored. A rate that rounds to 0 or 100 without being it reads
"<0.01%" or ">99.99%", as on ChessTB's statistics page.
"""
import argparse
import datetime
import glob
import json
import os
import struct
import sys

INFO = struct.Struct("<2Q2Q2Q2Q2H120s120s4x2Q")
assert INFO.size == 328

STRONG = "RNCP"     # rook, knight, cannon, pawn
GUARDS = "AB"       # advisor, bishop (elephant)
NAMES = ({"R": "车", "N": "马", "C": "炮", "P": "兵", "A": "士", "B": "相"},
         {"R": "车", "N": "马", "C": "炮", "P": "卒", "A": "士", "B": "象"})
TITLE = {"dtm": "DTM", "dtc": "DTC"}

HEAD = ('<!DOCTYPE html><html><head><meta content="text/html; charset=UTF-8" http-equiv="Content-Type">'
        '<title>中国象棋残局库 {title}统计</title><style>span{{line-height: 12px;vertical-align:middle;}}'
        'table{{border-collapse: collapse;border: 1px solid #D4E0EE;font: normal 12px Arial;color: #000555;'
        'background: #FCFDFE;white-space:nowrap;}}td{{text-align:right;}}td.la{{text-align:left;}}'
        'tr:nth-child(2n){{background: #F7F9FC;}}thead{{cursor: pointer;}}thead th{{background: #E6EDF5;'
        'color: #4F76A3;padding:8px;}}tbody td{{padding:8px;}}</style></head><body><table class="sortable">'
        '<thead><tr><th>文件名</th><th>中文名</th><th>胜率</th><th>和率</th><th>负率</th>'
        '<th colspan="2">最长局面（红先）</th><th colspan="2">最长局面（黑先）</th><th>局面个数</th></tr></thead><tbody>')
TAIL = '</tbody></table><script src="file/sorttable.js"></script></body></html>'


def read_info(path):
    v = INFO.unpack(open(path, "rb").read())
    fens = [f.split(b"\0", 1)[0].decode() for f in v[10:12]]
    return {"win": v[0:2], "lose": v[2:4], "draw": v[4:6], "illegal": v[6:8],
            "longest": v[8:10], "fen": fens}


def sides(material):
    """'KAARRNPKA' -> ('AARRNP', 'A'): each side's pieces without its king."""
    i = material.index("K", 1)
    return material[1:i], material[i + 1:]


def side_key(pieces):
    """More strong pieces (rooks first, then knights, cannons, pawns) sort
    later; then advisors, then bishops."""
    return tuple(pieces.count(p) for p in STRONG + GUARDS)


def order_key(material):
    red, black = sides(material)
    return side_key(red), side_key(black)


def chinese(material):
    """Strong pieces then advisors and bishops, per side: '车车马兵士 vs 士'."""
    out = []
    for pieces, names in zip(sides(material), NAMES):
        out.append("".join(names[p] * pieces.count(p) for p in STRONG + GUARDS))
    return " vs ".join(out)


def shown_ply(ply, metric):
    return ply - 1 + (ply & 1) if metric == "dtm" else ply


def longest_cells(info, color, metric):
    ply = info["longest"][color]
    if not ply:
        return "<td></td><td></td>"
    fen = info["fen"][color]
    link = fen.replace(" ", "_")
    return (f'<td class="la"><a target="_blank" href="/?{link}">{fen}</a></td>'
            f'<td id="la">{shown_ply(ply, metric)}</td>')


def percent(n, total):
    """Two decimals; <0.01% and >99.99% where it would round to 0 or 100
    without being it."""
    text = f"{n * 100 / total:.2f}" if total else "0.00"
    if text == "0.00" and n > 0:
        return "&lt;0.01%"
    if text == "100.00" and n < total:
        return "&gt;99.99%"
    return text + "%"


def row(material, info, metric):
    win, lose, draw = info["win"], info["lose"], info["draw"]
    total = sum(win) + sum(lose) + sum(draw)
    pct = lambda n: percent(n, total)
    red_win, red_lose, red_draw = win[0] + lose[1], lose[0] + win[1], sum(draw)
    return (f'<tr><td class="la">{material}</td><td class="la">{chinese(material)}</td>'
            f"<td>{pct(red_win)}</td><td>{pct(red_draw)}</td><td>{pct(red_lose)}</td>"
            f"{longest_cells(info, 0, metric)}{longest_cells(info, 1, metric)}"
            f"<td>{total}</td></tr>")


def json_data(root):
    tables = {}
    for metric in sorted(TITLE):
        for f in glob.glob(os.path.join(root, metric, "*.info")):
            material = os.path.basename(f)[:-len(".info")]
            info = read_info(f)
            row = tables.setdefault(material, {
                "m": material, "z": chinese(material),
                "c": [info["win"][0], info["draw"][0], info["lose"][0],
                      info["win"][1], info["draw"][1], info["lose"][1]],
                "l": {}})
            row["l"][metric] = [[shown_ply(info["longest"][s], metric) if info["longest"][s] else 0,
                                 info["fen"][s] if info["longest"][s] else ""] for s in (0, 1)]
    rows = [tables[m] for m in sorted(tables, key=order_key)]
    metrics = [m for m in sorted(TITLE) if any(m in r["l"] for r in rows)]
    return {"generated": datetime.date.today().isoformat(), "metrics": metrics, "rows": rows}


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n")[0])
    ap.add_argument("info_dir")
    ap.add_argument("--metric", choices=sorted(TITLE))
    ap.add_argument("--json", action="store_true", help="explorer data from INFO_DIR's dtc/ and dtm/")
    ap.add_argument("-o", "--out", help="output file (default: stdout)")
    args = ap.parse_args()
    if args.json:
        data = json_data(args.info_dir)
        if not data["rows"]:
            ap.error(f"no dtc/*.info or dtm/*.info under {args.info_dir!r}")
        text = json.dumps(data, ensure_ascii=False, separators=(",", ":"))
        if args.out:
            with open(args.out, "w", encoding="utf-8") as f:
                f.write(text)
        else:
            sys.stdout.write(text)
        print(f"{len(data['rows'])} tables", file=sys.stderr)
        return
    metric = args.metric or os.path.basename(os.path.normpath(args.info_dir)).lower()
    if metric not in TITLE:
        ap.error(f"can't tell the metric from {args.info_dir!r}: pass --metric")
    files = glob.glob(os.path.join(args.info_dir, "*.info"))
    materials = sorted((os.path.basename(f)[:-len(".info")] for f in files), key=order_key)
    body = "".join(row(m, read_info(os.path.join(args.info_dir, m + ".info")), metric) for m in materials)
    html = "﻿" + HEAD.format(title=TITLE[metric]) + body + TAIL
    if args.out:
        with open(args.out, "w", encoding="utf-8", newline="") as f:
            f.write(html)
    else:
        sys.stdout.buffer.write(html.encode("utf-8"))
    print(f"{len(materials)} tables", file=sys.stderr)


if __name__ == "__main__":
    main()
