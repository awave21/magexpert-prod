<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;
    
    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_active',
        'sort_order'
    ];
    
    /**
     * Атрибуты, которые должны быть приведены к типам.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'is_active' => 'boolean',
    ];
    
    /**
     * События, принадлежащие к категории.
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
    
    /**
     * Возвращает количество активных событий в категории.
     *
     * @return int
     */
    public function getActiveEventsCountAttribute(): int
    {
        return $this->events()->where('is_active', true)->count();
    }
}
