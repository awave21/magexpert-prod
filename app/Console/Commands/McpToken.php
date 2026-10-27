<?php

namespace App\Console\Commands;

use App\Mcp\McpTokens;
use App\Models\McpToken as Token;
use App\Models\User;
use Illuminate\Console\Command;

class McpToken extends Command
{
    protected $signature = 'mcp:token {email : Почта пользователя (admin, manager или editor)} {name=ИИ-ассистент : Название ключа} {--revoke : Отозвать все ключи пользователя}';

    protected $description = 'Выдаёт личный ключ MCP или отзывает ключи пользователя';

    public function handle(McpTokens $tokens): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('Пользователь не найден');

            return self::FAILURE;
        }

        if ($this->option('revoke')) {
            $count = Token::query()->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->info("Отозвано ключей: {$count}");

            return self::SUCCESS;
        }

        if (! $user->hasAnyRole(McpTokens::ROLES)) {
            $this->error('MCP доступен только ролям admin, manager, editor');

            return self::FAILURE;
        }

        if (Token::query()->where('user_id', $user->id)->whereNull('revoked_at')->count() >= McpTokens::MAX_ACTIVE) {
            $this->error('Слишком много действующих ключей, отзовите лишние (--revoke)');

            return self::FAILURE;
        }

        $issued = $tokens->issue($user, $this->argument('name'));
        $this->info('Ключ показывается один раз, сохраните его:');
        $this->line($issued['plain']);
        $this->line('Адрес сервера: '.url('/api/mcp'));

        return self::SUCCESS;
    }
}
