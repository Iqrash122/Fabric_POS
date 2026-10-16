<?php

namespace App\Http\Controllers;

use App\Services\MetaWhatsApp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class MetaWhatsAppController extends Controller
{
    public function check(Request $request, MetaWhatsApp $meta): RedirectResponse
    {
        $this->authorizeOwner($request);
        $meta->checkConnection();
        return back()->with('success', 'Meta accepted the credentials for this Phone Number ID. No message was sent.');
    }

    public function test(Request $request, MetaWhatsApp $meta): RedirectResponse
    {
        $this->authorizeOwner($request);
        $request->validate(['confirm_test' => 'required|accepted']);
        $meta->sendTest();
        return back()->with('success', 'Meta accepted the hello_world test message. This is not delivery confirmation; check the verified recipient phone.');
    }

    private function authorizeOwner(Request $request): void
    {
        abort_unless($request->user()?->is_owner, 403);
        abort_unless(config('whatsapp.driver') === 'meta', 422, 'Meta mode is not enabled.');
    }
}
