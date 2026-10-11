<?php

use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->publicDir = sys_get_temp_dir().'/sender-ui-test-'.uniqid();
    File::ensureDirectoryExists($this->publicDir);
    app()->usePublicPath($this->publicDir);
});

afterEach(function (): void {
    File::deleteDirectory($this->publicDir);
});

it('serves the built admin ui for any path under /sender', function (string $path): void {
    File::ensureDirectoryExists($this->publicDir.'/sender-static');
    File::put($this->publicDir.'/sender-static/index.html', '<div id="root"></div>');

    $response = $this->get($path);

    $response->assertOk();
    expect($response->baseResponse->getFile()->getPathname())->toBe($this->publicDir.'/sender-static/index.html');
})->with(['/sender', '/sender/domains/1', '/sender/api-keys']);

it('returns not found when the admin ui is not built', function (): void {
    $this->get('/sender')->assertNotFound();
});

it('serves the admin ui at the root of its own subdomain and redirects the old path', function (): void {
    config(['sender.ui_url' => 'https://mail.mag-expert.test']);
    File::ensureDirectoryExists($this->publicDir.'/sender-static');
    File::put($this->publicDir.'/sender-static/index.html', '<div id="root"></div>');
    // маршруты читают адрес поддомена при загрузке, поэтому перечитываем их
    app('router')->setRoutes(new Illuminate\Routing\RouteCollection);
    require base_path('app/Sender/routes.php');

    $this->get('https://mail.mag-expert.test/')->assertOk();
    $this->get('https://mail.mag-expert.test/templates/2')->assertOk();
    $this->get('http://localhost/sender/domains/1')->assertRedirect('https://mail.mag-expert.test/domains/1');
    $this->get('https://mail.mag-expert.test/api/sender/v1/admin/me')->assertUnauthorized();

    expect(App\Sender\Support\SenderUi::url('confirm-address/abc'))->toBe('https://mail.mag-expert.test/confirm-address/abc');
});
