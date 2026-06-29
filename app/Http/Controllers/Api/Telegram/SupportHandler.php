<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class SupportHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function showContact(string $chatId): JsonResponse
    {
        $username = ltrim((string) config('services.telegram.support_username'), '@');
        $phone    = config('services.telegram.support_phone', 'N/A');
        $hours    = config('services.telegram.support_hours', 'ចន្ទ-សុក្រ ៨:០០ - ១៧:០០');
    
        if (! $username) {
            $this->telegram->sendMessage($chatId, '⚠️ Support username is not configured.');
    
            return response()->json(['ok' => false]);
        }
    
        $safeUsername = htmlspecialchars($username, ENT_QUOTES, 'UTF-8');
        $safePhone    = htmlspecialchars((string) $phone, ENT_QUOTES, 'UTF-8');
        $safeHours    = htmlspecialchars((string) $hours, ENT_QUOTES, 'UTF-8');
    
        $text = implode("\n", [
            "💬 <b>ទំនាក់ទំនងផ្នែកជំនួយ</b>",
            "─────────────────────",
            "👤 Telegram: @{$safeUsername}",
            "📞 លេខទូរស័ព្ទ: <code>{$safePhone}</code>",
            "🕐 ម៉ោងធ្វើការ: {$safeHours}",
            "─────────────────────",
            "សូមផ្ញើសាររបស់អ្នកទៅកាន់ admin ខាងលើ។",
        ]);
    
        $this->telegram->sendMessage($chatId, $text, [
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '💬 ទំនាក់ទំនង Admin',
                            'url'  => "https://t.me/{$username}",
                        ],
                    ],
                ],
            ],
        ]);
    
        return response()->json(['ok' => true]);
    }
}