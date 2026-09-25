<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Editable message bodies, so the office can reword them without a developer.
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();          // applicant_registered, applicant_admitted ...
            $table->string('name');
            $table->text('body');                     // supports {placeholders}
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Every message we ever tried to send, successful or not.
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('pending')->index();  // pending|sent|failed|mocked
            $table->string('provider')->nullable();                 // termii
            $table->string('recipient', 30)->index();
            $table->text('body');
            $table->string('template_key')->nullable()->index();
            $table->nullableMorphs('subject');
            $table->string('provider_reference')->nullable();
            $table->json('response')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
        Schema::dropIfExists('sms_templates');
    }
};
