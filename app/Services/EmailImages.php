<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Throwable;

/**
 * Картинки для писем. Почтовые программы растягивают фото под размеры из шаблона (object-fit там работает не везде),
 * а Outlook для Windows не показывает WebP. Поэтому в письмо уходит квадратная JPEG-копия нужного размера.
 */
class EmailImages
{
    /**
     * Квадратная копия картинки с сайта (/storage/…): вертикальное фото обрезается снизу, чтобы не срезать голову,
     * остальные — по центру. Копия делается один раз и лежит рядом с оригиналом; новый файл фото — новая копия.
     * Внешние ссылки, отсутствующие файлы и ошибки обработки — исходный адрес: письмо не должно ломаться из-за картинки.
     */
    public function square(?string $path, int $size = 192): ?string
    {
        if (blank($path) || preg_match('~^https?://~i', $path) || ! str_starts_with($path, '/storage/')) {
            return $path;
        }

        $disk = Storage::disk('public');
        $source = substr($path, strlen('/storage/'));

        if (str_contains($source, '..') || ! $disk->exists($source)) {
            return $path;
        }

        $target = trim(dirname($source), '/.').'/email/'.pathinfo($source, PATHINFO_FILENAME)
            .'-'.$size.'-'.substr(md5($source.'|'.$disk->lastModified($source)), 0, 8).'.jpg';

        if ($disk->exists($target)) {
            return '/storage/'.ltrim($target, '/');
        }

        try {
            $image = (new ImageManager(new Driver))->read($disk->get($source));
            $position = $image->height() > $image->width() ? 'top' : 'center';
            $disk->put($target, (string) $image->cover($size, $size, $position)->toJpeg(85));
        } catch (Throwable $exception) {
            Log::warning('Картинка для письма не подготовлена, уходит оригинал', ['path' => $path, 'error' => $exception->getMessage()]);

            return $path;
        }

        return '/storage/'.ltrim($target, '/');
    }
}
