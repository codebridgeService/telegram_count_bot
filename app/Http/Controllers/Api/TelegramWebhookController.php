<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use App\Models\SubscriptionUsageLog;
use App\Models\User;
use App\Services\AbaPaymentService;
use App\Services\PaymentStatsService;
use App\Services\TelegramBotService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Constants\BotCallback;

class TelegramWebhookController extends Controller
{
    public function __construct(
        protected TelegramBotService  $telegram,
        protected AbaPaymentService   $aba,
        protected PaymentStatsService $stats,
    ) {}

    // -------------------------------------------------------------------------
    // Webhook entry point
    // -------------------------------------------------------------------------

    public function webhook(Request $request): JsonResponse
    {
        if (app()->isLocal()) {
            Log::info('TELEGRAM RAW', $request->all());
        }

        // ── Inline button callback ────────────────────────────────────────────
        if ($request->has('callback_query')) {
            return $this->handleCallback($request->input('callback_query'));
        }

        $message = $request->input('message');

        if (! $message) {
            return response()->json(['ok' => true]);
        }

        $chat   = $message['chat'] ?? [];
        $from   = $message['from'] ?? [];
        $text   = trim($message['text'] ?? '');
        $chatId = (string) ($chat['id'] ?? '');

        if (! $text || ! $chatId) {
            return response()->json(['ok' => true]);
        }

        // ── ABA payment ───────────────────────────────────────────────────────
        if (
            str_contains($text, 'paid by') &&
            str_contains($text, 'Trx. ID') &&
            str_contains($text, 'APV:')
        ) {
            $result = $this->aba->process($text, $chatId);

            return response()->json([
                'ok'           => true,
                'parsed'       => $result['parsed'],
                'is_duplicate' => $result['is_duplicate'],
                'currency'     => $result['currency'],
                'found_group'  => (bool) $result['group'],
                'payment_id'   => $result['payment']?->telegram_paymentID,
            ]);
        }

        // ── Ignore other bot messages ─────────────────────────────────────────
        if (! empty($from['is_bot'])) {
            return response()->json(['ok' => true]);
        }

        // ── Commands ──────────────────────────────────────────────────────────
        if (str_starts_with($text, '/start')) {
            return $this->startAccount($chat, $from);
        }

        if (str_starts_with($text, '/connect')) {
            return $this->connectGroup($chat, $from, $text);
        }

        if (str_starts_with($text, '/stats')) {
            $this->telegram->sendStatsMenu($chatId);
            return response()->json(['ok' => true]);
        }

        // ── Reply keyboard ────────────────────────────────────────────────────
        return match ($text) {
            '🆕 New Token'        => $this->reply($chatId, '🆕 New Token selected'),
            '🔑 My Tokens'        => $this->reply($chatId, '🔑 My Tokens selected'),
            '🌐 Domains'          => $this->reply($chatId, '🌐 Domains selected'),
            '💬 Support'          => $this->reply($chatId, '💬 Support selected'),
            '🔒 Privacy Policy'   => $this->reply($chatId, 'https://yourdomain.com/privacy'),
            '📜 Terms of Service' => $this->reply($chatId, 'https://yourdomain.com/terms'),
            default               => response()->json(['ok' => true]),
        };
    }

    // -------------------------------------------------------------------------
    // Inline keyboard callback handler
    // -------------------------------------------------------------------------



    private function handleCallback(array $callback): JsonResponse
    {
        $callbackId = $callback['id'];
        $chatId     = (string) ($callback['message']['chat']['id'] ?? '');
        $messageId  = (int)    ($callback['message']['message_id'] ?? 0);
        $data       = $callback['data'] ?? '';
    
        $from        = $callback['from'] ?? [];
        $requestedBy = trim(($from['first_name'] ?? '') . ' ' . ($from['last_name'] ?? ''));
        $requestedBy = $requestedBy ?: ($from['username'] ?? 'unknown');
    
        $this->telegram->answerCallbackQuery($callbackId);
    
        // ── Navigation callbacks that don't need a group ──────────────────────────
        match ($data) {
            BotCallback::STATS_WEEK => $this->telegram->editToWeekMenu($chatId, $messageId),
            BotCallback::STATS_BACK => $this->telegram->editToStatsMenu(
                $chatId,
                $messageId,
                "📊 *ស្ថិតិការទូទាត់*\nជ្រើសរយៈពេលដើម្បីមើល៖"
            ),
            default => null,
        };
    
        if (in_array($data, [BotCallback::STATS_WEEK, BotCallback::STATS_BACK], true)) {
            return response()->json(['ok' => true]);
        }
    
        // ── Month / Year sub-menus need a group ───────────────────────────────────
        if ($data === BotCallback::STATS_MONTH) {
            $group = $this->findConnectedGroup($chatId);
            if (! $group) {
                $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
                return response()->json(['ok' => true]);
            }
    
            $monthsWithData = $this->stats->monthsWithData($group->telegramGroupsID);
            $this->telegram->editToMonthMenu($chatId, $messageId, $monthsWithData);
            return response()->json(['ok' => true]);
        }
    
        if ($data === BotCallback::STATS_YEAR) {
            $group = $this->findConnectedGroup($chatId);
            if (! $group) {
                $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
                return response()->json(['ok' => true]);
            }
    
            $yearsWithData = $this->stats->yearsWithData($group->telegramGroupsID);
    
            if (count($yearsWithData) === 1 && $yearsWithData[0] === Carbon::now()->year) {
                $text = $this->stats->year($group->telegramGroupsID, $requestedBy);
                $this->telegram->editMessage($chatId, $messageId, $text);
            } else {
                $this->telegram->editToYearMenu($chatId, $messageId, $yearsWithData);
            }
    
            return response()->json(['ok' => true]);
        }
    
        // ── All stat data callbacks need a group ──────────────────────────────────
        $group = $this->findConnectedGroup($chatId);
        if (! $group) {
            $this->telegram->editMessage($chatId, $messageId, '❌ Group not registered. Please use /connect first.');
            return response()->json(['ok' => true]);
        }
    
        $uuid = $group->telegramGroupsID;
    
        $text = match (true) {
            $data === BotCallback::STATS_DAY
                => $this->stats->day($uuid, $requestedBy),
    
            (bool) preg_match(BotCallback::PATTERN_WEEK, $data, $m)
                => $this->stats->weekByNumber($uuid, (int) $m[1], $requestedBy),
    
            (bool) preg_match(BotCallback::PATTERN_MONTH, $data, $m)
                => $this->stats->monthByNumber($uuid, (int) $m[1], $requestedBy),
    
            (bool) preg_match(BotCallback::PATTERN_YEAR, $data, $m)
                => $this->stats->yearByNumber($uuid, (int) $m[1], $requestedBy),
    
            default => null,
        };
    
        if ($text !== null) {
            $this->telegram->editMessage($chatId, $messageId, $text);
        }
    
        return response()->json(['ok' => true]);
    }
    
    // ── Extracted helper — avoids repeating the same query 4 times ───────────────
    private function findConnectedGroup(string $chatId): ?TelegramGroup
    {
        return TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->first();
    }

    // -------------------------------------------------------------------------
    // /start
    // -------------------------------------------------------------------------

    private function startAccount(array $chat, array $from): JsonResponse
    {
        $chatId   = (string) ($chat['id'] ?? '');
        $chatType = $chat['type'] ?? 'private';

        if ($chatType !== 'private') {
            return $this->reply($chatId, '👋 Please open the bot in a private chat and send /start.');
        }

        $telegramId = (string) ($from['id'] ?? '');
        $firstName  = $from['first_name'] ?? 'Telegram';
        $lastName   = $from['last_name']  ?? null;
        $username   = $from['username']   ?? null;

        $user = User::firstOrCreate(
            ['telegram_id' => $telegramId],
            [
                'uuid'                => (string) Str::uuid(),
                'first_name'          => $firstName,
                'last_name'           => $lastName,
                'email'               => "telegram_{$telegramId}@telegram.local",
                'telegram_username'   => $username,
                'telegram_first_name' => $firstName,
                'telegram_last_name'  => $lastName,
                'password'            => bcrypt(Str::random(32)),
                'role'                => 'user',
                'status'              => 'active',
            ]
        );

        $user->update([
            'first_name'          => $firstName,
            'last_name'           => $lastName,
            'telegram_username'   => $username,
            'telegram_first_name' => $firstName,
            'telegram_last_name'  => $lastName,
        ]);

        $this->telegram->sendMainMenu(
            $chatId,
            "✅ Account ready!\n\nHello {$firstName}\nTelegram ID: {$telegramId}"
        );

        return response()->json([
            'ok'          => true,
            'uuid'        => $user->uuid,
            'telegram_id' => $telegramId,
        ]);
    }

    // -------------------------------------------------------------------------
    // /connect
    // -------------------------------------------------------------------------

    private function connectGroup(array $chat, array $from, string $text): JsonResponse
    {
        $chatId    = (string) ($chat['id'] ?? '');
        $chatTitle = $chat['title'] ?? null;
        $chatType  = $chat['type']  ?? 'private';

        if (! in_array($chatType, ['group', 'supergroup'])) {
            return $this->reply($chatId, '❌ Please use /connect inside a Telegram group.');
        }

        $telegramId      = (string) ($from['id'] ?? '');
        $parts           = preg_split('/\s+/', trim($text));
        $subscriptionKey = $parts[1] ?? null;

        if ($subscriptionKey) {
            $subscription = UserSubscription::with('package')
                ->where('subscription_key', $subscriptionKey)
                ->where('status', 'active')
                ->first();
        } else {
            $subscription = UserSubscription::with(['package', 'user'])
                ->where('status', 'active')
                ->whereHas('user', fn ($q) => $q->where('telegram_id', $telegramId))
                ->latest()
                ->first();
        }

        if (! $subscription) {
            return $this->reply($chatId, '❌ No active subscription found. Provide a key: /connect YOUR_KEY');
        }

        if ($subscription->ends_at && now()->greaterThan($subscription->ends_at)) {
            return $this->reply($chatId, '❌ Subscription expired.');
        }

        $existing = TelegramGroup::where('group_id', $chatId)->first();

        if ($existing) {
            if ($existing->status === 'connected') {
                return $this->reply($chatId, '✅ This group is already connected.');
            }

            $existing->update([
                'user_id'         => $subscription->user_id,
                'subscription_id' => $subscription->userSubscriptionsID,
                'group_name'      => $chatTitle,
                'status'          => 'connected',
                'connected_at'    => now(),
            ]);
        } else {
            TelegramGroup::create([
                'user_id'           => $subscription->user_id,
                'subscription_id'   => $subscription->userSubscriptionsID,
                'group_id'          => $chatId,
                'group_name'        => $chatTitle,
                'group_type'        => $chatType,
                'telegram_username' => $from['username'] ?? null,
                'bot_added_at'      => now(),
                'connected_at'      => now(),
                'status'            => 'connected',
            ]);

            $subscription->increment('group_used');

            SubscriptionUsageLog::create([
                'subscription_id' => $subscription->userSubscriptionsID,
                'user_id'         => $subscription->user_id,
                'type'            => 'group',
                'action'          => 'connected',
                'value'           => 1,
                'description'     => 'Telegram group connected',
                'metadata'        => [
                    'group_id'   => $chatId,
                    'group_name' => $chatTitle,
                    'group_type' => $chatType,
                ],
            ]);
        }

        return $this->reply($chatId, "✅ Group connected successfully!\nGroup: {$chatTitle}");
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function reply(string $chatId, string $text): JsonResponse
    {
        $this->telegram->sendMessage($chatId, $text);
        return response()->json(['ok' => true]);
    }

    public function webhookInfo(): array
    {
        return $this->telegram->webhookInfo();
    }

    public function setWebhook(): array
    {
        return $this->telegram->setWebhook();
    }

    public function testMessage(): array
    {
        $chatId = config('services.telegram.test_chat_id');

        if (! $chatId) {
            return ['ok' => false, 'message' => 'TELEGRAM_TEST_CHAT_ID not set in .env'];
        }

        return $this->telegram->sendMessage($chatId, '✅ Telegram Bot Test Success from Laravel');
    }
}