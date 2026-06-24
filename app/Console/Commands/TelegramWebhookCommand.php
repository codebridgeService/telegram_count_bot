<?php

namespace App\Console\Commands;

use App\Services\TelegramBotService;
use Illuminate\Console\Command;

class TelegramWebhookCommand extends Command
{
    protected $signature   = 'telegram:webhook {action : set|delete|info}';
    protected $description = 'Manage the Telegram bot webhook';

    public function __construct(protected TelegramBotService $telegram)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        match ($this->argument('action')) {
            'set'    => $this->setWebhook(),
            'delete' => $this->deleteWebhook(),
            'info'   => $this->info('Webhook URL: ' . $this->webhookUrl()),
            default  => $this->error('Unknown action. Use: set | delete | info'),
        };

        return self::SUCCESS;
    }

    private function setWebhook(): void
    {
        $url = $this->webhookUrl();

        // FIX #7: pass $url as first argument, not as $secretToken
        $result = $this->telegram->setWebhook($url);

        $result['ok'] ?? false
            ? $this->info("✅ Webhook set to: {$url}")
            : $this->error('❌ Failed: ' . json_encode($result));
    }

    private function deleteWebhook(): void
    {
        $result = $this->telegram->deleteWebhook();
        $result['ok'] ?? false
            ? $this->info('✅ Webhook removed.')
            : $this->error('❌ Failed: ' . json_encode($result));
    }

    private function webhookUrl(): string
    {
        return rtrim(config('app.url'), '/') . '/telegram/webhook';
    }
}