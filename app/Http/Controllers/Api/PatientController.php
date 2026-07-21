<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PatientController extends Controller
{
    public function index(): JsonResponse
    {
        $patients = Patient::orderBy('name')->get();

        return response()->json($patients);
    }

    public function show(Patient $patient): JsonResponse
    {
        return response()->json($patient);
    }

    public function search(Request $request): JsonResponse
    {
        $query = $request->query('query');

        $patients = Patient::query()
            ->when($query, function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('phone', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->get();

        return response()->json($patients);
    }

    public function update(Request $request, Patient $patient): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|max:20',
            'email' => 'nullable|email',
            'gender' => 'nullable|string',
            'date_of_birth' => 'nullable|date',
            'address' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        $patient->update($data);

        return response()->json([
            'message' => 'Patient updated successfully.',
            'data' => $patient,
        ]);
    }

    public function destroy(Patient $patient): JsonResponse
    {
        $patient->delete();

        return response()->json([
            'message' => 'Patient deleted successfully.'
        ]);
    }
}