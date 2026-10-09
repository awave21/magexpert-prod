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
