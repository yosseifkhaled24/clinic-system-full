<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Appointment extends Model
{
    use HasFactory;

 protected $fillable = [
    'patient_id',
    'user_id',
    'doctor_id',
    'service_id',

    'appointment_date',
    'queue_number',

    'service_price',
    'payment_status',
    'payment_method',
    'paid_at',

    'status',
    'notes',
];

    protected $casts = [
    'appointment_date' => 'date',
    'paid_at' => 'datetime',
    'service_price' => 'decimal:2',
];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function doctor()
    {
        return $this->belongsTo(Doctor::class);
    }

    public function service()
    {
        return $this->belongsTo(Service::class);
    }

    public function review()
    {
        return $this->hasOne(Review::class);
    }
}