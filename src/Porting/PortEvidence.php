<?php

declare(strict_types=1);

namespace LaraSlice\Porting;

/**
 * Port evidence: machine-readable verification results a
 * port records about itself, read by slice:conformance.
 *
 * The anatomy specs are ONE evidence source (structure).
 * Scripted journey gates (Gate T), interaction audits and
 * other runners are OTHERS. REPLICA-STATUS.md stays the
 * human roll-up — it cites evidence, it is not itself
 * evidence (prose cannot be gated). A runner records one
 * JSON file per suite in porting/evidence/:
 *
 * {
 *   "suite": "Gate T — scripted task journeys",
 *   "recorded_at": "2026-11-02T17:59:00+05:00",
 *   "app": "http://127.0.0.1:8002",
 *   "checks": [
 *     {"id": "A1", "label": "admin login", "status": "pass"},
 *     {"id": "A4b", "label": "probe created", "status": "fail", "detail": "..."}
 *   ]
 * }
 *
 * A malformed file is a load ERROR and its checks are
 * excluded — a broken evidence file must never silently
 * inflate or deflate the measured percent.
 */
class PortEvidence
{
    public static function directory(): string
    {
        return base_path('porting/evidence');
    }

    /**
     * @return array{
     *     suites: list<array{suite: string, file: string, recorded_at: ?string, app: ?string, checks: int, passed: int, failed: list<array{id: string, label: string, detail: string}>}>,
     *     checks: int, passed: int, failed_count: int, percent: ?float,
     *     errors: list<string>
     * }
     */
    public static function load(?string $dir = null): array
    {
        $dir ??= self::directory();
        $summary = ['suites' => [], 'checks' => 0, 'passed' => 0, 'failed_count' => 0, 'percent' => null, 'errors' => []];
        if (! is_dir($dir)) {
            return $summary;
        }

        $files = glob($dir.'/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $data = json_decode((string) file_get_contents($file), true);
            if (! is_array($data)) {
                $summary['errors'][] = "{$name}: not a JSON object — evidence excluded.";

                continue;
            }
            $suite = trim((string) ($data['suite'] ?? ''));
            $checks = $data['checks'] ?? null;
            if ($suite === '' || ! is_array($checks) || $checks === []) {
                $summary['errors'][] = "{$name}: missing 'suite' or a non-empty 'checks' list — evidence excluded.";

                continue;
            }
            $seen = [];
            $failed = [];
            $passed = 0;
            $valid = true;
            foreach ($checks as $check) {
                $id = is_array($check) ? trim((string) ($check['id'] ?? '')) : '';
                $status = is_array($check) ? (string) ($check['status'] ?? '') : '';
                if ($id === '' || ! in_array($status, ['pass', 'fail'], true)) {
                    $summary['errors'][] = "{$name}: a check in suite '{$suite}' lacks an id or a pass/fail status — evidence excluded.";
                    $valid = false;
                    break;
                }
                if (isset($seen[$id])) {
                    $summary['errors'][] = "{$name}: duplicate check id '{$id}' in suite '{$suite}' — evidence excluded.";
                    $valid = false;
                    break;
                }
                $seen[$id] = true;
                if ($status === 'pass') {
                    $passed++;
                } else {
                    $failed[] = ['id' => $id, 'label' => (string) ($check['label'] ?? $id), 'detail' => (string) ($check['detail'] ?? '')];
                }
            }
            if (! $valid) {
                continue;
            }
            $summary['suites'][] = [
                'suite' => $suite,
                'file' => $name,
                'recorded_at' => isset($data['recorded_at']) ? (string) $data['recorded_at'] : null,
                'app' => isset($data['app']) ? (string) $data['app'] : null,
                'checks' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ];
            $summary['checks'] += count($checks);
            $summary['passed'] += $passed;
            $summary['failed_count'] += count($failed);
        }

        if ($summary['checks'] > 0) {
            $summary['percent'] = round(100 * $summary['passed'] / $summary['checks'], 1);
        }

        return $summary;
    }
}
