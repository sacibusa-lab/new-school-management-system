<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdmissionController;
use App\Http\Controllers\Admin\ApplicantController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExamController;
use App\Http\Controllers\Admin\FeeController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\ResultController;
use App\Http\Controllers\Admin\ScoreEntryController;
use App\Http\Controllers\Admin\ScoreImportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LookupController;
use App\Http\Controllers\Public\RegistrationController;
use App\Http\Controllers\Public\ResitController;
use App\Http\Controllers\Public\ResultSlipController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public website — no authentication
|--------------------------------------------------------------------------
| Anyone may apply. Lookups need a number plus a second identifier, so a
| number on its own never exposes somebody else's record.
*/
Route::get('/', HomeController::class)->name('home');

Route::get('admissions/apply', [RegistrationController::class, 'create'])->name('public.register');
Route::post('admissions/apply', [RegistrationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('public.register.store');
Route::get('admissions/apply/submitted', [RegistrationController::class, 'done'])->name('public.register.done');
Route::get('admissions/slip/{applicant}', [RegistrationController::class, 'slip'])->name('public.slip');

Route::get('admission', [LookupController::class, 'status'])
    ->middleware('throttle:40,1')
    ->name('public.status');

// The previous path, kept so anything already printed or bookmarked still works.
Route::redirect('admission-status', 'admission');

// A candidate who missed the cutoff can book a resit without ringing the office.
Route::post('admission/resit', [ResitController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('public.resit.store');

Route::get('check-result', [LookupController::class, 'results'])->middleware('throttle:40,1')->name('public.results');
Route::get('check-result/{termResult}/slip', ResultSlipController::class)->name('public.result.slip');
Route::get('fee-status', [LookupController::class, 'fees'])->middleware('throttle:40,1')->name('public.fees');

/*
|--------------------------------------------------------------------------
| Authentication
|--------------------------------------------------------------------------
*/
Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:10,1');
});

Route::middleware('auth')->group(function () {
    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');

    Route::get('profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::put('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');
});

/*
|--------------------------------------------------------------------------
| Admin / staff control panel
|--------------------------------------------------------------------------
*/
Route::middleware(['auth'])
    ->prefix('admin')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DashboardController::class)->name('dashboard');

        /* ---------------- Admissions ---------------- */
        // Registration is done by the office, so these come before the resource
        // route — otherwise "create" would be read as an applicant id.
        Route::get('applicants/create', [ApplicantController::class, 'create'])->name('applicants.create');
        Route::post('applicants', [ApplicantController::class, 'store'])->name('applicants.store');

        Route::get('applicants/export', [ApplicantController::class, 'export'])->name('applicants.export');

        Route::get('applicants/import', [ApplicantController::class, 'import'])->name('applicants.import');
        Route::get('applicants/import/template', [ApplicantController::class, 'downloadTemplate'])
            ->name('applicants.import.template');
        Route::post('applicants/import/preview', [ApplicantController::class, 'previewImport'])
            ->name('applicants.import.preview');
        Route::post('applicants/import/commit', [ApplicantController::class, 'commitImport'])
            ->name('applicants.import.commit');

        /* Photographs, added after the names are already in. Literal paths before
           the {applicant} wildcard, or "photos" would be read as an applicant id. */
        Route::get('applicants/photos', [ApplicantController::class, 'photos'])->name('applicants.photos');
        Route::post('applicants/photos/preview', [ApplicantController::class, 'previewPhotos'])
            ->name('applicants.photos.preview');
        Route::post('applicants/photos/commit', [ApplicantController::class, 'commitPhotos'])
            ->name('applicants.photos.commit');
        Route::get('applicants/photos/staged/{index}', [ApplicantController::class, 'stagedPhoto'])
            ->name('applicants.photos.staged');

        Route::resource('applicants', ApplicantController::class)
            ->only(['index', 'show', 'edit', 'update', 'destroy']);

        Route::get('applicants/{applicant}/slip', [ApplicantController::class, 'slip'])
            ->name('applicants.slip');

        Route::get('applicants/{applicant}/letter', [ApplicantController::class, 'letter'])
            ->name('applicants.letter');
        Route::get('applicants/{applicant}/letter.pdf', [ApplicantController::class, 'letterPdf'])
            ->name('applicants.letter.pdf');

        // One applicant's photograph, added or replaced from their own page.
        Route::post('applicants/{applicant}/photo', [ApplicantController::class, 'updatePhoto'])
            ->name('applicants.photo.update');
        Route::delete('applicants/{applicant}/photo', [ApplicantController::class, 'destroyPhoto'])
            ->name('applicants.photo.destroy');

        /* ---------------- Examinations ---------------- */
        Route::resource('exams', ExamController::class);
        Route::post('exams/{exam}/subjects', [ExamController::class, 'storeSubject'])->name('exams.subjects.store');
        Route::put('exams/{exam}/subjects/{examSubject}', [ExamController::class, 'updateSubject'])->name('exams.subjects.update');
        Route::delete('exams/{exam}/subjects/{examSubject}', [ExamController::class, 'destroySubject'])->name('exams.subjects.destroy');
        Route::post('exams/{exam}/candidates', [ExamController::class, 'syncCandidates'])->name('exams.candidates.sync');

        // A blank scoresheet laid out one column per paper, so the office can fill
        // it in and upload it back rather than typing every mark.
        Route::get('exams/{exam}/scoresheet', [ExamController::class, 'scoresheetTemplate'])->name('exams.scoresheet-template');

        /* ---------------- Score entry ---------------- */
        Route::get('scores', [ScoreEntryController::class, 'index'])->name('scores.index');

        // Declared before the {examSubject} wildcard, or "grid" would be read as a
        // subject id.
        Route::get('scores/{exam}/grid', [ScoreEntryController::class, 'grid'])->name('scores.grid');
        Route::post('scores/{exam}/grid', [ScoreEntryController::class, 'saveGrid'])->name('scores.grid.store');

        // Also before the wildcard, for the same reason.
        Route::get('scores/{exam}/verify', [ScoreEntryController::class, 'verify'])->name('scores.verify');
        Route::post('scores/{exam}/verify', [ScoreEntryController::class, 'saveVerify'])->name('scores.verify.store');

        Route::get('scores/{exam}/{examSubject}', [ScoreEntryController::class, 'entry'])->name('scores.entry');
        Route::post('scores/{exam}/{examSubject}', [ScoreEntryController::class, 'store'])->name('scores.store');

        /* ---------------- Scoresheet imports ---------------- */
        Route::get('imports', [ScoreImportController::class, 'index'])->name('imports.index');
        Route::post('imports', [ScoreImportController::class, 'store'])->name('imports.store');
        Route::get('imports/{import}', [ScoreImportController::class, 'show'])->name('imports.show');
        Route::post('imports/{import}/process', [ScoreImportController::class, 'process'])->name('imports.process');
        Route::post('imports/{import}/rows/{row}/resolve', [ScoreImportController::class, 'resolveRow'])->name('imports.rows.resolve');
        Route::post('imports/{import}/commit', [ScoreImportController::class, 'commit'])->name('imports.commit');
        Route::delete('imports/{import}', [ScoreImportController::class, 'destroy'])->name('imports.destroy');

        /* ---------------- Cutoff & admission decisions ---------------- */
        Route::get('admissions', [AdmissionController::class, 'index'])->name('admissions.index');
        Route::put('admissions/settings/{level}', [AdmissionController::class, 'updateSetting'])->name('admissions.settings.update');
        Route::post('admissions/{exam}/compute', [AdmissionController::class, 'compute'])->name('admissions.compute');
        Route::post('admissions/{exam}/apply', [AdmissionController::class, 'apply'])->name('admissions.apply');
        Route::post('admissions/decision/{decision}', [AdmissionController::class, 'override'])->name('admissions.decision.override');
        Route::post('admissions/{exam}/enrol', [AdmissionController::class, 'enrol'])->name('admissions.enrol');
        Route::post('admissions/{exam}/resit', [AdmissionController::class, 'resit'])->name('admissions.resit');

        /* ---------------- Students ---------------- */
        Route::resource('students', StudentController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('students/{student}/reset-password', [StudentController::class, 'resetPassword'])->name('students.reset-password');

        /* ---------------- Results ---------------- */
        Route::get('results', [ResultController::class, 'index'])->name('results.index');
        Route::get('results/{termResult}', [ResultController::class, 'show'])->name('results.show');
        Route::post('results/compute', [ResultController::class, 'compute'])->name('results.compute');
        Route::post('results/publish', [ResultController::class, 'publish'])->name('results.publish');
        Route::post('results/unpublish', [ResultController::class, 'unpublish'])->name('results.unpublish');

        /* ---------------- Fees ---------------- */
        Route::get('fees/categories', [FeeController::class, 'categories'])->name('fees.categories.index');
        Route::post('fees/categories', [FeeController::class, 'storeCategory'])->name('fees.categories.store');
        Route::put('fees/categories/{category}', [FeeController::class, 'updateCategory'])->name('fees.categories.update');

        Route::get('fees/structures', [FeeController::class, 'structures'])->name('fees.structures.index');
        Route::post('fees/structures', [FeeController::class, 'storeStructure'])->name('fees.structures.store');
        Route::get('fees/structures/{structure}', [FeeController::class, 'showStructure'])->name('fees.structures.show');
        Route::put('fees/structures/{structure}', [FeeController::class, 'updateStructure'])->name('fees.structures.update');
        Route::post('fees/structures/{structure}/items', [FeeController::class, 'storeStructureItem'])->name('fees.structures.items.store');
        Route::delete('fees/structures/{structure}/items/{item}', [FeeController::class, 'destroyStructureItem'])->name('fees.structures.items.destroy');
        Route::post('fees/structures/{structure}/bill', [FeeController::class, 'billStudents'])->name('fees.structures.bill');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
        Route::get('invoices/{invoice}/receipt/{payment}', [PaymentController::class, 'receipt'])->name('invoices.receipt');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');

        /* ---------------- Messaging ---------------- */
        // Declared before the other sms routes so the literal path can never be
        // read as part of something else.
        Route::get('sms/center', [SmsController::class, 'center'])->name('sms.center');

        Route::get('sms', [SmsController::class, 'index'])->name('sms.index');
        Route::post('sms/flush', [SmsController::class, 'flush'])->name('sms.flush');
        Route::post('sms/{log}/resend', [SmsController::class, 'resend'])->name('sms.resend');

        Route::get('sms/batch', [SmsController::class, 'batch'])->name('sms.batch');
        Route::post('sms/batch', [SmsController::class, 'storeBatch'])->name('sms.batch.store');

        Route::get('sms/templates', [SmsController::class, 'templates'])->name('sms.templates');
        Route::put('sms/templates/{smsTemplate}', [SmsController::class, 'updateTemplate'])->name('sms.templates.update');
        Route::post('sms/templates/{smsTemplate}/reset', [SmsController::class, 'resetTemplate'])->name('sms.templates.reset');

        /* ---------------- Administration ---------------- */
        Route::resource('users', UserController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');
        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/sequences', [SettingController::class, 'updateSequence'])->name('settings.sequences.update');
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });
