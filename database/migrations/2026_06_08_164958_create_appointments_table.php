<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {

            $table->id();

$table->foreignId('patient_id')->constrained()->cascadeOnDelete();

$table->foreignId('user_id')
    ->nullable()
    ->constrained()
    ->nullOnDelete();

$table->foreignId('doctor_id')->constrained()->cascadeOnDelete();

$table->foreignId('service_id')->constrained()->cascadeOnDelete();

// بيانات الدفع
$table->decimal('service_price', 10, 2);
$table->enum('payment_status', ['paid', 'unpaid'])->default('unpaid');
$table->enum('payment_method', ['cash', 'visa', 'insurance'])->nullable();
$table->timestamp('paid_at')->nullable();

// بيانات الحجز
$table->date('appointment_date');
$table->unsignedInteger('queue_number');

$table->enum('status', [
    'pending',
    'arrived',
    'in_progress',
    'completed',
    'cancelled',
    'no_show'
])->default('pending');

$table->text('notes')->nullable();

$table->timestamps();

$table->index(['doctor_id', 'appointment_date']);

$table->unique([
    'doctor_id',
    'appointment_date',
    'queue_number'
]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};