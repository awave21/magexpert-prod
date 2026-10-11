<?php

namespace App\Sender\Mail;

use App\Sender\Models\SenderAddress;
use Illuminate\Mail\Mailable;

/**
 * Письмо со ссылкой подтверждения адреса отправителя.
 */
class SenderAddressConfirmationMail extends Mailable
{
    public function __construct(public SenderAddress $address, public string $url, public string $letter) {}

    public function build(): self
    {
        return $this->from((string) config('sender.client.from_address'), 'Sender')
            ->subject('Подтвердите адрес отправителя '.$this->address->email)
            ->html($this->letter);
    }
}
