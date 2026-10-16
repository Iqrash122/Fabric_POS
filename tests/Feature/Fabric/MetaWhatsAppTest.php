<?php

namespace Tests\Feature\Fabric;

use App\Models\User;
use App\Services\MetaWhatsApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MetaWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config(['whatsapp.driver' => 'meta', 'whatsapp.meta' => ['token' => 'test-secret', 'phone_number_id' => '123456', 'version' => 'v23.0', 'test_number_confirmed' => true, 'test_recipient' => '923001234567']]);
        $owner = User::factory()->create(['is_owner' => true]);
        $this->actingAs($owner);
    }

    public function test_connection_check_uses_bearer_token_and_does_not_send(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['id' => '123456'])]);
        $this->post('/fabric/whatsapp/meta/check')->assertSessionHasNoErrors();
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->url() === 'https://graph.facebook.com/v23.0/123456?fields=id' && $request->hasHeader('Authorization', 'Bearer test-secret'));
        Http::assertSentCount(1);
        $this->assertStringNotContainsString('test-secret', json_encode(app(MetaWhatsApp::class)->status(), JSON_THROW_ON_ERROR));
    }

    public function test_missing_credentials_blocks_network(): void
    {
        config(['whatsapp.meta.token' => '']);
        $this->post('/fabric/whatsapp/meta/check')->assertSessionHasErrors('form');
        Http::assertNothingSent();
    }

    public function test_test_message_uses_server_recipient_and_fixed_sample(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.test']]])]);
        $this->post('/fabric/whatsapp/meta/test', ['confirm_test' => true, 'to' => '15555555555', 'message' => 'Do not use client input'])->assertSessionHasNoErrors()->assertSessionHas('success');
        Http::assertSent(fn ($request) => $request->method() === 'POST' && $request['to'] === '923001234567' && $request['template']['name'] === 'hello_world' && $request['template']['language']['code'] === 'en_US');
        Http::assertSentCount(1);
    }

    public function test_unconfirmed_test_number_or_recipient_confirmation_blocks_sending(): void
    {
        $this->post('/fabric/whatsapp/meta/test')->assertSessionHasErrors('confirm_test');
        config(['whatsapp.meta.test_number_confirmed' => false]);
        $this->post('/fabric/whatsapp/meta/test', ['confirm_test' => true])->assertSessionHasErrors('form');
        Http::assertNothingSent();
    }

    public function test_non_owner_cannot_check_or_send(): void
    {
        $this->actingAs(User::factory()->create(['is_owner' => false, 'fabric_role' => 'cashier']));
        $this->post('/fabric/whatsapp/meta/check')->assertForbidden();
        $this->post('/fabric/whatsapp/meta/test', ['confirm_test' => true])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_upstream_failure_is_sanitized_and_not_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'test-secret private upstream data']], 401)]);
        $this->post('/fabric/whatsapp/meta/test', ['confirm_test' => true])->assertSessionHasErrors('form');
        $this->assertStringNotContainsString('test-secret', session('errors')->first('form'));
        Http::assertSentCount(1);
    }

    public function test_timeout_is_not_retried(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);
        $this->post('/fabric/whatsapp/meta/test', ['confirm_test' => true])->assertSessionHasErrors('form');
        $this->assertStringContainsString('delivery is unknown', session('errors')->first('form'));
    }
}
