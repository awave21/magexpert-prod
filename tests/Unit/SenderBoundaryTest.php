<?php

/**
 * Граница между сайтом и Sender: сайт обращается к нему только по публичному API
 * (docs/sender-api.openapi.yaml, клиент App\Services\Sender\SenderApi), код Sender напрямую не вызывает.
 */
function projectRoot(): string
{
    return dirname(__DIR__, 2);
}

it('keeps the site code free of direct references to the Sender code', function (): void {
    $roots = ['app', 'routes', 'config', 'database'];
    $violations = [];

    foreach ($roots as $root) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(projectRoot().'/'.$root, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $path = str_replace(projectRoot().'/', '', $file->getPathname());

            // сам Sender живёт в app/Sender; там свои правила
            if ($file->getExtension() !== 'php' || str_starts_with($path, 'app/Sender/')) {
                continue;
            }

            if (preg_match('/App\\\\Sender\\\\/', (string) file_get_contents($file->getPathname()))) {
                $violations[] = $path;
            }
        }
    }

    expect($violations)->toBe([]);
});

it('lets the Sender code in app/Sender depend on nothing of the site except the known exception', function (): void {
    // известная зависимость: вход через VK ID использует провайдер Socialite сайта. При выносе Sender
    // провайдер переезжает в app/Sender, а эта строка удаляется.
    $allowed = ['app/Sender/Http/Controllers/OAuthController.php' => 'App\\Services\\VkIdProvider'];
    $violations = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(projectRoot().'/app/Sender', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $path = str_replace(projectRoot().'/', '', $file->getPathname());
        $source = (string) file_get_contents($file->getPathname());

        if (isset($allowed[$path])) {
            $source = str_replace('use '.$allowed[$path].';', '', $source);
        }

        // любое use App\… вне пространства App\Sender\
        if (preg_match('/^use App\\\\(?!Sender\\\\)/m', $source)) {
            $violations[] = $path;
        }
    }

    expect($violations)->toBe([]);
});
