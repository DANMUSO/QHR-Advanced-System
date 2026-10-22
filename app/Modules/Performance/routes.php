<?php

use App\Modules\Performance\Http\Controllers\AppraisalController;
use App\Modules\Performance\Http\Controllers\CoachingController;
use App\Modules\Performance\Http\Controllers\GoalController;
use App\Modules\Performance\Http\Controllers\LearningController;
use App\Modules\Performance\Http\Controllers\PlanController;
use App\Modules\Performance\Http\Controllers\QuestionnaireController;
use App\Modules\Performance\Http\Controllers\RecognitionController;
use App\Modules\Performance\Http\Controllers\ReportController;
use App\Modules\Performance\Http\Controllers\SetupController;
use App\Modules\Performance\Http\Controllers\SuccessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('performance')->group(function () {
    // Employee self-service
    Route::middleware('permission:performance.participate')->group(function () {
        Route::get('my-goals', [GoalController::class, 'mine']);
        Route::get('my-appraisals', [AppraisalController::class, 'mine']);
        Route::get('my-coaching', [CoachingController::class, 'mine']);
        Route::post('appraisals/{appraisal}/self-review', [AppraisalController::class, 'selfReview']);
        Route::post('appraisals/{appraisal}/acknowledge', [AppraisalController::class, 'acknowledge']);
        Route::get('goals/{goal}', [GoalController::class, 'show']);
        Route::get('goals/{goal}/evidence', [GoalController::class, 'evidence']);
        Route::post('goals/{goal}/accept', [GoalController::class, 'accept']);
        Route::post('goals/{goal}/convert', [GoalController::class, 'convertToSelf']);
        Route::post('goals/{goal}/submit', [GoalController::class, 'submit']);
        Route::post('goals/{goal}/request-review', [GoalController::class, 'requestReview']);
        Route::post('goals/{goal}/comment', [GoalController::class, 'comment']);

        // Recognition I received / gave
        Route::get('recognition/my', [RecognitionController::class, 'my']);

        // Learning self-service
        Route::get('learning/catalogue', [LearningController::class, 'selfCatalogue']);
        Route::post('learning/catalogue/{course}/enrol', [LearningController::class, 'selfEnrol'])->whereNumber('course');
        Route::get('learning/my', [LearningController::class, 'my']);
        Route::put('learning/my/{enrollment}', [LearningController::class, 'updateMy'])->whereNumber('enrollment');
    });

    // Open to everyone in the performance process; each action is authorised per record in the controller / workflow.
    Route::middleware('permission:performance.participate|performance.manage-team|performance.admin')->group(function () {
        // Approval queue + decisions: open to anyone a KPI can be routed / escalated to (ApproverResolver, kpi_review
        // approval chain); GoalWorkflow::isApprover authorises each decision.
        Route::get('approvals', [GoalController::class, 'approvals']);
        Route::get('team-goals', [GoalController::class, 'team']);
        Route::post('goals/{goal}/approve', [GoalController::class, 'approve']);
        Route::post('goals/{goal}/reject', [GoalController::class, 'reject']);
        Route::post('goals/{goal}/decide', [GoalController::class, 'decide']);
        Route::post('goals/{goal}/extend', [GoalController::class, 'extend']);
        Route::post('goals/{goal}/amend', [GoalController::class, 'amend']);
        Route::post('goals/{goal}/reject-review', [GoalController::class, 'rejectReview']);
        // An employee with direct reports cascades a received KPI further (GoalWorkflow checks ownership + reports).
        Route::post('goals/{goal}/cascade', [GoalController::class, 'cascade']);

        // Recognition wall: anyone can recognise a colleague (never themselves).
        Route::get('recognition', [RecognitionController::class, 'index']);
        Route::post('recognition', [RecognitionController::class, 'store']);
        Route::delete('recognition/{recognition}', [RecognitionController::class, 'destroy']);

        // Coaching records the employee can open and comment on (staff comment).
        Route::get('coaching/sessions/{session}', [CoachingController::class, 'showSession'])->whereNumber('session');
        Route::put('coaching/sessions/{session}', [CoachingController::class, 'updateSession'])->whereNumber('session');
        Route::get('coaching/pips/{pip}', [CoachingController::class, 'showPip'])->whereNumber('pip');

        // 360 / self-assessment form: the employee rates themself, the supervisor rates them (QuestionnaireController::role).
        Route::get('reviews/{questionnaire}', [QuestionnaireController::class, 'show'])->whereNumber('questionnaire');
        Route::post('reviews/{questionnaire}/respond', [QuestionnaireController::class, 'respond'])->whereNumber('questionnaire');
    });

    // Supervisor (and HR)
    Route::middleware('permission:performance.manage-team|performance.admin')->group(function () {
        Route::get('setup', [SetupController::class, 'index']);
        Route::get('cycles', [SetupController::class, 'cycles']); // read-only for supervisors
        Route::get('team-overview', [GoalController::class, 'teamOverview']);
        Route::delete('goals/{goal}', [GoalController::class, 'destroy']);

        // Assessment plans: Plan → Focus areas → Objectives (weights) → KPIs. PlanController lets the plan's creator or HR edit.
        Route::get('plans', [PlanController::class, 'index']);
        Route::post('plans', [PlanController::class, 'store']);
        Route::get('plans/{plan}', [PlanController::class, 'show'])->whereNumber('plan');
        Route::put('plans/{plan}', [PlanController::class, 'update'])->whereNumber('plan');
        Route::delete('plans/{plan}', [PlanController::class, 'destroy'])->whereNumber('plan');
        Route::put('plans/{plan}/weights', [PlanController::class, 'weights'])->whereNumber('plan');
        Route::post('plans/{plan}/focus-areas', [PlanController::class, 'storeFocusArea'])->whereNumber('plan');
        Route::put('focus-areas/{focusArea}', [PlanController::class, 'updateFocusArea']);
        Route::delete('focus-areas/{focusArea}', [PlanController::class, 'destroyFocusArea']);
        Route::post('focus-areas/{focusArea}/objectives', [PlanController::class, 'storeObjective']);
        Route::put('objectives/{objective}', [PlanController::class, 'updateObjective']);
        Route::delete('objectives/{objective}', [PlanController::class, 'destroyObjective']);
        Route::post('objectives/{objective}/kpis', [PlanController::class, 'storeKpi']);
        Route::put('kpis/{kpi}', [PlanController::class, 'updateKpi']);
        Route::delete('kpis/{kpi}', [PlanController::class, 'destroyKpi']);
        Route::post('kpis/{kpi}/assign', [PlanController::class, 'assign']);

        // Appraisals: team list (scoped in the controller), live preview and finalisation by the supervisor or HR.
        Route::get('appraisals', [AppraisalController::class, 'index']);
        Route::get('appraisals/preview', [AppraisalController::class, 'preview']);
        Route::post('appraisals/finalize', [AppraisalController::class, 'finalize']);
        Route::post('appraisals/{appraisal}/finalize', [AppraisalController::class, 'finalize'])->whereNumber('appraisal');

        // Coaching (Performance Boost Program): discussion points, GROW sessions, PIPs
        Route::get('coaching', [CoachingController::class, 'index']);
        Route::post('coaching', [CoachingController::class, 'store']);
        Route::get('coaching/points', [CoachingController::class, 'points']);
        Route::post('coaching/points', [CoachingController::class, 'storePoint']);
        Route::put('coaching/points/{point}', [CoachingController::class, 'updatePoint'])->whereNumber('point');
        Route::delete('coaching/points/{point}', [CoachingController::class, 'destroyPoint'])->whereNumber('point');
        Route::get('coaching/sessions', [CoachingController::class, 'sessions']);
        Route::post('coaching/sessions', [CoachingController::class, 'storeSession']);
        Route::delete('coaching/sessions/{session}', [CoachingController::class, 'destroySession'])->whereNumber('session');
        Route::post('coaching/sessions/{session}/escalate', [CoachingController::class, 'escalate'])->whereNumber('session');
        Route::get('coaching/pips', [CoachingController::class, 'pips']);
        Route::post('coaching/pips', [CoachingController::class, 'storePip']);
        Route::put('coaching/pips/{pip}', [CoachingController::class, 'updatePip'])->whereNumber('pip');
        Route::post('coaching/pips/{pip}/checkin', [CoachingController::class, 'checkin'])->whereNumber('pip');
        Route::post('coaching/pips/{pip}/close', [CoachingController::class, 'closePip'])->whereNumber('pip');

        // 360 questionnaires
        Route::get('reviews', [QuestionnaireController::class, 'index']);
        Route::post('reviews', [QuestionnaireController::class, 'store']);
        Route::delete('reviews/{questionnaire}', [QuestionnaireController::class, 'destroy']);
    });

    // Admin
    Route::middleware('permission:performance.admin')->group(function () {
        // Cycles
        Route::post('cycles', [SetupController::class, 'storeCycle']);
        Route::put('cycles/{cycle}', [SetupController::class, 'updateCycle']);
        Route::post('cycles/{cycle}/activate', [SetupController::class, 'activateCycle']);
        Route::post('cycles/{cycle}/close', [SetupController::class, 'closeCycle']);
        Route::delete('cycles/{cycle}', [SetupController::class, 'destroyCycle']);

        // Calibration & reports
        Route::get('calibration', [ReportController::class, 'calibration']);
        Route::post('calibration/{appraisal}', [ReportController::class, 'calibrate']);
        Route::get('reports', [ReportController::class, 'index']);
        Route::get('reports/export', [ReportController::class, 'export']);
        Route::get('employees/{employee}/summary', [ReportController::class, 'employeeSummary']);

        // Setup: units, frequencies, scales, bands, questions
        Route::post('units', [SetupController::class, 'storeUnit']);
        Route::put('units/{unit}', [SetupController::class, 'updateUnit']);
        Route::delete('units/{unit}', [SetupController::class, 'destroyUnit']);
        Route::post('frequencies', [SetupController::class, 'storeFrequency']);
        Route::put('frequencies/{frequency}', [SetupController::class, 'updateFrequency']);
        Route::delete('frequencies/{frequency}', [SetupController::class, 'destroyFrequency']);
        Route::put('scale', [SetupController::class, 'saveScale']);
        Route::put('bands', [SetupController::class, 'saveBands']);
        Route::get('categories', [SetupController::class, 'categories']);
        Route::post('categories', [SetupController::class, 'storeCategory']);
        Route::put('categories/{category}', [SetupController::class, 'updateCategory']);
        Route::delete('categories/{category}', [SetupController::class, 'destroyCategory']);
        Route::post('questions', [SetupController::class, 'storeQuestion']);
        Route::put('questions/{question}', [SetupController::class, 'updateQuestion']);
        Route::delete('questions/{question}', [SetupController::class, 'destroyQuestion']);
    });
});

// Learning & Development (HR)
Route::prefix('learning')->middleware('permission:performance.admin')->group(function () {
    Route::get('catalogue', [LearningController::class, 'catalogue']);
    Route::post('catalogue', [LearningController::class, 'storeCourse']);
    Route::put('catalogue/{course}', [LearningController::class, 'updateCourse']);
    Route::delete('catalogue/{course}', [LearningController::class, 'destroyCourse']);
    Route::get('catalogue/{course}/enrollments', [LearningController::class, 'enrollments']);
    Route::post('catalogue/{course}/enrollments', [LearningController::class, 'enroll']);
    Route::put('enrollments/{enrollment}', [LearningController::class, 'updateEnrollment']);
    Route::get('skills', [LearningController::class, 'skills']);
    Route::post('skills', [LearningController::class, 'storeSkill']);
    Route::delete('skills/{skill}', [LearningController::class, 'destroySkill']);
    Route::post('skills/assess', [LearningController::class, 'assessSkill']);
    Route::post('skills/{skill}/assess', [LearningController::class, 'assessSkill']);
    Route::get('certifications', [LearningController::class, 'certifications']);
    Route::post('certifications', [LearningController::class, 'storeCertification']);
    Route::put('certifications/{certification}', [LearningController::class, 'updateCertification']);
    Route::delete('certifications/{certification}', [LearningController::class, 'destroyCertification']);
});

// Legacy self-service paths (same handlers as /performance/learning/my*)
Route::prefix('learning')->middleware('permission:performance.participate')->group(function () {
    Route::get('my', [LearningController::class, 'my']);
    Route::put('my/{enrollment}', [LearningController::class, 'updateMy']);
});

// Succession & talent (SuccessionHub.vue)
Route::prefix('succession')->middleware('permission:performance.admin')->group(function () {
    Route::get('/', [SuccessionController::class, 'index']);
    Route::post('/', [SuccessionController::class, 'store']);
    Route::put('{plan}', [SuccessionController::class, 'update'])->whereNumber('plan');
    Route::delete('{plan}', [SuccessionController::class, 'destroy'])->whereNumber('plan');
    Route::get('careers', [SuccessionController::class, 'careers']);
    Route::post('careers', [SuccessionController::class, 'storeCareer']);
    Route::put('careers/{careerPath}', [SuccessionController::class, 'updateCareer']);
    Route::delete('careers/{careerPath}', [SuccessionController::class, 'destroyCareer']);
    Route::get('talent-pools', [SuccessionController::class, 'pools']);
    Route::post('talent-pools', [SuccessionController::class, 'storePool']);
    Route::put('talent-pools/{talentPool}', [SuccessionController::class, 'updatePool']);
    Route::delete('talent-pools/{talentPool}', [SuccessionController::class, 'destroyPool']);
    Route::get('9box', [SuccessionController::class, 'nineBox']);
});
