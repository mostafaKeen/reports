<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\BitrixOAuthController;
use App\Http\Controllers\BitrixWebhookController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ReportingController;

// Authentication
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/', function () {
    return redirect()->route('companies.index');
});

// Admin Companies Console (Protected by Auth)
Route::middleware('auth')->group(function () {
    Route::resource('companies', CompanyController::class)->only(['index', 'store', 'destroy']);
    Route::patch('/companies/{company}/toggle-active', [CompanyController::class, 'toggleActive'])->name('companies.toggle-active');
});

// OAuth Flows
Route::get('/bitrix24/connect/{company}', [BitrixOAuthController::class, 'connect'])->name('bitrix24.connect');
// Handle both GET (OAuth redirect) and POST (installation callback) on the same endpoint
Route::match(['get', 'post'], '/bitrix24/callback', [BitrixOAuthController::class, 'callback'])->name('bitrix24.callback');
Route::post('/bitrix24/install', [BitrixOAuthController::class, 'install'])->name('bitrix24.install');
Route::post('/bitrix24/install/complete', [BitrixOAuthController::class, 'completeInstall'])->name('bitrix24.install.complete');

// Inbound webhook endpoints (excluded from CSRF in bootstrap/app.php)
Route::post('/webhook/bitrix24', [BitrixWebhookController::class, 'handle'])->name('bitrix24.webhook');

// ── Reporting Dashboard ──────────────────────────────────────────────
// Signed URL routes: accessible from admin sidebar AND embedded in Bitrix24 UI
Route::get('/report/{company}', [ReportingController::class, 'show'])->name('report.show');
Route::get('/report/{company}/data', [ReportingController::class, 'fetchData'])->name('report.data');
Route::post('/report/{company}/clear-cache', [ReportingController::class, 'clearCache'])->name('report.clearCache');
Route::get('/report/{company}/export', [ReportingController::class, 'exportCsv'])->name('report.export');