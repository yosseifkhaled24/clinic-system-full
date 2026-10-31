<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Models\Specialty;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    /**
     * Public doctor list.
     *
     * Filters (all optional, combinable with AND):
     *  - search:       doctor name, LIKE match (pre-existing).
     *  - specialty_id: exact match on the canonical specialties FK.
     *  - specialty:    specialty NAME, whitespace-normalized via
     *                  Specialty::normalizeName() and matched
     *                  case-insensitively against the legacy
     *                  doctors.specialty string (kept in sync with the
     *                  specialties table by the Doctor::saving() hook,
     *                  so both spellings behave identically).
     *
     * An unknown specialty id or name yields an empty {data: []} — the
     * response shape is never altered for any filter combination.
     */
    public function index(Request $request)
    {
        $request->validate([
            'specialty_id' => 'sometimes|nullable|integer',
        ]);

        $query = Doctor::query();

        // Search by doctor name
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
        }

        // Smart booking: doctors of one specialty, by canonical id.
        if ($request->filled('specialty_id')) {
            $query->where('specialty_id', (int) $request->specialty_id);
        }

        // Smart booking: the same filter by specialty name (the public
        // site holds Specialty rows with {id, name}).
        if ($request->filled('specialty')) {
            $query->whereRaw(
                'LOWER(specialty) = LOWER(?)',
                [Specialty::normalizeName((string) $request->specialty)]
            );
        }

        $doctors = $query->get()->map(function ($doctor) {
            $doctor->image_url = $doctor->getFirstMediaUrl('doctors');
            return $doctor;
        });

        return response()->json([
            'data' => $doctors
        ]);
    }

    public function show($id)
    {
        $doctor = Doctor::find($id);

        if (! $doctor) {
            return response()->json([
                'message' => 'Doctor not found'
            ], 404);
        }

        $doctor->image_url = $doctor->getFirstMediaUrl('doctors');

        return response()->json([
            'data' => $doctor
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            // Specialty selection is mandatory for admin writes: the id of
            // a REAL specialties row, validated against the table. Free
            // text is no longer accepted — `prohibited` rejects a
            // `specialty` string outright (with or without specialty_id),
            // so conflicting pairs cannot slip through either. The legacy
            // doctors.specialty string is re-synced from the linked row by
            // the Doctor::saving() hook and stays in every response.
            'specialty_id' => 'required|integer|exists:specialties,id',
            'specialty' => 'prohibited',
            'email' => 'required|email|max:255|unique:doctors,email',
            'phone' => 'required|string|max:50',
            'bio' => 'nullable|string',
            'allow_reviews' => 'sometimes|boolean',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // `image` is handled separately below; `specialty` never reaches
        // mass-assignment (it can only pass validation when null/empty,
        // and the string column is derived from specialty_id).
        unset($data['image'], $data['specialty']);

        $doctor = Doctor::create($data);

        if ($request->hasFile('image')) {
            $doctor
                ->addMediaFromRequest('image')
                ->toMediaCollection('doctors');
        }

        $doctor->image_url = $doctor->getFirstMediaUrl('doctors');

        return response()->json([
            'data' => $doctor
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $doctor = Doctor::find($id);

        if (! $doctor) {
            return response()->json([
                'message' => 'Doctor not found'
            ], 404);
        }

        $data = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            // Same contract as store(): every admin update must carry a
            // valid specialty_id (partial updates included — the specialty
            // is a required attribute of a doctor, not an optional field),
            // and free-text `specialty` is rejected outright.
            'specialty_id' => 'required|integer|exists:specialties,id',
            'specialty' => 'prohibited',
            'email' => 'sometimes|required|email|max:255|unique:doctors,email,' . $doctor->id,
            'phone' => 'sometimes|required|string|max:50',
            'bio' => 'sometimes|nullable|string',
            'allow_reviews' => 'sometimes|boolean',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        // `image` is handled separately below; `specialty` never reaches
        // mass-assignment (it can only pass validation when null/empty,
        // and the string column is derived from specialty_id).
        unset($data['image'], $data['specialty']);

        $doctor->update($data);

        if ($request->hasFile('image')) {
            $doctor->clearMediaCollection('doctors');

            $doctor
                ->addMediaFromRequest('image')
                ->toMediaCollection('doctors');
        }

        $doctor->image_url = $doctor->getFirstMediaUrl('doctors');

        return response()->json([
            'data' => $doctor
        ]);
    }

    public function destroy($id)
    {
        $doctor = Doctor::find($id);

        if (! $doctor) {
            return response()->json([
                'message' => 'Doctor not found'
            ], 404);
        }

        $doctor->delete();

        return response()->json(null, 204);
    }
}