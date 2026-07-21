<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use Illuminate\Http\Request;

class DoctorController extends Controller
{
    public function index(Request $request)
    {
        $query = Doctor::query();

        // Search by doctor name
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->search . '%');
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
            'specialty' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255|unique:doctors,email',
            'phone' => 'nullable|string|max:50',
            'bio' => 'nullable|string',
            'allow_reviews' => 'nullable|boolean',
            'image' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);

        unset($data['image']);

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
            'specialty' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255|unique:doctors,email,' . $doctor->id,
            'phone' => 'sometimes|nullable|string|max:50',
            'bio' => 'sometimes|nullable|string',
            'allow_reviews' => 'sometimes|boolean',
            'image' => 'sometimes|nullable|image|mimes:jpg,jpeg,png|max:2048',
        ]);
        
        unset($data['image']);

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