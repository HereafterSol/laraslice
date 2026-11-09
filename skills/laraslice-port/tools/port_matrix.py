#!/usr/bin/env python3
"""Scored port matrix for laraslice-port. Standard library only.

Scores a port's `porting/port-matrix.csv` — the single checklist every stage
of a port updates — and prints the legible parity number that sits BESIDE the
five-gate verdict in REPLICA-STATUS.md (the gates stay the verdict layer; this
score is the number a founder can read).

    python3 port_matrix.py porting/port-matrix.csv
    python3 port_matrix.py porting/port-matrix.csv --markdown > parity.md
    python3 port_matrix.py porting/port-matrix.csv --visual diffs/*.json
    python3 port_matrix.py porting/port-matrix.csv --status-line
    python3 port_matrix.py porting/port-matrix.csv --fail-under 80

The CSV has these columns (extra columns are ignored):

    feature    what the source does, in plain words ("create an invoice")
    area       the module / surface it belongs to ("Finance")
    priority   must | should | could   (P0 | P1 | P2 also work)
    source     yes | no     does the source system have it (no = port-side
                            addition; excluded from the score, listed apart)
    port       yes | partial | no | skip
    evidence   where the port value was measured (file, gate run, ledger row)
    notes      anything. For skip, the reason is required ("owner decision
               pending", "system table, out of declared scope")

Weights: must 3, should 2, could 1. yes counts 1, partial 0.5, no 0.
skip rows and port-side additions (source = no) are left out of the score and
listed separately, so the number only ever measures parity with the source.

Verdict line: any must-have not fully done (partial counts as not done) means
NOT SHIPPABLE; all must-haves done and score >= 80 means SHIPPABLE; anything
else is NOT SHIPPABLE. This mirrors the gate discipline: the score never
upgrades a gate verdict, it only makes the distance legible.

With --visual, layout scores from layout_diff.py --json are averaged and the
overall score is 80% features + 20% layout. Features are what users pay for.

Adapted from replica-diff/parity.py (MIT), Jakeschincariol/replica-skill —
scoring rule and report shape; columns and verdict language are LaraSlice's.
"""

import argparse
import csv
import json
import sys

WEIGHT = {"must": 3, "should": 2, "could": 1, "p0": 3, "p1": 2, "p2": 1}
CREDIT = {"yes": 1.0, "done": 1.0, "partial": 0.5, "no": 0.0, "": 0.0, "todo": 0.0}
PRIORITY_NAME = {3: "must", 2: "should", 1: "could"}


class MatrixError(Exception):
    pass


def load(path):
    with open(path, newline="", encoding="utf-8-sig") as fh:
        reader = csv.DictReader(fh)
        if reader.fieldnames is None:
            raise MatrixError("%s is empty" % path)
        fields = [f.strip().lower() for f in reader.fieldnames]
        for need in ("feature", "priority", "port"):
            if need not in fields:
                raise MatrixError("%s has no '%s' column. Columns needed: "
                                  "feature, area, priority, source, port, "
                                  "evidence, notes" % (path, need))
        rows = []
        for i, raw in enumerate(reader, start=2):
            if None in raw or None in raw.values():
                raise MatrixError("%s line %d: field count mismatch — "
                                  "quote fields that contain commas"
                                  % (path, i))
            row = {(k or "").strip().lower(): (v or "").strip() for k, v in raw.items()}
            if not row.get("feature"):
                continue
            row["line"] = i
            rows.append(row)
    return rows


def score(rows):
    counted = []
    skipped = []
    extras = []
    problems = []
    for row in rows:
        source = row.get("source", "yes").lower() or "yes"
        port = row.get("port", "").lower()
        prio = row.get("priority", "").lower()
        if source in ("no", "n", "false"):
            extras.append(row)
            continue
        if port == "skip":
            skipped.append(row)
            if not row.get("notes"):
                problems.append("line %d: skip row '%s' has no reason in notes — "
                                "a skip without a reason is a silent gap"
                                % (row["line"], row["feature"]))
            continue
        if prio not in WEIGHT:
            problems.append("line %d: priority '%s' is not must/should/could, counted as could"
                            % (row["line"], row.get("priority", "")))
        if port not in CREDIT:
            problems.append("line %d: port '%s' is not yes/partial/no/skip, counted as no"
                            % (row["line"], row.get("port", "")))
        w = WEIGHT.get(prio, 1)
        c = CREDIT.get(port, 0.0)
        counted.append(dict(row, weight=w, credit=c))

    total = sum(r["weight"] for r in counted)
    got = sum(r["weight"] * r["credit"] for r in counted)
    feature_score = 100.0 * got / total if total else 0.0

    areas = {}
    for r in counted:
        a = r.get("area") or "(no area)"
        t = areas.setdefault(a, [0.0, 0.0, 0])
        t[0] += r["weight"] * r["credit"]
        t[1] += r["weight"]
        t[2] += 1
    by_area = sorted(
        ({"area": a, "score": round(100.0 * g / t, 1) if t else 0.0, "features": n}
         for a, (g, t, n) in areas.items()),
        key=lambda d: d["score"])

    missing = [r for r in counted if r["credit"] < 1.0]
    missing.sort(key=lambda r: (-r["weight"], r["credit"], r.get("area", ""), r["feature"]))

    def brief(r):
        return {"feature": r["feature"], "area": r.get("area", ""),
                "priority": PRIORITY_NAME.get(r.get("weight", WEIGHT.get(
                    r.get("priority", "").lower(), 1)), "could"),
                "port": r.get("port", "") or "no",
                "evidence": r.get("evidence", ""), "notes": r.get("notes", "")}

    musts = [r for r in counted if r["weight"] == 3]
    must_done = sum(1 for r in musts if r["credit"] == 1.0)
    if must_done < len(musts):
        verdict = "NOT SHIPPABLE — %d must-have(s) not fully done" % (len(musts) - must_done)
    elif feature_score >= 80:
        verdict = "SHIPPABLE — all must-haves done, score >= 80"
    else:
        verdict = "NOT SHIPPABLE — score below 80"
    return {
        "feature_score": round(feature_score, 1),
        "counted": len(counted),
        "must_have_done": must_done,
        "must_have_total": len(musts),
        "verdict": verdict,
        "by_area": by_area,
        "missing": [brief(r) for r in missing],
        "skipped": [brief(r) for r in skipped],
        "extras": [brief(r) for r in extras],
        "problems": problems,
    }


def visual_scores(paths):
    scores = []
    for p in paths:
        with open(p, encoding="utf-8") as fh:
            data = json.load(fh)
        if "score" not in data:
            raise MatrixError("%s is not layout_diff.py --json output" % p)
        name = data.get("files", {}).get("port", p)
        scores.append({"file": name, "score": float(data["score"]),
                       "mode": data.get("mode", "layout")})
    return scores


def combine(result, visuals):
    if visuals:
        layout = sum(v["score"] for v in visuals) / len(visuals)
        result["visual"] = visuals
        result["layout_score"] = round(layout, 1)
        result["overall"] = round(0.8 * result["feature_score"] + 0.2 * layout, 1)
    else:
        result["overall"] = result["feature_score"]
    return result


def status_line(result):
    weakest = ", ".join("%s %.1f" % (a["area"], a["score"])
                        for a in result["by_area"][:3])
    return ("Parity score: %.1f/100 · must-haves %d/%d · weakest: %s · %s"
            % (result["overall"], result["must_have_done"],
               result["must_have_total"], weakest, result["verdict"]))


def render(result, markdown=False):
    out = []
    h = "## " if markdown else ""
    out.append("%sPort parity: %.1f / 100" % (h, result["overall"]))
    out.append("")
    out.append("features %.1f  (%d counted, must-haves %d of %d done)" % (
        result["feature_score"], result["counted"], result["must_have_done"],
        result["must_have_total"]))
    if "layout_score" in result:
        out.append("layout   %.1f  (%d screens compared)" % (
            result["layout_score"], len(result["visual"])))
    out.append("verdict: %s" % result["verdict"])
    out.append("")
    out.append("%sBy area, weakest first" % h)
    for a in result["by_area"]:
        out.append("- %-28s %5.1f  (%d features)" % (a["area"], a["score"], a["features"]))
    out.append("")
    out.append("%sMissing, in build order" % h)
    if not result["missing"]:
        out.append("- nothing. Every counted feature is done.")
    for m in result["missing"]:
        note = ("  (%s)" % m["notes"]) if m["notes"] else ""
        ev = ("  [%s]" % m["evidence"]) if m["evidence"] else ""
        out.append("- [%s] %s: %s, %s%s%s" % (m["priority"], m["area"] or "-",
                                              m["feature"], m["port"], note, ev))
    if result["skipped"]:
        out.append("")
        out.append("%sSkipped on purpose (not scored)" % h)
        for m in result["skipped"]:
            out.append("- %s: %s" % (m["feature"], m["notes"] or "no reason given, add one"))
    if result["extras"]:
        out.append("")
        out.append("%sPort-side additions, not in the source (not scored)" % h)
        for m in result["extras"]:
            out.append("- %s" % m["feature"])
    if result.get("visual"):
        out.append("")
        out.append("%sScreens" % h)
        for v in sorted(result["visual"], key=lambda d: d["score"]):
            out.append("- %-40s %5.1f" % (v["file"], v["score"]))
    if result["problems"]:
        out.append("")
        out.append("%sFix in the matrix" % h)
        for p in result["problems"]:
            out.append("- %s" % p)
    return "\n".join(out)


def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("matrix", help="the port matrix CSV (porting/port-matrix.csv)")
    ap.add_argument("--visual", nargs="*", default=[],
                    help="layout_diff.py --json outputs to fold in")
    ap.add_argument("--json", action="store_true")
    ap.add_argument("--markdown", action="store_true")
    ap.add_argument("--status-line", action="store_true",
                    help="print the one-line score for REPLICA-STATUS.md")
    ap.add_argument("--fail-under", type=float)
    args = ap.parse_args(argv)
    try:
        result = combine(score(load(args.matrix)), visual_scores(args.visual))
    except (MatrixError, OSError, ValueError) as exc:
        print("port_matrix: %s" % exc, file=sys.stderr)
        return 2
    if args.json:
        print(json.dumps(result, indent=2))
    elif args.status_line:
        print(status_line(result))
    else:
        print(render(result, markdown=args.markdown))
    if args.fail_under is not None and result["overall"] < args.fail_under:
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
