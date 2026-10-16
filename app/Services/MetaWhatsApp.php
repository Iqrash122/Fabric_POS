<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

final class MetaWhatsApp
{
    /** @return array{configured:bool,test_ready:bool,missing:list<string>} */
    public function status(): array
    {
        $missing = [];
        foreach (['token' => 'META_WHATSAPP_ACCESS_TOKEN', 'phone_number_id' => 'META_WHATSAPP_PHONE_NUMBER_ID', 'version' => 'META_GRAPH_VERSION'] as $key => $variable) {
            if (! trim((string) config('whatsapp.meta.'.$key))) $missing[] = $variable;
        }
        return ['configured' => $missing === [], 'test_ready' => $missing === [] && config('whatsapp.meta.test_number_confirmed') === true && preg_match('/^[1-9][0-9]{7,14}$/', (string) config('whatsapp.meta.test_recipient')) === 1, 'missing' => $missing];
    }

    public function checkConnection(): void
    {
        $this->request('GET', ['fields' => 'id']);
    }

    public function sendTest(): void
    {
        if (! $this->status()['test_ready']) {
            throw ValidationException::withMessages(['form' => 'Configure a Meta test phone number, explicitly confirm test-number mode, and set a verified test recipient. Production sending is disabled.']);
        }
        $body = $this->request('POST', [
            'messaging_product' => 'whatsapp',
            'to' => (string) config('whatsapp.meta.test_recipient'),
            'type' => 'template',
            'template' => ['name' => 'hello_world', 'language' => ['code' => 'en_US']],
        ]);
        if (! is_string(data_get($body, 'messages.0.id'))) {
            throw ValidationException::withMessages(['form' => 'Meta did not return a message ID. Do not assume delivery; check WhatsApp Manager before retrying.']);
        }
    }

    /** @param array<string,mixed> $payload
     *  @return array<string,mixed>
     */
    private function request(string $method, array $payload): array
    {
        $version = (string) config('whatsapp.meta.version');
        $id = (string) config('whatsapp.meta.phone_number_id');
        if (! $this->status()['configured'] || ! preg_match('/^v[0-9]+\.0$/', $version) || ! preg_match('/^[0-9]+$/', $id)) {
            throw ValidationException::withMessages(['form' => 'Meta configuration is missing or invalid. Set the access token, numeric Phone Number ID and Graph API version in the local .env file.']);
        }
        $url = 'https://graph.facebook.com/'.$version.'/'.$id.($method === 'POST' ? '/messages' : '');
        try {
            // Never retry a message POST automatically: a timeout may follow acceptance.
            $client = Http::withToken((string) config('whatsapp.meta.token'))->acceptJson()->connectTimeout(5)->timeout(20)->withoutRedirecting();
            $response = $method === 'GET' ? $client->get($url, $payload) : $client->post($url, $payload);
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['form' => 'Meta could not be reached. If sending, delivery is unknown; check WhatsApp Manager before retrying.']);
        }
        if (! $response->successful()) {
            $code = (int) $response->json('error.code', 0);
            $hint = match ($code) {
                190 => 'Access token expired or invalid. Update it locally.',
                131030 => 'Add and verify the test recipient in Meta API Setup.',
                10, 200 => 'Check WhatsApp permissions and access to this Phone Number ID.',
                default => 'Check the token, Phone Number ID and API version in Meta API Setup.',
            };
            // Do not expose upstream payloads: they can contain phone numbers or secrets.
            throw ValidationException::withMessages(['form' => "Meta request failed (HTTP {$response->status()}, code {$code}). {$hint}"]);
        }
        $body = $response->json();
        return is_array($body) ? $body : [];
    }
}
