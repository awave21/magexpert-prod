<?php

namespace App\Services;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;

class SendsayService
{
    protected string $apiUrl;
    protected string $login;
    protected string $sublogin;
    protected string $password;
    protected ?string $token = null;
    
    // Список email адресов руководителей (будет настраиваться через конфиг)
    protected array $managerEmails = [];

    public function __construct()
    {
        $this->apiUrl = config('sendsay.api_url');
        $this->login = config('sendsay.login');
        $this->sublogin = config('sendsay.sublogin');
        $this->password = config('sendsay.password');
        $this->managerEmails = config('sendsay.manager_emails', []);
    }

    /**
     * Перевод типа мероприятия с английского на русский
     *
     * @param string $eventType
     * @return string
     */
    protected function translateEventType(string $eventType): string
    {
        $types = [
            'webinar' => 'Вебинар',
            'conference' => 'Конференция',
            'course' => 'Курс',
            'workshop' => 'Мастер-класс',
            'seminar' => 'Семинар',
            'other' => 'Другое',
        ];

        return $types[$eventType] ?? $eventType;
    }

    /**
     * Перевод формата мероприятия с английского на русский
     *
     * @param string $format
     * @return string
     */
    protected function translateFormat(string $format): string
    {
        $formats = [
            'online' => 'Онлайн',
            'offline' => 'Офлайн',
            'hybrid' => 'Гибридный',
        ];

        return $formats[$format] ?? $format;
    }

    /**
     * Логирование в отдельный канал Sendsay
     *
     * @param string $level Уровень логирования (debug, info, warning, error)
     * @param string $message Сообщение
     * @param array $context Контекст
     * @return void
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel('sendsay')->$level($message, $context);
    }

    /**
     * Маскирует чувствительные данные в массивах для логирования.
     */
    protected function sanitizeSensitiveData(array $data): array
    {
        $sensitiveKeys = ['password', 'passwd'];

        $sanitize = function ($value) use (&$sanitize, $sensitiveKeys) {
            if (is_array($value)) {
                $result = [];
                foreach ($value as $k => $v) {
                    if (is_string($k) && in_array(strtolower($k), $sensitiveKeys, true)) {
                        $result[$k] = '[СКРЫТ]';
                    } else {
                        $result[$k] = $sanitize($v);
                    }
                }
                return $result;
            }
            return $value;
        };

        return $sanitize($data);
    }

    /**
     * Получение токена Sendsay API
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        try {
            $loginData = [
                'action' => 'login',
                'login' => $this->login,
                'sublogin' => $this->sublogin,
                'passwd' => $this->password
            ];

            $this->log('debug', 'Отправляем запрос для получения токена Sendsay', [
                'api_url' => $this->apiUrl,
                'login' => $this->login,
                'sublogin' => $this->sublogin,
                'request_data' => array_merge($loginData, ['passwd' => '[СКРЫТ]']) // Не логируем пароль
            ]);

            $response = Http::post($this->apiUrl, $loginData);

            $data = $response->json();
            $this->log('info', 'Ответ при получении токена Sendsay', ['response' => $data]);

            if (isset($data['session'])) {
                $this->token = $data['session'];
                return $this->token;
            }

            $this->log('error', 'Ошибка получения токена Sendsay', ['response' => $data]);
            return null;
        } catch (Exception $e) {
            $this->log('error', 'Исключение при получении токена Sendsay', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Проверка валидности токена и его обновление при необходимости
     *
     * @return bool
     */
    protected function ensureTokenValid(): bool
    {
        if (!$this->token) {
            return $this->getToken() !== null;
        }
        
        return true;
    }

    /**
     * Добавление/обновление пользователя в Sendsay (member.set)
     *
     * @param string $email Email пользователя
     * @param array $customFields Кастомные поля (obj.custom)
     * @param bool|null $mailingConsent Согласие на рассылку (custom.mailing). Если null — поле не добавляется
     * @param string|null $groupId ID группы (obj.-group[ID] = '1')
     * @param array $baseFields Базовые поля (obj.base), например ['firstName' => 'Иван']
     * @return bool
     */
    public function addMember(string $email, array $customFields = [], ?bool $mailingConsent = null, ?string $groupId = null, array $baseFields = []): bool
    {
        try {
            if (!$this->ensureTokenValid()) {
                return false;
            }

            // Кастомные поля. mailing добавляем только если передан флаг согласия
            $custom = $customFields;
            if ($mailingConsent !== null) {
                $custom = array_merge(['mailing' => $mailingConsent ? 'Да' : 'Нет'], $customFields);
            }
            
            $memberData = [
                'action' => 'member.set',
                'email' => strtolower($email),
                'newbie.confirm' => '0',
                'obj' => [
                    // Базовые поля (если переданы)
                    // firstName и т.п. сохраняем в base
                    // Если пусто — не отправляем ключ
                    'custom' => $custom
                ]
            ];

            if (!empty($baseFields)) {
                $memberData['obj']['base'] = $baseFields;
            }

            // Добавляем группу если указана
            if ($groupId) {
                $memberData['obj']['-group'] = [$groupId => '1'];
            }

            // Логируем отправляемые данные (пароль маскируем)
            $this->log('debug', 'Отправляем данные для добавления пользователя в Sendsay', [
                'email' => $email,
                'mailing_consent' => $mailingConsent,
                'group_id' => $groupId,
                'custom_fields' => $this->sanitizeSensitiveData($customFields),
                'request_data' => $this->sanitizeSensitiveData($memberData)
            ]);
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'sendsay session="' . $this->token . '"'
            ])->post($this->apiUrl, $memberData);

            $data = $response->json();
            $this->log('info', 'Ответ Sendsay при добавлении пользователя', [
                'email' => $email,
                'response' => $data
            ]);

            return $response->successful();
        } catch (Exception $e) {
            $this->log('error', 'Исключение при добавлении пользователя в Sendsay', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Отправка письма по шаблону
     *
     * @param string $email Email получателя
     * @param int $draftId ID черновика письма в Sendsay
     * @param array $variables Переменные для подстановки в шаблон
     * @param array $extraData Дополнительные данные
     * @return bool
     */
    public function sendEmail(string $email, int $draftId, array $variables = [], array $extraData = []): bool
    {
        try {
            if (!$this->ensureTokenValid()) {
                return false;
            }

            $requestData = [
                'action' => 'issue.send',
                'sendwhen' => 'now',
                'letter' => ['draft.id' => $draftId],
                'email' => strtolower($email),
                'group' => 'personal'
            ];

            // Добавляем переменные если есть
            if (!empty($variables)) {
                $requestData['vars'] = $variables;
            }

            // Добавляем дополнительные данные если есть
            if (!empty($extraData)) {
                $requestData['extra'] = $extraData;
            }

            // Готовим безопасные для логирования данные
            $safeVariables = $this->sanitizeSensitiveData($variables);
            $safeExtra = $this->sanitizeSensitiveData($extraData);
            $safeRequestData = $this->sanitizeSensitiveData($requestData);

            $this->log('debug', 'Отправляем письмо через Sendsay', [
                'email' => $email,
                'draft_id' => $draftId,
                'variables' => $safeVariables,
                'extra_data' => $safeExtra,
                'full_request_data' => $safeRequestData
            ]);
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'sendsay session="' . $this->token . '"'
            ])->post($this->apiUrl, $requestData);

            $data = $response->json();
            $this->log('info', 'Ответ Sendsay при отправке письма', [
                'email' => $email,
                'draft_id' => $draftId,
                'response' => $data
            ]);

            return $response->successful();
        } catch (Exception $e) {
            $this->log('error', 'Исключение при отправке письма через Sendsay', [
                'email' => $email,
                'draft_id' => $draftId,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Массовая отправка писем по списку email адресов
     *
     * @param array $emails Массив email адресов
     * @param int $draftId ID черновика письма в Sendsay
     * @param array $variables Переменные для подстановки в шаблон
     * @param array $extraData Дополнительные данные
     * @return array Результат отправки для каждого email ['email' => bool]
     */
    public function sendBulkEmails(array $emails, int $draftId, array $variables = [], array $extraData = []): array
    {
        $results = [];
        
        foreach ($emails as $email) {
            $results[$email] = $this->sendEmail($email, $draftId, $variables, $extraData);
            
            // Небольшая задержка между отправками для избежания rate limiting
            usleep(100000); // 0.1 секунды
        }
        
        return $results;
    }

    /**
     * Отправка уведомлений руководителям
     *
     * @param int $draftId ID черновика письма в Sendsay
     * @param array $variables Переменные для подстановки в шаблон
     * @param array $extraData Дополнительные данные
     * @return bool Возвращает true, если хотя бы одно письмо успешно отправлено
     */
    public function sendNotificationToManagers(int $draftId, array $variables = [], array $extraData = []): bool
    {
        if (empty($this->managerEmails)) {
            $this->log('warning', 'Список email руководителей пуст. Уведомление не отправлено.', [
                'draft_id' => $draftId
            ]);
            return false;
        }

        $this->log('info', 'Начало отправки уведомления руководителям', [
            'manager_emails' => $this->managerEmails,
            'draft_id' => $draftId
        ]);

        $results = $this->sendBulkEmails($this->managerEmails, $draftId, $variables, $extraData);
        
        // Проверяем, было ли отправлено хотя бы одно письмо успешно
        $successCount = array_sum($results);
        $overallSuccess = $successCount > 0;
        
        $this->log('info', 'Результат отправки уведомлений руководителям', [
            'success_count' => $successCount,
            'total_count' => count($this->managerEmails),
            'overall_success' => $overallSuccess,
            'results' => $results
        ]);

        return $overallSuccess;
    }

    /**
     * Получение информации о пользователе из Sendsay
     *
     * @param string $email
     * @return array|null
     */
    public function getMemberInfo(string $email): ?array
    {
        try {
            if (!$this->ensureTokenValid()) {
                return null;
            }
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'sendsay session="' . $this->token . '"'
            ])->post($this->apiUrl, [
                'action' => 'member.get',
                'email' => strtolower($email)
            ]);

            $data = $response->json();
            $this->log('debug', 'Информация о пользователе из Sendsay', [
                'email' => $email,
                'response' => $data
            ]);

            return $response->successful() ? $data : null;
        } catch (Exception $e) {
            $this->log('error', 'Исключение при получении информации о пользователе из Sendsay', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Удаление пользователя из Sendsay
     *
     * @param string $email
     * @return bool
     */
    public function deleteMember(string $email): bool
    {
        try {
            if (!$this->ensureTokenValid()) {
                return false;
            }
            
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => 'sendsay session="' . $this->token . '"'
            ])->post($this->apiUrl, [
                'action' => 'member.delete',
                'email' => strtolower($email)
            ]);

            $data = $response->json();
            $this->log('info', 'Результат удаления пользователя из Sendsay', [
                'email' => $email,
                'response' => $data
            ]);

            return $response->successful();
        } catch (Exception $e) {
            $this->log('error', 'Исключение при удалении пользователя из Sendsay', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    // ========================================
    // СПЕЦИАЛИЗИРОВАННЫЕ МЕТОДЫ ДЛЯ ОТПРАВКИ ПИСЕМ
    // ========================================

    /**
     * Отправка приветственного письма новому пользователю
     *
     * @param string $email
     * @param string $name Имя пользователя
     * @param array $additionalData Дополнительные данные
     * @return bool
     */
    public function sendWelcomeEmail(string $email, string $name = '', array $additionalData = []): bool
    {
        $draftId = config('sendsay.draft_ids.welcome');
        
        if (!$draftId) {
            $this->log('error', 'ID черновика приветственного письма не настроен в конфиге');
            return false;
        }

        $variables = array_merge([
            'name' => $name,
        ], $additionalData);

        return $this->sendEmail($email, $draftId, $variables);
    }

    /**
     * Отправка письма с восстановлением пароля
     *
     * @param string $email
     * @param string $newPassword Новый пароль
     * @param string $name Имя пользователя
     * @return bool
     */
    public function sendPasswordResetEmail(string $email, string $newPassword, string $name = ''): bool
    {
        $draftId = config('sendsay.draft_ids.password_reset');
        
        if (!$draftId) {
            $this->log('error', 'ID черновика письма восстановления пароля не настроен в конфиге');
            return false;
        }

        // Переменные для шаблона можно оставить пустыми
        $variables = [];

        // В extra отправляем email пользователя, новый пароль и имя
        $extraData = [
            'user_email' => strtolower($email),
            'password' => $newPassword,
            'name' => $name,
        ];

        return $this->sendEmail($email, $draftId, $variables, $extraData);
    }

    /**
     * Отправка промо письма
     *
     * @param string $email
     * @param string $promoCode Промокод (опционально)
     * @param array $additionalData Дополнительные данные
     * @return bool
     */
    public function sendPromotionalEmail(string $email, string $promoCode = '', array $additionalData = []): bool
    {
        $draftId = config('sendsay.draft_ids.promotional');
        
        if (!$draftId) {
            $this->log('error', 'ID черновика промо письма не настроен в конфиге');
            return false;
        }

        $variables = array_merge([
            'promo_code' => $promoCode,
        ], $additionalData);

        return $this->sendEmail($email, $draftId, $variables);
    }

    /**
     * Отправка письма с подарочным сертификатом
     *
     * @param string $email
     * @param string $certificateCode Код сертификата
     * @param float|int $certificateBalance Баланс сертификата
     * @param string $recipientName Имя получателя
     * @return bool
     */
    public function sendGiftCertificateEmail(string $email, string $certificateCode, float|int $certificateBalance, string $recipientName = ''): bool
    {
        $draftId = config('sendsay.draft_ids.gift_certificate');
        
        if (!$draftId) {
            $this->log('error', 'ID черновика письма с сертификатом не настроен в конфиге');
            return false;
        }

        // Форматируем баланс для отображения
        $formattedBalance = number_format(floor($certificateBalance), 0, '.', '');

        $extraData = [
            'cert_code' => $certificateCode,
            'cert_balance' => $formattedBalance,
            'recipient_name' => $recipientName,
        ];

        $this->log('debug', 'Подготовлены данные для письма с подарочным сертификатом', [
            'email' => $email,
            'certificate_code' => $certificateCode,
            'certificate_balance' => $formattedBalance,
            'recipient_name' => $recipientName,
            'extra_data' => $extraData
        ]);

        return $this->sendEmail($email, $draftId, [], $extraData);
    }

    /**
     * Регистрация пользователя с отправкой приветственного письма
     *
     * @param string $name Имя пользователя
     * @param string $email Email пользователя
     * @param string $password Пароль пользователя (опционально)
     * @param bool $mailingConsent Согласие на рассылку
     * @param bool $sendWelcomeEmail Отправлять ли приветственное письмо
     * @return bool
     */
    public function registerUserWithWelcome(string $name, string $email, string $password = '', bool $mailingConsent = true, bool $sendWelcomeEmail = true): bool
    {
        // Подготавливаем кастомные поля
        $customFields = [
            'firstName' => $name,
        ];

        if ($password) {
            $customFields['password'] = $password;
        }

        // Добавляем пользователя в базу Sendsay
        $groupId = config('sendsay.groups.customers');
        
        $this->log('debug', 'Регистрация пользователя с приветственным письмом', [
            'email' => $email,
            'name' => $name,
            'has_password' => !empty($password),
            'mailing_consent' => $mailingConsent,
            'send_welcome_email' => $sendWelcomeEmail,
            'group_id' => $groupId,
            'custom_fields' => $customFields
        ]);
        
        $memberAdded = $this->addMember($email, $customFields, $mailingConsent, $groupId);

        if (!$memberAdded) {
            $this->log('error', 'Не удалось добавить пользователя в Sendsay', [
                'email' => $email,
                'name' => $name
            ]);
            return false;
        }

        // Отправляем приветственное письмо если требуется
        if ($sendWelcomeEmail) {
            $welcomeSent = $this->sendWelcomeEmail($email, $name);
            
            if (!$welcomeSent) {
                $this->log('warning', 'Пользователь добавлен, но приветственное письмо не отправлено', [
                    'email' => $email,
                    'name' => $name
                ]);
            }
        }

        $this->log('info', 'Пользователь успешно зарегистрирован в Sendsay', [
            'email' => $email,
            'name' => $name,
            'welcome_email_sent' => $sendWelcomeEmail
        ]);

        return true;
    }

    /**
     * Отправка письма о регистрации на мероприятие
     *
     * @param \App\Models\Event $event Мероприятие
     * @param \App\Models\User $user Пользователь
     * @param string $password Пароль пользователя (если сгенерирован)
     * @param bool $isNewUser Новый ли это пользователь
     * @return bool
     */
    public function sendEventRegistrationEmail($event, $user, string $password = '', bool $isNewUser = false): bool
    {
        $draftId = config('sendsay.draft_ids.event_registration');
        
        if (!$draftId) {
            $this->log('error', 'ID черновика письма о регистрации на мероприятие не настроен в конфиге');
            return false;
        }

        // Подготавливаем данные о пользователе
        $userName = trim($user->first_name . ' ' . $user->last_name);
        $firstName = '';
        if (!empty($user->first_name)) {
            $firstName = $user->first_name;
        } else {
            $firstName = trim(explode(' ', $userName)[0] ?? '');
        }
        
        // Подготавливаем данные о мероприятии (только заполненные поля) - все в extraData
        $eventData = [
            'event_title' => $event->title,
            'is_paid' => $event->isPaid(),
        ];

        // Добавляем опциональные поля только если они заполнены (переводим на русский)
        if (!empty($event->event_type)) {
            $eventData['event_type'] = $this->translateEventType($event->event_type);
        }
        
        if (!empty($event->format)) {
            $eventData['event_format'] = $this->translateFormat($event->format);
        }
        
        if (!empty($event->location)) {
            $eventData['event_location'] = $event->location;
        }
        
        $formattedPrice = $event->getFormattedPrice();
        if ($formattedPrice) {
            $eventData['price'] = $formattedPrice;
        }

        // Добавляем даты и время только для неархивных мероприятий и только если они заполнены
        if (!$event->is_archived) {
            if ($event->start_date) {
                // Проверяем, является ли start_date объектом Carbon или строкой
                if (is_string($event->start_date)) {
                    $eventData['start_date'] = $event->start_date;
                } else {
                    $eventData['start_date'] = $event->start_date->format('d.m.Y');
                }
            }
            if ($event->start_time) {
                $eventData['start_time'] = $event->start_time;
            }
            if ($event->end_date) {
                // Проверяем, является ли end_date объектом Carbon или строкой
                if (is_string($event->end_date)) {
                    $eventData['end_date'] = $event->end_date;
                } else {
                    $eventData['end_date'] = $event->end_date->format('d.m.Y');
                }
            }
            if ($event->end_time) {
                $eventData['end_time'] = $event->end_time;
            }
        }

        // Подготавливаем данные о спикерах (только заполненные поля)
        $speakers = [];
        foreach ($event->speakers as $speaker) {
            $speakerData = [
                'name' => $speaker->full_name,
            ];

            // Добавляем опциональные поля только если они заполнены
            if (!empty($speaker->position)) {
                $speakerData['position'] = $speaker->position;
            }
            
            if (!empty($speaker->company)) {
                $speakerData['company'] = $speaker->company;
            }
            
            if (!empty($speaker->regalia)) {
                $speakerData['regalia'] = $speaker->regalia;
            }

            // Добавляем информацию из pivot (роль и тема на мероприятии) только если заполнены
            if ($speaker->pivot) {
                if (!empty($speaker->pivot->role)) {
                    $speakerData['role'] = $speaker->pivot->role;
                }
                if (!empty($speaker->pivot->topic)) {
                    $speakerData['topic'] = $speaker->pivot->topic;
                }
            }

            $speakers[] = $speakerData;
        }

        // Переменные для шаблона (оставляем пустыми, все данные в extraData)
        $variables = [];

        // Подготавливаем дополнительные данные - все данные здесь
        $extraData = array_merge([
            'user_name' => $userName,
            'user_email' => $user->email,
            'is_new_user' => $isNewUser,
            'event_slug' => $event->slug,
            'tag' => $event->id,
        ], $eventData);

        // Добавляем пароль только если он есть и не пустой
        if (!empty($password)) {
            $extraData['password'] = $password;
        }

        // Добавляем спикеров только если они есть
        if (!empty($speakers)) {
            $extraData['speakers'] = $speakers;
        }

        // Дополнительно: добавляем/обновляем участника в Sendsay с назначением группы
        // Берем группу из поля события groupsensay, иначе используем pl4344
        $groupIdForMember = $event->groupsensay ?: 'pl4344';
        $memberCustomFields = [
            'event_title' => $event->title,
        ];
        $memberBaseFields = [];
        if ($firstName !== '') {
            $memberBaseFields['firstName'] = $firstName;
        }

        $memberSetOk = $this->addMember(
            $user->email,
            $memberCustomFields,
            null,
            $groupIdForMember,
            $memberBaseFields
        );

        $this->log('info', 'Результат member.set при регистрации на мероприятие', [
            'email' => $user->email,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'group_id' => $groupIdForMember,
            'success' => $memberSetOk,
        ]);

        $this->log('debug', 'Подготовлены данные для письма о регистрации на мероприятие', [
            'email' => $user->email,
            'event_id' => $event->id,
            'event_title' => $event->title,
            'is_new_user' => $isNewUser,
            'has_password' => !empty($password),
            'speakers_count' => count($speakers),
            'variables_empty' => empty($variables),
            'extra_data' => $extraData
        ]);

        return $this->sendEmail($user->email, $draftId, $variables, $extraData);
    }
}