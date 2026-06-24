<?php

declare(strict_types=1);

namespace App\Telegram;

use App\Services\AbaPaymentService;
use danog\MadelineProto\EventHandler\Attributes\Handler;
use danog\MadelineProto\EventHandler\Message;
use danog\MadelineProto\EventHandler\Message\GroupMessage;
use danog\MadelineProto\EventHandler\Message\PrivateMessage;
use danog\MadelineProto\SimpleEventHandler;
use Illuminate\Support\Facades\Log;

final class AbaTelegramHandler extends SimpleEventHandler
{
    private AbaPaymentService $paymentService;

    public function onStart(): void
    {
        $this->paymentService = app(AbaPaymentService::class);

        Log::info('✅ AbaTelegramHandler started');
        echo "✅ Listening for ABA payments from ALL senders (users + bots)...\n";
    }

    #[Handler]
    public function onGroupMessage(GroupMessage $message): void
    {
        $this->handle($message);
    }

    #[Handler]
    public function onPrivateMessage(PrivateMessage $message): void
    {
        if (! app()->isLocal()) {
            return;
        }

        $this->handle($message);
    }

    // -------------------------------------------------------------------------

    private function handle(Message $message): void
    {
        $text   = trim($message->message ?? '');
        $chatId = (string) $message->chatId;

        echo '[' . date('H:i:s') . "] Chat: {$chatId} | Msg: " . mb_substr($text, 0, 60) . "\n";

        if (! $this->isAbaPayment($text)) {
            return;
        }

        echo "💳 ABA payment detected!\n";

        Log::info('ABA message detected', [
            'chat_id'    => $chatId,
            'message_id' => $message->id,
            'text'       => $text,
        ]);

        try {
            $result = $this->paymentService->process($text, $chatId);

            if ($result['parsed']) {
                $trxId = $result['payment']->trx_id ?? 'unknown';
                $isDup = $result['is_duplicate'] ? ' (duplicate)' : '';
                echo "✅ Saved — Trx ID: {$trxId}{$isDup}\n";
                Log::info('ABA payment processed', ['trx_id' => $trxId]);
            } else {
                echo "⚠️  Parse failed — raw message saved\n";
                Log::warning('ABA parse failed, raw saved', ['chat_id' => $chatId]);
            }
        } catch (\Throwable $e) {
            echo "❌ Error: {$e->getMessage()}\n";
            Log::error('AbaHandler error', [
                'error'   => $e->getMessage(),
                'line'    => $e->getLine(),
                'file'    => $e->getFile(),
                'chat_id' => $chatId,
            ]);
        }
    }

    private function isAbaPayment(string $text): bool
    {
        return str_contains($text, 'paid by')
            && str_contains($text, 'Trx. ID')
            && str_contains($text, 'APV:');
    }
}