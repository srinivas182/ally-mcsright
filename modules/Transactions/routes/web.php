<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\Middleware\EnsureTenantWorkspace;
use Modules\Transactions\Http\BoardController;
use Modules\Transactions\Http\BringOverFilesController;
use Modules\Transactions\Http\ClosingController;
use Modules\Transactions\Http\ComplianceController;
use Modules\Transactions\Http\DeadlinesController;
use Modules\Transactions\Http\DealController;
use Modules\Transactions\Http\DealIqController;
use Modules\Transactions\Http\DocumentReadingController;
use Modules\Transactions\Http\FileReviewController;
use Modules\Transactions\Http\MoneyDeskController;
use Modules\Transactions\Http\OffersController;
use Modules\Transactions\Http\PayController;
use Modules\Transactions\Http\PipelineController;
use Modules\Transactions\Http\PlaybooksController;
use Modules\Transactions\Http\PoliciesController;
use Modules\Transactions\Http\PortalAdminController;
use Modules\Transactions\Http\PortalController;
use Modules\Transactions\Http\PublicOfferController;
use Modules\Transactions\Http\RepairsController;
use Modules\Transactions\Http\WinCardController;

// PG1: the CRM pipeline (deals not under contract yet).
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.minimal'])->group(function (): void {
    Route::get('pipeline', [PipelineController::class, 'index'])->name('pipeline');
    Route::post('pipeline/{deal}/stage', [PipelineController::class, 'stage'])->middleware('throttle:120,1')->name('pipeline.stage');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.minimal'])->prefix('deals')->name('deals.')->group(function (): void {
    Route::get('/', [DealController::class, 'index'])->name('index');
    Route::get('review', [DealController::class, 'queue'])->name('review');
    Route::get('listings', [BoardController::class, 'listings'])->name('listings'); // LM1
    Route::get('money', [BoardController::class, 'money'])->name('money');
    Route::get('money/forecast.csv', [BoardController::class, 'forecastCsv'])->middleware('throttle:20,1')->name('money.forecast');
    Route::get('money/royalties.csv', [BoardController::class, 'royaltiesCsv'])->middleware('throttle:20,1')->name('money.royalties');
    Route::post('{deal}/listing', [BoardController::class, 'saveListing'])->name('listing.save');
    Route::get('bring-over-files', [BringOverFilesController::class, 'index'])->name('files.index');
    Route::post('bring-over-files', [BringOverFilesController::class, 'store'])->middleware('throttle:10,1')->name('files.store');
    Route::post('pay-settings', [PayController::class, 'settings'])->name('pay.settings');
    Route::post('pay-from', [PayController::class, 'payFrom'])->name('pay.from');
    Route::get('compliance', [ComplianceController::class, 'settings'])->name('compliance');
    Route::get('risk', [DeadlinesController::class, 'risk'])->name('risk');
    Route::get('risk.csv', [DeadlinesController::class, 'export'])->name('risk.export');
    Route::get('risk/audit-pack', [DeadlinesController::class, 'auditPack'])->middleware(['password.confirm', 'throttle:10,1'])->name('risk.audit-pack'); // RD1
    Route::post('alerts', [DeadlinesController::class, 'consent'])->name('alerts');
    Route::post('calendar', [DeadlinesController::class, 'calendar'])->name('calendar');
    Route::post('compliance/deadlines', [DeadlinesController::class, 'settings'])->name('compliance.deadlines');
    Route::post('compliance/mls', [ComplianceController::class, 'saveMlsRules'])->name('compliance.mls');
    Route::get('playbooks', [PlaybooksController::class, 'index'])->name('playbooks');
    Route::get('vendors', [ClosingController::class, 'vendors'])->name('vendors');
    Route::post('vendors', [ClosingController::class, 'saveVendor'])->name('vendors.store');
    Route::post('vendors/{vendor}', [ClosingController::class, 'saveVendor'])->name('vendors.update');
    Route::delete('vendors/{vendor}', [ClosingController::class, 'removeVendor'])->name('vendors.destroy');
    Route::post('playbooks/{key}', [PlaybooksController::class, 'save'])->where('key', '[a-z0-9_]+')->name('playbooks.save');
    Route::delete('playbooks/{key}', [PlaybooksController::class, 'reset'])->where('key', '[a-z0-9_]+')->name('playbooks.reset');
    Route::post('compliance/checks/{code}', [ComplianceController::class, 'saveCheck'])->where('code', '[a-z_.]+')->name('compliance.check');
    Route::post('compliance/rules', [ComplianceController::class, 'draftRule'])->middleware('throttle:10,1,compliance-rule')->name('compliance.rules.draft');
    Route::post('compliance/rules/{rule}/confirm', [ComplianceController::class, 'confirmRule'])->name('compliance.rules.confirm');
    Route::post('compliance/rules/{rule}/off', [ComplianceController::class, 'deactivateRule'])->name('compliance.rules.off');
    Route::post('/', [DealController::class, 'store'])->name('store');
    // Deal-IQ (specs/ai/02, DQ1–DQ2).
    Route::post('intake', [DealIqController::class, 'intakeFile'])->middleware('throttle:20,1')->name('intake');
    Route::post('intake/text', [DealIqController::class, 'intakeText'])->middleware(['throttle:20,1', 'feature:crm.ai'])->name('intake.text');
    Route::get('{deal}/setup', [DealIqController::class, 'setup'])->name('setup');
    Route::post('{deal}/setup', [DealIqController::class, 'confirm'])->name('setup.confirm');
    Route::post('{deal}/property', [DealIqController::class, 'property'])->name('property');
    Route::post('{deal}/timeline', [DealIqController::class, 'timeline'])->name('timeline');
    Route::post('{deal}/assistant', [DealIqController::class, 'ask'])->middleware(['throttle:30,1', 'feature:crm.ai'])->name('assistant');
    Route::post('{deal}/assistant/{turn}', [DealIqController::class, 'doIt'])->middleware('throttle:30,1')->name('assistant.do');
    Route::post('{deal}/assistant/{turn}/dismiss', [DealIqController::class, 'dismiss'])->name('assistant.dismiss');
    Route::post('{deal}/upload-requests', [DealIqController::class, 'requestUpload'])->middleware('throttle:30,1')->name('upload-requests');
    // The deal portal, agent side (specs/ai/02, DQ3).
    Route::post('{deal}/portal', [PortalAdminController::class, 'invite'])->middleware('throttle:30,1')->name('portal.invite');
    Route::delete('{deal}/portal/{access}', [PortalAdminController::class, 'revoke'])->name('portal.revoke');
    Route::delete('{deal}/portal/{access}/devices', [PortalAdminController::class, 'signOutEverywhere'])->name('portal.sign-out');
    Route::post('{deal}/portal/documents/{document}', [PortalAdminController::class, 'share'])->name('portal.share');
    Route::post('{deal}/portal/questions/{question}', [PortalAdminController::class, 'reply'])->middleware('throttle:30,1')->name('portal.reply');
    // Playbooks, 1031 links and calendars (specs/ai/02, DQ5a).
    Route::post('{deal}/playbook', [PlaybooksController::class, 'choose'])->name('playbook');
    Route::post('{deal}/playbook/{step}/send', [PlaybooksController::class, 'send'])->where('step', '[a-z0-9_]+')->middleware('throttle:20,1')->name('playbook.send');
    Route::post('{deal}/playbook/{step}/skip', [PlaybooksController::class, 'skip'])->where('step', '[a-z0-9_]+')->name('playbook.skip');
    Route::post('{deal}/link', [PlaybooksController::class, 'link'])->name('link');
    Route::post('{deal}/portal/calendars', [PlaybooksController::class, 'calendars'])->middleware('throttle:10,1')->name('portal.calendars');
    // AI document review (specs/ai/02, DQ6).
    Route::post('{deal}/compliance/ask-sign', [ComplianceController::class, 'askSign'])->middleware('throttle:20,1')->name('compliance.ask-sign');
    Route::get('{deal}/file-review', [FileReviewController::class, 'show'])->name('file-review');
    Route::post('{deal}/file-review/brief', [FileReviewController::class, 'brief'])->middleware(['throttle:20,1', 'feature:crm.ai'])->name('file-review.brief');
    Route::post('{deal}/file-review/approve', [FileReviewController::class, 'approve'])->name('file-review.approve');
    Route::post('{deal}/file-review/send-back', [FileReviewController::class, 'sendBack'])->name('file-review.send-back');
    // Closing logistics (specs/ai/02, DQ5b).
    Route::post('{deal}/appointments', [ClosingController::class, 'book'])->middleware('throttle:30,1')->name('appointments.store');
    Route::post('{deal}/appointments/{appointment}/{action}', [ClosingController::class, 'update'])->whereIn('action', ['accept', 'done', 'cancel'])->name('appointments.update');
    Route::post('{deal}/closing', [ClosingController::class, 'details'])->name('closing');
    // Offers and repair requests (specs/ai/02, DQ4).
    Route::get('{deal}/offers', [OffersController::class, 'index'])->name('offers');
    Route::post('{deal}/offers/link', [OffersController::class, 'link'])->name('offers.link');
    Route::post('{deal}/offers/upload', [OffersController::class, 'upload'])->middleware('throttle:20,1')->name('offers.upload');
    Route::post('{deal}/offers', [OffersController::class, 'store'])->name('offers.store');
    Route::post('{deal}/offers/notify', [OffersController::class, 'notify'])->middleware('throttle:10,1')->name('offers.notify');
    Route::post('{deal}/offers/sheet', [OffersController::class, 'sheet'])->name('offers.sheet');
    Route::post('{deal}/offers/summary', [OffersController::class, 'summary'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('offers.summary');
    Route::post('{deal}/offers/summary/save', [OffersController::class, 'saveSummary'])->name('offers.summary.save');
    Route::post('{deal}/offers/{offer}', [OffersController::class, 'update'])->name('offers.update');
    Route::post('{deal}/offers/{offer}/status', [OffersController::class, 'status'])->name('offers.status');
    Route::post('{deal}/offers/{offer}/counter', [OffersController::class, 'counter'])->middleware('throttle:10,1')->name('offers.counter');
    Route::post('{deal}/offers/{offer}/accept', [OffersController::class, 'accept'])->name('offers.accept');
    Route::get('{deal}/offers/{offer}/file', [OffersController::class, 'file'])->name('offers.file');
    Route::get('{deal}/repairs', [RepairsController::class, 'index'])->name('repairs');
    Route::post('{deal}/repairs', [RepairsController::class, 'start'])->middleware(['throttle:10,1', 'feature:crm.ai'])->name('repairs.start');
    Route::post('{deal}/repairs/{repair}', [RepairsController::class, 'save'])->name('repairs.save');
    Route::post('{deal}/repairs/{repair}/draft', [RepairsController::class, 'draft'])->middleware('throttle:10,1')->name('repairs.draft');
    Route::post('{deal}/repairs/{repair}/share', [RepairsController::class, 'share'])->name('repairs.share');
    Route::get('{deal}', [DealController::class, 'show'])->name('show');
    Route::get('{deal}/why', [DealController::class, 'why'])->name('why');
    Route::post('{deal}/fields', [DealController::class, 'saveFields'])->name('fields');
    Route::post('{deal}/status', [DealController::class, 'status'])->name('status');
    Route::post('{deal}/pay/override', [PayController::class, 'override'])->middleware('throttle:20,1')->name('pay.override');
    Route::post('{deal}/pay/reverse', [PayController::class, 'reverse'])->middleware('throttle:20,1')->name('pay.reverse');
    Route::post('{deal}/pay/again', [PayController::class, 'again'])->middleware('throttle:20,1')->name('pay.again');
    Route::post('{deal}/pay/gci', [PayController::class, 'gci'])->middleware('throttle:20,1')->name('pay.gci');
    Route::post('{deal}/dates', [DealController::class, 'addDate'])->name('dates.store');
    Route::post('{deal}/facts', [DocumentReadingController::class, 'apply'])->name('facts.apply');
    Route::post('{deal}/compliance/scan', [ComplianceController::class, 'scan'])->middleware('throttle:20,1,compliance-scan')->name('compliance.scan');
    Route::post('{deal}/compliance/findings/{finding}/dismiss', [ComplianceController::class, 'dismiss'])->whereNumber('finding')->name('compliance.dismiss');
    Route::post('{deal}/compliance/submit', [ComplianceController::class, 'submit'])->name('compliance.submit');
    Route::post('{deal}/compliance/rules/{rule}/approve', [ComplianceController::class, 'approveRule'])->name('compliance.rule.approve');
    Route::post('{deal}/dates/{date}/toggle', [DealController::class, 'toggleDate'])->whereNumber('date')->name('dates.toggle');
    Route::delete('{deal}/dates/{date}', [DealController::class, 'removeDate'])->whereNumber('date')->name('dates.destroy');
    Route::post('{deal}/documents', [DealController::class, 'upload'])->middleware('throttle:60,1')->name('upload');
    Route::get('documents/{document}', [DealController::class, 'download'])->name('download');
    Route::get('documents/{document}/evidence', [ComplianceController::class, 'evidence'])->name('document.evidence');
    Route::get('documents/{document}/safe', [DocumentReadingController::class, 'safe'])->name('document.safe');
    Route::post('documents/{document}/ask', [DocumentReadingController::class, 'ask'])->middleware('throttle:20,1,doc-ask')->name('document.ask');
    Route::post('documents/{document}/compare', [DocumentReadingController::class, 'compare'])->middleware('throttle:30,1')->name('document.compare');
    Route::post('documents/{document}/explain', [DocumentReadingController::class, 'explain'])->middleware('throttle:10,1,doc-explain')->name('document.explain');
    Route::post('documents/{document}/share', [DocumentReadingController::class, 'share'])->middleware('throttle:20,1')->name('document.share');
    Route::post('items/{item}/review', [DealController::class, 'review'])->name('item.review');
    Route::post('review/bulk', [MoneyDeskController::class, 'bulkApprove'])->name('review.bulk');
    Route::post('{deal}/deposits', [MoneyDeskController::class, 'expectDeposit'])->name('deposits.expect');
    Route::post('deposits/{deposit}/receive', [MoneyDeskController::class, 'receiveDeposit'])->name('deposits.receive');
    Route::post('deposits/{deposit}/release', [MoneyDeskController::class, 'releaseDeposit'])->name('deposits.release');
    Route::post('{deal}/referrals', [MoneyDeskController::class, 'referral'])->name('referrals.store');
    Route::post('{deal}/win-card', [WinCardController::class, 'make'])->middleware('throttle:20,1')->name('win.make'); // RF1
    Route::delete('{deal}/win-card', [WinCardController::class, 'hide'])->name('win.hide');
    Route::post('{deal}/cdas', [MoneyDeskController::class, 'generateCda'])->name('cdas.generate');
    Route::post('cdas/{cda}/approve', [MoneyDeskController::class, 'approveCda'])->name('cdas.approve');
    Route::post('cdas/{cda}/send', [MoneyDeskController::class, 'sendCda'])->middleware('throttle:10,1')->name('cdas.send');
    Route::get('{deal}/export', [MoneyDeskController::class, 'export'])->middleware('throttle:10,1')->name('export');
});

Route::middleware(['web', EnsureTenantWorkspace::class, 'auth'])->prefix('settings/transactions')->name('transactions.settings')->group(function (): void {
    Route::get('/', [MoneyDeskController::class, 'settings']);
    Route::post('titles', [MoneyDeskController::class, 'addTitle'])->name('.titles');
    Route::post('titles/{title}/verify', [MoneyDeskController::class, 'verifyTitle'])->name('.titles.verify');
    Route::middleware('password.confirm')->group(function (): void {
        Route::post('wire/propose', [MoneyDeskController::class, 'proposeWire'])->name('.wire.propose');
        Route::post('wire/confirm', [MoneyDeskController::class, 'confirmWire'])->name('.wire.confirm');
        Route::post('agents-direct', [MoneyDeskController::class, 'agentsDirect'])->name('.agents-direct');
    });
});

// The title officer's CDA: a secret link on the brokerage's host, no sign-in.
Route::middleware(['web', EnsureTenantWorkspace::class, 'throttle:30,1'])->group(function (): void {
    Route::get('cda/{token}', [MoneyDeskController::class, 'viewCda'])->where('token', '[A-Za-z0-9]{48}')->name('cda.view');
    Route::post('cda/{token}/acknowledge', [MoneyDeskController::class, 'acknowledgeCda'])->where('token', '[A-Za-z0-9]{48}')->name('cda.acknowledge');
});

// An agent's private calendar feed (subscribed from Google, Outlook or Apple Calendar; the token is the key).
Route::middleware(['web', EnsureTenantWorkspace::class, 'throttle:60,1,calendar-feed'])->get('calendar/{token}.ics', [DeadlinesController::class, 'feed'])->name('calendar.feed');

// The other side's upload link (specs/ai/02, §7.4): no account; one document; the link is the key.
Route::middleware(['web', EnsureTenantWorkspace::class])->prefix('deal-upload/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->group(function (): void {
    Route::get('/', [DealIqController::class, 'uploadPage'])->middleware('throttle:30,1')->name('deal-upload.show');
    Route::post('/', [DealIqController::class, 'uploadReceive'])->middleware('throttle:10,1')->name('deal-upload.store');
});

// The deal portal for clients and the other side (specs/ai/02, DQ3): sign-in links, no account; never indexed.
Route::middleware(['web', EnsureTenantWorkspace::class])->prefix('my-deal')->name('portal.')->group(function (): void {
    Route::get('/', [PortalController::class, 'signIn'])->middleware('throttle:60,1')->name('sign-in');
    Route::post('link', [PortalController::class, 'sendLink'])->middleware('throttle:5,1,portal-link')->name('link');
    Route::post('sign-out', [PortalController::class, 'signOut'])->name('sign-out');
    Route::prefix('in/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->group(function (): void {
        Route::get('/', [PortalController::class, 'enter'])->middleware('throttle:30,1')->name('enter');
        Route::post('code', [PortalController::class, 'sendCode'])->middleware('throttle:5,1,portal-code')->name('code');
        Route::post('verify', [PortalController::class, 'verify'])->middleware('throttle:10,1,portal-verify')->name('verify');
    });
    Route::get('calendar/{token}.ics', [PortalController::class, 'calendarFeed'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:60,1')->name('calendar.feed');
    Route::get('not-me/{token}', [PortalController::class, 'notMe'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:20,1')->name('not-me');
    Route::post('not-me/{token}', [PortalController::class, 'confirmNotMe'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:5,1')->name('not-me.confirm');
    Route::prefix('{access}')->where(['access' => '[0-9A-HJKMNP-TV-Z]{26}'])->group(function (): void {
        Route::get('/', [PortalController::class, 'show'])->middleware('throttle:120,1')->name('show');
        Route::post('ask', [PortalController::class, 'ask'])->middleware('throttle:20,1,portal-ask')->name('ask');
        Route::post('ask/{question}/agent', [PortalController::class, 'askAgent'])->middleware('throttle:20,1')->name('ask.agent');
        Route::post('upload', [PortalController::class, 'upload'])->middleware('throttle:20,1')->name('upload');
        Route::get('documents/{document}', [PortalController::class, 'document'])->middleware('throttle:60,1')->name('document');
        Route::post('preferences', [PortalController::class, 'preferences'])->middleware('throttle:20,1')->name('preferences');
        Route::post('moving/{item}', [PortalController::class, 'moving'])->where('item', '[a-z_]+')->middleware('throttle:60,1')->name('moving');
        Route::get('calendar', [PortalController::class, 'calendar'])->middleware('throttle:10,1')->name('calendar');
        Route::post('feedback', [PortalController::class, 'feedback'])->middleware('throttle:5,1')->name('feedback');
    });
});

// A safe copy shared by link (specs/ai/07): sensitive numbers blacked out, signed and short-lived.
Route::middleware(['web', EnsureTenantWorkspace::class, 'throttle:30,1'])->get('shared/doc/{document}', [DocumentReadingController::class, 'shared'])
    ->where('document', '[0-9A-HJKMNP-TV-Z]{26}')->name('shared.document');

// Offers from buyers' agents (specs/ai/02, DQ4): the listing's offer link, and each agent's private revise link.
Route::middleware(['web', EnsureTenantWorkspace::class])->prefix('offer')->name('offer.')->group(function (): void {
    Route::get('revise/{token}', [PublicOfferController::class, 'revisePage'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('revise');
    Route::post('revise/{token}', [PublicOfferController::class, 'revise'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('revise.store');
    Route::get('{token}', [PublicOfferController::class, 'show'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:30,1')->name('show');
    Route::post('{token}', [PublicOfferController::class, 'store'])->where('token', '[A-Za-z0-9]{48}')->middleware('throttle:10,1')->name('store');
});

// A vendor confirms an appointment or suggests another time (specs/ai/02, DQ5b): no account; the link is the key.
Route::middleware(['web', EnsureTenantWorkspace::class])->prefix('appointment/{token}')->where(['token' => '[A-Za-z0-9]{48}'])->group(function (): void {
    Route::get('/', [ClosingController::class, 'vendorPage'])->middleware('throttle:30,1')->name('appointment.show');
    Route::post('/', [ClosingController::class, 'vendorAnswer'])->middleware('throttle:10,1')->name('appointment.answer');
});

// Compliance Knowledge (specs/ai/05, DQ6): policy questions, the brokerage's documents, acknowledgements.
Route::middleware(['web', EnsureTenantWorkspace::class, 'auth', 'feature:backoffice.minimal'])->prefix('policies')->name('policies.')->group(function (): void {
    Route::get('/', [PoliciesController::class, 'index'])->name('index');
    Route::post('ask', [PoliciesController::class, 'ask'])->middleware(['throttle:30,1', 'feature:crm.ai'])->name('ask');
    Route::post('documents', [PoliciesController::class, 'add'])->middleware('throttle:20,1')->name('documents.store');
    Route::delete('documents/{doc}', [PoliciesController::class, 'remove'])->name('documents.destroy');
    Route::post('documents/{doc}/acknowledge', [PoliciesController::class, 'acknowledge'])->name('documents.acknowledge');
    Route::post('questions/{question}/reply', [PoliciesController::class, 'reply'])->name('questions.reply');
    Route::post('questions/{question}/close', [PoliciesController::class, 'closeGap'])->name('questions.close');
});
