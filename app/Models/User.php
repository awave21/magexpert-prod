<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'email',
        'phone',
        'position',
        'specialization',
        'company',
        'avatar',
        'city',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Получить полное имя пользователя.
     *
     * @return string
     */
    public function getFullNameAttribute(): string
    {
        $parts = array_filter([
            $this->last_name,
            $this->first_name,
            $this->middle_name
        ]);
        
        return implode(' ', $parts);
    }

    /**
     * Роли, принадлежащие пользователю.
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /**
     * События, к которым у пользователя есть доступ.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)
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
     * Проверяет, имеет ли пользователь указанную роль.
     *
     * @param string $role
     * @return bool
     */
    public function hasRole(string $role): bool
    {
        return $this->roles()->where('name', $role)->exists();
    }

    /**
     * Проверяет, имеет ли пользователь любую из указанных ролей.
     *
     * @param array $roles
     * @return bool
     */
    public function hasAnyRole(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->exists();
    }

    /**
     * Проверяет, имеет ли пользователь все указанные роли.
     *
     * @param array $roles
     * @return bool
     */
    public function hasAllRoles(array $roles): bool
    {
        return $this->roles()->whereIn('name', $roles)->count() === count($roles);
    }

    /**
     * Назначает пользователю указанную роль.
     *
     * @param string $role
     * @return $this
     */
    public function assignRole(string $role): self
    {
        $role = Role::where('name', $role)->first();
        
        if ($role) {
            $this->roles()->syncWithoutDetaching([$role->id]);
        }
        
        return $this;
    }

    /**
     * Отношение к уведомлениям пользователя
     */
    public function notifications()
    {
        return $this->hasMany(Notification::class);
    }

    /**
     * Получить непрочитанные уведомления
     */
    public function unreadNotifications()
    {
        return $this->notifications()->where('read', false);
    }

    /**
     * Получить прочитанные уведомления
     */
    public function readNotifications()
    {
        return $this->notifications()->where('read', true);
    }

    /**
     * Получить события с активным доступом.
     * 
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function accessibleEvents(): BelongsToMany
    {
        return $this->events()
            ->wherePivot('is_active', true)
            ->where(function($query) {
                // Проверяем что доступ не истек
                $query->whereNull('event_user.access_expires_at')
                      ->orWhere('event_user.access_expires_at', '>', now());
            });
    }

    /**
     * Получить live мероприятия, к которым у пользователя есть доступ.
     * Использует обновленную логику с флагом is_live.
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getLiveEventsAttribute()
    {
        return $this->accessibleEvents()
            ->where('events.is_active', true)
            ->where('events.is_archived', false)
            ->where(function($query) {
                $now = now();
                
                // 1. Мероприятия с флагом is_live = true
                $query->where(function($q) use ($now) {
                    $q->where('events.is_live', true)
                      ->where(function($timeQ) use ($now) {
                          // Если есть время события, проверяем временные рамки
                          $timeQ->where(function($withTimeQ) use ($now) {
                              $withTimeQ->whereNotNull('events.start_date')
                                       ->whereNotNull('events.start_time')
                                       ->where(function($frameQ) use ($now) {
                                           // С указанным временем окончания
                                           $frameQ->where(function($endQ) use ($now) {
                                               $endQ->whereNotNull('events.end_date')
                                                   ->whereNotNull('events.end_time')
                                                   ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp <= ?", [$now])
                                                   ->whereRaw("(events.end_date::text || ' ' || events.end_time::text)::timestamp >= ?", [$now]);
                                           })
                                           // Без времени окончания (3 часа)
                                           ->orWhere(function($noEndQ) use ($now) {
                                               $noEndQ->where(function($nullEndQ) {
                                                   $nullEndQ->whereNull('events.end_date')->orWhereNull('events.end_time');
                                               })
                                               ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp <= ?", [$now])
                                               ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp + INTERVAL '3 hours' >= ?", [$now]);
                                           });
                                       });
                          })
                          // Или если нет времени события, но флаг установлен
                          ->orWhere(function($noTimeQ) {
                              $noTimeQ->whereNull('events.start_date')->orWhereNull('events.start_time');
                          });
                      });
                })
                // 2. Мероприятия с is_live = null, определяем по времени
                ->orWhere(function($q) use ($now) {
                    $q->whereNull('events.is_live')
                      ->whereNotNull('events.start_date')
                      ->whereNotNull('events.start_time')
                      ->where(function($timeQ) use ($now) {
                          // С указанным временем окончания
                          $timeQ->where(function($endQ) use ($now) {
                              $endQ->whereNotNull('events.end_date')
                                  ->whereNotNull('events.end_time')
                                  ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp <= ?", [$now])
                                  ->whereRaw("(events.end_date::text || ' ' || events.end_time::text)::timestamp >= ?", [$now]);
                          })
                          // Без времени окончания (3 часа)
                          ->orWhere(function($noEndQ) use ($now) {
                              $noEndQ->where(function($nullEndQ) {
                                  $nullEndQ->whereNull('events.end_date')->orWhereNull('events.end_time');
                              })
                              ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp <= ?", [$now])
                              ->whereRaw("(events.start_date::text || ' ' || events.start_time::text)::timestamp + INTERVAL '3 hours' >= ?", [$now]);
                          });
                      });
                });
                // Исключаем мероприятия с is_live = false
            })
            ->where(function($query) {
                $query->whereNull('events.is_live')->orWhere('events.is_live', '!=', false);
            })
            ->with([
                'category',
                'categories' => function($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'speakers' => function($query) {
                    $query->where('is_active', true)->orderBy('pivot_sort_order', 'asc');
                }
            ])
            ->orderBy('events.start_date', 'asc')
            ->orderBy('events.start_time', 'asc')
            ->get();
    }

    /**
     * Получить предстоящие мероприятия, к которым у пользователя есть доступ.
     * 
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getUpcomingEventsAttribute()
    {
        return $this->accessibleEvents()
            ->where('events.is_active', true)
            ->where('events.is_archived', false)
            ->whereNotNull('events.start_date')
            ->where(function($query) {
                $now = now();
                
                $query->where(function($q) use ($now) {
                    // Мероприятия с датой начала в будущем
                    $q->whereRaw("(events.start_date::text || ' ' || COALESCE(events.start_time::text, '00:00:00'))::timestamp > ?", [$now]);
                })
                // ИЛИ мероприятия по запросу (только если у них есть дата)
                ->orWhere(function($q) {
                    $q->where('events.is_on_demand', true)
                      ->whereNotNull('events.start_date');
                });
            })
            ->with([
                'category',
                'categories' => function($query) {
                    $query->where('is_active', true)->orderBy('sort_order');
                },
                'speakers' => function($query) {
                    $query->where('is_active', true)->orderBy('pivot_sort_order', 'asc');
                }
            ])
            ->orderBy('events.start_date', 'asc')
            ->orderBy('events.start_time', 'asc')
            ->get();
    }

    /**
     * Проверить, имеет ли пользователь доступ к конкретному мероприятию.
     * 
     * @param Event $event
     * @return bool
     */
    public function hasAccessToEvent(Event $event): bool
    {
        return $this->accessibleEvents()->where('events.id', $event->id)->exists();
    }

    /**
     * Получить тип доступа к мероприятию.
     * 
     * @param Event $event
     * @return string|null
     */
    public function getEventAccessType(Event $event): ?string
    {
        $pivot = $this->events()->where('events.id', $event->id)->first()?->pivot;
        return $pivot?->access_type;
    }

    /**
     * Получить статус оплаты для мероприятия.
     * 
     * @param Event $event
     * @return string|null
     */
    public function getEventPaymentStatus(Event $event): ?string
    {
        $pivot = $this->events()->where('events.id', $event->id)->first()?->pivot;
        return $pivot?->payment_status;
    }
}
