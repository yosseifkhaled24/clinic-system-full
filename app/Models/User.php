<?php

namespace App\Models;


// SEC-03: the MustVerifyEmail contract is implemented EXPLICITLY so the
// verification flow no longer depends on the trait silently inherited
// from Illuminate\Foundation\Auth\User. Registration never issues a
// token until the address is confirmed.
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Laravel\Sanctum\HasApiTokens;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
   use HasApiTokens, HasFactory, Notifiable;    

    /**
     * The attributes that are mass assignable.
     *
     * SEC-10: `role` is intentionally NOT mass assignable. Role assignment
     * happens only through explicit server-side code (registration pins
     * 'user'; administrators are provisioned solely by `php artisan
     * app:admin`), so no request payload — registration, profile
     * update, or any future form request that accidentally spreads
     * validated data — can escalate a role.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function appointments()
    {
        return $this->hasMany(Appointment::class);
    }
    
    public function reviews()
    {
     return $this->hasMany(Review::class);
    }
    public function patient()
    {
     return $this->hasOne(Patient::class);
    }

}