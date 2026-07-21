<?php

namespace App\Http\Controllers;

use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Http\Requests\DoctorScheduleRequest;

class DoctorScheduleController extends Controller
{
    public function index()
    {
        $schedules = DoctorSchedule::all();

        return view('schedules.index', compact('schedules'));
    }

    public function create()
    {
        $doctors = Doctor::all();

        return view('schedules.create', compact('doctors'));
    }

    public function store(DoctorScheduleRequest $request)
    {
        $data = $request->validated();

        DoctorSchedule::create($data);

        return redirect()->route('schedules.index');
    }

    public function show(DoctorSchedule $schedule)
    {
        return view('schedules.show', compact('schedule'));
    }

    public function edit(DoctorSchedule $schedule)
    {
        $doctors = Doctor::all();

        return view('schedules.edit', compact('schedule', 'doctors'));
    }

    public function update(DoctorScheduleRequest $request, DoctorSchedule $schedule)
    {
        $data = $request->validated();

        $schedule->update($data);

        return redirect()->route('schedules.index');
    }

    public function destroy(DoctorSchedule $schedule)
    {
        $schedule->delete();

        return redirect()->route('schedules.index');
    }
}