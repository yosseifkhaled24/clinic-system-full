<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table) {

            $table->id();

            $table->foreignId('doctor_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->enum('day', [
                'Saturday',
                'Sunday',
                'Monday',
                'Tuesday',
                'Wednesday',
                'Thursday',
                'Friday'
            ]);

            // وقت بداية العمل
            $table->time('start_time');

            // وقت نهاية العمل
            $table->time('end_time');

            // هل الدكتور متاح؟
            $table->boolean('is_available')->default(true);

            // الحد الأقصى للمرضى
            $table->unsignedInteger('max_patients')->default(30);

            $table->timestamps();

            $table->unique(['doctor_id', 'day']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};