<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Doctor;
use App\Http\Requests\AppointmentRequest;

class AppointmentController extends Controller
{
    public function index()
    {
        $appointments = Appointment::all();

        return view('appointments.index', compact('appointments'));
    }

    public function create()
    {
        $users = User::all();
        $doctors = Doctor::all();

        return view('appointments.create', compact('users', 'doctors'));
    }

    public function store(AppointmentRequest $request)
    {
        $data = $request->validated();

        Appointment::create($data);

        return redirect()->route('appointments.index');
    }

    public function show(Appointment $appointment)
    {
        return view('appointments.show', compact('appointment'));
    }

    public function edit(Appointment $appointment)
    {
        $users = User::all();
        $doctors = Doctor::all();

        return view('appointments.edit', compact('appointment', 'users', 'doctors'));
    }

    public function update(AppointmentRequest $request, Appointment $appointment)
    {
        $data = $request->validated();

        $appointment->update($data);

        return redirect()->route('appointments.index');
    }

    public function destroy(Appointment $appointment)
    {
        $appointment->delete();

        return redirect()->route('appointments.index');
    }
}