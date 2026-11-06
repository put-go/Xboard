<?php

namespace Tests\Unit\Services;

use App\Exceptions\ApiException;
use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Middleware\Client;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\Plugin\HookManager;
use App\Services\Plugin\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Plugin\Epay\Plugin as EpayPlugin;
use Plugin\Mgate\Plugin as MgatePlugin;
use Tests\TestCase;

class UpstreamMergeCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->app->instance('hook.actions', []);
        $this->app->instance('hook.filters', []);
    }

    public function test_payment_keeps_the_custom_return_url_and_order_details(): void
    {
        $plugin = $this->createMock(EpayPlugin::class);
        $plugin->method('getPluginCode')->willReturn('epay');
        $plugin->expects($this->once())->method('pay')->with($this->callback(function ($order) {
            $this->assertSame('https://portal.example:8443/#/payment?trade_no=order-123', $order['return_url']);
            $this->assertSame('order-123', $order['trade_no']);
            $this->assertSame(1250, $order['total_amount']);
            $this->assertSame(9, $order['user_id']);
            $this->assertStringContainsString('/api/v1/guest/payment/notify/EPay/', $order['notify_url']);
            return true;
        }))->willReturn(['type' => 1, 'data' => 'https://gateway.example/pay']);
        $this->enablePlugins([$plugin]);
        $payment = $this->makePayment('EPay', ['pid' => '42', 'key' => 'epay-test-secret']);
        $request = Request::create('https://api.example/checkout');
        $request->headers->set('Referer', 'https://portal.example:8443/checkout');
        $this->app->instance('request', $request);

        $result = (new PaymentService('EPay', $payment->id))->pay([
            'trade_no' => 'order-123',
            'total_amount' => 1250,
            'user_id' => 9,
            'stripe_token' => null,
        ]);

        $this->assertSame('https://gateway.example/pay', $result['data']);
    }

    public function test_epay_accepts_a_valid_signed_callback(): void
    {
        $this->assertSame([
            'trade_no' => 'order-123',
            'callback_no' => 'gateway-456',
        ], $this->epay()->notify($this->epayCallback()));
    }

    public function test_mgate_accepts_a_valid_signed_callback(): void
    {
        $this->assertSame([
            'trade_no' => 'order-123',
            'callback_no' => 'gateway-456',
        ], $this->mgate()->notify($this->mgateCallback()));
    }

    public function test_payment_plugins_reject_invalid_signatures_and_structured_parameters(): void
    {
        foreach ([[$this->epay(), $this->epayCallback()], [$this->mgate(), $this->mgateCallback()]] as [$plugin, $valid]) {
            $invalidCases = [
                'incorrect signature' => array_replace($valid, ['sign' => str_repeat('0', 32)]),
                'modified order' => array_replace($valid, ['out_trade_no' => 'another-order']),
                'nested parameter' => array_replace($valid, ['out_trade_no' => ['order-123']]),
                'array signature' => array_replace($valid, ['sign' => [$valid['sign']]]),
                'missing order number' => array_diff_key($valid, ['out_trade_no' => true]),
                'non-array callback' => 'out_trade_no=order-123',
            ];
            foreach ($invalidCases as $description => $params) {
                $this->assertFalse($plugin->notify($params), $plugin->getPluginCode() . ': ' . $description);
            }
        }
    }

    public function test_payment_plugins_reject_empty_secrets_even_with_matching_signatures(): void
    {
        $epay = $this->epay();
        $epay->setConfig(['pid' => '42', 'key' => '']);
        $params = $this->epayCallback();
        $params['sign'] = md5('out_trade_no=order-123&pid=42&trade_no=gateway-456&trade_status=TRADE_SUCCESS');
        $this->assertFalse($epay->notify($params));

        $mgate = $this->mgate();
        $mgate->setConfig(['mgate_app_secret' => '']);
        $params = $this->mgateCallback();
        $params['sign'] = md5('out_trade_no=order-123&trade_no=gateway-456');
        $this->assertFalse($mgate->notify($params));
    }

    public function test_epay_rejects_another_merchant_or_unsuccessful_payment(): void
    {
        foreach (['pid' => '99', 'trade_status' => 'WAIT_BUYER_PAY', 'sign_type' => 'SHA256'] as $key => $value) {
            $params = array_replace($this->epayCallback(), [$key => $value]);
            $unsigned = $params;
            unset($unsigned['sign'], $unsigned['sign_type']);
            ksort($unsigned);
            $params['sign'] = md5(urldecode(http_build_query($unsigned)) . 'epay-test-secret');
            $this->assertFalse($this->epay()->notify($params), $key);
        }
    }

    public function test_callback_cannot_select_a_different_gateway_from_the_payment_record(): void
    {
        $this->enablePlugins([$this->epay(), $this->mgate()]);
        $payment = $this->makePayment('EPay', ['pid' => '42', 'key' => 'epay-test-secret']);
        $service = new PaymentService('MGate', null, $payment->uuid);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('payment method mismatch');
        $service->notify($this->mgateCallback());
    }

    public function test_payment_accounts_do_not_overwrite_a_shared_plugin_configuration(): void
    {
        $sharedPlugin = $this->epay();
        $this->enablePlugins([$sharedPlugin]);
        $first = $this->makePayment('EPay', ['pid' => '42', 'key' => 'epay-test-secret']);
        $second = $this->makePayment('EPay', ['pid' => '99', 'key' => 'another-secret']);

        $firstService = new PaymentService('EPay', $first->id);
        $secondService = new PaymentService('EPay', $second->id);

        $this->assertSame('order-123', $firstService->notify($this->epayCallback())['trade_no']);
        $this->assertFalse($secondService->notify($this->epayCallback()));
        $this->assertSame('epay-test-secret', $sharedPlugin->getConfig('key'));
        $this->assertSame('42', $sharedPlugin->getConfig('pid'));
    }

    public function test_subscription_rejects_missing_malformed_and_unknown_tokens(): void
    {
        foreach ([null, '', 'short', str_repeat('z', 32), ['unexpected'], str_repeat('a', 32)] as $token) {
            $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $token]);
            try {
                (new Client())->handle($request, function () {
                    $this->fail('An invalid subscription token must not reach the controller.');
                });
                $this->fail('An invalid subscription token must be rejected.');
            } catch (ApiException $exception) {
                $this->assertSame(403, $exception->getCode());
            }
        }
    }

    public function test_valid_subscription_token_authenticates_the_expected_user(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $user->token]);

        $response = (new Client())->handle($request, function () use ($user) {
            $this->assertSame($user->id, Auth::id());
            return response('authenticated');
        });

        $this->assertSame('authenticated', $response->getContent());
    }

    public function test_query_token_cannot_override_an_invalid_route_token(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/subscription/invalid', 'GET', ['token' => $user->token]);
        $route = new \Illuminate\Routing\Route('GET', '/subscription/{token}', fn () => null);
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $this->expectException(ApiException::class);
        $this->expectExceptionCode(403);
        (new Client())->handle($request, fn () => $this->fail('Query token must not replace the route token.'));
    }

    public function test_expired_authenticated_subscription_keeps_the_custom_empty_200_response(): void
    {
        $user = $this->makeUser();
        $request = Request::create('/api/v1/client/subscribe', 'GET', ['token' => $user->token]);
        $request->setUserResolver(fn () => Auth::user());
        $this->app->instance('request', $request);

        $response = (new Client())->handle($request, fn ($request) => (new ClientController())->subscribe($request));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('', $response->getContent());
        $this->assertSame('text/plain', $response->headers->get('Content-Type'));
    }

    public function test_telegram_webhook_prefers_tgurl_over_admin_configuration(): void
    {
        $previousEnv = $_ENV['TGURL'] ?? null;
        $previousServer = $_SERVER['TGURL'] ?? null;
        $_ENV['TGURL'] = $_SERVER['TGURL'] = 'https://telegram.example/custom/';

        try {
            admin_setting([
                'telegram_bot_token' => '123:test-token',
                'telegram_webhook_url' => 'https://configured.example',
                'app_url' => 'https://app.example',
            ]);
            Http::preventStrayRequests();
            Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

            (new ConfigController())->setTelegramWebhook(Request::create('/', 'POST', ['telegram_bot_token' => '123:test-token']));

            Http::assertSent(function ($request) {
                return str_contains($request->url(), '/setWebhook')
                    && $request['url'] === 'https://telegram.example/custom/api/v1/guest/telegram/webhook?access_token=' . md5('123:test-token');
            });
        } finally {
            if ($previousEnv === null) {
                unset($_ENV['TGURL']);
            } else {
                $_ENV['TGURL'] = $previousEnv;
            }
            if ($previousServer === null) {
                unset($_SERVER['TGURL']);
            } else {
                $_SERVER['TGURL'] = $previousServer;
            }
        }
    }

    private function enablePlugins(array $plugins): void
    {
        $manager = $this->createMock(PluginManager::class);
        $manager->method('getEnabledPaymentPlugins')->willReturn($plugins);
        $this->app->instance(PluginManager::class, $manager);
        HookManager::registerFilter('available_payment_methods', fn () => [
            'EPay' => ['plugin_code' => 'epay'],
            'MGate' => ['plugin_code' => 'mgate'],
        ]);
    }

    private function makePayment(string $method, array $config): Payment
    {
        return Payment::create([
            'uuid' => bin2hex(random_bytes(16)),
            'payment' => $method,
            'name' => 'test gateway',
            'config' => $config,
            'enable' => true,
        ]);
    }

    private function makeUser(): User
    {
        return User::create([
            'email' => 'subscription@example.com',
            'password' => password_hash('test-password', PASSWORD_DEFAULT),
            'uuid' => '31cb217e-a035-446e-962e-3ced6812ed5b',
            'token' => '0123456789abcdef0123456789abcdef',
            'transfer_enable' => 1024,
            'expired_at' => time() - 3600,
        ]);
    }

    private function epay(): EpayPlugin
    {
        $plugin = new EpayPlugin('epay');
        $plugin->setConfig(['pid' => '42', 'key' => 'epay-test-secret']);
        return $plugin;
    }

    private function mgate(): MgatePlugin
    {
        $plugin = new MgatePlugin('mgate');
        $plugin->setConfig(['mgate_app_secret' => 'mgate-test-secret']);
        return $plugin;
    }

    private function epayCallback(): array
    {
        // Fixed callback fixtures, independent of the plugin's signing implementation.
        return [
            'out_trade_no' => 'order-123',
            'pid' => '42',
            'trade_no' => 'gateway-456',
            'trade_status' => 'TRADE_SUCCESS',
            'sign_type' => 'MD5',
            'sign' => 'cff81bb30513642e0b0a79397fb70e3f',
        ];
    }

    private function mgateCallback(): array
    {
        return [
            'out_trade_no' => 'order-123',
            'trade_no' => 'gateway-456',
            'sign' => 'a8c13befc35c0a8fae8afe58e14a7811',
        ];
    }
}
