<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Services\SenderAddressService;
use App\Sender\Support\PublicPage;
use App\Sender\Support\SenderUi;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Подтверждение адреса отправителя по ссылке из письма. GET только показывает кнопку: почтовые сканеры
 * и предпросмотр ссылок открывают их сами и не должны подтверждать адрес за человека. Подтверждает POST.
 */
class ConfirmSenderAddressController extends Controller
{
    public function show(string $token, SenderAddressService $addresses): Response
    {
        $address = $addresses->findPending($token);

        if ($address === null) {
            return $this->invalid();
        }

        $form = '<form method="post" action="'.e(request()->url()).'"><button type="submit">Подтвердить адрес</button></form>';

        return PublicPage::render('Подтвердите адрес отправителя', 'С адреса '.e($address->email).' можно будет отправлять письма.', null, 200, $form);
    }

    public function store(string $token, SenderAddressService $addresses): Response
    {
        $address = $addresses->confirm($token);

        if ($address === null) {
            return $this->invalid();
        }

        $panel = '<a href="'.e(SenderUi::url('domains')).'">Открыть Sender</a>';

        return PublicPage::render('Адрес подтверждён', 'Теперь с адреса '.e($address->email).' можно отправлять письма. Эту вкладку можно закрыть.', true, 200, $panel);
    }

    private function invalid(): Response
    {
        return PublicPage::render('Ссылка не действует', 'Возможно, она устарела или адрес уже подтверждён. Отправьте письмо ещё раз из раздела «Домены» в Sender.', false, 410);
    }
}
