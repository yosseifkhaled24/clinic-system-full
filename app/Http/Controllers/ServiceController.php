<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Http\Requests\ServiceRequest;

class ServiceController extends Controller
{
    public function index()
    {
        $services = Service::all();

        return view('services.index', compact('services'));
    }

    public function create()
    {
        return view('services.create');
    }

    public function store(ServiceRequest $request)
    {
        $data = $request->validated();

        unset($data['image']);

        $service = Service::create($data);

        if ($request->hasFile('image')) {

            $service
                ->addMediaFromRequest('image')
                ->toMediaCollection('services');
        }

        return redirect()->route('services.index');
    }

    public function show(Service $service)
    {
        return view('services.show', compact('service'));
    }

    public function edit(Service $service)
    {
        return view('services.edit', compact('service'));
    }

    public function update(ServiceRequest $request, Service $service)
    {
        $data = $request->validated();

        unset($data['image']);

        $service->update($data);

        if ($request->hasFile('image')) {

            $service->clearMediaCollection('services');

            $service
                ->addMediaFromRequest('image')
                ->toMediaCollection('services');
        }

        return redirect()->route('services.index');
    }

    public function destroy(Service $service)
    {
        $service->delete();

        return redirect()->route('services.index');
    }
}