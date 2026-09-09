<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DatabaseDumpDownloadController;
use App\Http\Controllers\DatabaseUploadController;
use App\Http\Controllers\RepositoryWebhookController;
use App\Http\Controllers\SiteFileDownloadController;
use App\Http\Controllers\SiteSnapshotController;
use App\Http\Controllers\TerminalAuthorizationController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WebhookController;
use App\Livewire\BackupManager;
use App\Livewire\Dashboard;
use App\Livewire\DatabaseManager;
use App\Livewire\DnsChecker;
use App\Livewire\FileManager;
use App\Livewire\GitManager;
use App\Livewire\LaravelManager;
use App\Livewire\ServerHealth;
use App\Livewire\ServerSetup;
use App\Livewire\ServerTerminal;
use App\Livewire\SiteManager;
use App\Livewire\SslManager;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/', Dashboard::class)->name('dashboard');
    Route::get('/sites/{site}', SiteManager::class)->name('sites.show');
    Route::get('/sites/{site}/snapshot', SiteSnapshotController::class)->name('sites.snapshot');
    Route::get('/sites/{site}/databases', DatabaseManager::class)->name('sites.databases');
    Route::post('/sites/{site}/database-uploads', [DatabaseUploadController::class, 'start'])->middleware('throttle:10,1,database-upload-start:')->name('sites.database-uploads.start');
    Route::post('/sites/{site}/database-uploads/{token}', [DatabaseUploadController::class, 'chunk'])->middleware('throttle:1200,1,database-upload-chunk:')->name('sites.database-uploads.chunk');
    Route::get('/sites/{site}/database-operations/{operation}/download', DatabaseDumpDownloadController::class)->name('sites.databases.download');
    Route::get('/sites/{site}/files', FileManager::class)->name('sites.files');
    Route::get('/sites/{site}/backups', BackupManager::class)->name('sites.backups');
    Route::get('/sites/{site}/git', GitManager::class)->name('sites.git');
    Route::get('/sites/{site}/laravel', LaravelManager::class)->name('sites.laravel');
    Route::get('/sites/{site}/ssl', SslManager::class)->name('sites.ssl');
    Route::get('/sites/{site}/files/{operation}/download', SiteFileDownloadController::class)->whereNumber('operation')->name('sites.files.download');
    Route::get('/setup/server', ServerSetup::class)->name('server.setup');
    Route::get('/setup/terminal', ServerTerminal::class)->name('server.terminal');
    Route::get('/setup/health', ServerHealth::class)->name('server.health');
    Route::get('/tools/dns', DnsChecker::class)->name('tools.dns');
    Route::get('/confirm-password', [AuthController::class, 'confirmPassword'])->name('password.confirm');
    Route::post('/confirm-password', [AuthController::class, 'storeConfirmedPassword'])->name('password.confirm.store');
    Route::get('/security', [TwoFactorController::class, 'settings'])->name('security.settings');
    Route::post('/security/two-factor', [TwoFactorController::class, 'begin'])->name('security.two-factor.begin');
    Route::post('/security/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('security.two-factor.confirm');
    Route::delete('/security/two-factor', [TwoFactorController::class, 'disable'])->name('security.two-factor.disable');
    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
});

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->middleware('throttle:login');
    Route::get('/two-factor', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
    Route::post('/two-factor', [TwoFactorController::class, 'verify'])->middleware('throttle:6,1')->name('two-factor.verify');
    Route::post('/two-factor/cancel', [TwoFactorController::class, 'cancel'])->name('two-factor.cancel');
});

Route::get('/server-terminal/auth', TerminalAuthorizationController::class)->name('server.terminal.auth');

Route::post('/webhooks/{site:webhook_token}', WebhookController::class)->middleware('throttle:60,1')->name('webhooks.deploy');
Route::post('/webhooks/repositories/{repository:key_token}', RepositoryWebhookController::class)->middleware('throttle:30,1')->name('webhooks.repository');
