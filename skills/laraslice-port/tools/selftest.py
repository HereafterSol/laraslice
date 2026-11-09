#!/usr/bin/env python3
"""Self-test for the laraslice-port tools. Standard library only.

    python3 selftest.py

Builds a sample port matrix with hand-computed expectations and two synthetic
PNGs, runs both tools' scoring code AND their command lines, and asserts the
results. Exits non-zero on the first failure. Safe to run anywhere: all
fixtures are written to a temp directory and removed.
"""

import json
import os
import subprocess
import sys
import tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)

import layout_diff  # noqa: E402
import port_matrix  # noqa: E402

SAMPLE = """feature,area,priority,source,port,evidence,notes
Directory lists employees,HR,must,yes,yes,gate-t.json A1a,
Employee hub opens,HR,must,yes,partial,ledger HR row,rail widgets missing
Deals pipeline,CRM,should,yes,no,ledger CRM row,
Sticky notes,Platform,could,yes,yes,,
Visa details tab,HR,must,yes,skip,,owner scope decision pending
Studio copilot,Platform,should,no,yes,,port-side addition
"""


def check(name, cond, detail=""):
    if not cond:
        print("FAIL %s %s" % (name, detail))
        sys.exit(1)
    print("ok   %s" % name)


def block_image(block_x):
    """120x90 white page with a black 'card' and a grey 'table' band."""
    w, h = 120, 90
    rows = []
    for y in range(h):
        row = []
        for x in range(w):
            px = (255, 255, 255)
            if block_x <= x < block_x + 30 and 10 <= y < 40:
                px = (20, 20, 20)
            if 50 <= y < 80 and 5 <= x < 115:
                px = (200, 200, 200) if (y // 6) % 2 else (235, 235, 235)
            row.append(px)
        rows.append(row)
    return w, h, rows


def main():
    tmp = tempfile.mkdtemp(prefix="laraslice-port-tools-")

    # ---- port_matrix: hand-computed expectations -------------------------
    # counted: HR must yes (3/3), HR must partial (1.5/3),
    #          CRM should no (0/2), Platform could yes (1/1)
    # total weight 9, got 5.5 -> 61.1 ; musts done 1 of 2 -> NOT SHIPPABLE
    csv_path = os.path.join(tmp, "matrix.csv")
    with open(csv_path, "w", encoding="utf-8") as fh:
        fh.write(SAMPLE)
    result = port_matrix.combine(port_matrix.score(port_matrix.load(csv_path)), [])
    check("matrix score", result["feature_score"] == 61.1, str(result["feature_score"]))
    check("matrix counted", result["counted"] == 4)
    check("matrix musts", (result["must_have_done"], result["must_have_total"]) == (1, 2))
    check("matrix verdict", result["verdict"].startswith("NOT SHIPPABLE"))
    areas = [a["area"] for a in result["by_area"]]
    check("matrix weakest first", areas[0] == "CRM", str(areas))
    check("matrix skip listed", len(result["skipped"]) == 1
          and "owner scope decision" in result["skipped"][0]["notes"])
    check("matrix extra listed", len(result["extras"]) == 1)
    line = port_matrix.status_line(result)
    check("matrix status line", "61.1/100" in line and "must-haves 1/2" in line, line)

    out = subprocess.run([sys.executable, os.path.join(HERE, "port_matrix.py"),
                          csv_path, "--fail-under", "80"],
                         capture_output=True, text=True)
    check("matrix CLI fail-under exits 1", out.returncode == 1, out.stdout + out.stderr)
    out = subprocess.run([sys.executable, os.path.join(HERE, "port_matrix.py"),
                          csv_path, "--status-line"], capture_output=True, text=True)
    check("matrix CLI status line", out.returncode == 0 and "Parity score:" in out.stdout)

    # ---- layout_diff: synthetic PNGs --------------------------------------
    a_path = os.path.join(tmp, "a.png")
    b_path = os.path.join(tmp, "b.png")
    layout_diff.write_png(a_path, *block_image(10))
    layout_diff.write_png(b_path, *block_image(70))
    img_a = layout_diff.read_png(a_path)
    img_b = layout_diff.read_png(b_path)

    same, _ = layout_diff.compare(img_a, img_a)
    check("layout identical = 100", same["score"] == 100.0
          and same["verdict"] == "matches", str(same["score"]))
    moved, _ = layout_diff.compare(img_a, img_b)
    check("layout moved block scores lower", 0.0 <= moved["score"] < 100.0,
          str(moved["score"]))
    check("layout moved block has regions", len(moved["regions"]) >= 1)
    pix, _ = layout_diff.compare(img_a, img_a, mode="pixel")
    check("pixel identical = 100", pix["score"] == 100.0, str(pix["score"]))

    out = subprocess.run([sys.executable, os.path.join(HERE, "layout_diff.py"),
                          a_path, b_path, "--json"], capture_output=True, text=True)
    data = json.loads(out.stdout)
    check("layout CLI json", out.returncode == 0 and data["score"] == moved["score"]
          and data["files"]["port"] == b_path)

    print("\nAll tool self-tests passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
