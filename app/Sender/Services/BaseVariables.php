<?php

namespace App\Sender\Services;

/**
 * Базовые переменные: их передаёт приложение при отправке писем.
 */
class BaseVariables
{
    /**
     * @return list<array{key: string, label: string, description: string, sample: string}>
     */
    public static function all(): array
    {
        return [
            ['key' => 'name', 'label' => 'Имя', 'description' => 'Имя получателя (письма сброса пароля и API)', 'sample' => 'Анна Иванова'],
            ['key' => 'user_name', 'label' => 'Имя и фамилия', 'description' => 'Полное имя участника', 'sample' => 'Анна Иванова'],
            ['key' => 'first_name', 'label' => 'Имя (короткое)', 'description' => 'Только имя участника', 'sample' => 'Анна'],
            ['key' => 'user_email', 'label' => 'Email получателя', 'description' => 'Адрес, на который отправлено письмо', 'sample' => 'anna@example.com'],
            ['key' => 'password', 'label' => 'Пароль', 'description' => 'Временный пароль для входа', 'sample' => 'Qw3rty_91'],
            ['key' => 'event_title', 'label' => 'Название мероприятия', 'description' => 'Заголовок мероприятия', 'sample' => 'Весенняя конференция 2026'],
            ['key' => 'event_url', 'label' => 'Ссылка на мероприятие', 'description' => 'Адрес страницы мероприятия на сайте', 'sample' => 'https://example.com/events/example'],
            ['key' => 'event_type', 'label' => 'Тип мероприятия', 'description' => 'Вебинар, конференция, курс и т.д.', 'sample' => 'Вебинар'],
            ['key' => 'event_format', 'label' => 'Формат', 'description' => 'Онлайн, офлайн или гибрид', 'sample' => 'Онлайн'],
            ['key' => 'event_location', 'label' => 'Место проведения', 'description' => 'Адрес или площадка (для офлайн)', 'sample' => 'Москва, ул. Примерная, 1'],
            ['key' => 'event_image', 'label' => 'Картинка мероприятия', 'description' => 'Полный адрес изображения; пусто, если у мероприятия нет картинки', 'sample' => 'https://example.com/storage/events/example.webp'],
            ['key' => 'event_description', 'label' => 'Краткое описание', 'description' => 'Короткое описание мероприятия без разметки', 'sample' => 'Практический вебинар с разбором примеров'],
            ['key' => 'event_datetime', 'label' => 'Дата и время начала', 'description' => 'Одной строкой: дд.мм.гггг, чч:мм', 'sample' => '15.11.2026, 10:00'],
            ['key' => 'speakers_count', 'label' => 'Количество спикеров', 'description' => 'Сколько спикеров у мероприятия', 'sample' => '2'],
            ['key' => 'speakers', 'label' => 'Спикеры (список)', 'description' => 'Список спикеров для цикла {{#each speakers}}…{{/each}}; внутри: {{ name }}, {{ regalia }}, {{ position }}, {{ company }}, {{ photo }}', 'sample' => 'Иван Иванов; Пётр Петров'],
            ['key' => 'speaker_1_name', 'label' => 'Спикер 1: имя', 'description' => 'Устарело, используйте {{#each speakers}}. Так же speaker_2_name … speaker_6_name', 'sample' => 'Иван Иванов'],
            ['key' => 'speaker_1_position', 'label' => 'Спикер 1: должность', 'description' => 'Устарело, используйте {{#each speakers}}. Так же speaker_2_position …', 'sample' => 'Профессор'],
            ['key' => 'speaker_1_company', 'label' => 'Спикер 1: организация', 'description' => 'Устарело, используйте {{#each speakers}}. Так же speaker_2_company …', 'sample' => 'Компания «Пример»'],
            ['key' => 'speaker_1_photo', 'label' => 'Спикер 1: фото', 'description' => 'Устарело, используйте {{#each speakers}}. Полный адрес фото; так же speaker_2_photo …', 'sample' => 'https://example.com/storage/speakers/example.webp'],
            ['key' => 'event_city', 'label' => 'Город', 'description' => 'Город проведения; пусто, если не указан', 'sample' => 'Москва'],
            ['key' => 'speaker_1_regalia', 'label' => 'Спикер 1: регалии', 'description' => 'Устарело, используйте {{#each speakers}}. Звания и регалии; так же speaker_2_regalia …', 'sample' => 'Д.м.н., профессор'],
            ['key' => 'price', 'label' => 'Стоимость', 'description' => 'Цена участия', 'sample' => '1 500 ₽'],
            ['key' => 'start_date', 'label' => 'Дата начала', 'description' => 'Формат дд.мм.гггг', 'sample' => '15.11.2026'],
            ['key' => 'start_time', 'label' => 'Время начала', 'description' => 'Формат чч:мм', 'sample' => '10:00'],
            ['key' => 'end_date', 'label' => 'Дата окончания', 'description' => 'Формат дд.мм.гггг', 'sample' => '15.11.2026'],
            ['key' => 'end_time', 'label' => 'Время окончания', 'description' => 'Формат чч:мм', 'sample' => '18:00'],
            ['key' => 'speakers_text', 'label' => 'Спикеры (строкой)', 'description' => 'Спикеры одной строкой через точку с запятой', 'sample' => 'Иванов И. И., профессор'],
            ['key' => 'email', 'label' => 'Email подписчика', 'description' => 'Адрес подписчика из базы (рассылки)', 'sample' => 'anna@example.com'],
            ['key' => 'unsubscribe_url', 'label' => 'Ссылка отписки', 'description' => 'Есть в каждом письме. Если её нет в тексте, ссылка отписки добавится внизу сама и убрать её нельзя', 'sample' => 'https://example.com/unsubscribe/example'],
            ['key' => 'generated_at', 'label' => 'Дата создания', 'description' => 'Когда сформировано письмо (письмо про API)', 'sample' => '09.10.2026 18:30'],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }
}
