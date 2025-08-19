<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class DaDataController extends Controller
{
    /**
     * Поиск городов через DaData API
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function searchCities(Request $request): JsonResponse
    {
        // Валидация входных данных
        $validator = Validator::make($request->all(), [
            'query' => 'required|string|min:2|max:100',
        ], [
            'query.required' => 'Поисковый запрос обязателен',
            'query.min' => 'Минимальная длина запроса 2 символа',
            'query.max' => 'Максимальная длина запроса 100 символов',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'error' => 'Некорректные данные',
                'details' => $validator->errors()
            ], 422);
        }

        $query = $request->input('query');
        
        // Проверяем наличие API ключей
        $token = config('services.dadata.token');
        $secret = config('services.dadata.secret');
        
        if (!$token || !$secret) {
            Log::error('DaData API ключи не настроены');
            return response()->json([
                'success' => false,
                'error' => 'Сервис временно недоступен'
            ], 500);
        }

        try {
            // Запрос к DaData API
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => "Token {$token}",
                'X-Secret' => $secret,
            ])->timeout(10)->post('https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address', [
                'query' => $query,
                'locations' => [['country' => 'Россия']],
                'restrict_value' => true,
                'from_bound' => ['value' => 'city'],
                'to_bound' => ['value' => 'city'],
                'count' => 10
            ]);

            if ($response->successful()) {
                $data = $response->json();
                
                // Обрабатываем результаты
                $suggestions = collect($data['suggestions'] ?? [])->map(function ($item) {
                    return [
                        'value' => $item['data']['city'] ?? $item['value'] ?? '',
                        'full_address' => $item['value'] ?? '',
                        'region' => $item['data']['region_with_type'] ?? '',
                        'city' => $item['data']['city_with_type'] ?? $item['data']['settlement_with_type'] ?? '',
                        'fias_id' => $item['data']['city_fias_id'] ?? null,
                    ];
                })->filter(function ($item) {
                    return !empty($item['value']); // Убираем пустые значения
                })->values();

                return response()->json([
                    'success' => true,
                    'suggestions' => $suggestions
                ]);
            } else {
                Log::error('DaData API error', [
                    'status' => $response->status(),
                    'body' => $response->body()
                ]);
                
                return response()->json([
                    'success' => false,
                    'error' => 'Ошибка при обращении к сервису поиска'
                ], 500);
            }
        } catch (\Exception $e) {
            Log::error('DaData API exception', [
                'message' => $e->getMessage(),
                'query' => $query
            ]);
            
            return response()->json([
                'success' => false,
                'error' => 'Ошибка при поиске городов'
            ], 500);
        }
    }
}