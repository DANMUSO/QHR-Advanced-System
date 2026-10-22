<?php

use App\Modules\Documents\Http\Controllers\CompanyDocumentController;
use App\Modules\Documents\Http\Controllers\DocumentReportController;
use App\Modules\Documents\Http\Controllers\DocumentTypeController;
use App\Modules\Documents\Http\Controllers\EmployeeDocumentController;
use Illuminate\Support\Facades\Route;

// Already inside auth:sanctum + account.secure group.

Route::prefix('documents')->group(function () {
    // Document types (HR manages)
    Route::get('types', [DocumentTypeController::class, 'index']);
    Route::middleware('permission:documents.manage')->group(function () {
        Route::post('types', [DocumentTypeController::class, 'store']);
        Route::put('types/{documentType}', [DocumentTypeController::class, 'update']);
        Route::delete('types/{documentType}', [DocumentTypeController::class, 'destroy']);
    });

    // Employee personal file — self-service
    Route::get('my-file', [EmployeeDocumentController::class, 'mine']);
    Route::post('employees/me', [EmployeeDocumentController::class, 'storeMine']);

    // HR: employee file management
    Route::middleware('permission:documents.manage')->group(function () {
        Route::get('employees', [DocumentReportController::class, 'employees']);
        Route::get('expiry', [DocumentReportController::class, 'expiry']);
        Route::get('employees/{employee}', [EmployeeDocumentController::class, 'index']);
        Route::post('employees/{employee}', [EmployeeDocumentController::class, 'store']);
        Route::get('expiring', [EmployeeDocumentController::class, 'expiring']);
        Route::get('overview', [EmployeeDocumentController::class, 'overview']);
    });

    // Individual document actions (access checked inside controller)
    Route::get('employee-docs/{employeeDocument}', [EmployeeDocumentController::class, 'show']);
    Route::put('employee-docs/{employeeDocument}', [EmployeeDocumentController::class, 'update']);
    Route::post('employee-docs/{employeeDocument}/replace', [EmployeeDocumentController::class, 'replace']);
    Route::delete('employee-docs/{employeeDocument}', [EmployeeDocumentController::class, 'destroy']);
    Route::get('employee-docs/{employeeDocument}/download', [EmployeeDocumentController::class, 'download']);

    // Company documents (memos, newsletters, policies)
    Route::get('company', [CompanyDocumentController::class, 'index']);
    Route::get('company/{companyDocument}', [CompanyDocumentController::class, 'show']);
    Route::post('company/{companyDocument}/acknowledge', [CompanyDocumentController::class, 'acknowledge']);
    Route::get('company/{companyDocument}/download', [CompanyDocumentController::class, 'download']);

    Route::middleware('permission:documents.manage')->group(function () {
        Route::post('company', [CompanyDocumentController::class, 'store']);
        Route::put('company/{companyDocument}', [CompanyDocumentController::class, 'update']);
        Route::post('company/{companyDocument}', [CompanyDocumentController::class, 'update']); // multipart (file replacement)
        Route::get('company/{companyDocument}/acknowledgements', [CompanyDocumentController::class, 'acknowledgements']);
        Route::post('company/{companyDocument}/remind', [CompanyDocumentController::class, 'remind']);
        Route::delete('company/{companyDocument}', [CompanyDocumentController::class, 'destroy']);
    });
});
