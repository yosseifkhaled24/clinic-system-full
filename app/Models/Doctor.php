<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\Review;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Doctor extends Model implements HasMedia
{
     use HasFactory, InteractsWithMedia;

    protected $fillable = [
        'name',
        'specialty',
        'specialty_id',
        'email',
        'phone',
        'bio',
        'allow_reviews'
    ];

    /**
     * The canonical specialty record backing this doctor.
     *
     * IMPORTANT: never eager-load this relation on models that are
     * serialized into API responses — Eloquent relations override
     * same-named attributes in toArray()/JSON, which would replace the
     * plain `specialty` string every API consumer (frontend, tests,
     * appointment payloads) depends on with an object. Always read
     * `$doctor->specialty` — the synced string attribute — for output.
     * Existing response-shape tests assert the string and act as the
     * regression guard.
     */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }

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

    /**
     * Keep the legacy `specialty` string and the `specialty_id` link in
     * sync on every save. This hook is what allows ALL pre-existing write
     * paths — factories, seeders, tests passing `'specialty' => 'X'`, the
     * free-text admin API field and the legacy Blade form — to keep
     * working completely unchanged while rows end up properly linked.
     *
     * Rules, in order:
     *  1. `specialty_id` changed (explicit FK path, takes precedence when
     *     both fields change in one save) → mirror the linked row's
     *     canonical name into the string column.
     *  2. The string changed (legacy free-text path) → normalize it, link
     *     to the case-insensitively matching specialty row (creating it
     *     when it is new) and mirror that row's canonical name back.
     *  3. Neither changed but no link exists (e.g. a legacy row written
     *     around this hook) → heal the link from the string.
     *
     * An invalid FK id fails loudly at the database's restrict constraint
     * instead of silently unlinking; an unlinked doctor with a usable
     * string gets re-linked by rule 3, so the link cannot be nulled while
     * a name exists.
     */
    protected static function booted(): void
    {
        static::saving(function (Doctor $doctor) {
            // RAW attribute reads only. Accessing $doctor->specialty through
            // getAttribute() would lazy-load the same-named relation whenever
            // the column value is not present yet — and a loaded relation
            // OVERRIDES the plain string in toArray()/JSON, which would break
            // every API response the frontend depends on.
            $attributes = $doctor->getAttributes();
            $specialtyId = $attributes['specialty_id'] ?? null;
            $stringAttribute = $attributes['specialty'] ?? null;
            $string = is_string($stringAttribute) ? $stringAttribute : null;

            // Rule 1: explicit specialty_id wins.
            if ($specialtyId !== null && $doctor->isDirty('specialty_id')) {
                $linkedName = Specialty::query()
                    ->whereKey($specialtyId)
                    ->value('name');

                if (is_string($linkedName) && $linkedName !== '') {
                    $doctor->setAttribute('specialty', $linkedName);
                }

                return;
            }

            // Rule 2: legacy free-text specialty (create or change).
            if ($string !== null && $doctor->isDirty('specialty')) {
                $normalized = Specialty::normalizeName($string);

                if ($normalized !== '') {
                    $specialty = Specialty::findOrCreateByName($normalized);

                    $doctor->setAttribute('specialty_id', $specialty->id);
                    $doctor->setAttribute('specialty', $specialty->name);
                }

                return;
            }

            // Rule 3: heal an unlinked doctor that still has a usable name.
            if ($specialtyId === null && $string !== null) {
                $normalized = Specialty::normalizeName($string);

                if ($normalized !== '') {
                    $specialty = Specialty::findOrCreateByName($normalized);

                    $doctor->setAttribute('specialty_id', $specialty->id);
                    $doctor->setAttribute('specialty', $specialty->name);
                }
            }
        });
    }
}
