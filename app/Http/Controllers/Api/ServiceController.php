<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\JsonResponse;

class ServiceController extends Controller
{
    public function index(): JsonResponse
    {
        // Phase 1: eager-load media so formatService()'s getFirstMediaUrl()
        // reads the already-loaded relation instead of querying once per
        // service (the 1 + N media queries of the audit). formatService()
        // returns a fixed scalar array, so the JSON response is byte-for-
        // byte what it was before (locked by MediaEagerLoadingTest).
        $services = Service::with('media')->get()->map(function (Service $service) {
            return $this->formatService($service);
        });

        return response()->json($services);
    }

    public function show(Service $service): JsonResponse
    {
        return response()->json($this->formatService($service));
    }

    public function store(ServiceRequest $request): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);

        $service = Service::create($data);

        if ($request->hasFile('image')) {
            $service->addMediaFromRequest('image')->toMediaCollection('services');
        }

        return response()->json($this->formatService($service), 201);
    }

    public function update(ServiceRequest $request, Service $service): JsonResponse
    {
        $data = $request->validated();
        unset($data['image']);

        $service->update($data);

        if ($request->hasFile('image')) {
            $service->clearMediaCollection('services');
            $service->addMediaFromRequest('image')->toMediaCollection('services');
        }

        return response()->json($this->formatService($service->refresh()));
    }

    public function destroy(Service $service): JsonResponse
    {
        $service->delete();

        return response()->json(null, 204);
    }

    private function formatService(Service $service): array
    {
        return [
            'id' => $service->id,
            'name' => $service->name,
            'description' => $service->description,
            'price' => $service->price,
            'image_url' => $service->getFirstMediaUrl('services') ?: null,
            'created_at' => $service->created_at?->toDateTimeString(),
            'updated_at' => $service->updated_at?->toDateTimeString(),
        ];
    }
}
