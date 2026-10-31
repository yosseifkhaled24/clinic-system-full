<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Specialty;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Specialties CRUD.
 *
 * Routes mirror the doctors/services split: a public read-only index and
 * show, with every write behind auth:sanctum + the admin middleware.
 *
 * Response shapes follow the existing {data: ...} convention. Renames are
 * mirrored into the legacy doctors.specialty string column so chatbot
 * context, appointment payloads and the frontend (which all read that
 * plain string) stay correct, and in-use specialties are refused with a
 * friendly 409 instead of ever deleting or unlinking doctors — the
 * foreign key's restrict rule is the hard backstop behind that check.
 */
class SpecialtyController extends Controller
{
    public function index(): JsonResponse
    {
        $specialties = Specialty::query()
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $specialties,
        ]);
    }

    public function show($id): JsonResponse
    {
        $specialty = Specialty::find($id);

        if (! $specialty) {
            return response()->json([
                'message' => 'Specialty not found',
            ], 404);
        }

        return response()->json([
            'data' => $specialty,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
        ]);

        // Canonical form (trimmed, inner whitespace collapsed) — the model
        // applies the same normalization on saving as well.
        $data['name'] = Specialty::normalizeName($data['name']);

        if (Specialty::nameTaken($data['name'])) {
            return $this->nameTakenResponse();
        }

        $specialty = Specialty::create($data);

        return response()->json([
            'data' => $specialty,
        ], 201);
    }

    public function update(Request $request, $id): JsonResponse
    {
        $specialty = Specialty::find($id);

        if (! $specialty) {
            return response()->json([
                'message' => 'Specialty not found',
            ], 404);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
        ]);

        if (array_key_exists('name', $data)) {
            $data['name'] = Specialty::normalizeName($data['name']);

            if (Specialty::nameTaken($data['name'], $specialty->id)) {
                return $this->nameTakenResponse();
            }
        }

        $renamed = array_key_exists('name', $data) && $data['name'] !== $specialty->name;

        $specialty->update($data);

        if ($renamed) {
            // Keep the legacy doctors.specialty mirror in step with the new
            // name. Query builder on purpose: no model hooks needed here,
            // the value is already canonical.
            DB::table('doctors')
                ->where('specialty_id', $specialty->id)
                ->update(['specialty' => $specialty->name]);
        }

        return response()->json([
            'data' => $specialty,
        ]);
    }

    public function destroy($id): JsonResponse
    {
        $specialty = Specialty::find($id);

        if (! $specialty) {
            return response()->json([
                'message' => 'Specialty not found',
            ], 404);
        }

        if ($specialty->doctors()->exists()) {
            return $this->inUseResponse();
        }

        try {
            $specialty->delete();
        } catch (QueryException $e) {
            // Foreign-key restrict() backstop: a doctor was assigned
            // between the existence check and the delete. Refusing is the
            // correct outcome — never cascade to doctors.
            return $this->inUseResponse();
        }

        return response()->json(null, 204);
    }

    private function inUseResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Cannot delete a specialty that is assigned to doctors.',
        ], 409);
    }

    /**
     * Standard Laravel "already taken" 422 shape, checked case-insensitively
     * so the answer is identical on MySQL (ci collation) and SQLite.
     */
    private function nameTakenResponse(): JsonResponse
    {
        $message = 'The name has already been taken.';

        return response()->json([
            'message' => 'The given data was invalid.',
            'errors' => [
                'name' => [$message],
            ],
        ], 422);
    }
}
