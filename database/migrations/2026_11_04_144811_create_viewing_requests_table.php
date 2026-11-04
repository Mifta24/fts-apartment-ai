<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('viewing_requests', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('apartment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_type_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->string('visitor_name');
            $table->string('visitor_email')->nullable();
            $table->string('visitor_phone')->nullable();
            $table->string('contact_type', 20)->nullable(); // whatsapp | phone | email
            $table->string('locale', 5)->nullable();
            $table->date('preferred_date');
            $table->string('preferred_time', 5); // HH:MM
            $table->text('notes')->nullable();
            $table->string('status')->default('requested'); // requested | scheduled | completed | cancelled
            $table->timestamps();

            $table->index(['apartment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('viewing_requests');
    }
};
