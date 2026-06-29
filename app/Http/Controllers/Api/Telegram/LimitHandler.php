<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telegram;

use App\Constants\BotCallback;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;


class LimitHandler
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    public function showLimits(string $chatId, array $from): JsonResponse
    {
        try {
            $telegramUserId = (string) ($from['id'] ?? '');

            $group = TelegramGroup::query()
                ->where('status', 'connected')
                ->latest()
                ->first();

            if (! $group) {
                $this->telegram->sendMessage($chatId,
                    "📊 <b>My Limits</b>\n\n"
                    . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                    . "សូមប្រើ /connect ជាមុនសិន។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            $subscription = UserSubscription::query()
                ->where('user_id', $group->user_id)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (! $subscription) {
                $this->telegram->sendMessage($chatId,
                    "📊 <b>My Limits</b>\n\n"
                    . "អ្នកមិនទាន់មាន active subscription/package ទេ។\n"
                    . "សូមចុច 🆕 Package ដើម្បីជ្រើសរើស package។",
                    ['parse_mode' => 'HTML']
                );

                return response()->json(['ok' => true]);
            }

            /*
            |--------------------------------------------------------------------------
            | Get package info from packages table
            |--------------------------------------------------------------------------
            */
            $package = $this->findPackage((string) $subscription->package_id);

            /*
            |--------------------------------------------------------------------------
            | Correct limit logic
            |--------------------------------------------------------------------------
            | 1. If user has override limit, use override.
            | 2. Else use package limit.
            */
            $paymentLimit = (int) (
                $subscription->override_payment_limit
                ?? ($package->payment_limit ?? 0)
            );

            $groupLimit = (int) (
                $subscription->override_group_limit
                ?? ($package->group_limit ?? 0)
            );

            /*
            |--------------------------------------------------------------------------
            | Used count
            |--------------------------------------------------------------------------
            | You have payment_used and group_used in user_subscriptions.
            | But payment_used is currently 0, so this also checks real payment count.
            */
            $usedGroups = (int) (
                $subscription->group_used
                ?? TelegramGroup::query()
                    ->where('user_id', $group->user_id)
                    ->where('status', 'connected')
                    ->count()
            );

            $realPaymentCount = TelegramPayment::query()
                ->where('user_id', $group->user_id)
                ->where('parsed_successfully', true)
                ->where('is_duplicate', false)
                ->count();

            $usedPayments = max((int) ($subscription->payment_used ?? 0), $realPaymentCount);

            $remainingGroups = max($groupLimit - $usedGroups, 0);
            $remainingPayments = max($paymentLimit - $usedPayments, 0);

            $packageName = e(
                $package->name
                ?? $package->package_name
                ?? 'Active Package'
            );

            $text = implode("\n", [
                "📊 <b>My Limits</b>",
                "─────────────────────",
                "📦 <b>Package:</b> {$packageName}",
                "",
                "👥 <b>Groups:</b> {$usedGroups} / {$groupLimit}",
                "✅ <b>Remaining Groups:</b> {$remainingGroups}",
                "",
                "💳 <b>Payments:</b> {$usedPayments} / {$paymentLimit}",
                "✅ <b>Remaining Payments:</b> {$remainingPayments}",
                "─────────────────────",
            ]);

            Log::info('User checked limits', [
                'chat_id' => $chatId,
                'telegram_user_id' => $telegramUserId,
                'user_id' => $group->user_id,
                'subscription_id' => $subscription->userSubscriptionsID ?? null,
                'package_id' => $subscription->package_id,
                'package' => $package,
                'payment_limit' => $paymentLimit,
                'group_limit' => $groupLimit,
                'used_payments' => $usedPayments,
                'used_groups' => $usedGroups,
            ]);

            $this->telegram->sendMessage($chatId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => [
                    'inline_keyboard' => [
                        [
                            [
                                'text' => '👥 មើលក្រុមរបស់ខ្ញុំ',
                                'callback_data' => BotCallback::MY_GROUPS,
                            ],
                        ],
                        [
                            [
                                'text' => '🆕 ប្ដូរ Package',
                                'callback_data' => BotCallback::SHOW_PACKAGES,
                            ],
                        ],
                    ],
                ],
            ]);
            
            return response()->json(['ok' => true]);

        } catch (\Throwable $e) {
            Log::error('LimitHandler error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Cannot check limits now.\nPlease contact support."
            );

            return response()->json(['ok' => false]);
        }
    }

    private function findPackage(string $packageId): ?object
    {
        if (! Schema::hasTable('packages')) {
            Log::warning('packages table does not exist');

            return null;
        }

        $possibleIdColumns = [
            'id',
            'package_id',
            'packageID',
            'packagesID',
        ];

        foreach ($possibleIdColumns as $column) {
            if (! Schema::hasColumn('packages', $column)) {
                continue;
            }

            $package = DB::table('packages')
                ->where($column, $packageId)
                ->first();

            if ($package) {
                return $package;
            }
        }

        Log::warning('Package not found for subscription', [
            'package_id' => $packageId,
        ]);

        return null;
    }

    public function showMyGroups(string $chatId, array $from): JsonResponse
{
    try {
        $group = TelegramGroup::query()
            ->where('status', 'connected')
            ->latest()
            ->first();

        if (! $group) {
            $this->telegram->sendMessage($chatId,
                "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                . "អ្នកមិនទាន់បាន connect group នៅឡើយទេ។\n"
                . "សូមប្រើ /connect ជាមុនសិន។",
                ['parse_mode' => 'HTML']
            );

            return response()->json(['ok' => true]);
        }

        $subscription = UserSubscription::query()
            ->where('user_id', $group->user_id)
            ->where('status', 'active')
            ->latest()
            ->first();

        if (! $subscription) {
            $this->telegram->sendMessage($chatId,
                "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                . "អ្នកមិនទាន់មាន active subscription/package ទេ។",
                ['parse_mode' => 'HTML']
            );

            return response()->json(['ok' => true]);
        }

        $groups = TelegramGroup::query()
            ->where(function ($query) use ($group, $subscription) {
                $query->where('user_id', $group->user_id)
                    ->orWhere('subscription_id', $subscription->userSubscriptionsID);
            })
            ->where('status', 'connected')
            ->latest()
            ->get();

        if ($groups->isEmpty()) {
            $this->telegram->sendMessage($chatId,
                "👥 <b>ក្រុមរបស់ខ្ញុំ</b>\n\n"
                . "មិនមាន group connected ទេ។",
                ['parse_mode' => 'HTML']
            );

            return response()->json(['ok' => true]);
        }

        $package = $this->findPackage((string) $subscription->package_id);

        $groupLimit = (int) (
            $subscription->override_group_limit
            ?? ($package->group_limit ?? 0)
        );

        $usedGroups = $groups->count();
        $remainingGroups = max($groupLimit - $usedGroups, 0);

        $lines = [
            "👥 <b>ក្រុមរបស់ខ្ញុំ</b>",
            "─────────────────────",
            "📊 <b>ប្រើប្រាស់:</b> {$usedGroups} / {$groupLimit}",
            "✅ <b>នៅសល់:</b> {$remainingGroups}",
            "─────────────────────",
            "",
        ];

        foreach ($groups as $index => $telegramGroup) {
            $number = $index + 1;

            $groupName = e(
                $telegramGroup->group_name
                ?? $telegramGroup->title
                ?? $telegramGroup->name
                ?? 'Unknown Group'
            );

            $groupId = e((string) ($telegramGroup->group_id ?? 'N/A'));

            $connectedAt = $telegramGroup->created_at
                ? $telegramGroup->created_at->format('M j, Y h:i A')
                : 'N/A';

            $lastPaymentAt = $telegramGroup->last_payment_at
                ? $telegramGroup->last_payment_at->format('M j, Y h:i A')
                : 'មិនទាន់មាន payment';

            $lines[] = "{$number}. <b>{$groupName}</b>";
            $lines[] = "   🆔 <code>{$groupId}</code>";
            $lines[] = "   🔗 Connected: {$connectedAt}";
            $lines[] = "   💳 Last Payment: {$lastPaymentAt}";
            $lines[] = "";
        }

        if ($usedGroups > $groupLimit) {
            $lines[] = "⚠️ <b>Warning:</b> អ្នកប្រើ group លើស limit។";
        }

        $keyboard = [];

        foreach ($groups as $telegramGroup) {
            $groupName = $telegramGroup->group_name
                ?? $telegramGroup->title
                ?? $telegramGroup->name
                ?? 'Unknown Group';
        
            $keyboard[] = [
                [
                    'text' => '🗑 Remove ' . mb_strimwidth($groupName, 0, 25, '...'),
                    'callback_data' => BotCallback::REMOVE_GROUP_PREFIX . $telegramGroup->telegramGroupsID,
                ],
            ];
        }
        
        $keyboard[] = [
            [
                'text' => '📊 ត្រឡប់ទៅ My Limits',
                'callback_data' => BotCallback::MY_LIMITS,
            ],
        ];
        
        $this->telegram->sendMessage($chatId, implode("\n", $lines), [
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => $keyboard,
            ],
        ]);


        return response()->json(['ok' => true]);

    } catch (\Throwable $e) {
        Log::error('ShowMyGroups error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "⚠️ Cannot check groups now.\nPlease contact support."
        );

        return response()->json(['ok' => false]);
    }
}
public function removeGroup(string $chatId, string $telegramGroupsID, array $from): JsonResponse
{
    try {
        $group = TelegramGroup::query()
            ->where('telegramGroupsID', $telegramGroupsID)
            ->where('status', 'connected')
            ->first();

        if (! $group) {
            $this->telegram->sendMessage($chatId,
                "⚠️ <b>Group not found</b>\n\n"
                . "This group may already be removed.",
                ['parse_mode' => 'HTML']
            );

            return response()->json(['ok' => true]);
        }

        $subscription = UserSubscription::query()
            ->where('user_id', $group->user_id)
            ->where('status', 'active')
            ->latest()
            ->first();

        $groupName = e(
            $group->group_name
            ?? $group->title
            ?? $group->name
            ?? 'Unknown Group'
        );

        DB::transaction(function () use ($group, $subscription, $telegramGroupsID) {
            /*
            |--------------------------------------------------------------------------
            | Remove group safely
            |--------------------------------------------------------------------------
            | Use query update instead of $group->update()
            | because your primary key is telegramGroupsID, not id.
            */
             TelegramGroup::query()
                ->where('telegramGroupsID', $telegramGroupsID)
                ->where('status', 'connected')
                ->update([
                    'status' => 'disconnected',
                    'updated_at' => now(),
            ]);

            /*
            |--------------------------------------------------------------------------
            | Decrease group_used safely
            |--------------------------------------------------------------------------
            | Use query decrement instead of $subscription->decrement()
            | because your primary key is userSubscriptionsID, not id.
            */
            if ($subscription && (int) $subscription->group_used > 0) {
                UserSubscription::query()
                    ->where('userSubscriptionsID', $subscription->userSubscriptionsID)
                    ->decrement('group_used');
            }
        });

        Log::info('Telegram group removed by user', [
            'chat_id' => $chatId,
            'telegram_group_id' => $telegramGroupsID,
            'user_id' => $group->user_id,
            'subscription_id' => $subscription->userSubscriptionsID ?? null,
            'removed_by' => $from['id'] ?? null,
        ]);

        $this->telegram->sendMessage($chatId,
        "✅ <b>Group Disconnected</b>\n\n"
        . "👥 Group: <b>{$groupName}</b>\n"
        . "This group has been disconnected from your package.",
        [
            'parse_mode' => 'HTML',
            'reply_markup' => [
                'inline_keyboard' => [
                    [
                        [
                            'text' => '👥 មើលក្រុមរបស់ខ្ញុំ',
                            'callback_data' => BotCallback::MY_GROUPS,
                        ],
                    ],
                    [
                        [
                            'text' => '📊 ត្រឡប់ទៅ My Limits',
                            'callback_data' => BotCallback::MY_LIMITS,
                        ],
                    ],
                ],
            ],
        ]
    );
        return response()->json(['ok' => true]);

    } catch (\Throwable $e) {
        Log::error('Remove group error', [
            'message' => $e->getMessage(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'telegram_group_id' => $telegramGroupsID,
            'trace' => $e->getTraceAsString(),
        ]);

        $this->telegram->sendMessage(
            $chatId,
            "⚠️ Cannot remove group now.\nPlease contact support."
        );

        return response()->json(['ok' => false]);
    }
}
}