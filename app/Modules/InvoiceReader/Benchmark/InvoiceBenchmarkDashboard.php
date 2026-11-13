<?php

namespace App\Modules\InvoiceReader\Benchmark;

use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkDocument;
use App\Modules\InvoiceReader\Benchmark\Models\InvoiceBenchmarkRun;
use App\Modules\InvoiceReader\InvoiceData;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class InvoiceBenchmarkDashboard
{
    public function summarize(Builder $documents): array
    {
        $rows = (clone $documents)->with('runs')->orderByDesc('created_at')->orderByDesc('id')->limit(100)->get();
        $runs = $rows->flatMap(fn (InvoiceBenchmarkDocument $document) => $document->runs)
            ->whereIn('status', ['succeeded', 'failed']);
        $requests = DB::table('invoice_benchmark_requests')->whereIn('run_id', $runs->pluck('id'))
            ->get()->groupBy('run_id');

        $groups = [];
        $summary = $this->emptyGroup();
        foreach ($rows as $document) {
            foreach ($document->runs as $run) {
                if (! in_array($run->status, ['succeeded', 'failed'], true)) {
                    continue;
                }
                $key = $run->mode.($run->model ? ' / '.$run->model : '');
                $groups[$key] ??= $this->emptyGroup();
                $attempts = $requests->get($run->id, collect());
                $estimated = BigDecimal::zero();
                $complete = $attempts->isNotEmpty();
                $tokens = 0;
                $usageReported = 0;
                foreach ($attempts as $request) {
                    $usage = json_decode($request->usage ?? '{}', true, flags: JSON_THROW_ON_ERROR);
                    if (isset($usage['total_tokens'])) {
                        $tokens += $usage['total_tokens'];
                        $usageReported++;
                    }
                    if ($request->estimated_usd !== null) {
                        $estimated = $estimated->plus((string) $request->estimated_usd);
                    } elseif ($request->http_status !== 429) {
                        $complete = false;
                    }
                }
                $complete = $complete && $usageReported > 0;
                $groundTruth = $document->verified_at === null ? [] : array_filter($document->ground_truth ?? [], InvoiceData::available(...));
                $accuracy = $this->compare($run, $groundTruth);
                $rollups = [&$groups[$key]];
                if ($run->mode !== 'demo') {
                    $rollups[] = &$summary;
                }
                foreach ($rollups as &$group) {
                    $group['runs']++;
                    $group['success'] += (int) ($run->status === 'succeeded');
                    $group['requests'] += $attempts->count();
                    $group['retries'] += max(0, $attempts->count() - 1);
                    if ($run->duration_ms !== null) {
                        $group['latencies'][] = $run->duration_ms;
                    }
                    if ($usageReported > 0) {
                        $group['tokens'] += $tokens;
                        $group['token_samples']++;
                    }
                    if ($attempts->contains(fn ($attempt): bool => $attempt->estimated_usd !== null)) {
                        $group['total_cost'] = $group['total_cost']->plus($estimated);
                    }
                    if ($complete) {
                        $group['cost_samples']++;
                        $group['sample_cost'] = $group['sample_cost']->plus($estimated);
                    }
                    if ($accuracy !== []) {
                        $correct = ! in_array('incorrect', $accuracy, true) && ! in_array('missing', $accuracy, true);
                        $group['verified']++;
                        $group['correct'] += (int) $correct;
                        if ($complete || $run->mode === 'qr') {
                            $group['verified_cost'] = $group['verified_cost']->plus($estimated);
                            $group['correct_with_cost'] += (int) $correct;
                            $group['verified_cost_samples']++;
                        }
                        foreach ($accuracy as $field => $result) {
                            $group['fields'][$field] ??= ['correct' => 0, 'incorrect' => 0, 'missing' => 0];
                            $group['fields'][$field][$result]++;
                        }
                    }
                }
                unset($group);
            }
        }

        return [
            'documents' => $runs->where('mode', '!=', 'demo')->pluck('document_id')->unique()->count(),
            'demos' => $runs->where('mode', 'demo')->count(),
            'overall' => $this->metrics($summary, projectVolume: false),
            'groups' => array_map($this->metrics(...), $groups),
        ];
    }

    private function compare(InvoiceBenchmarkRun $run, array $expected): array
    {
        $comparison = [];
        foreach ($expected as $field => $value) {
            if (in_array($field, InvoiceData::ADDRESS_FIELDS['supplier'], true)
                || in_array($field, InvoiceData::ADDRESS_FIELDS['customer'], true)) {
                continue;
            }

            $actual = $run->result[$field] ?? null;
            $comparison[$field] = match (true) {
                ! InvoiceData::available($actual) => 'missing',
                InvoiceData::compare($actual, $value, $field) => 'correct',
                default => 'incorrect',
            };
        }

        return $comparison;
    }

    private function emptyGroup(): array
    {
        return [
            'runs' => 0, 'success' => 0, 'requests' => 0, 'retries' => 0, 'latencies' => [],
            'tokens' => 0, 'token_samples' => 0, 'cost_samples' => 0,
            'total_cost' => BigDecimal::zero(), 'sample_cost' => BigDecimal::zero(),
            'verified' => 0, 'correct' => 0, 'fields' => [],
            'verified_cost' => BigDecimal::zero(), 'correct_with_cost' => 0, 'verified_cost_samples' => 0,
        ];
    }

    private function metrics(array $group, bool $projectVolume = true): array
    {
        $latencies = $group['latencies'];
        sort($latencies);
        $count = count($latencies);
        $middle = intdiv($count, 2);
        $checks = array_sum(array_map(array_sum(...), $group['fields']));
        $missing = array_sum(array_column($group['fields'], 'missing'));
        $averageCost = $group['cost_samples'] === 0 ? null : $group['sample_cost']->dividedBy($group['cost_samples'], 12, RoundingMode::HalfUp);

        return [
            'runs' => $group['runs'], 'success' => $group['success'], 'failed' => $group['runs'] - $group['success'],
            'requests' => $group['requests'], 'retries' => $group['retries'],
            'avg_ms' => $count === 0 ? null : round(array_sum($latencies) / $count),
            'median_ms' => $count === 0 ? null : ($count % 2 === 0 ? ($latencies[$middle - 1] + $latencies[$middle]) / 2 : $latencies[$middle]),
            'p95_ms' => $count === 0 ? null : $latencies[(int) ceil($count * .95) - 1],
            'avg_tokens' => $group['token_samples'] === 0 ? null : round($group['tokens'] / $group['token_samples']),
            'token_samples' => $group['token_samples'],
            'total_cost' => $group['requests'] === 0 || ($group['cost_samples'] === 0 && $group['total_cost']->isZero()) ? null : (string) $group['total_cost'],
            'cost_samples' => $group['cost_samples'], 'avg_cost' => $averageCost === null ? null : (string) $averageCost,
            'projections' => $averageCost === null || ! $projectVolume ? [] : array_combine([100, 1000, 10000, 100000], array_map(
                fn (int $volume): string => (string) $averageCost->multipliedBy($volume)->toScale(4, RoundingMode::HalfUp),
                [100, 1000, 10000, 100000],
            )),
            'verified' => $group['verified'], 'correct' => $group['correct'],
            'missing_rate' => $checks === 0 ? null : round($missing * 100 / $checks, 1),
            'correction_rate' => $group['verified'] === 0 ? null : round(($group['verified'] - $group['correct']) * 100 / $group['verified'], 1),
            'fields' => $group['fields'],
            'cost_per_correct' => $group['correct_with_cost'] === 0 ? null : (string) $group['verified_cost']->dividedBy($group['correct_with_cost'], 12, RoundingMode::HalfUp),
            'verified_cost_samples' => $group['verified_cost_samples'],
        ];
    }
}
