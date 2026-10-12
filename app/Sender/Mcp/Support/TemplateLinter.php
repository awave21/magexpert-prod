<?php

namespace App\Sender\Mcp\Support;

use App\Sender\Models\Domain;
use App\Sender\Models\Organization;
use App\Sender\Models\Template;
use Dom\HTMLDocument;
use Illuminate\Support\Str;

/**
 * Проверка письма перед отправкой: ссылки, картинки, размер, тема, прехедер, переменные, отправитель.
 */
class TemplateLinter
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    /**
     * Gmail обрезает письма тяжелее ~102 КБ.
     */
    public const MAX_HTML_BYTES = 102400;

    public const MAX_SUBJECT = 60;

    /**
     * Слова, из-за которых письмо чаще попадает в спам.
     */
    public const SPAM_WORDS = ['бесплатно', 'срочно', 'только сегодня', 'выигр', 'гарантир', 'деньги', 'скидк', 'акция', 'распродаж', 'заработ', 'кредит', 'жми', 'кликни', '100%', 'free'];

    public function __construct(private readonly TemplateVariables $variables) {}

    /**
     * @param  array{subject: string, preheader: ?string, html: string, text: ?string}  $letter
     * @param  string  $kind  campaign — рассылка, transactional — служебное письмо сайта
     * @return list<array{severity: string, code: string, message: string}>
     */
    public function check(Organization $organization, array $letter, string $kind, ?Template $template = null): array
    {
        $findings = [];
        $add = function (string $severity, string $code, string $message) use (&$findings): void {
            $findings[] = ['severity' => $severity, 'code' => $code, 'message' => $message];
        };

        $html = $letter['html'];
        $subject = trim($letter['subject']);

        // ссылка отписки
        if ($kind === 'campaign' && ! preg_match('/\{\{\s*unsubscribe_url\s*\}\}/', $html)) {
            $add(self::WARNING, 'unsubscribe_missing', 'В письме рассылки нет ссылки отписки {{ unsubscribe_url }}. Sender добавит её внизу сам, но лучше поставить её в подвал явно: так письмо реже попадает в спам.');
        }

        $this->checkMarkup($html, $add);

        $bytes = strlen($html);

        if ($bytes > self::MAX_HTML_BYTES) {
            $add(self::WARNING, 'html_too_large', 'HTML весит '.round($bytes / 1024).' КБ: Gmail обрежет письмо после ~100 КБ. Уберите лишние стили и фрагменты.');
        }

        // тема
        if ($subject === '') {
            $add(self::ERROR, 'subject_missing', 'Нет темы письма.');
        } else {
            if (mb_strlen($subject) > self::MAX_SUBJECT) {
                $add(self::WARNING, 'subject_too_long', 'Тема длиннее '.self::MAX_SUBJECT.' символов ('.mb_strlen($subject).'): на телефоне её конец не будет виден.');
            }

            $letters = preg_replace('/[^\p{L}]/u', '', preg_replace('/\{\{.*?\}\}/', '', $subject));

            if (mb_strlen($letters) >= 6 && mb_strtoupper($letters) === $letters) {
                $add(self::WARNING, 'subject_caps', 'Тема написана заглавными буквами — это похоже на спам.');
            }

            if (substr_count($subject, '!') >= 2) {
                $add(self::WARNING, 'subject_exclamations', 'В теме несколько восклицательных знаков — это похоже на спам.');
            }

            $lower = mb_strtolower($subject);
            $spam = array_values(array_filter(self::SPAM_WORDS, fn (string $word): bool => str_contains($lower, $word)));

            if ($spam !== []) {
                $add(self::WARNING, 'subject_spam_words', 'В теме есть слова, которые любят спам-фильтры: '.implode(', ', $spam).'.');
            }
        }

        if (preg_match('/обучени/iu', $subject.' '.$html)) {
            $add(self::WARNING, 'forbidden_word', 'В письме есть слово, которое на сайте MagExpert не используется (про учёбу). Замените на «курс», «программа» или «мероприятие».');
        }

        if (blank($letter['preheader'])) {
            $add(self::INFO, 'preheader_missing', 'Нет прехедера: почта покажет после темы начало текста. Добавьте короткую фразу (40–90 символов), которая дополняет тему.');
        }

        // переменные
        $known = $this->variables->known($organization);
        $unknown = array_values(array_filter(
            $this->variables->used($letter['subject'], (string) $letter['preheader'], $html, (string) $letter['text']),
            fn (string $key): bool => ! array_key_exists(Str::before($key, '.'), $known),
        ));

        if ($unknown !== []) {
            $add(self::WARNING, 'unknown_variables', 'Переменные не заданы ни в базовых, ни в переменных организации, ни в полях баз: '.implode(', ', $unknown).'. В письме они будут пустыми.');
        }

        $this->checkSender($organization, $template, $add);

        return $findings;
    }

    private function checkMarkup(string $html, callable $add): void
    {
        $doc = HTMLDocument::createFromString($html === '' ? '<p></p>' : $html, LIBXML_NOERROR);
        $insecure = [];
        $empty = 0;
        $scripts = 0;
        $placeholder = 0;

        foreach ($doc->querySelectorAll('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            $lower = Str::lower($href);

            match (true) {
                $href === '' || $href === '#' => $empty++,
                in_array($lower, ['https://', 'http://'], true) => $placeholder++,
                str_starts_with($lower, 'javascript:') => $scripts++,
                str_starts_with($lower, 'http://') => $insecure[] = $href,
                default => null,
            };
        }

        if ($empty > 0) {
            $add(self::ERROR, 'link_empty', "Ссылок без адреса: {$empty}. Укажите, куда они ведут.");
        }

        if ($placeholder > 0) {
            $add(self::ERROR, 'link_placeholder', "Ссылок-заготовок «https://» без адреса: {$placeholder}. Обычно это кнопка, которой забыли указать ссылку.");
        }

        if ($scripts > 0) {
            $add(self::ERROR, 'link_javascript', 'Ссылки javascript: почта блокирует. Замените их обычными https-ссылками.');
        }

        if ($insecure !== []) {
            $add(self::WARNING, 'link_insecure', 'Незащищённые ссылки http:// (лучше https://): '.implode(', ', array_slice(array_unique($insecure), 0, 5)).'.');
        }

        $noAlt = 0;
        $insecureImages = 0;

        foreach ($doc->querySelectorAll('img') as $img) {
            if (trim((string) $img->getAttribute('alt')) === '') {
                $noAlt++;
            }

            if (str_starts_with(Str::lower(trim((string) $img->getAttribute('src'))), 'http://')) {
                $insecureImages++;
            }
        }

        if ($noAlt > 0) {
            $add(self::WARNING, 'image_alt_missing', "Картинок без описания (alt): {$noAlt}. Без него письмо хуже читается, когда картинки отключены, и недоступно для экранных дикторов.");
        }

        if ($insecureImages > 0) {
            $add(self::WARNING, 'image_insecure', "Картинок по http:// : {$insecureImages}. Почта может их не показать — используйте https://.");
        }

        if ($doc->querySelector('script') !== null) {
            $add(self::ERROR, 'script', 'В письме есть <script>: почта его вырежет, а спам-фильтры насторожатся.');
        }
    }

    private function checkSender(Organization $organization, ?Template $template, callable $add): void
    {
        $sender = $template?->senderAddress;

        if ($sender !== null) {
            if ($sender->confirmed_at === null) {
                $add(self::ERROR, 'sender_unconfirmed', "Адрес отправителя {$sender->email} не подтверждён: письма с него не уйдут.");
            }

            return;
        }

        $hasDomain = $organization->domains()->where('status', Domain::STATUS_VERIFIED)->exists();

        if (! $hasDomain) {
            $add(self::ERROR, 'sender_missing', 'У письма нет адреса отправителя, и у организации нет подтверждённого домена: отправить письмо не получится.');
        } elseif ($template !== null) {
            $add(self::INFO, 'sender_default', 'Адрес отправителя в письме не выбран: уйдёт с адреса по умолчанию (см. get_brand → sender.default).');
        }
    }
}
