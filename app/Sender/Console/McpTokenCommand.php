<?php

namespace App\Sender\Console;

use App\Sender\Mcp\McpTokens;
use App\Sender\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class McpTokenCommand extends Command
{
    protected $signature = 'sender:mcp-token {email : пользователь Sender} {--name=Консоль : название ключа}';

    protected $description = 'Выдаёт пользователю личный ключ MCP для подключения ИИ-ассистента и печатает адрес сервера';

    public function handle(McpTokens $tokens): int
    {
        $user = User::query()->where('email', Str::lower(trim((string) $this->argument('email'))))->first();

        if ($user === null) {
            $this->error('Пользователь не найден');

            return self::FAILURE;
        }

        if (! $user->is_active) {
            $this->error('Пользователь отключён: ключ работать не будет');

            return self::FAILURE;
        }

        $result = $tokens->issue($user, (string) $this->option('name'));
        $url = $tokens->url();

        $this->info('Ключ создан. Сохраните его: повторно он не показывается.');
        $this->line("URL:   {$url}");
        $this->line("Ключ:  {$result['plain']}");
        $this->line('Роль:  '.($user->role ?? 'admin'));
        $this->newLine();
        $this->line("claude mcp add --transport http sender {$url} --header \"Authorization: Bearer {$result['plain']}\"");

        return self::SUCCESS;
    }
}
