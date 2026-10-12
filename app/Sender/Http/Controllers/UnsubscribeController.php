<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Events\RecipientUnsubscribed;
use App\Sender\Models\Message;
use App\Sender\Models\Suppression;
use App\Sender\Models\Webhook;
use App\Sender\Services\SubscriptionService;
use App\Sender\Services\WebhookService;
use App\Sender\Support\PublicPage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Отписка от рассылок по ссылке из письма. GET показывает кнопку (почтовые сканеры открывают ссылки сами),
 * POST отписывает: его же присылают почтовые программы по заголовку List-Unsubscribe-Post.
 */
class UnsubscribeController extends Controller
{
    public function show(string $uuid): Response
    {
        $message = $this->message($uuid);

        if ($message === null) {
            return $this->page('Ссылка не действует', 'Возможно, письмо было удалено из системы.', false, 410);
        }

        if ($this->isUnsubscribed($message)) {
            return $this->page('Вы уже отписаны', 'Рассылки на адрес '.e($message->to_email).' больше не приходят.', true);
        }

        $action = e(request()->url());
        $form = '<form method="post" action="'.$action.'"><button type="submit">Отписаться</button></form>';

        return $this->page('Отписаться от рассылки?', 'Письма рассылок перестанут приходить на адрес '.e($message->to_email).'. Служебные письма, например о смене пароля, продолжат приходить.', null, 200, $form);
    }

    public function store(Request $request, string $uuid, SubscriptionService $subscriptions): Response
    {
        $message = $this->message($uuid);

        if ($message === null) {
            return $this->page('Ссылка не действует', 'Возможно, письмо было удалено из системы.', false, 410);
        }

        $subscriptions->unsubscribe($message->organization, $message->to_email);

        RecipientUnsubscribed::dispatch($message->organization->slug, $message->to_email);
        app(WebhookService::class)->dispatch($message->organization_id, Webhook::EVENT_UNSUBSCRIBED, ['email' => $message->to_email, 'campaign_id' => $message->campaign_id]);

        return $this->page('Вы отписались', 'Рассылки на адрес '.e($message->to_email).' больше не будут приходить.', true);
    }

    /**
     * Письмо рассылки узнаётся по своей ссылке отписки в данных, а не по campaign_id:
     * после удаления рассылки campaign_id обнуляется, а ссылки в уже отправленных письмах должны работать.
     */
    private function message(string $uuid): ?Message
    {
        $message = Message::query()->with('organization')->where('uuid', $uuid)->first();

        return $message !== null && str_ends_with((string) ($message->data['unsubscribe_url'] ?? ''), '/unsubscribe/'.$uuid) ? $message : null;
    }

    private function isUnsubscribed(Message $message): bool
    {
        return Suppression::query()
            ->where('organization_id', $message->organization_id)
            ->where('email', $message->to_email)
            ->exists();
    }

    private function page(string $title, string $text, ?bool $ok, int $status = 200, string $extra = ''): Response
    {
        return PublicPage::render($title, $text, $ok, $status, $extra);
    }
}
