<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function (): void {
    $this->dedupStore = tempnam(sys_get_temp_dir(), 'tg-dedup');
    unlink($this->dedupStore);

    config([
        'logging.default' => 'stack',
        'logging.channels.stack.channels' => ['telegram'],
        'logging.channels.telegram.token' => 'bot-secret-token',
        'logging.channels.telegram.chat_id' => '42',
        'logging.channels.telegram.level' => 'error',
        'logging.channels.telegram.dedup_store' => $this->dedupStore,
    ]);
    Log::forgetChannel('stack');
    Log::forgetChannel('telegram');

    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
});

afterEach(function (): void {
    @unlink($this->dedupStore);
});

function sentText(): string
{
    $request = Http::recorded()->first()[0];

    return (string) $request['text'];
}

function paymentFailure(): RuntimeException
{
    return new RuntimeException('Не удалось списать оплату для ivan@mail.ru, телефон +7 (912) 345-67-89, ключ '.str_repeat('a1B2', 10));
}

it('does nothing without a token or chat', function (): void {
    config(['logging.channels.telegram.token' => null]);
    Log::forgetChannel('stack');
    Log::forgetChannel('telegram');

    report(new RuntimeException('Ошибка без бота'));
    Log::channel('telegram')->critical('И эта тоже');

    Http::assertNothingSent();
});

it('sends one short message with the class and the text, without personal data or secrets', function (): void {
    report(paymentFailure());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://api.telegram.org/botbot-secret-token/sendMessage')
        && $request['chat_id'] === '42');

    $text = sentText();
    expect($text)->toContain('RuntimeException')
        ->toContain('Не удалось списать оплату')
        ->toContain('tests/Feature/TelegramErrorLogTest.php:')
        ->toContain('[email]')
        ->toContain('[телефон]')
        ->not->toContain('ivan@mail.ru')
        ->not->toContain('345-67-89')
        ->not->toContain('a1B2a1B2')
        ->not->toContain('bot-secret-token')
        ->not->toContain('#0 ')
        ->and(mb_strlen($text))->toBeLessThanOrEqual(4000);
});

it('sends the same error only once within the window, but a different one again', function (): void {
    report(paymentFailure());
    report(paymentFailure());
    report(paymentFailure());

    Http::assertSentCount(1);

    report(new RuntimeException('Не удалось списать оплату для ivan@mail.ru, телефон +7 (912) 345-67-89, ключ '.str_repeat('a1B2', 10)));

    Http::assertSentCount(2);

    // окно прошло — снова приходит (Monolog берёт время с системных часов, поэтому состариваем записи в файле)
    $lines = array_map(fn (string $line): string => preg_replace('/^\d+/', (string) (time() - 660), $line), file($this->dedupStore, FILE_IGNORE_NEW_LINES));
    file_put_contents($this->dedupStore, implode("\n", $lines)."\n");
    report(paymentFailure());

    Http::assertSentCount(3);
});

it('does not break the request when telegram fails or hangs', function (Closure $telegram): void {
    Http::fake(['api.telegram.org/*' => $telegram]);

    expect(fn () => report(new RuntimeException('Сайт упал, а Telegram тоже')))->not->toThrow(Throwable::class);
    expect(fn () => Log::channel('telegram')->error('И ещё раз'))->not->toThrow(Throwable::class);
})->with([
    'ответ 500' => fn () => fn () => Http::response('down', 500),
    'таймаут' => fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out'),
]);

it('does not send records below the error level', function (): void {
    Log::warning('Медленный запрос');
    Log::info('Пользователь вошёл');

    Http::assertNothingSent();

    Log::error('Не удалось отправить чек');
    Http::assertSentCount(1);
});

it('does not report what is not an error: 404, 419, validation, authentication, authorization', function (): void {
    report(new NotFoundHttpException);
    report(new Symfony\Component\HttpKernel\Exception\HttpException(419, 'Page Expired'));
    report(ValidationException::withMessages(['email' => 'Неверный email']));
    report(new AuthenticationException);
    report(new AuthorizationException);

    Http::assertNothingSent();
});

it('drops the sql with values from database errors', function (): void {
    report(new QueryException('pgsql', 'select * from users where email = ? and password = ?', ['ivan@mail.ru', 'hunter2'], new Exception('SQLSTATE[42P01]: Undefined table: relation "users" does not exist')));

    expect(sentText())->toContain('QueryException')
        ->toContain('SQLSTATE[42P01]')
        ->not->toContain('select')
        ->not->toContain('hunter2')
        ->not->toContain('ivan@mail.ru');
});

it('shows the request address without the query string and the user id', function (): void {
    // страница ошибки в режиме отладки тяжёлая, а здесь проверяется только сообщение
    config(['app.debug' => false]);
    Route::middleware('web')->get('/_test/boom', fn () => throw new RuntimeException('Сломалась страница'));
    $user = User::factory()->create();

    $this->actingAs($user)->get('/_test/boom?token=very-secret&email=ivan@mail.ru')->assertServerError();

    expect(sentText())->toContain('GET '.url('/_test/boom'))
        ->toContain('пользователь #'.$user->id)
        ->not->toContain('very-secret')
        ->not->toContain('token=');
});
