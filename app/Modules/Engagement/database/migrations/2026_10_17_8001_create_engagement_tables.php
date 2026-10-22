<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete(); // null = anonymous
            $table->string('category', 60);
            $table->text('body');
            $table->boolean('anonymous')->default(false);
            $table->enum('status', ['pending', 'replied'])->default('pending');
            $table->timestamps();
            $table->index(['company_id', 'status']);
        });

        Schema::create('engagement_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('engagement_comments')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->boolean('seen')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_replies');
        Schema::dropIfExists('engagement_comments');
    }
};
