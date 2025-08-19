<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Speaker extends Model
{
    use HasFactory;
    
    /**
     * Атрибуты, которые можно массово присваивать.
     *
     * @var array<string>
     */
    protected $fillable = [
        'first_name',
        'last_name',
        'middle_name',
        'photo',
        'regalia',
        'description',
        'position',
        'company',
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
     * Атрибуты, которые должны быть добавлены к массивам модели.
     *
     * @var array
     */
    protected $appends = ['full_name', 'short_name'];
    
    /**
     * События, на которых выступает спикер.
     */
    public function events(): BelongsToMany
    {
        return $this->belongsToMany(Event::class)
            ->withPivot(['role', 'topic', 'sort_order'])
            ->withTimestamps();
    }
    
    /**
     * Получить полное имя спикера.
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
     * Получить короткое имя спикера (фамилия и инициалы).
     *
     * @return string
     */
    public function getShortNameAttribute(): string
    {
        $result = $this->last_name;
        
        if ($this->first_name) {
            $result .= ' ' . mb_substr($this->first_name, 0, 1) . '.';
        }
        
        if ($this->middle_name) {
            $result .= ' ' . mb_substr($this->middle_name, 0, 1) . '.';
        }
        
        return $result;
    }
}
