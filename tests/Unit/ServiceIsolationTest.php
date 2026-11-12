<?php

use Symfony\Component\Process\Process;

test('standalone services expose only their own application routes', function (string $service, bool $site, bool $sender) {
    $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], dirname(__DIR__, 2), [
        'APP_SERVICE' => $service,
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/magexpert-isolation-no-cache.php',
    ]);
    $process->mustRun();
    $routes = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    $names = array_column($routes, 'name');

    expect(in_array('welcome', $names, true))->toBe($site)
        ->and(in_array('sender.admin.login', $names, true))->toBe($sender)
        ->and(in_array('up', array_column($routes, 'uri'), true))->toBeTrue();
})->with([
    ['site', true, false],
    ['sender', false, true],
    ['combined', true, true],
]);

test('invalid service mode fails instead of enabling both applications', function () {
    $process = new Process([PHP_BINARY, 'artisan', 'route:list', '--json'], dirname(__DIR__, 2), [
        'APP_SERVICE' => 'invalid',
        'APP_CONFIG_CACHE' => sys_get_temp_dir().'/magexpert-isolation-no-cache.php',
    ]);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('APP_SERVICE must be');
});
