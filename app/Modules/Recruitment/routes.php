<?php

use App\Modules\Recruitment\Http\Controllers\RecruitmentController as R;
use Illuminate\Support\Facades\Route;

// Already inside the auth:sanctum + account.secure group, under /api.
//   recruitment.requisition — supervisors raise and track their own requisitions;
//   recruitment.manage      — HR runs the pipeline (all requisitions, postings, applicants, offers, onboarding);
//   recruitment.reports     — hiring reports.
// The public careers page (no auth) is registered from module.php.
Route::prefix('recruitment')->group(function () {
    Route::middleware('permission:recruitment.requisition|recruitment.manage')->group(function () {
        // Requesters see and change only their own (controller); managers see all.
        Route::get('requisitions', [R::class, 'requisitions']);
        Route::post('requisitions', [R::class, 'storeRequisition']);
        Route::put('requisitions/{id}', [R::class, 'updateRequisition'])->whereNumber('id');
        Route::delete('requisitions/{id}', [R::class, 'destroyRequisition'])->whereNumber('id');
    });

    Route::middleware('permission:recruitment.reports|recruitment.manage')->group(function () {
        Route::get('reports', [R::class, 'reports']);
        Route::get('reports/export', [R::class, 'exportReport']);
    });

    Route::middleware('permission:recruitment.manage')->group(function () {
        Route::get('postings', [R::class, 'postings']);
        Route::get('applicants', [R::class, 'applicants']);
        Route::get('applicants/{id}', [R::class, 'showApplicant'])->whereNumber('id');
        Route::get('applicants/{id}/cv', [R::class, 'applicantCv'])->whereNumber('id');
        Route::get('interviews', [R::class, 'interviews']);
        Route::get('offers', [R::class, 'offers']);
        Route::get('offers/{id}/pdf', [R::class, 'offerPdf'])->whereNumber('id');
        Route::get('onboarding', [R::class, 'onboarding']);
        Route::get('induction', [R::class, 'induction']);
        Route::get('assets', [R::class, 'assets']);

        foreach ([
            'postings' => 'Posting', 'applicants' => 'Applicant', 'interviews' => 'Interview',
            'offers' => 'Offer', 'induction' => 'Induction', 'assets' => 'Asset',
        ] as $path => $name) {
            Route::post($path, [R::class, "store{$name}"]);
            Route::put("{$path}/{id}", [R::class, "update{$name}"])->whereNumber('id');
            Route::delete("{$path}/{id}", [R::class, "destroy{$name}"])->whereNumber('id');
        }
        // Approved / rejected by someone other than the requester (checked in the controller).
        Route::post('requisitions/{id}/approve', [R::class, 'approveRequisition'])->whereNumber('id');
        Route::post('requisitions/{id}/reject', [R::class, 'rejectRequisition'])->whereNumber('id');
        Route::post('offers/{id}/status', [R::class, 'offerStatus'])->whereNumber('id');
        Route::patch('applicants/{id}/stage', [R::class, 'moveStage'])->whereNumber('id');
        // Prefill for "Convert to employee"; the employee itself is created through POST /employees {applicant_id}.
        Route::get('applicants/{id}/conversion', [R::class, 'conversion'])->whereNumber('id');
        Route::post('onboarding/{employee}/tasks/{task}/complete', [R::class, 'completeTask'])->whereNumber(['employee', 'task']);
    });
});
