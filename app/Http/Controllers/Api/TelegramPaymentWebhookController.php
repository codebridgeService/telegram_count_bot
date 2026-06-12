<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class TelegramPaymentWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        $message = $request->input('message');

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $chatTitle = $chat['title'] ?? null;
        $chatType = $chat['type'] ?? 'private';
        $text = trim($message['text'] ?? '');

        if (!$text) {
            return response()->json(['ok' => true]);
        }

        if (str_starts_with($text, '/connect')) {
            return $this->connectGroup($chatId, $chatTitle, $chatType, $from, $text);
        }

        $telegramGroup = TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->first();

        if (!$telegramGroup) {
            return response()->json([
                'ok' => true,
                'message' => 'Group not connected',
            ]);
        }

        return $this->saveAbaPayment($telegramGroup, $text);
    }

    public function setWebhook()
    {
        $url = rtrim(config('app.url'), '/') . '/api/telegram/webhook';

        return $this->telegramApi('setWebhook', [
            'url' => $url,
            'allowed_updates' => [
                'message',
                'my_chat_member',
            ],
        ]);
    }

    public function webhookInfo()
    {
        return $this->telegramApi('getWebhookInfo');
    }

    public function sendTestPayment()
    {
        $group = TelegramGroup::where('status', 'connected')->first();

        if (!$group) {
            return response()->json([
                'success' => false,
                'message' => 'No connected Telegram group',
            ], 404);
        }

        $text = '៛100 paid by SENG HORNG KHEANG (*621) on Jun 12, 02:20 PM via ABA KHQR (Bakong) at KHEANG SENG HORNG. Trx. ID: 178124880679525, APV: 573031.';

        return $this->sendMessage($group->group_id, $text);
    }

    private function saveAbaPayment($telegramGroup, string $text)
    {
        preg_match_all(
            '/៛([\d,]+)\s+paid by\s+(.*?)\s+\(\*(\d+)\)\s+on\s+(.*?),\s+via\s+(.*?)\s+at\s+(.*?)\.\s+Trx\. ID:\s*(\d+),\s*APV:\s*(\d+)/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        if (empty($matches)) {
            return response()->json([
                'ok' => true,
                'message' => 'No ABA payment found',
            ]);
        }

        $saved = 0;
        $duplicates = 0;

        foreach ($matches as $payment) {
            $trxId = trim($payment[7]);

            if (TelegramPayment::where('trx_id', $trxId)->exists()) {
                $duplicates++;
                continue;
            }

            TelegramPayment::create([
                'user_id' => $telegramGroup->user_id,
                'subscription_id' => $telegramGroup->subscription_id,
                'telegram_group_id' => $telegramGroup->telegramGroupsID,

                'currency' => 'KHR',
                'amount' => (float) str_replace(',', '', $payment[1]),

                'payer_name' => trim($payment[2]),
                'payer_account' => '*' . trim($payment[3]),
                'merchant_name' => trim($payment[6]),

                'payment_method' => trim($payment[5]),
                'bank_code' => 'ABA',

                'trx_id' => $trxId,
                'apv' => trim($payment[8]),

                'payment_date' => $this->parseAbaDate(trim($payment[4])),
                'report_date' => now()->toDateString(),
                'report_month' => now()->month,
                'report_year' => now()->year,

                'raw_message' => $text,
                'parsed_successfully' => true,
                'is_duplicate' => false,
                'status' => 'success',
            ]);

            $saved++;
        }

        return response()->json([
            'ok' => true,
            'saved' => $saved,
            'duplicates' => $duplicates,
        ]);
    }

    private function connectGroup($chatId, $chatTitle, $chatType, $from, $text)
    {
        $parts = explode(' ', $text);
        $code = $parts[1] ?? null;

        if (!$code) {
            return response()->json([
                'ok' => true,
                'message' => 'Please use /connect YOUR_CODE',
            ]);
        }

        $telegramGroup = TelegramGroup::where('connect_code', $code)->first();

        if (!$telegramGroup) {
            return response()->json([
                'ok' => true,
                'message' => 'Invalid connect code',
            ]);
        }

        $telegramGroup->update([
            'group_id' => $chatId,
            'group_name' => $chatTitle,
            'group_type' => $chatType,
            'connected_by_telegram_id' => $from['id'] ?? null,
            'connected_by_username' => $from['username'] ?? null,
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        $this->sendMessage($chatId, '✅ Telegram group connected successfully.');

        return response()->json([
            'ok' => true,
            'message' => 'Telegram group connected successfully',
        ]);
    }

    private function sendMessage($chatId, string $text)
    {
        return $this->telegramApi('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }

    private function telegramApi(string $method, array $data = [])
    {
        $token = config('services.telegram.bot_token');

        if (!$token) {
            return response()->json([
                'success' => false,
                'message' => 'Missing TELEGRAM_BOT_TOKEN',
            ], 500);
        }

        $url = "https://api.telegram.org/bot{$token}/{$method}";

        if (empty($data)) {
            return Http::get($url)->json();
        }

        return Http::post($url, $data)->json();
    }

    private function parseAbaDate(string $dateText)
    {
        try {
            return Carbon::parse($dateText . ' ' . now()->year);
        } catch (\Throwable $e) {
            return now();
        }
    }
}