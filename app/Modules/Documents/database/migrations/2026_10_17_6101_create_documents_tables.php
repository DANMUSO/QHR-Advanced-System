<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->enum('group', ['company', 'personal', 'academic', 'professional', 'office'])->default('personal');
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('is_required')->default(false);      // appears on the per-employee checklist
            $table->boolean('allow_self_upload')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::create('employee_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 200);
            $table->enum('source', ['hr', 'self'])->default('hr');
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_confidential')->default(false);
            // Current file (previous files live in employee_document_versions)
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('alert_30_sent_at')->nullable();
            $table->timestamp('alert_7_sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'expiry_date']);
            $table->index(['employee_id', 'document_type_id']);
        });

        Schema::create('employee_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->enum('category', ['memo', 'newsletter', 'policy']);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->foreignId('referred_by_department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->date('effective_date')->nullable();
            $table->enum('audience', ['all', 'department', 'branch'])->default('all');
            $table->json('audience_ids')->nullable();
            $table->boolean('requires_acknowledgement')->default(false);
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->unsignedSmallInteger('version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_reminded_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'category']);
        });

        Schema::create('company_document_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->string('file_path');
            $table->string('file_name');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('uploaded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('company_document_acknowledgements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('acknowledged_at');
            $table->timestamps();
            $table->unique(['company_document_id', 'employee_id'], 'company_doc_ack_unique');
        });
    }

    public function down(): void
    {
        foreach (['company_document_acknowledgements', 'company_document_versions', 'company_documents',
            'employee_document_versions', 'employee_documents', 'document_types'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
