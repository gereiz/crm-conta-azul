<?php

use App\Http\Controllers\BillingRestrictionController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\ContaAzulConnectionController;
use App\Http\Controllers\ContaAzulController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmpresaController;
use App\Http\Controllers\InstallController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsAppController;
use App\Http\Controllers\WhatsappTemplateController;
use App\Http\Controllers\WebhookController;
use App\Http\Controllers\WhatsAppReturnController;
use Illuminate\Support\Facades\Route;

// Rotas de Instalação (Públicas, mas protegidas pelo middleware CheckInstalled)
Route::prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'index'])->name('index');
    Route::get('/environment', [InstallController::class, 'environment'])->name('environment');
    Route::post('/environment', [InstallController::class, 'saveEnvironment'])->name('environment.save');
    Route::get('/database', [InstallController::class, 'database'])->name('database');
    Route::post('/database', [InstallController::class, 'migrate'])->name('migrate');
    Route::get('/admin', [InstallController::class, 'createUser'])->name('admin');
    Route::post('/admin', [InstallController::class, 'storeUser'])->name('admin.store');
    Route::get('/finish', [InstallController::class, 'finish'])->name('finish');
});

Route::get('/', function () {
    return redirect()->route('login');
});

// Webhooks (públicos) sem CSRF
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->match(['post','put'], '/webhooks/whatsapp/status/{secret?}/{extra?}', [WebhookController::class, 'whatsappStatus'])
    ->where('extra', '.*')
    ->name('webhooks.whatsapp.status');
Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class])
    ->match(['post','put'], '/api/errors/{type?}/{extra?}', [WebhookController::class, 'providerError'])
    ->where('extra', '.*')
    ->name('webhooks.provider.error');

Route::get('/dashboard', [DashboardController::class, 'index'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard');
Route::get('/dashboard/check-whapi', [DashboardController::class, 'checkWhapiHealth'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.check-whapi');
Route::get('/dashboard/check-evolution', [DashboardController::class, 'checkEvolutionHealth'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.check-evolution');
Route::get('/dashboard/evolution-qr', [DashboardController::class, 'evolutionQr'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.evolution-qr');

Route::get('/dashboard/sync-financials', [DashboardController::class, 'syncFinancials'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.sync-financials');
Route::post('/dashboard/select-connection', [DashboardController::class, 'selectConnection'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.select-connection');
Route::get('/dashboard/stats', [DashboardController::class, 'stats'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.stats');
Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.chart-data');
Route::get('/dashboard/chart-data-whatsapp', [DashboardController::class, 'chartDataWhatsapp'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.chart-data-whatsapp');
Route::get('/dashboard/chart-data-whatsapp-phones', [DashboardController::class, 'chartDataWhatsappPhones'])
    ->middleware(['auth', 'verified'])
    ->name('dashboard.chart-data-whatsapp-phones');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Integração Conta Azul
    Route::get('/conta-azul/connect', [ContaAzulController::class, 'connect'])->name('contaazul.connect');
    Route::get('/conta-azul/callback', [ContaAzulController::class, 'callback'])->name('contaazul.callback');
    Route::prefix('conta-azul/connections')->name('contaazul.connections.')->group(function () {
        Route::get('/', [ContaAzulConnectionController::class, 'index'])->name('index');
        Route::post('/', [ContaAzulConnectionController::class, 'store'])->name('store');
        Route::put('/{connection}', [ContaAzulConnectionController::class, 'update'])->name('update');
        Route::delete('/{connection}', [ContaAzulConnectionController::class, 'destroy'])->name('destroy');
        Route::get('/{connection}/connect', [ContaAzulConnectionController::class, 'connect'])->name('connect');
        Route::get('/{connection}/callback', [ContaAzulConnectionController::class, 'callback'])->name('callback');
        Route::post('/{connection}/refresh', [ContaAzulConnectionController::class, 'refreshToken'])->name('refresh');
    });

    // Configurações
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/conta-azul', [SettingsController::class, 'index'])->name('contaazul.index');
        Route::post('/conta-azul/sync', [SettingsController::class, 'syncClientes'])->name('contaazul.sync');
        Route::post('/conta-azul/sync-all', [SettingsController::class, 'syncAllClientes'])->name('contaazul.syncAll');
        Route::get('/conta-azul/token', [SettingsController::class, 'getToken'])->name('contaazul.token');

        Route::get('/system', [SettingsController::class, 'system'])->name('system.index');
        Route::post('/system', [SettingsController::class, 'systemSave'])->name('system.save');
        Route::get('/system/json', [SettingsController::class, 'systemJson'])->name('system.json');
        Route::get('/cron/conta-azul', [SettingsController::class, 'contaAzulCronStatus'])->name('cron.contaazul.status');
        Route::post('/cron/conta-azul', [SettingsController::class, 'contaAzulCronToggle'])->name('cron.contaazul.toggle');
        Route::post('/cron/run-command', [SettingsController::class, 'runArtisanCommand'])->name('cron.run-command');
        Route::get('/cron/delayed/status', [SettingsController::class, 'delayedCronStatus'])->name('cron.delayed.status');
        Route::post('/cron/delayed/process', [SettingsController::class, 'processNextDelayedCron'])->name('cron.delayed.process');
        Route::post('/cron/delayed/clear', [SettingsController::class, 'clearDelayedCrons'])->name('cron.delayed.clear');
        Route::post('/cron/process-now', [SettingsController::class, 'processCronsNow'])->name('cron.process_now');
        Route::get('/scheduler/status', [SettingsController::class, 'schedulerStatus'])->name('scheduler.status');
        Route::post('/system/restore-phones', [SettingsController::class, 'restorePhones'])->name('settings.system.restore_phones');

        // Orquestrador
        Route::get('/orchestrator', [SettingsController::class, 'orchestrator'])->name('orchestrator.index');
        Route::post('/orchestrator', [SettingsController::class, 'orchestratorSave'])->name('orchestrator.save');
        Route::get('/orchestrator/status', [SettingsController::class, 'orchestratorStatus'])->name('orchestrator.status');
        Route::post('/orchestrator/resume', [SettingsController::class, 'orchestratorResume'])->name('orchestrator.resume');
        Route::post('/orchestrator/force-resume', [SettingsController::class, 'orchestratorForceResume'])->name('orchestrator.force_resume');
        Route::post('/orchestrator/clear-queue', [SettingsController::class, 'orchestratorClearQueue'])->name('orchestrator.clear_queue');

        // Mensagens Padrão
        Route::resource('templates', WhatsappTemplateController::class);

        // Crons (Mensagens Automáticas)
        Route::get('crons/{cron}/preview', [\App\Http\Controllers\MessageCronController::class, 'preview'])->name('crons.preview');
        Route::post('crons/{cron}/run', [\App\Http\Controllers\MessageCronController::class, 'runNow'])->name('crons.run');
        Route::resource('crons', \App\Http\Controllers\MessageCronController::class);

        // Feriados Nacionais (DEFINIR ANTES do resource para evitar colisão com {restriction})
        Route::get('restrictions/holidays', [\App\Http\Controllers\NationalHolidayController::class, 'index'])->name('restrictions.holidays.index');
        Route::post('restrictions/holidays', [\App\Http\Controllers\NationalHolidayController::class, 'store'])->name('restrictions.holidays.store');
        Route::delete('restrictions/holidays/{holiday}', [\App\Http\Controllers\NationalHolidayController::class, 'destroy'])->name('restrictions.holidays.destroy');

        Route::resource('restrictions', BillingRestrictionController::class)->middleware('can:viewAny,App\Models\BillingRestriction');
        Route::post('restrictions/{restriction}/toggle', [BillingRestrictionController::class, 'toggle'])->name('restrictions.toggle');
        Route::get('restrictions/autocomplete/clients', [BillingRestrictionController::class, 'autocompleteClients'])->name('restrictions.autocomplete.clients');
        Route::get('restrictions/autocomplete/invoices', [BillingRestrictionController::class, 'autocompleteInvoices'])->name('restrictions.autocomplete.invoices');

        Route::resource('roles', RoleController::class)->middleware('permission:roles.manage');
        Route::resource('permissions', PermissionController::class)->middleware('permission:permissions.manage');
    });

    // Módulos
    Route::get('/clientes/faturas-atrasadas', [ClienteController::class, 'overdueInvoices'])->name('clientes.invoices.overdue');
    Route::get('/clientes/{id}/invoices', [ClienteController::class, 'getClientInvoices'])->name('clientes.invoices.json');
    Route::get('/clientes/export', [ClienteController::class, 'export'])->name('clientes.export');
    Route::resource('clientes', ClienteController::class);
    Route::post('/clientes/{cliente}/toggle-international', [ClienteController::class, 'toggleInternational'])->name('clientes.toggle_international');
    Route::resource('empresas', EmpresaController::class);
    Route::post('empresas/{connection}/settings/messages', [EmpresaController::class, 'updateMessageSettings'])->name('empresas.settings.messages');
    Route::post('empresas/{connection}/settings/cron', [EmpresaController::class, 'saveCronRule'])->name('empresas.settings.cron');
    Route::get('whatsapp/reports', [\App\Http\Controllers\WhatsappReportController::class, 'index'])->name('whatsapp.reports.index');
    Route::get('whatsapp/reports/{id}/download', [\App\Http\Controllers\WhatsappReportController::class, 'download'])->name('whatsapp.reports.download');
    Route::get('whatsapp/reports/cron/download-grouped', [\App\Http\Controllers\WhatsappReportController::class, 'downloadGroupedAutomations'])->name('whatsapp.reports.cron.download_grouped');
    Route::get('whatsapp/reports/download-client', [\App\Http\Controllers\WhatsappReportController::class, 'downloadClientReport'])->name('whatsapp.reports.download_client');

    // Envios Futuros
    Route::get('whatsapp/future-messages', [\App\Http\Controllers\FutureMessageController::class, 'index'])->name('whatsapp.future.index');

    Route::resource('whatsapp', WhatsAppController::class)->only(['index','store','update','destroy']);
    Route::get('/whatsapp/retornos', [WhatsAppReturnController::class, 'index'])->name('whatsapp.returns.index');
    Route::get('/whatsapp/retornos/download-xlsx', [WhatsAppReturnController::class, 'downloadXlsx'])->name('whatsapp.returns.download_xlsx');
    Route::get('/whatsapp/retornos/debug', [WhatsAppReturnController::class, 'webhookDebug'])->name('whatsapp.returns.debug');
    Route::get('/whatsapp/retornos/by-ids', [WhatsAppReturnController::class, 'getByIds'])->name('whatsapp.returns.by_ids');

    // Envio de Mensagens
    Route::post('/messages/send', [MessageController::class, 'send'])->name('messages.send')->middleware('permission:messages.send');

    // Gestão de Usuários (Apenas Admin)
    Route::resource('users', UserController::class);
});

require __DIR__.'/auth.php';
require __DIR__.'/debug_auth.php';
