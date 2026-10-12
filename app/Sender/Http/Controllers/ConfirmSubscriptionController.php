<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Models\Contact;
use App\Sender\Services\SubscriptionConfirmationService;
use App\Sender\Support\PublicPage;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Подтверждение подписки по ссылке из письма. Как и отписка: GET показывает кнопку (почтовые сканеры
 * открывают ссылки сами и не должны подтверждать подписку за человека), POST подтверждает.
 */
class ConfirmSubscriptionController extends Controller
{
    public function __construct(private readonly SubscriptionConfirmationService $confirmations) {}

    public function show(string $token): Response
    {
        $contact = $this->confirmations->findByToken($token);

        if ($contact === null) {
            return $this->invalid();
        }

        if ($contact->unsubscribed_at !== null) {
            return $this->unsubscribed($contact);
        }

        if ($contact->confirmed_at !== null) {
            return $this->confirmed($contact);
        }

        $form = '<form method="post" action="'.e(request()->url()).'"><button type="submit">Подтвердить подписку</button></form>';

        return PublicPage::render('Подтвердите подписку', 'Письма рассылки '.e($this->organization($contact)).' будут приходить на адрес '.e($contact->email).'.', null, 200, $form);
    }

    public function store(string $token): Response
    {
        [$contact] = $this->confirmations->confirm($token);

        if ($contact === null) {
            return $this->invalid();
        }

        return $contact->unsubscribed_at !== null ? $this->unsubscribed($contact) : $this->confirmed($contact);
    }

    private function confirmed(Contact $contact): Response
    {
        return PublicPage::render('Подписка подтверждена', 'Письма рассылки '.e($this->organization($contact)).' будут приходить на адрес '.e($contact->email).'. Эту вкладку можно закрыть.', true);
    }

    private function unsubscribed(Contact $contact): Response
    {
        return PublicPage::render('Вы отписаны', 'Рассылки на адрес '.e($contact->email).' не приходят: вы отписались от них раньше.', false);
    }

    private function invalid(): Response
    {
        return PublicPage::render('Ссылка недействительна', 'Возможно, адрес удалили из рассылки или в ссылке ошибка.', false, 404);
    }

    private function organization(Contact $contact): string
    {
        return (string) $contact->list?->organization?->name;
    }
}
