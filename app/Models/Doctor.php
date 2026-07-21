<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Review;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Doctor extends Model implements HasMedia
{
     use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'specialty',
        'email',
        'phone',
        'bio',
        'allow_reviews'
    ];

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
    public function reviews()
    {
     return $this->hasMany(Review::class);
    } 
    public function schedules()
    {
     return $this->hasMany(DoctorSchedule::class);
    }
}