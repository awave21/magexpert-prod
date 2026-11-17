<?php

use Symfony\Component\Process\Process;

test('site no longer exposes websocket demo or broadcasting authentication routes', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], dirname(__DIR__, 2), [
        'APP_SERVICE' => 'site',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/magexpert-websocket-no-cache.php',
    ]);
    $process->mustRun();
    $routes = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $uris = array_column($routes, 'uri');

    foreach (['game', 'api/test-websocket', 'api/test-notification', 'broadcasting/auth'] as $uri) {
        expect($uris)->not->toContain($uri);
    }

    expect($uris)->toContain('admin/notifications', 'cabinet/messages');
});
