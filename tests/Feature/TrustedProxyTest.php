<?php

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class TrustedProxyTest extends TestCase
{
    public function test_https_header_is_only_accepted_from_a_configured_proxy(): void
    {
        config(['trustedproxy.proxies' => ['172.19.0.5']]);
        Route::get('/proxy-test', fn (Request $request) => $request->isSecure() ? 'https' : 'http');

        $this->withServerVariables(['REMOTE_ADDR' => '172.19.0.5'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/proxy-test')->assertOk()->assertSeeText('https');

        $this->withServerVariables(['REMOTE_ADDR' => '172.19.0.99'])
            ->withHeader('X-Forwarded-Proto', 'https')
            ->get('/proxy-test')->assertOk()->assertSeeText('http');
    }

    public function test_client_address_is_only_accepted_from_a_configured_proxy(): void
    {
        config(['trustedproxy.proxies' => ['172.29.240.3']]);
        Route::get('/proxy-ip-test', fn (Request $request) => $request->ip());

        $this->withServerVariables(['REMOTE_ADDR' => '172.29.240.3'])
            ->withHeader('X-Forwarded-For', '203.0.113.42')
            ->get('/proxy-ip-test')->assertOk()->assertSeeText('203.0.113.42');

        $this->withServerVariables(['REMOTE_ADDR' => '172.29.240.99'])
            ->withHeader('X-Forwarded-For', '203.0.113.42')
            ->get('/proxy-ip-test')->assertOk()->assertSeeText('172.29.240.99');
    }
}
