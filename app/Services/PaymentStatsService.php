<?php

namespace App\Services;

use App\Helpers\KhmerDateFormatter;
use App\Models\TelegramPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentStatsService
{
    // ─────────────────────────────────────────────────────────────────────────
    // TODAY
    // ─────────────────────────────────────────────────────────────────────────
    public function day(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $date = KhmerDateFormatter::date(Carbon::today()); // ២៣ មិថុនា ២០២៥

            ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
                $this->aggregate(Carbon::today(), Carbon::now(), $groupId);

            Log::channel('telegram_stats')->info('Stats viewed', [
                'period'       => 'day',
                'requested_by' => $requestedBy,
                'group_id'     => $groupId,
                'date'         => $date,
                'count'        => $count,
                'usd_total'    => $usd,
                'khr_total'    => $khr,
            ]);

            return implode("\n", [
                "📅 *ថ្ងៃនេះ — {$date}*",
                "━━━━━━━━━━━━━━━━━━━━━━",
                "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
                "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
                "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
            ]);

        } catch (\Throwable $e) {
            Log::channel('telegram_stats')->error('Stats query failed', [
                'period'   => 'day',
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return "❌ Failed to load today's stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // WEEK BY NUMBER (Week 1–4 of this month)
    // ─────────────────────────────────────────────────────────────────────────
    public function weekByNumber(string $groupId, int $weekNumber, string $requestedBy = 'unknown'): string
    {
        try {
            $now        = Carbon::now();
            $monthStart = $now->copy()->startOfMonth();
    
            $weekStart = $monthStart->copy()->addDays(($weekNumber - 1) * 7);
            $weekEnd   = ($weekNumber === 4)
                ? $now->copy()->endOfMonth()
                : $weekStart->copy()->addDays(6)->endOfDay();
    
            if ($weekStart->isAfter($now)) {
                $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);
                return "⏳ *សប្ដាហ៍ទី {$weekNumKh}* មិនទាន់ដល់ពេលនៅឡើយ។";
            }
    
            $queryEnd = $weekEnd->isAfter($now) ? $now->copy() : $weekEnd->copy();
            $range    = KhmerDateFormatter::date($weekStart) . ' – ' . KhmerDateFormatter::date($queryEnd);
    
            ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
                $this->aggregate($weekStart, $queryEnd, $groupId);
    
            $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);
    
            Log::channel('telegram_stats')->info('Stats viewed', [
                'period'       => "week_{$weekNumber}",
                'requested_by' => $requestedBy,
                'group_id'     => $groupId,
                'range'        => $range,
                'count'        => $count,
                'usd_total'    => $usd,
                'khr_total'    => $khr,
            ]);
    
            return implode("\n", [
                "📌 *សប្ដាហ៍ទី {$weekNumKh} — {$range}*",
                "━━━━━━━━━━━━━━━━━━━━━━",
                "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
                "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
                "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
            ]);
    
        } catch (\Throwable $e) {
            Log::channel('telegram_stats')->error('Stats query failed', [
                'period'   => "week_{$weekNumber}",
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
    
            $weekNumKh = KhmerDateFormatter::toKhmerNum($weekNumber);
            return "❌ មិនអាចទាញស្ថិតិសប្ដាហ៍ទី {$weekNumKh} បានទេ។ សូមព្យាយាមម្តងទៀត។";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THIS MONTH
    // ─────────────────────────────────────────────────────────────────────────
    public function month(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $now = Carbon::now();
            $label = KhmerDateFormatter::monthYear(Carbon::now()); // មិថុនា ២០២៥
            ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
                $this->aggregate($now->copy()->startOfMonth(), $now, $groupId);

            Log::channel('telegram_stats')->info('Stats viewed', [
                'period'       => 'month',
                'requested_by' => $requestedBy,
                'group_id'     => $groupId,
                'month'        => $now->format('F Y'),
                'count'        => $count,
                'usd_total'    => $usd,
                'khr_total'    => $khr,
            ]);

           return implode("\n", [
                "🗓 *ខែនេះ — {$label}*",
                "━━━━━━━━━━━━━━━━━━━━━━",
                "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
                "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
                "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
            ]);

        } catch (\Throwable $e) {
            Log::channel('telegram_stats')->error('Stats query failed', [
                'period'   => 'month',
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return "❌ Failed to load monthly stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // THIS YEAR
    // ─────────────────────────────────────────────────────────────────────────
    public function year(string $groupId, string $requestedBy = 'unknown'): string
    {
        try {
            $now = Carbon::now();

            ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
                $this->aggregate($now->copy()->startOfYear(), $now, $groupId);

            Log::channel('telegram_stats')->info('Stats viewed', [
                'period'       => 'year',
                'requested_by' => $requestedBy,
                'group_id'     => $groupId,
                'year'         => $now->year,
                'count'        => $count,
                'usd_total'    => $usd,
                'khr_total'    => $khr,
            ]);

            return implode("\n", [
                "📊 *ឆ្នាំនេះ — " . $now->year . "*",
                "━━━━━━━━━━━━━━━━━━━━━━",
                "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
                "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
                "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
            ]);

        } catch (\Throwable $e) {
            Log::channel('telegram_stats')->error('Stats query failed', [
                'period'   => 'year',
                'group_id' => $groupId,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return "❌ Failed to load yearly stats. Please try again.";
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // DB aggregate — runs 2 SQL queries, loads ZERO rows into memory
    // ─────────────────────────────────────────────────────────────────────────
    private function aggregate(Carbon $start, Carbon $end, string $groupId): array
    {
        $rows = TelegramPayment::query()
            ->whereBetween('created_at', [$start, $end])
            ->where('telegram_group_id', $groupId)
            ->select(
                DB::raw('COUNT(*) as total_count'),
                DB::raw("SUM(CASE WHEN currency = 'USD' THEN amount ELSE 0 END) as usd_total"),
                DB::raw("SUM(CASE WHEN currency = 'KHR' THEN amount ELSE 0 END) as khr_total"),
            )
            ->first();

        return [
            'count' => (int)   ($rows->total_count ?? 0),
            'usd'   => (float) ($rows->usd_total   ?? 0),
            'khr'   => (float) ($rows->khr_total   ?? 0),
        ];
    }
// ============================================================
// ADD these methods to PaymentStatsService.php
// ============================================================

// ─────────────────────────────────────────────────────────────────────────
// SPECIFIC MONTH  (e.g. month 3 = March of current year)
// ─────────────────────────────────────────────────────────────────────────
public function monthByNumber(string $groupId, int $monthNumber, string $requestedBy = 'unknown'): string
{
    try {
        $now   = Carbon::now();
        $start = Carbon::create($now->year, $monthNumber, 1)->startOfMonth();
        $end   = ($monthNumber === $now->month)
            ? $now->copy()
            : $start->copy()->endOfMonth();

        $label = KhmerDateFormatter::monthYear($start);

        ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
            $this->aggregate($start, $end, $groupId);

        Log::channel('telegram_stats')->info('Stats viewed', [
            'period'       => "month_{$monthNumber}",
            'requested_by' => $requestedBy,
            'group_id'     => $groupId,
            'month'        => $label,
            'count'        => $count,
            'usd_total'    => $usd,
            'khr_total'    => $khr,
        ]);

        return implode("\n", [
            "🗓 *ខែ {$label}*",
            "━━━━━━━━━━━━━━━━━━━━━━",
            "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
            "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
            "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
        ]);

    } catch (\Throwable $e) {
        Log::channel('telegram_stats')->error('Stats query failed', [
            'period'   => "month_{$monthNumber}",
            'group_id' => $groupId,
            'error'    => $e->getMessage(),
        ]);
        return "❌ Failed to load stats for month {$monthNumber}. Please try again.";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// SPECIFIC YEAR  (e.g. year 2024)
// ─────────────────────────────────────────────────────────────────────────
public function yearByNumber(string $groupId, int $year, string $requestedBy = 'unknown'): string
{
    try {
        $now   = Carbon::now();
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end   = ($year === $now->year)
            ? $now->copy()
            : $start->copy()->endOfYear();

        ['count' => $count, 'usd' => $usd, 'khr' => $khr] =
            $this->aggregate($start, $end, $groupId);

        Log::channel('telegram_stats')->info('Stats viewed', [
            'period'       => "year_{$year}",
            'requested_by' => $requestedBy,
            'group_id'     => $groupId,
            'year'         => $year,
            'count'        => $count,
            'usd_total'    => $usd,
            'khr_total'    => $khr,
        ]);

        return implode("\n", [
            "📊 *ឆ្នាំ {$year}*",
            "━━━━━━━━━━━━━━━━━━━━━━",
            "🔢 ចំនួនប្រតិបត្តិការ : *{$count}*",
            "💵 សរុប USD          : *$ " . number_format($usd, 2) . "*",
            "💴 សរុប KHR          : *៛ " . number_format($khr, 0) . "*",
        ]);

    } catch (\Throwable $e) {
        Log::channel('telegram_stats')->error('Stats query failed', [
            'period'   => "year_{$year}",
            'group_id' => $groupId,
            'error'    => $e->getMessage(),
        ]);
        return "❌ Failed to load stats for year {$year}. Please try again.";
    }
}

// ─────────────────────────────────────────────────────────────────────────
// HELPER — which months (1–12) in current year have at least 1 payment
// ─────────────────────────────────────────────────────────────────────────
public function monthsWithData(string $groupId): array
{
    return TelegramPayment::query()
        ->where('telegram_group_id', $groupId)
        ->whereYear('created_at', Carbon::now()->year)
        ->selectRaw('MONTH(created_at) as m')
        ->groupBy('m')
        ->pluck('m')
        ->map(fn($v) => (int) $v)
        ->toArray();
}

// ─────────────────────────────────────────────────────────────────────────
// HELPER — which years have at least 1 payment (newest first)
// ─────────────────────────────────────────────────────────────────────────
public function yearsWithData(string $groupId): array
{
    return TelegramPayment::query()
        ->where('telegram_group_id', $groupId)
        ->selectRaw('YEAR(created_at) as y')
        ->groupBy('y')
        ->orderByDesc('y')
        ->pluck('y')
        ->map(fn($v) => (int) $v)
        ->toArray();
}

}