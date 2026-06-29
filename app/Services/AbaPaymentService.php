<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class AbaPaymentService
{
    private const CURRENCY_MAP = [
        '៛'  => 'KHR',
        '$'  => 'USD',
        '＄' => 'USD',
    ];

    /**
     * Supports:
     *
     * $2.50 paid by Sreyla Botum (*948) on Jun 28, 06:11 PM via ABA KHQR (Wing Bank (Cambodia) Plc) at CHEN KHEANG. Trx. ID: 178264507253336, APV: 437425.
     *
     * ៛4,000 paid by BORN SOPHEAK (*021) on Jun 28, 06:13 PM via ABA PAY at CHEN KHEANG. Trx. ID: 178264518833769, APV: 350810.
     */
    private const PATTERN = '/
        (?P<currency_symbol>[៛\$＄])
        \s*
        (?P<amount>[\d,]+(?:\.\d+)?)
        \s+paid\s+by\s+
        (?P<payer_name>.+?)
        \s+\((?P<payer_account>[^)]+)\)
        \s+on\s+
        (?P<date>[A-Za-z]+\s+\d{1,2},\s*\d{1,2}:\d{2}\s*(?:AM|PM))
        \s+via\s+
        (?P<method>ABA\s+.+?)
        (?:\s+\((?P<bank_code>.+?)\))?
        \s+at\s+
        (?P<merchant>.+?)
        \.\s+Trx\.\s*ID:\s*
        (?P<trx_id>\d+)
        ,\s*APV:\s*
        (?P<apv>\d+)
    /uix';

    public function __construct(
        protected TelegramBotService $telegram
    ) {}

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    public function process(string $rawText, string $telegramChatId): array
    {
        $group = $this->findGroup($telegramChatId);

        $userId = $group?->user_id;
        $subscriptionId = $group?->subscription_id;
        $telegramGroupId = $group?->telegramGroupsID;

        $cleanText = $this->cleanText($rawText);

        /*
        |--------------------------------------------------------------------------
        | Find all ABA payments in one Telegram message
        |--------------------------------------------------------------------------
        | preg_match() only gets one payment.
        | preg_match_all() gets all payments.
        */
        preg_match_all(self::PATTERN, $cleanText, $matches, PREG_SET_ORDER);

        // ---------------------------------------------------------------------
        // Parse failed
        // ---------------------------------------------------------------------

        if (empty($matches)) {
            Log::warning('ABA parse failed', [
                'chat_id' => $telegramChatId,
                'original' => $rawText,
                'clean' => $cleanText,
            ]);

            $payment = TelegramPayment::create([
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'telegram_group_id' => $telegramGroupId,
                'currency' => null,
                'raw_message' => $rawText,
                'status' => 'pending',
                'parsed_successfully' => false,
                'is_duplicate' => false,
            ]);

            if ($group) {
                $this->telegram->sendMarkdown(
                    $telegramChatId,
                    "⚠️ *ABA Payment Received — Parse Failed*\n\n"
                    . "Could not read the message automatically.\n"
                    . "Raw text saved for manual review.\n\n"
                    . "```\n{$cleanText}\n```"
                );
            }

            return [
                'parsed' => false,
                'group' => $group,
                'payment' => $payment,
                'payments' => [$payment],
                'currency' => null,
                'is_duplicate' => false,
                'count' => 0,
                'success_count' => 0,
                'duplicate_count' => 0,
            ];
        }

        // ---------------------------------------------------------------------
        // Parse success: save all payments
        // ---------------------------------------------------------------------

        $savedPayments = [];
        $duplicateCount = 0;
        $successCount = 0;

        foreach ($matches as $match) {
            $currency = $this->detectCurrency($match['currency_symbol'] ?? '');
            $paymentDate = $this->parseDate($match['date']);
            $trxId = trim($match['trx_id']);

            $amount = (float) str_replace(',', '', $match['amount']);
            $payerName = trim($match['payer_name']);
            $payerAccount = trim($match['payer_account']);
            $merchant = trim($match['merchant']);
            $method = trim($match['method']);
            $bankCode = ! empty($match['bank_code']) ? trim($match['bank_code']) : null;
            $apv = trim($match['apv']);

            $payment = TelegramPayment::updateOrCreate(
                [
                    'trx_id' => $trxId,
                ],
                [
                    'user_id' => $userId,
                    'subscription_id' => $subscriptionId,
                    'telegram_group_id' => $telegramGroupId,

                    'currency' => $currency,
                    'amount' => $amount,
                    'payer_name' => $payerName,
                    'payer_account' => $payerAccount,
                    'merchant_name' => $merchant,
                    'payment_method' => $method,
                    'bank_code' => $bankCode,
                    'trx_id' => $trxId,
                    'apv' => $apv,

                    'payment_date' => $paymentDate,
                    'report_date' => $paymentDate->toDateString(),
                    'report_month' => $paymentDate->month,
                    'report_year' => $paymentDate->year,

                    'raw_message' => $rawText,
                    'status' => 'success',
                    'parsed_successfully' => true,
                    'is_duplicate' => false,
                ]
            );

            $isDuplicate = ! $payment->wasRecentlyCreated;

            if ($isDuplicate) {
                $duplicateCount++;

                $payment->update([
                    'is_duplicate' => true,
                ]);
            } else {
                $successCount++;
                $this->incrementPaymentUsed($subscriptionId);

                if ($group) {
                    $this->sendPaymentAlert(
                        telegramChatId: $telegramChatId,
                        amount: $amount,
                        currency: $currency,
                        payerName: $payerName,
                        payerAccount: $payerAccount,
                        merchant: $merchant,
                        method: $method,
                        bankCode: $bankCode,
                        paymentDate: $paymentDate,
                        trxId: $trxId,
                        apv: $apv,
                    );
                }
            }

            $savedPayments[] = $payment;

            Log::info('ABA payment saved', [
                'trx_id' => $trxId,
                'amount' => $amount,
                'currency' => $currency,
                'payer' => $payerName,
                'merchant' => $merchant,
                'method' => $method,
                'bank_code' => $bankCode,
                'is_duplicate' => $isDuplicate,
            ]);
        }

        $group?->update([
            'last_payment_at' => now(),
        ]);

        return [
            'parsed' => true,
            'group' => $group,
            'payment' => $savedPayments[0] ?? null,
            'payments' => $savedPayments,
            'currency' => $savedPayments[0]?->currency ?? null,
            'is_duplicate' => $duplicateCount > 0,
            'count' => count($savedPayments),
            'success_count' => $successCount,
            'duplicate_count' => $duplicateCount,
        ];
    }

    // -------------------------------------------------------------------------
    // Alert formatter
    // -------------------------------------------------------------------------

    private function sendPaymentAlert(
        string $telegramChatId,
        float $amount,
        string $currency,
        string $payerName,
        string $payerAccount,
        string $merchant,
        string $method,
        ?string $bankCode,
        Carbon $paymentDate,
        string $trxId,
        string $apv,
    ): void {
        $symbol = $currency === 'KHR' ? '៛' : '$';
        $decimals = $currency === 'KHR' ? 0 : 2;

        $formattedAmount = number_format($amount, $decimals);
        $displayMethod = $bankCode ? "{$method} ({$bankCode})" : $method;

        $message = implode("\n", [
            "💳 *ABA Payment Received*",
            "━━━━━━━━━━━━━━━━━━",
            "💰 *Amount:*    `{$symbol}{$formattedAmount}`",
            "👤 *Payer:*     `{$payerName} ({$payerAccount})`",
            "🏪 *Merchant:*  `{$merchant}`",
            "📲 *Method:*    `{$displayMethod}`",
            "📅 *Date:*      `{$paymentDate->format('M j, Y h:i A')}`",
            "🔖 *Trx ID:*    `{$trxId}`",
            "✅ *APV:*       `{$apv}`",
            "━━━━━━━━━━━━━━━━━━",
            "✅ Payment confirmed",
        ]);

        $this->telegram->sendMarkdown($telegramChatId, $message);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function findGroup(string $chatId): ?TelegramGroup
    {
        $group = TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->latest()
            ->first();

        if (! $group) {
            Log::notice('AbaPaymentService: no connected group for chat', [
                'chat_id' => $chatId,
            ]);
        }

        return $group;
    }

    private function cleanText(string $text): string
    {
        $text = trim($text);

        /*
        |--------------------------------------------------------------------------
        | Remove Telegram copied message header
        |--------------------------------------------------------------------------
        | Example:
        | PayWay by ABA, [28 Jun 2026 at 6:11:12 in the evening]:
        */
        $text = preg_replace('/^.+?,\s*\[.*?\]:\s*/us', '', $text);

        /*
        |--------------------------------------------------------------------------
        | Remove Telegram username prefix
        |--------------------------------------------------------------------------
        | Example:
        | @PayWayBot ៛4,000 paid by ...
        */
        $text = preg_replace('/^@\S+\s+/u', '', $text);

        /*
        |--------------------------------------------------------------------------
        | Remove bracket prefix
        |--------------------------------------------------------------------------
        | Example:
        | [PayWay by ABA]: ៛4,000 paid by ...
        */
        $text = preg_replace('/^\[.*?\]:\s*/us', '', $text);

        /*
        |--------------------------------------------------------------------------
        | Remove leading dots
        |--------------------------------------------------------------------------
        */
        $text = preg_replace('/^\.{3}\s*/u', '', $text);

        return trim($text);
    }

    private function detectCurrency(string $symbol): string
    {
        $symbol = trim($symbol);

        $currency = self::CURRENCY_MAP[$symbol] ?? null;

        if ($currency === null) {
            Log::warning('AbaPaymentService: unknown currency symbol', [
                'symbol' => $symbol,
            ]);

            return 'KHR';
        }

        return $currency;
    }

    private function parseDate(string $raw): Carbon
    {
        $raw = preg_replace('/\s+/', ' ', trim($raw));

        try {
            $parsed = Carbon::createFromFormat('M j, g:i A', $raw);
            $now = now();

            $candidate = $parsed->year($now->year);

            if ($candidate->isAfter($now)) {
                $candidate->subYear();
            }

            return $candidate;
        } catch (\Throwable $e) {
            Log::warning('ABA date parse with format failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable $e) {
            Log::warning('ABA date parse fallback failed', [
                'raw' => $raw,
                'error' => $e->getMessage(),
            ]);
        }

        return now();
    }

    private function incrementPaymentUsed(?string $subscriptionId): void
{
    if (! $subscriptionId) {
        Log::warning('Cannot increment payment_used: subscription_id is null');

        return;
    }

    DB::transaction(function () use ($subscriptionId) {
        $subscription = UserSubscription::query()
            ->where('userSubscriptionsID', $subscriptionId)
            ->lockForUpdate()
            ->first();

        if (! $subscription) {
            Log::warning('Cannot increment payment_used: subscription not found', [
                'subscription_id' => $subscriptionId,
            ]);

            return;
        }

        $subscription->increment('payment_used');

        Log::info('payment_used incremented', [
            'subscription_id' => $subscriptionId,
            'payment_used' => $subscription->fresh()?->payment_used,
        ]);
    });
}
}