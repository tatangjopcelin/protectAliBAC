<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
| Formulaires fournisseur (liens e-mail) : même chemin /api/... que dans les e-mails,
| mais middleware "web" (session + CSRF) pour que les POST depuis Safari / iOS fonctionnent.
| Les routes api.php sont enregistrées avant web : on retire le doublon côté api.
*/
Route::prefix('api')->group(function () {
    Route::get('/supplier-orders/token/{token}/respond/{decision}', [OrderController::class, 'supplierRespondByToken']);
    Route::post('/supplier-orders/token/{token}/respond/{decision}', [OrderController::class, 'supplierRespondByToken']);
});

Route::get('/supplier-orders/confirmation-pdf-landing/{key}', [OrderController::class, 'supplierConfirmationPdfLanding'])
    ->name('supplier-order.confirmation-pdf-landing');
Route::get('/supplier-orders/confirmation-pdf-download/{key}', [OrderController::class, 'supplierConfirmationPdfDownload'])
    ->name('supplier-order.confirmation-pdf-download');
Route::get('/supplier-orders/reject-done', [OrderController::class, 'supplierRejectDone'])->name('supplier-order.reject-done');
