<?php

namespace App\Sender\Http\Controllers;

use App\Sender\Models\Message;
use App\Sender\Services\TrackingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Открытия (картинка 1×1) и переходы по ссылкам из писем рассылок.
 */
class TrackController extends Controller
{
    public function __construct(private readonly TrackingService $tracking) {}

    public function open(Request $request, string $uuid): Response
    {
        $message = Message::query()->where('uuid', $uuid)->where('tracked', true)->first();

        if ($message !== null) {
            $this->tracking->recordOpen($message, $request->ip(), $request->userAgent());
        }

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }

    /**
     * Подпись защищает от перенаправления на чужие сайты через наш домен.
     */
    public function click(Request $request, string $uuid): RedirectResponse
    {
        $url = (string) $request->query('u', '');

        abort_unless(
            preg_match('~^https?://~i', $url) && hash_equals($this->tracking->signature($uuid, $url), (string) $request->query('s', '')),
            404,
        );

        $message = Message::query()->where('uuid', $uuid)->where('tracked', true)->first();

        if ($message !== null) {
            $this->tracking->recordClick($message, $url, $request->ip(), $request->userAgent());
        }

        return redirect()->away($url, 302);
    }
}
