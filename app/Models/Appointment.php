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
    'needs_investigation',
];

    protected $casts = [
    'appointment_date' => 'date',
    'paid_at' => 'datetime',
    'service_price' => 'decimal:2',
    'needs_investigation' => 'boolean',
];

    /**
     * Statuses that still hold daily capacity or block a same-day rebooking.
     * Cancelled and no-show appointments release their slot (D1) but their
     * queue numbers are never recycled.
     */
    public const ACTIVE_STATUSES = ['pending', 'arrived', 'in_progress', 'completed'];

    public function scopeActive($query)
    {
        return $query->whereIn('status', self::ACTIVE_STATUSES);
    }

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