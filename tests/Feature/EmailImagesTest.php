<?php

use App\Models\Event;
use App\Models\Speaker;
use App\Models\User;
use App\Services\EmailImages;
use App\Services\Sender\SenderClient;
use App\Services\SenderMailService;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    Storage::fake('public');
});

/**
 * Картинка заданного размера в WebP, как фото спикера на сайте.
 */
function putPhoto(string $path, int $width, int $height): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 120, 80));
    ob_start();
    imagewebp($image);
    Storage::disk('public')->put($path, (string) ob_get_clean());
}

it('makes a square jpeg copy of a non-square photo once', function (int $width, int $height): void {
    putPhoto('speakers/7/photo.webp', $width, $height);

    $path = app(EmailImages::class)->square('/storage/speakers/7/photo.webp');
    $file = substr((string) $path, strlen('/storage/'));
    [$w, $h, $type] = getimagesizefromstring(Storage::disk('public')->get($file));

    expect($path)->toStartWith('/storage/speakers/7/email/photo-192-')->toEndWith('.jpg')
        ->and([$w, $h, $type])->toBe([192, 192, IMAGETYPE_JPEG])
        ->and(app(EmailImages::class)->square('/storage/speakers/7/photo.webp'))->toBe($path)
        ->and(Storage::disk('public')->allFiles('speakers/7/email'))->toHaveCount(1);
})->with([
    'wide' => [720, 498],
    'tall' => [380, 455],
]);

it('keeps the original address when the photo cannot be prepared', function (): void {
    Storage::disk('public')->put('speakers/8/broken.webp', 'not an image');

    expect(app(EmailImages::class)->square('/storage/speakers/8/broken.webp'))->toBe('/storage/speakers/8/broken.webp')
        ->and(app(EmailImages::class)->square('/storage/speakers/9/missing.webp'))->toBe('/storage/speakers/9/missing.webp')
        ->and(app(EmailImages::class)->square('https://cdn.example.com/p.webp'))->toBe('https://cdn.example.com/p.webp')
        ->and(app(EmailImages::class)->square('/storage/../.env'))->toBe('/storage/../.env')
        ->and(app(EmailImages::class)->square(null))->toBeNull();
});

it('sends square speaker photos in the event registration letter', function (): void {
    putPhoto('speakers/3/face.webp', 720, 498);

    $client = new class implements SenderClient
    {
        public array $calls = [];

        public function send(string $template, string $to, array $data = [], ?string $idempotencyKey = null, ?string $requestId = null): array
        {
            $this->calls[] = $data;

            return ['id' => 'abc', 'status' => 'queued', 'error' => null];
        }
    };
    $this->app->instance(SenderClient::class, $client);

    $event = Event::make(['title' => 'Школа', 'slug' => 'school', 'format' => 'online', 'is_archived' => false, 'is_paid' => false, 'show_price' => false]);
    $event->setRelation('speakers', collect([new Speaker(['first_name' => 'Елена', 'last_name' => 'Пашкова', 'photo' => '/storage/speakers/3/face.webp'])]));

    (new SenderMailService)->sendEventRegistrationEmail($event, User::make(['first_name' => 'Анна', 'email' => 'anna@example.com']));

    expect($client->calls[0]['speakers'][0]['photo'])->toStartWith(url('/storage/speakers/3/email/face-192-'))->toEndWith('.jpg');
});
