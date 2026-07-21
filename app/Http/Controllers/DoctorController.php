<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Http\Requests\DoctorRequest;

class DoctorController extends Controller
{
    public function index()
    {
        $doctors = Doctor::all();

        return view('doctors.index', compact('doctors'));
    }

    public function create()
    {
        return view('doctors.create');
    }

  public function store(DoctorRequest $request)
{
    $data = $request->validated();

    unset($data['image']);

    $doctor = Doctor::create($data);

    if ($request->hasFile('image')) {

        $doctor
            ->addMediaFromRequest('image')
            ->toMediaCollection('doctors');
    }

    return redirect()->route('doctors.index');
}    public function show(Doctor $doctor)
    {
        return view('doctors.show', compact('doctor'));
    }

    public function edit(Doctor $doctor)
    {
        return view('doctors.edit', compact('doctor'));
    }

public function update(DoctorRequest $request, Doctor $doctor)
{
    $data = $request->validated();

    unset($data['image']);

    $doctor->update($data);

    if ($request->hasFile('image')) {

        $doctor->clearMediaCollection('doctors');

        $doctor
            ->addMediaFromRequest('image')
            ->toMediaCollection('doctors');
    }

    return redirect()->route('doctors.index');
}

    public function destroy(Doctor $doctor)
    {
        $doctor->delete();

        return redirect()->route('doctors.index');
    }
}