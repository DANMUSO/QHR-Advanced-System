<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** When each employee last opened or downloaded a company document (acknowledging requires a view first). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('company_document_views')) {
            return;
        }
        Schema::create('company_document_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('version')->default(1); // document version last viewed
            $table->timestamp('first_viewed_at')->useCurrent();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();
            $table->unique(['company_document_id', 'employee_id'], 'company_doc_view_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_document_views');
    }
};
