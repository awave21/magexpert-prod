<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Carbon\Carbon;

class WelcomeController extends Controller
{
    /**
     * Отображает главную страницу
     *
     * @return \Inertia\Response
     */
    public function index()
    {
        // Получаем ближайшее мероприятие
        $upcomingEvent = Event::query()
            ->with(['category'])
            ->where('is_active', true)
            ->where('is_archived', false)
            ->where('registration_enabled', true)
            ->where(function($query) {
                // Ищем мероприятия, которые еще не прошли
                $query->where('start_date', '>=', Carbon::today())
                      ->orWhere('is_on_demand', true);
            })
            ->orderBy('start_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->first();

        return Inertia::render('Welcome', [
            'canLogin' => Route::has('login'),
            'canRegister' => Route::has('register'),
            'upcomingEvent' => $upcomingEvent ? [
                'id' => $upcomingEvent->id,
                'title' => $upcomingEvent->title,
                'slug' => $upcomingEvent->slug,
                'start_date' => $upcomingEvent->start_date ? Carbon::parse($upcomingEvent->start_date)->format('d.m.Y') : null,
                'start_time' => $upcomingEvent->start_time,
                'location' => $upcomingEvent->location,
                'format' => $upcomingEvent->format,
                'image' => $upcomingEvent->image,
                'category' => $upcomingEvent->category?->name,
                'short_description' => $upcomingEvent->short_description,
                'is_on_demand' => $upcomingEvent->is_on_demand,
            ] : null,
        ]);
    }
} 