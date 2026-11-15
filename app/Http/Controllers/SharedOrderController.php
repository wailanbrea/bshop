<?php

namespace App\Http\Controllers;

use App\Domain\Sales\OrderViewLinks;

class SharedOrderController extends Controller
{
    public const HEADERS = ['Cache-Control' => 'private, no-store, max-age=0', 'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'X-Content-Type-Options' => 'nosniff',
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'"];

    public function show(string $token, OrderViewLinks $links)
    {
        $order = $links->publicOrder($token);

        return response()->view('shared-order', ['order' => $order], $order ? 200 : 404, self::HEADERS);
    }
}
