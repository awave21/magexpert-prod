<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Event extends Model
{
    use HasFactory;

    /**
     * Атрибуты, которые можно массово присваивать.
     * 
     * Все даты и время мероприятий сохраняются и обрабатываются 
     * в московском часовом поясе (Europe/Moscow).
     *
     * @var array<string>
     */
    protected $fillable = [
        'title',
        'slug',
        'start_date', // Дата начала (московское время)
        'start_time', // Время начала (московское время)
        'end_date',   // Дата окончания (московское время)
        'end_time',   // Время окончания (московское время)
        'is_on_demand',
        'event_type',
        'short_description',
        'full_description',
        'topic',
        'location',
        'price',
        'is_paid', // Платное мероприятие (независимо от цены)
        'show_price', // Показывать цену на фронтенде
        'format',
        'image',
        'registration_enabled',
        'category_id', // устарел, используем categories
        'is_active',
        'sort_order',
        'is_archived',
        'kinescope_id', // ID записи Кинескопа
        'kinescope_playlist_id', // ID плейлиста Кинескопа
        'kinescope_type', // Тип: 'video' или 'playlist'
        'is_live', // Транслируется ли событие в прямом эфире
        'letter_draft_id',
        'groupsensay', // Группа Sendsay
        'max_quantity', // Максимальное количество мест
    ];

    /**
     * Атрибуты, которые должны быть приведены к типам.
     * 
     * Даты сохраняются как простые даты без времени в московском часовом поясе.
     * Время сохраняется отдельно в полях start_time и end_time.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'registration_enabled' => 'boolean',
        'is_active' => 'boolean',
        'is_archived' => 'boolean',
        'is_on_demand' => 'boolean',
        'is_paid' => 'boolean',
        'show_price' => 'boolean',
        'price' => 'decimal:2',
        'is_live' => 'boolean',
        'max_quantity' => 'integer',
    ];

    /**
     * Получить категорию мероприятия (основную).
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
    
    /**
     * Получить все категории мероприятия.
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'event_categories')
            ->withTimestamps();
    }
    
    /**
     * Получить спикеров мероприятия.
     */
    public function speakers(): BelongsToMany
    {
        return $this->belongsToMany(Speaker::class)
            ->withPivot(['role', 'topic', 'sort_order'])
            ->withTimestamps();
    }
    
    /**
     * Пользователи, которые имеют доступ к мероприятию.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot([
                'access_type',
                'payment_amount',
                'payment_id',
                'payment_status',
                'access_granted_at',
                'access_expires_at',
                'is_active'
            ])
            ->withTimestamps();
    }
    
    /**
     * Проверить, имеет ли мероприятие запись Кинескопа.
     * 
     * @return bool
     */
    public function hasKinescopeRecord(): bool
    {
        return !empty($this->kinescope_id) || !empty($this->kinescope_playlist_id);
    }
    
    /**
     * Проверить, является ли контент Кинескопа плейлистом.
     * 
     * @return bool
     */
    public function isKinescopePlaylist(): bool
    {
        return $this->kinescope_type === 'playlist' && !empty($this->kinescope_playlist_id);
    }
    
    /**
     * Проверить, является ли контент Кинескопа видео.
     * 
     * @return bool
     */
    public function isKinescopeVideo(): bool
    {
        return $this->kinescope_type === 'video' && !empty($this->kinescope_id);
    }
    
    /**
     * Получить URL для встраивания записи Кинескопа.
     * 
     * @param string $size Размер плеера (default, compact, mini)
     * @return string|null
     */
    public function getKinescopeEmbedUrl(string $size = 'default'): ?string
    {
        if (!$this->hasKinescopeRecord()) {
            return null;
        }
        
        $sizeParam = match($size) {
            'compact' => '?compact=true',
            'mini' => '?mini=true',
            default => '',
        };
        
        // Если это плейлист
        if ($this->isKinescopePlaylist()) {
            return "https://kinescope.io/embed/pl/{$this->kinescope_playlist_id}{$sizeParam}";
        }
        
        // Если это видео
        if ($this->isKinescopeVideo()) {
            return "https://kinescope.io/embed/{$this->kinescope_id}{$sizeParam}";
        }
        
        return null;
    }
    
    /**
     * Получить тип контента Кинескопа для отображения.
     * 
     * @return string|null
     */
    public function getKinescopeTypeLabel(): ?string
    {
        return match($this->kinescope_type) {
            'video' => 'Видео',
            'playlist' => 'Плейлист',
            default => null,
        };
    }
    
    /**
     * Проверить, является ли мероприятие платным.
     * 
     * @return bool
     */
    public function isPaid(): bool
    {
        return $this->is_paid;
    }
    
    /**
     * Проверить, нужно ли показывать цену мероприятия.
     * 
     * @return bool
     */
    public function shouldShowPrice(): bool
    {
        return $this->show_price;
    }
    
    /**
     * Получить отформатированную цену мероприятия.
     * Возвращает null если цену не нужно показывать или она не установлена.
     * 
     * @return string|null
     */
    public function getFormattedPrice(): ?string
    {
        if (!$this->shouldShowPrice() || !$this->price) {
            return null;
        }
        
        return number_format($this->price, 0, '.', ' ') . ' ₽';
    }
    
    /**
     * Проверить, доступна ли регистрация на мероприятие.
     * Учитывает формат мероприятия, архивность и настройки регистрации.
     * 
     * @return bool
     */
    public function isRegistrationAvailable(): bool
    {
        // Если регистрация отключена - недоступна
        if (!$this->registration_enabled) {
            return false;
        }
        
        // Для архивных мероприятий регистрация доступна только если есть запись
        if ($this->is_archived) {
            return $this->hasKinescopeRecord();
        }
        
        // Для офлайн мероприятий регистрация недоступна если они уже прошли (и не архивные)
        if ($this->format === 'offline' && $this->start_date && $this->start_date->isPast()) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Получить статус платности для отображения.
     * 
     * @return string
     */
    public function getPaymentStatus(): string
    {
        return $this->isPaid() ? 'Платно' : 'Бесплатно';
    }
    
    /**
     * Проверить, имеет ли пользователь доступ к мероприятию.
     * 
     * @param User $user
     * @return bool
     */
    public function hasUserAccess(User $user): bool
    {
        return $this->users()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->where(function($query) {
                $query->whereNull('event_user.access_expires_at')
                      ->orWhere('event_user.access_expires_at', '>', now());

            })
            ->where(function($query) {
                // Универсальные правила доступа
                $query->where('event_user.payment_status', 'completed') // оплачено
                      ->orWhere('event_user.payment_status', 'free')     // бесплатное
                      ->orWhere('event_user.payment_status', 'unpaid');  // "по запросу"
            })
            ->exists();
    }
    
    /**
     * Получить тип доступа пользователя к мероприятию.
     * 
     * @param User $user
     * @return string|null
     */
    public function getUserAccessType(User $user): ?string
    {
        $pivot = $this->users()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->first()?->pivot;
            
        return $pivot?->access_type;
    }
    
    /**
     * Получить статус оплаты пользователя для мероприятия.
     * 
     * @param User $user
     * @return string|null
     */
    public function getUserPaymentStatus(User $user): ?string
    {
        $pivot = $this->users()
            ->where('users.id', $user->id)
            ->wherePivot('is_active', true)
            ->first()?->pivot;
            
        return $pivot?->payment_status;
    }
    
    /**
     * Получить читаемое название типа доступа.
     * 
     * @param string $accessType
     * @return string
     */
    public static function getAccessTypeLabel(string $accessType): string
    {
        return match($accessType) {
            'free' => 'Бесплатный',
            'paid' => 'Платный',
            'promotional' => 'Промо',
            'admin' => 'Админ',
            'invited' => 'По приглашению',
            default => $accessType,
        };
    }
    
    /**
     * Получить читаемое название статуса оплаты.
     * 
     * @param string $paymentStatus
     * @return string
     */
    public static function getPaymentStatusLabel(string $paymentStatus): string
    {
        return match($paymentStatus) {
            'free' => 'Бесплатно',
            'pending' => 'Ожидает оплаты',
            'completed' => 'Оплачено',
            'failed' => 'Ошибка оплаты',
            'refunded' => 'Возврат',
            default => $paymentStatus,
        };
    }
    
    /**
     * Проверить, идет ли мероприятие в данный момент (live).
     * Учитывает флаг is_live и временные рамки события.
     * 
     * ВАЖНО: Все вычисления времени выполняются в московском часовом поясе.
     * 
     * @return bool
     */
    public function isLive(): bool
    {
        // Если мероприятие неактивно или архивировано - не live
        if (!$this->is_active || $this->is_archived) {
            return false;
        }
        
        // Если флаг is_live явно установлен в false - не live
        if ($this->is_live === false) {
            return false;
        }
        
        // Если флаг is_live установлен в true, но нет времени события - используем только флаг
        if ($this->is_live === true) {
            // Если есть время события, проверяем временные рамки
            if ($this->start_date && $this->start_time) {
                return $this->isWithinEventTimeFrame();
            }
            // Если нет времени события, но флаг установлен - считаем live
            return true;
        }
        
        // Если флаг is_live не установлен (null), определяем по времени события
        if ($this->is_live === null) {
            return $this->isWithinEventTimeFrame();
        }
        
        return false;
    }
    
    /**
     * Проверить, находится ли текущее время в рамках события.
     * 
     * @return bool
     */
    private function isWithinEventTimeFrame(): bool
    {
        if (!$this->start_date || !$this->start_time) {
            return false;
        }
        
        $now = now();
        
        // Безопасное получение даты начала
        $startDateStr = is_string($this->start_date) ? $this->start_date : $this->start_date->format('Y-m-d');
        $startDateTime = $startDateStr . ' ' . $this->start_time;
        $start = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $startDateTime);
        
        // Если есть время окончания, используем его
        if ($this->end_date && $this->end_time) {
            $endDateStr = is_string($this->end_date) ? $this->end_date : $this->end_date->format('Y-m-d');
            $endDateTime = $endDateStr . ' ' . $this->end_time;
            $end = \Carbon\Carbon::createFromFormat('Y-m-d H:i:s', $endDateTime);
            
            return $now->between($start, $end);
        }
        
        // Если нет времени окончания, считаем что мероприятие идет 3 часа
        $end = $start->copy()->addHours(3);
        
        return $now->between($start, $end);
    }
    
    /**
     * Получить live мероприятия.
     * Учитывает флаг is_live и временные рамки событий.
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function live()
    {
        return static::query()->where('is_active', true)
            ->where('is_archived', false)
            ->where(function($query) {
                $now = now();
                
                // 1. Мероприятия с флагом is_live = true
                $query->where(function($q) use ($now) {
                    $q->where('is_live', true)
                      ->where(function($timeQ) use ($now) {
                          // Если есть время события, проверяем временные рамки
                          $timeQ->where(function($withTimeQ) use ($now) {
                              $withTimeQ->whereNotNull('start_date')
                                       ->whereNotNull('start_time')
                                       ->where(function($frameQ) use ($now) {
                                           // С указанным временем окончания
                                           $frameQ->where(function($endQ) use ($now) {
                                               $endQ->whereNotNull('end_date')
                                                   ->whereNotNull('end_time')
                                                   ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp <= ?", [$now])
                                                   ->whereRaw("(end_date::text || ' ' || end_time::text)::timestamp >= ?", [$now]);
                                           })
                                           // Без времени окончания (3 часа)
                                           ->orWhere(function($noEndQ) use ($now) {
                                               $noEndQ->where(function($nullEndQ) {
                                                   $nullEndQ->whereNull('end_date')->orWhereNull('end_time');
                                               })
                                               ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp <= ?", [$now])
                                               ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp + INTERVAL '3 hours' >= ?", [$now]);
                                           });
                                       });
                          })
                          // Или если нет времени события, но флаг установлен
                          ->orWhere(function($noTimeQ) {
                              $noTimeQ->whereNull('start_date')->orWhereNull('start_time');
                          });
                      });
                })
                // 2. Мероприятия с is_live = null, определяем по времени
                ->orWhere(function($q) use ($now) {
                    $q->whereNull('is_live')
                      ->whereNotNull('start_date')
                      ->whereNotNull('start_time')
                      ->where(function($timeQ) use ($now) {
                          // С указанным временем окончания
                          $timeQ->where(function($endQ) use ($now) {
                              $endQ->whereNotNull('end_date')
                                  ->whereNotNull('end_time')
                                  ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp <= ?", [$now])
                                  ->whereRaw("(end_date::text || ' ' || end_time::text)::timestamp >= ?", [$now]);
                          })
                          // Без времени окончания (3 часа)
                          ->orWhere(function($noEndQ) use ($now) {
                              $noEndQ->where(function($nullEndQ) {
                                  $nullEndQ->whereNull('end_date')->orWhereNull('end_time');
                              })
                              ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp <= ?", [$now])
                              ->whereRaw("(start_date::text || ' ' || start_time::text)::timestamp + INTERVAL '3 hours' >= ?", [$now]);
                          });
                      });
                });
                // Исключаем мероприятия с is_live = false
            })
            ->where(function($query) {
                $query->whereNull('is_live')->orWhere('is_live', '!=', false);
            });
    }
    
    /**
     * Получить предстоящие мероприятия (не архивные и не live).
     * 
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public static function upcoming()
    {
        return static::query()->where('is_active', true)
            ->where('is_archived', false)
            ->whereNotNull('start_date') // Исключаем мероприятия без даты
            ->where(function($query) {
                $now = now();
                
                $query->where(function($q) use ($now) {
                    // Мероприятия с датой начала в будущем
                    $q->whereRaw("(start_date::text || ' ' || COALESCE(start_time::text, '00:00:00'))::timestamp > ?", [$now]);
                })
                // ИЛИ мероприятия по запросу (только если у них есть дата)
                ->orWhere(function($q) {
                    $q->where('is_on_demand', true)
                      ->whereNotNull('start_date');
                });
            })
            ->orderBy('start_date', 'asc')
            ->orderBy('start_time', 'asc');
    }
    
    /**
     * Мутатор для start_date - сохраняем в московском времени
     * 
     * @param mixed $value
     * @return void
     */
    public function setStartDateAttribute($value)
    {
        if ($value) {
            // Если передана строка даты, сохраняем как есть (без конвертации часового пояса)
            $this->attributes['start_date'] = is_string($value) ? $value : $value->format('Y-m-d');
        } else {
            $this->attributes['start_date'] = null;
        }
    }
    
    /**
     * Мутатор для end_date - сохраняем в московском времени
     * 
     * @param mixed $value
     * @return void
     */
    public function setEndDateAttribute($value)
    {
        if ($value) {
            // Если передана строка даты, сохраняем как есть (без конвертации часового пояса)
            $this->attributes['end_date'] = is_string($value) ? $value : $value->format('Y-m-d');
        } else {
            $this->attributes['end_date'] = null;
        }
    }
    
    /**
     * Аксессор для start_date - возвращаем в формате Y-m-d для фронтенда
     * 
     * @param mixed $value
     * @return string|null
     */
    public function getStartDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
    }
    
    /**
     * Аксессор для end_date - возвращаем в формате Y-m-d для фронтенда
     * 
     * @param mixed $value
     * @return string|null
     */
    public function getEndDateAttribute($value)
    {
        return $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : null;
    }
}

