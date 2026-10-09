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
            ['key' => 'event_title', 'label' => 'Название мероприятия', 'description' => 'Заголовок мероприятия', 'sample' => 'Сложный пациент 2026'],
            ['key' => 'event_url', 'label' => 'Ссылка на мероприятие', 'description' => 'Адрес страницы мероприятия на сайте', 'sample' => 'https://mag-expert.ru/events/example'],
            ['key' => 'event_type', 'label' => 'Тип мероприятия', 'description' => 'Вебинар, конференция, курс и т.д.', 'sample' => 'Вебинар'],
            ['key' => 'event_format', 'label' => 'Формат', 'description' => 'Онлайн, офлайн или гибрид', 'sample' => 'Онлайн'],
            ['key' => 'event_location', 'label' => 'Место проведения', 'description' => 'Адрес или площадка (для офлайн)', 'sample' => 'Москва, ул. Рабочая, 93с1'],
            ['key' => 'price', 'label' => 'Стоимость', 'description' => 'Цена участия', 'sample' => '1 500 ₽'],
            ['key' => 'start_date', 'label' => 'Дата начала', 'description' => 'Формат дд.мм.гггг', 'sample' => '15.11.2026'],
            ['key' => 'start_time', 'label' => 'Время начала', 'description' => 'Формат чч:мм', 'sample' => '10:00'],
            ['key' => 'end_date', 'label' => 'Дата окончания', 'description' => 'Формат дд.мм.гггг', 'sample' => '15.11.2026'],
            ['key' => 'end_time', 'label' => 'Время окончания', 'description' => 'Формат чч:мм', 'sample' => '18:00'],
            ['key' => 'speakers', 'label' => 'Спикеры', 'description' => 'Список спикеров через точку с запятой', 'sample' => 'Иванов И. И., профессор'],
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
