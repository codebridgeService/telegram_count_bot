<?php

namespace App\Jobs;

use App\Models\TelegramGroup;
use App\Services\PaymentStatsService;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendStatsSummaryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public string $period = 'day'
    ) {}

    public function handle(
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): void {
        TelegramGroup::query()
            ->whereNotNull('group_id')
            ->where('status', 'connected')
            ->chunkById(100, function ($groups) use ($stats, $bot) {
                foreach ($groups as $group) {
                    $this->sendToGroup($group, $stats, $bot);
                    usleep(50_000); // ~20 sends/sec, under Telegram's ceiling
                }
            });
    }

    private function sendToGroup(
        TelegramGroup $group,
        PaymentStatsService $stats,
        TelegramBotService $bot
    ): void {
        try {
            $summary = match ($this->period) {
                'day'   => $stats->day($group->telegramGroupsID, 'scheduler'),
                'week'  => $stats->weekByNumber($group->telegramGroupsID, $this->currentWeekOfMonth(), 'scheduler'),
                'month' => $stats->month($group->telegramGroupsID, 'scheduler'),
                'year'  => $stats->year($group->telegramGroupsID, 'scheduler'),
                default => $stats->day($group->telegramGroupsID, 'scheduler'),
            };

            $bot->sendMessage($group->group_id, $summary);
        } catch (\Throwable $e) {
            if (str_contains($e->getMessage(), '403')) {
                $group->update(['status' => 'disconnected']);
            }

            Log::warning('Stats summary failed for group', [
                'group'  => $group->telegramGroupsID,
                'period' => $this->period,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    private function currentWeekOfMonth(): int
    {
        $now  = Carbon::now('Asia/Phnom_Penh');
        $week = (int) ceil($now->day / 7);

        return min($week, 4);
    }
}