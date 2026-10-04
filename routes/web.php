<?php

use App\Http\Controllers\Admin\AcademicCalendarController;
use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AddStudentController;
use App\Http\Controllers\Admin\AdmissionController;
use App\Http\Controllers\Admin\ApplicantController;
use App\Http\Controllers\Admin\BankAccountController;
use App\Http\Controllers\Admin\CheckResultController;
use App\Http\Controllers\Admin\ClassesAndSectionsController;
use App\Http\Controllers\Admin\ClassSectionReportController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ExamController;
use App\Http\Controllers\Admin\FeeController;
use App\Http\Controllers\Admin\FeesPaymentsController;
use App\Http\Controllers\Admin\InvoiceController;
use App\Http\Controllers\Admin\PaymentController;
use App\Http\Controllers\Admin\PrintingController;
use App\Http\Controllers\Admin\PromotionController;
use App\Http\Controllers\Admin\ResultController;
use App\Http\Controllers\Admin\ResultPinController;
use App\Http\Controllers\Admin\ScholarshipController;
use App\Http\Controllers\Admin\ScoreEntryController;
use App\Http\Controllers\Admin\ScoreImportController;
use App\Http\Controllers\Admin\SettingController;
use App\Http\Controllers\Admin\SmsController;
use App\Http\Controllers\Admin\StudentController;
use App\Http\Controllers\Admin\StudentImportController;
use App\Http\Controllers\Admin\StudentRegisterController;
use App\Http\Controllers\Admin\StudentsResultsController;
use App\Http\Controllers\Admin\SubjectAssignmentController;
use App\Http\Controllers\Admin\SubjectController;
use App\Http\Controllers\Admin\TeachersController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Admin\VirtualAccountController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Public\HomeController;
use App\Http\Controllers\Public\LookupController;
use App\Http\Controllers\Public\RegistrationController;
use App\Http\Controllers\Public\ResitController;
use App\Http\Controllers\Public\ResultSlipController;
use App\Http\Controllers\WebhookController;
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
 * Where the payment gateway tells us money has arrived. It cannot carry a session
 * or a CSRF token, and is answered only if the signature over its raw body checks
 * out against the school's secret key — see WebhookController.
 *
 * Deliberately not throttled: term-time payments arrive in bursts, and a 429 here
 * would make Paystack retry a charge that was real.
 */
Route::post('webhooks/paystack', [WebhookController::class, 'paystack'])->name('webhooks.paystack');

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
Route::middleware('auth')
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

        // The papers a family brings in, attached to their own record.
        Route::post('applicants/{applicant}/documents', [ApplicantController::class, 'storeDocuments'])
            ->name('applicants.documents.store');
        Route::delete('applicants/{applicant}/documents', [ApplicantController::class, 'destroyDocument'])
            ->name('applicants.documents.destroy');

        /* ---------------- Examinations ---------------- */
        Route::resource('exams', ExamController::class);
        Route::post('exams/{exam}/subjects', [ExamController::class, 'storeSubject'])->name('exams.subjects.store');
        Route::put('exams/{exam}/subjects/{examSubject}', [ExamController::class, 'updateSubject'])->name('exams.subjects.update');
        Route::delete('exams/{exam}/subjects/{examSubject}', [ExamController::class, 'destroySubject'])->name('exams.subjects.destroy');
        Route::post('exams/{exam}/candidates', [ExamController::class, 'syncCandidates'])->name('exams.candidates.sync');

        // A blank scoresheet laid out one column per paper, so the office can fill
        // it in and upload it back rather than typing every mark.
        Route::get('exams/{exam}/scoresheet', [ExamController::class, 'scoresheetTemplate'])->name('exams.scoresheet-template');
        Route::get('exams/{exam}/admit-cards', [ExamController::class, 'admitCards'])->name('exams.admit-cards');

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

        /* Where the office goes to print: every list the school hands out, in one
           place, rather than on whichever desk happens to produce it. Declared
           before the {exam} routes below, as a literal path. */
        Route::get('admissions/printing', [PrintingController::class, 'index'])->name('admissions.printing');
        Route::put('admissions/settings/{level}', [AdmissionController::class, 'updateSetting'])->name('admissions.settings.update');
        Route::post('admissions/{exam}/compute', [AdmissionController::class, 'compute'])->name('admissions.compute');
        Route::post('admissions/{exam}/apply', [AdmissionController::class, 'apply'])->name('admissions.apply');
        Route::post('admissions/decision/{decision}', [AdmissionController::class, 'override'])->name('admissions.decision.override');
        Route::post('admissions/{exam}/enrol', [AdmissionController::class, 'enrol'])->name('admissions.enrol');

        /* The paperwork: the sheet the school pins up, the letters it posts, and
           the waiting list it works from when a place comes free. */
        Route::get('admissions/{exam}/merit', [AdmissionController::class, 'merit'])->name('admissions.merit');
        Route::get('admissions/{exam}/merit.csv', [AdmissionController::class, 'meritCsv'])->name('admissions.merit.csv');
        Route::get('admissions/{exam}/letters', [AdmissionController::class, 'letters'])->name('admissions.letters');
        Route::get('admissions/{exam}/waiting', [AdmissionController::class, 'waiting'])->name('admissions.waiting');
        Route::post('admissions/{exam}/waiting', [AdmissionController::class, 'promote'])->name('admissions.waiting.promote');
        Route::post('admissions/{exam}/resit', [AdmissionController::class, 'resit'])->name('admissions.resit');

        /* ---------------- Students ---------------- */
        Route::resource('students', StudentController::class)->only(['index', 'show', 'edit', 'update']);
        Route::post('students/{student}/reset-password', [StudentController::class, 'resetPassword'])->name('students.reset-password');

        // One student's photograph, added or replaced from their own record. It is the
        // same picture the termly results sheet and the fee slip will print, so it is
        // kept on the student rather than on the application they came in on.
        Route::post('students/{student}/photo', [StudentController::class, 'updatePhoto'])
            ->name('students.photo.update');
        Route::delete('students/{student}/photo', [StudentController::class, 'destroyPhoto'])
            ->name('students.photo.destroy');

        /* ---------------- Results ---------------- */
        Route::get('results', [ResultController::class, 'index'])->name('results.index');
        Route::get('results/{termResult}', [ResultController::class, 'show'])->name('results.show');
        Route::post('results/compute', [ResultController::class, 'compute'])->name('results.compute');
        Route::post('results/publish', [ResultController::class, 'publish'])->name('results.publish');
        Route::post('results/unpublish', [ResultController::class, 'unpublish'])->name('results.unpublish');

        /*
        | Students & Results — the module being built one page at a time.
        |
        | Every route is listed individually rather than caught by a {page}
        | wildcard: each page is going to grow its own controller, its own
        | permission and eventually its own children, and a wildcard would have to
        | be pulled apart again at that point. Declared before the Fees block so
        | the URL prefix keeps this module visibly separate from the flat
        | /admin/students and /admin/results routes.
        */
        Route::prefix('students-results')->name('students-results.')->group(function (): void {
            Route::get('/', [StudentsResultsController::class, 'dashboard'])->name('dashboard');
            // Graduated out of the placeholder controller: it has a lookup and a
            // report card in it now, not a page saying it has not been built.
            Route::get('check-result', CheckResultController::class)->name('check-result');
            Route::get('performance', [StudentsResultsController::class, 'performance'])->name('performance');
            Route::get('pins', [ResultPinController::class, 'index'])->name('pins');
            Route::post('pins', [ResultPinController::class, 'store'])->name('pins.generate');

            // The sheet the office cuts up. A literal path, declared with the rest so
            // that nothing can read "sheet" as the id of anything.
            Route::get('pins/sheet', [ResultPinController::class, 'sheet'])->name('pins.sheet');
            // Graduated out of the placeholder controller, as Check Result, Generate
            // Pin and Promotion did before it: the student register, narrowed by class
            // and section, with the two ways of taking somebody off it.
            Route::get('students', StudentRegisterController::class)->name('students');

            // The pages that hang off Students Details — all literal paths, declared
            // before {student} so that none of them can be read as the id of a student,
            // and written in the order the submenu lists them.
            Route::get('students/add', [AddStudentController::class, 'create'])
                ->name('students.add');
            Route::post('students/add', [AddStudentController::class, 'store'])
                ->name('students.add.store');

            Route::get('students/class-section-report', ClassSectionReportController::class)
                ->name('students.class-section-report');

            /*
            | Taking a class onto the roll from a spreadsheet. Four addressed paths
            | rather than one {step}, the same way the teachers import is laid out: the
            | sample file, the read and the write are different requests, and a wildcard
            | would have to be pulled apart the first time one needs a child of its own.
            */
            Route::get('students/multiple-import', [StudentImportController::class, 'index'])
                ->name('students.multiple-import');
            Route::get('students/multiple-import/sample', [StudentImportController::class, 'template'])
                ->name('students.multiple-import.template');
            Route::post('students/multiple-import/preview', [StudentImportController::class, 'preview'])
                ->name('students.multiple-import.preview');
            Route::post('students/multiple-import/commit', [StudentImportController::class, 'commit'])
                ->name('students.multiple-import.commit');

            Route::delete('students', [StudentRegisterController::class, 'destroySelected'])->name('students.destroy-selected');
            Route::delete('students/{student}', [StudentRegisterController::class, 'destroy'])->name('students.destroy');

            /*
            | Teachers, and the two pages that hang off it. `teachers` is the
            | section's own page, which lists the two below it rather than
            | duplicating one of them; `teachers/list` and `teachers/create` are
            | declared after it as literal segments, so "list" and "create" can
            | never be read as the id of a teacher.
            */
            Route::get('teachers', [StudentsResultsController::class, 'teachers'])->name('teachers');
            Route::get('teachers/list', [TeachersController::class, 'index'])->name('teachers.list');
            Route::get('teachers/create', [TeachersController::class, 'create'])->name('teachers.create');
            Route::post('teachers', [TeachersController::class, 'store'])->name('teachers.store');

            // Bulk upload: all four are literal paths, so none of them can be read
            // as the id of a teacher, and they are declared before {teacher} for
            // the same reason.
            Route::get('teachers/import', [TeachersController::class, 'import'])->name('teachers.import');
            Route::get('teachers/import/template', [TeachersController::class, 'downloadTemplate'])->name('teachers.import.template');
            Route::post('teachers/import/preview', [TeachersController::class, 'previewImport'])->name('teachers.import.preview');
            Route::post('teachers/import/commit', [TeachersController::class, 'commitImport'])->name('teachers.import.commit');
            Route::get('teachers/import/done', [TeachersController::class, 'importDone'])->name('teachers.import.done');

            Route::get('teachers/{teacher}/edit', [TeachersController::class, 'edit'])->name('teachers.edit');
            Route::put('teachers/{teacher}', [TeachersController::class, 'update'])->name('teachers.update');
            Route::post('teachers/{teacher}/password', [TeachersController::class, 'resetPassword'])->name('teachers.password.reset');

            /*
            | Removing one teacher, and removing several at once. The collection
            | route is listed first because it has no id to be confused with one.
            */
            Route::delete('teachers', [TeachersController::class, 'destroySelected'])->name('teachers.destroy-selected');
            Route::delete('teachers/{teacher}', [TeachersController::class, 'destroy'])->name('teachers.destroy');

            Route::get('academics', [StudentsResultsController::class, 'academics'])->name('academics');

            /*
            | The four pages that hang off Academic, listed individually for the
            | same reason as the menu above: each will grow its own controller,
            | and a {page} wildcard under `academics` would have to be pulled
            | apart the first time one of them needs a child of its own.
            */
            Route::get('academics/classes', [ClassesAndSectionsController::class, 'index'])->name('academics.classes');

            // Sections are created first, then the class names, then a class out of
            // the two of them — the order the page itself is laid out in. The
            // literal segments are declared before {level} so that "sections" and
            // "names" are never read as the id of a class.
            Route::post('academics/classes/sections', [ClassesAndSectionsController::class, 'storeSection'])->name('academics.classes.sections.store');
            Route::delete('academics/classes/sections/{section}', [ClassesAndSectionsController::class, 'destroySection'])->name('academics.classes.sections.destroy');

            Route::post('academics/classes/names', [ClassesAndSectionsController::class, 'storeClass'])->name('academics.classes.names.store');
            Route::put('academics/classes/names/{level}', [ClassesAndSectionsController::class, 'updateClass'])->name('academics.classes.names.update');
            Route::delete('academics/classes/names/{level}', [ClassesAndSectionsController::class, 'destroyClass'])->name('academics.classes.names.destroy');
            Route::patch('academics/classes/names/{level}/status', [ClassesAndSectionsController::class, 'updateClassStatus'])->name('academics.classes.names.status');

            // The class teacher belongs to the class rather than the class name:
            // JSS1A and JSS1B have one each. The allocation form names the class the
            // way the school says it — the class, then the section — so it posts the
            // two rather than an id already known, and this is declared before
            // {level} so that "teacher" is never read as a class name.
            Route::post('academics/classes/teacher', [ClassesAndSectionsController::class, 'storeFormTeacher'])->name('academics.classes.teacher.store');
            Route::delete('academics/classes/{schoolClass}/teacher', [ClassesAndSectionsController::class, 'destroyFormTeacher'])->name('academics.classes.teacher.destroy');

            Route::post('academics/classes/{level}', [ClassesAndSectionsController::class, 'storeClassSection'])->name('academics.classes.store-class');
            Route::delete('academics/classes/{schoolClass}', [ClassesAndSectionsController::class, 'destroyClassSection'])->name('academics.classes.destroy-class');

            Route::get('academics/subjects', [SubjectController::class, 'index'])->name('academics.subjects');
            Route::post('academics/subjects', [SubjectController::class, 'store'])->name('academics.subjects.store');
            Route::put('academics/subjects/{subject}', [SubjectController::class, 'update'])->name('academics.subjects.update');
            Route::patch('academics/subjects/{subject}/status', [SubjectController::class, 'updateStatus'])->name('academics.subjects.status');
            Route::get('academics/subjects/assignments', [SubjectAssignmentController::class, 'index'])->name('academics.subjects.assignments');
            Route::post('academics/subjects/assignments', [SubjectAssignmentController::class, 'store'])->name('academics.subjects.assignments.store');
            Route::put('academics/subjects/assignments/{schoolClass}', [SubjectAssignmentController::class, 'update'])->name('academics.subjects.assignments.update');
            Route::delete('academics/subjects/assignments/{schoolClass}', [SubjectAssignmentController::class, 'destroy'])->name('academics.subjects.assignments.destroy');
            Route::get('academics/schedule', [StudentsResultsController::class, 'academicSchedule'])->name('academics.schedule');
            Route::get('academics/promotion', [PromotionController::class, 'index'])->name('academics.promotion');
            Route::post('academics/promotion', [PromotionController::class, 'store'])->name('academics.promotion.store');

            Route::get('exam-master', [StudentsResultsController::class, 'examMaster'])->name('exam-master');
            Route::get('attendance', [StudentsResultsController::class, 'attendance'])->name('attendance');
            Route::get('reports', [StudentsResultsController::class, 'reports'])->name('reports');
            Route::get('alumni', [StudentsResultsController::class, 'alumni'])->name('alumni');
            Route::get('settings', [StudentsResultsController::class, 'settings'])->name('settings');
        });

        /* ---------------- Fees ---------------- */
        // The catalogue of what the school charges. Listed before the rest because it is
        // the bare path, the one the sidebar points at. The categories and structures
        // below it predate it and still answer at their own addresses.
        Route::get('fees', [FeeController::class, 'index'])->name('fees.index');
        Route::post('fees', [FeeController::class, 'store'])->name('fees.store');

        // A fee's own page. It cannot be read as one of the literal paths below, because
        // the last segment is the literal "edit" and theirs are "categories" and
        // "structures".
        Route::get('fees/{fee}/edit', [FeeController::class, 'edit'])->name('fees.edit');
        Route::put('fees/{fee}', [FeeController::class, 'update'])->name('fees.update');
        Route::post('fees/{fee}/toggle', [FeeController::class, 'toggle'])->name('fees.toggle');

        // The two tabs that divide a fee up: between the school's own accounts, and
        // differently for a year group.
        Route::post('fees/{fee}/beneficiaries', [FeeController::class, 'saveBeneficiaries'])->name('fees.beneficiaries');
        Route::post('fees/{fee}/overrides', [FeeController::class, 'saveOverrides'])->name('fees.overrides');

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

        // What a student is let off. It changes what a bill says, so it is decided
        // here and written onto the bill, rather than edited on the bill itself and
        // leaving no record of who agreed to it.
        Route::get('fees/scholarships', [ScholarshipController::class, 'index'])->name('fees.scholarships.index');
        Route::post('fees/scholarships', [ScholarshipController::class, 'store'])->name('fees.scholarships.store');
        Route::post('fees/scholarships/{scholarship}/approve', [ScholarshipController::class, 'approve'])->name('fees.scholarships.approve');
        Route::post('fees/scholarships/{scholarship}/reject', [ScholarshipController::class, 'reject'])->name('fees.scholarships.reject');

        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::post('invoices', [InvoiceController::class, 'store'])->name('invoices.store');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');
        Route::get('invoices/{invoice}/receipt/{payment}', [PaymentController::class, 'receipt'])->name('invoices.receipt');

        Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');
        Route::post('payments/{payment}/reverse', [PaymentController::class, 'reverse'])->name('payments.reverse');

        // The rest of the collection side, in the order the school's own fees site
        // puts it. Each is a page saying what will be on it until it is built — see
        // PaymentController::PAGES — so the menu can be complete without a link that
        // errors.
        Route::get('payments/overview', [PaymentController::class, 'overview'])->name('payments.overview');

        // The detail behind one row of the overview, fetched when that row is opened. The
        // first answers JSON rather than drawing a page, which is why neither is in the
        // controller's PAGES list — that list is the screens, and these are one screen's
        // figures.
        Route::get('payments/level', [PaymentController::class, 'level'])->name('payments.level');
        Route::get('payments/level/export', [PaymentController::class, 'exportLevel'])->name('payments.level.export');

        Route::get('payments/schedule', [PaymentController::class, 'schedule'])->name('payments.schedule');
        Route::get('payments/settlements', [PaymentController::class, 'settlements'])->name('payments.settlements');
        Route::get('payments/reports', [PaymentController::class, 'reports'])->name('payments.reports');

        // The account number a child's fees are paid into. Paystack issues it; the
        // office presses the button from the student's bill.
        //
        // The plural is listed first so the literal path can never be read as the id
        // of a student. It takes the names ticked in the students hub, and is the one
        // place a September intake is opened without pressing anything two hundred
        // times.
        Route::post('fees/students/virtual-accounts', [VirtualAccountController::class, 'storeMany'])
            ->name('fees.virtual-accounts');

        Route::post('fees/students/{student}/virtual-account', [VirtualAccountController::class, 'store'])
            ->name('fees.virtual-account');

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

        // The two screens the Fees & Payments banner owns itself: its dashboard, and the
        // students read by what they owe.
        Route::get('fees-payments/dashboard', [FeesPaymentsController::class, 'dashboard'])->name('fees-payments.dashboard');
        Route::get('fees-payments/students-hub', [FeesPaymentsController::class, 'studentsHub'])->name('fees-payments.students-hub');

        /* ---------------- Business ---------------- */
        // The school's own accounts, which are what a letter names — as against the
        // account number each child is given, which lives under Payments.
        Route::get('bank-accounts', [BankAccountController::class, 'index'])->name('bank-accounts.index');
        Route::post('bank-accounts', [BankAccountController::class, 'store'])->name('bank-accounts.store');

        // Whose account a number is, asked while the form is being filled in. Literal
        // path, and before the routes that take an id, so "resolve" can never be read
        // as one. Throttled because each call is a call to Paystack.
        Route::post('bank-accounts/resolve', [BankAccountController::class, 'resolve'])
            ->middleware('throttle:30,1')
            ->name('bank-accounts.resolve');

        Route::put('bank-accounts/{account}', [BankAccountController::class, 'update'])->name('bank-accounts.update');
        Route::delete('bank-accounts/{account}', [BankAccountController::class, 'destroy'])->name('bank-accounts.destroy');

        /* ---------------- Administration ---------------- */
        Route::resource('users', UserController::class)->only(['index', 'store', 'edit', 'update', 'destroy']);
        Route::get('settings', [SettingController::class, 'index'])->name('settings.index');

        // The settings the office sets up in a sitting of their own. Saved through
        // the same route as the rest: a setting is a setting wherever it is drawn.
        Route::get('settings/admissions', [SettingController::class, 'admissions'])->name('settings.admissions');

        // The accounts the school holds elsewhere — Paystack, Termii, DeepSeek.
        Route::get('settings/api', [SettingController::class, 'api'])->name('settings.api');

        Route::put('settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('settings/sequences', [SettingController::class, 'updateSequence'])->name('settings.sequences.update');

        // Where the school is now: the session we are in and the term that is
        // active, plus the calendar itself — adding and deleting sessions and
        // terms. Its own controller because it moves rows, not setting values.
        Route::prefix('settings/academic')->name('settings.academic.')->group(function (): void {
            Route::put('/', [AcademicCalendarController::class, 'update'])->name('update');

            Route::post('sessions', [AcademicCalendarController::class, 'storeSession'])->name('sessions.store');
            Route::delete('sessions/{academicSession}', [AcademicCalendarController::class, 'destroySession'])->name('sessions.destroy');

            Route::post('terms', [AcademicCalendarController::class, 'storeTerm'])->name('terms.store');
            Route::put('terms/{term}/dates', [AcademicCalendarController::class, 'updateTermDates'])->name('terms.dates');
            Route::delete('terms/{term}', [AcademicCalendarController::class, 'destroyTerm'])->name('terms.destroy');
        });
        Route::get('activity', [ActivityLogController::class, 'index'])->name('activity.index');
    });
