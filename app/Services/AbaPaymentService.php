<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class AbaPaymentService
{
    private const CURRENCY_MAP = [
        '៛'  => 'KHR',
        '$'  => 'USD',
        '＄' => 'USD',
    ];

    private const PATTERN = '/
        (?P<amount>[\d,]+(?:\.\d+)?)
        \s+paid\s+by\s+
        (?P<payer_name>.+?)
        \s+\((?P<payer_account>[^)]+)\)
        \s+on\s+
        (?P<date>[A-Za-z]+\s+\d{1,2},\s*\d{1,2}:\d{2}\s*(?:AM|PM))
        \s+via\s+
        (?P<method>ABA\s+\S+)
        (?:\s+\((?P<bank_code>[^)]+)\))?
        \s+at\s+
        (?P<merchant>.+?)
        \.\s+Trx\.\s*ID:\s*
        (?P<trx_id>\d+)
        ,\s*APV:\s*
        (?P<apv>\d+)
    /uix';

    public function __construct(protected TelegramBotService $telegram) {}

    // -------------------------------------------------------------------------
    // Main entry point
    // -------------------------------------------------------------------------

    public function process(string $rawText, string $telegramChatId): array
    {
        $group           = $this->findGroup($telegramChatId);
        $userId          = $group?->user_id;
        $subscriptionId  = $group?->subscription_id;
        $telegramGroupId = $group?->telegramGroupsID;

        $cleanText = $this->cleanText($rawText);
        $currency  = $this->detectCurrency($cleanText);
        $regexText = $this->stripCurrencySymbol($cleanText);

        preg_match(self::PATTERN, $regexText, $match);

        // ── Parse failed ──────────────────────────────────────────────────────
        if (! isset($match['trx_id'])) {
            Log::warning('ABA parse failed', [
                'chat_id'  => $telegramChatId,
                'original' => $rawText,
                'clean'    => $cleanText,
            ]);

            $payment = TelegramPayment::create([
                'user_id'             => $userId,
                'subscription_id'     => $subscriptionId,
                'telegram_group_id'   => $telegramGroupId,
                'currency'            => $currency,
                'raw_message'         => $rawText,
                'status'              => 'pending',
                'parsed_successfully' => false,
                'is_duplicate'        => false,
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
                'parsed'       => false,
                'group'        => $group,
                'payment'      => $payment,
                'currency'     => null,
                'is_duplicate' => false,
            ];
        }

        // ── Parse succeeded ───────────────────────────────────────────────────
        $paymentDate = $this->parseDate($match['date']);
        $trxId       = trim($match['trx_id']);

        $payment = TelegramPayment::updateOrCreate(
            ['trx_id' => $trxId],
            [
                'user_id'             => $userId,
                'subscription_id'     => $subscriptionId,
                'telegram_group_id'   => $telegramGroupId,
                'currency'            => $currency,
                'amount'              => (float) str_replace(',', '', $match['amount']),
                'payer_name'          => trim($match['payer_name']),
                'payer_account'       => trim($match['payer_account']),
                'merchant_name'       => trim($match['merchant']),
                'payment_method'      => trim($match['method']),
                // FIX #8: store null instead of empty string for missing bank_code
                'bank_code'           => $match['bank_code'] !== '' ? trim($match['bank_code']) : null,
                'trx_id'              => $trxId,
                'apv'                 => trim($match['apv']),
                'payment_date'        => $paymentDate,
                'report_date'         => $paymentDate->toDateString(),
                'report_month'        => $paymentDate->month,
                'report_year'         => $paymentDate->year,
                'raw_message'         => $rawText,
                'status'              => 'success',
                'parsed_successfully' => true,
                'is_duplicate'        => false,
            ]
        );

        $isDuplicate = ! $payment->wasRecentlyCreated;
        if ($isDuplicate) {
            $payment->update(['is_duplicate' => true]);
        }

        $group?->update(['last_payment_at' => now()]);

        Log::info('ABA payment saved', [
            'trx_id'       => $trxId,
            'amount'       => $match['amount'],
            'currency'     => $currency,
            'payer'        => $match['payer_name'],
            'merchant'     => $match['merchant'],
            'is_duplicate' => $isDuplicate,
        ]);

        if ($group && ! $isDuplicate) {
            $this->sendPaymentAlert($telegramChatId, $match, $currency, $paymentDate);
        }

        return [
            'parsed'       => true,
            'group'        => $group,
            'payment'      => $payment,
            'currency'     => $currency,
            'is_duplicate' => $isDuplicate,
        ];
    }

    // -------------------------------------------------------------------------
    // Alert formatter
    // -------------------------------------------------------------------------

    private function sendPaymentAlert(
        string $chatId,
        array  $match,
        string $currency,
        Carbon $paymentDate,
    ): void {
        $symbol   = $currency === 'KHR' ? '៛' : '$';
        $decimals = $currency === 'KHR' ? 0 : 2;
        $amount   = number_format((float) str_replace(',', '', $match['amount']), $decimals);
        $bankCode = ! empty($match['bank_code']) ? " ({$match['bank_code']})" : '';
        $method   = trim($match['method']) . $bankCode;

        $this->telegram->sendMarkdown($chatId, implode("\n", [
            "💳 *ABA Payment Received*",
            "━━━━━━━━━━━━━━━━━━",
            "💰 *Amount:*    `{$symbol}{$amount}`",
            "👤 *Payer:*     `{$match['payer_name']} ({$match['payer_account']})`",
            "🏪 *Merchant:*  `{$match['merchant']}`",
            "📲 *Method:*    `{$method}`",
            "📅 *Date:*      `{$paymentDate->format('M j, Y h:i A')}`",
            "🔖 *Trx ID:*    `{$match['trx_id']}`",
            "✅ *APV:*       `{$match['apv']}`",
            "━━━━━━━━━━━━━━━━━━",
            "✅ Payment confirmed",
        ]));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    // FIX #5: log when no group is found
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
        $text = preg_replace('/^@\S+\s+/u',     '', $text);
        $text = preg_replace('/^\[.*?\]:\s*/us', '', $text);
        $text = preg_replace('/^\.{3}\s*/u',     '', $text);
        return trim($text);
    }

    // FIX #3: log unknown currency symbols instead of silently defaulting
    private function detectCurrency(string $text): string
    {
        $first    = mb_substr($text, 0, 1, 'UTF-8');
        $currency = self::CURRENCY_MAP[$first] ?? null;

        if ($currency === null) {
            Log::warning('AbaPaymentService: unknown currency symbol', [
                'char' => $first,
                'ord'  => mb_ord($first),
            ]);
            return 'KHR';
        }

        return $currency;
    }

    private function stripCurrencySymbol(string $text): string
    {
        return trim(preg_replace('/^[៛\$＄]/u', '', $text));
    }

    // FIX #1 + #2: correct year inference and use g:i (no leading zero) format
    private function parseDate(string $raw): Carbon
    {
        $raw = preg_replace('/\s+/', ' ', trim($raw));

        // ABA format: "Jun 5, 2:30 PM" — no year, no leading zero on hour
        try {
            $parsed    = Carbon::createFromFormat('M j, g:i A', $raw);
            $now       = now();
            $candidate = $parsed->year($now->year);

            // If the resulting date is in the future, it belongs to last year
            if ($candidate->isAfter($now)) {
                $candidate->subYear();
            }

            return $candidate;
        } catch (\Throwable) {}

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {}

        return now();
    }
}