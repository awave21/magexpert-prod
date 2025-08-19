<?php

namespace App\Traits;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

trait ManagesAvatars
{
    /**
     * Обрабатывает и сохраняет аватар пользователя
     *
     * @param User $user
     * @param UploadedFile $avatarFile
     * @return void
     */
    protected function processAndSaveAvatar(User $user, UploadedFile $avatarFile): void
    {
        // Удаляем старый аватар, если он существует и это не http ссылка
        if ($user->avatar && !str_starts_with($user->avatar, 'http')) {
            $oldAvatarPath = str_replace('/storage/', '', $user->avatar);
            if (Storage::disk('public')->exists($oldAvatarPath)) {
                Storage::disk('public')->delete($oldAvatarPath);
            }
        }

        // Создаем директорию для пользователя, если она не существует
        $userDir = "users/{$user->id}";
        Storage::disk('public')->makeDirectory($userDir);

        // Создаем менеджер изображений с драйвером GD
        $manager = new ImageManager(new Driver());

        // Читаем и конвертируем изображение в WebP
        $img = $manager->read($avatarFile)->toWebp(90); // 90 - качество
        
        // Сохраняем изображение
        $fileName = time() . '_' . uniqid() . '.webp';
        $path = $userDir . '/' . $fileName;
        
        Storage::disk('public')->put($path, (string) $img);
        
        // Обновляем путь к аватару в модели
        $user->avatar = '/storage/' . $path;
        $user->save();
    }
} 