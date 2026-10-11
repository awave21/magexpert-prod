<?php

namespace App\Sender\Console;

use App\Sender\Models\SenderAddress;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class AddressConfirmCommand extends Command
{
    protected $signature = 'sender:address-confirm {email : адрес отправителя}';

    protected $description = 'Подтверждает адрес отправителя без письма (для служебных ящиков без входящей почты, например noreply@)';

    public function handle(): int
    {
        $email = Str::lower(trim((string) $this->argument('email')));
        $addresses = SenderAddress::query()->where('email', $email)->get();

        if ($addresses->isEmpty()) {
            $this->error("Адрес {$email} не найден. Сначала добавьте его на странице домена.");

            return self::FAILURE;
        }

        $addresses->each(fn (SenderAddress $address) => $address->forceFill(['confirmed_at' => now(), 'confirmation_token' => null])->save());
        $this->info("Адрес {$email} подтверждён.");

        return self::SUCCESS;
    }
}
