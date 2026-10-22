<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Organization module: approval workflows, reminders, integrations, webhooks, imports, data exports, support tickets. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('org_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 40);                 // leave_request, medical_claim, payroll_run, ...
            $table->text('description')->nullable();
            $table->json('steps')->nullable();             // [{type: supervisor|hod|role, role?, label}]
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['company_id', 'trigger']);
        });

        Schema::create('org_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('event', 40);                   // contract_expiry, probation_end, document_expiry, birthday, work_anniversary
            $table->unsignedSmallInteger('days_before')->default(0);
            $table->string('channel', 20)->default('email'); // email | sms | in_app
            $table->string('recipients', 20)->default('hr'); // hr | employee | supervisor
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_run_at')->nullable();
            $table->unsignedInteger('last_run_count')->default(0);
            $table->timestamps();
        });

        Schema::create('org_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key', 40);
            $table->boolean('is_enabled')->default(false);
            $table->text('config')->nullable();            // encrypted JSON
            $table->timestamps();
            $table->unique(['company_id', 'key']);
        });

        Schema::create('org_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->text('secret')->nullable();            // encrypted
            $table->json('events')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->timestamps();
        });

        Schema::create('org_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 40);
            $table->string('filename');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 20)->default('pending'); // pending | completed | failed
            $table->json('errors')->nullable();
            $table->timestamps();
        });

        Schema::create('org_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('filename');
            $table->string('path')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('tables')->default(0);
            $table->unsignedInteger('records')->default(0);
            $table->string('status', 20)->default('running'); // running | completed | failed
            $table->string('error')->nullable();
            $table->timestamps();
        });

        Schema::create('org_support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ticket_number', 20)->unique();
            $table->string('subject', 200);
            $table->string('category', 30)->default('technical');
            $table->string('priority', 20)->default('medium');
            $table->text('description');
            $table->string('status', 20)->default('open'); // open | in_progress | resolved | closed
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('org_support_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('org_support_tickets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['org_support_replies', 'org_support_tickets', 'org_backups', 'org_imports', 'org_webhooks', 'org_integrations', 'org_reminders', 'org_workflows'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
