<?php

namespace App\Sender\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Документация публичного API: спецификация OpenAPI и две страницы для чтения (Swagger UI и Redoc).
 * Источник один, docs/sender-api.openapi.yaml; страницы подключают библиотеки с CDN и ничего не хранят.
 */
class ApiDocsController extends Controller
{
    private const SPEC_FILE = 'docs/sender-api.openapi.yaml';

    private const SWAGGER_UI_VERSION = '5.17.14';

    private const REDOC_VERSION = '2.1.5';

    public function spec(Request $request): Response
    {
        $path = base_path(self::SPEC_FILE);
        abort_unless(is_file($path), 404, 'Спецификация не найдена');

        // адрес сервера в спецификации — тот, по которому её открыли: «Try it out» ходит в этот же Sender
        $yaml = str_replace(
            'https://mail.mag-expert.ru/api/sender/v1',
            $request->getSchemeAndHttpHost().'/api/sender/v1',
            (string) file_get_contents($path),
        );

        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=UTF-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    public function index(Request $request): Response
    {
        $base = $request->getSchemeAndHttpHost().'/api/sender/v1';

        return $this->page('Sender API', <<<HTML
<main style="max-width:640px;margin:48px auto;padding:0 16px;font:16px/1.6 system-ui,Arial,sans-serif;color:#1C1F27">
  <h1>Sender API</h1>
  <p>Публичный API сервиса рассылок: отправка писем, согласие на рассылку, базы и подписчики.</p>
  <ul>
    <li><a href="{$base}/docs/swagger">Swagger UI</a>: можно пробовать запросы прямо на странице</li>
    <li><a href="{$base}/docs/redoc">Redoc</a>: читаемая справка</li>
    <li><a href="{$base}/openapi.yaml">openapi.yaml</a>: спецификация OpenAPI 3.1</li>
  </ul>
</main>
HTML);
    }

    public function swagger(Request $request): Response
    {
        $spec = e($request->getSchemeAndHttpHost().'/api/sender/v1/openapi.yaml');
        $version = self::SWAGGER_UI_VERSION;

        return $this->page('Sender API: Swagger UI', <<<HTML
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/swagger-ui-dist@{$version}/swagger-ui.css">
<div id="swagger-ui"></div>
<script src="https://cdn.jsdelivr.net/npm/swagger-ui-dist@{$version}/swagger-ui-bundle.js"></script>
<script>
  window.ui = SwaggerUIBundle({ url: "{$spec}", dom_id: '#swagger-ui', deepLinking: true, persistAuthorization: true });
</script>
HTML);
    }

    public function redoc(Request $request): Response
    {
        $spec = e($request->getSchemeAndHttpHost().'/api/sender/v1/openapi.yaml');
        $version = self::REDOC_VERSION;

        return $this->page('Sender API: Redoc', <<<HTML
<redoc spec-url="{$spec}"></redoc>
<script src="https://cdn.jsdelivr.net/npm/redoc@{$version}/bundles/redoc.standalone.js"></script>
HTML);
    }

    private function page(string $title, string $body): Response
    {
        $title = e($title);

        return response(<<<HTML
<!doctype html>
<html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title></head>
<body style="margin:0">{$body}</body></html>
HTML, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
