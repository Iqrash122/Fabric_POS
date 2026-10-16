<?php

use App\Http\Controllers\FabricController;
use App\Http\Middleware\FabricAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');
Route::get('/dashboard', function (Request $request) {
    $user = $request->user();
    $role = $user->is_owner ? 'owner' : (string) ($user->fabric_role ?? '');
    abort_unless(in_array($role, ['owner', 'manager', 'cashier'], true), 403);

    return redirect('/fabric');
})->middleware('auth')->name('dashboard');

Route::middleware(['auth', FabricAccess::class])->group(function () {
    Route::get('/fabric', [FabricController::class, 'index'])->name('fabric');
    Route::get('/fabric/{section}', [FabricController::class, 'index'])->where('section', 'dashboard|pos|customers|vendors|purchases|inventory|returns|cash-bank|expenses|promotions|marketing|whatsapp-bulk|reports|settings');
    Route::post('/fabric/action/{action}/{id?}', [FabricController::class, 'action'])->middleware('throttle:120,1');
    Route::get('/fabric/barcode/{code}', [FabricController::class, 'barcode'])->where('code', '.*');
    Route::get('/fabric/barcode-image/{id}', [FabricController::class, 'barcodeImage'])->whereNumber('id');
    Route::get('/fabric/sale-quote', [FabricController::class, 'saleQuote']);
    Route::post('/fabric/whatsapp/authorize', [FabricController::class, 'authorizeWhatsApp'])->middleware('throttle:20,1');
    Route::get('/fabric/export/{type}', [FabricController::class, 'export']);
    Route::get('/fabric/document/{type}/{id}', [FabricController::class, 'document']);
    Route::get('/fabric/expense-receipt/{id}', [FabricController::class, 'expenseReceipt']);
    Route::post('/fabric/campaign/{id}/launch', [FabricController::class, 'campaignLaunch']);
});

require __DIR__.'/settings.php';
