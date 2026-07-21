<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use App\Models\Doctor;
use App\Models\Appointment;
use App\Http\Requests\ReviewRequest;

class ReviewController extends Controller
{
    public function index()
    {
        $reviews = Review::all();

        return view('reviews.index', compact('reviews'));
    }

    public function create()
    {
        $users = User::all();
        $doctors = Doctor::all();
        $appointments = Appointment::all();

        return view('reviews.create', compact(
            'users',
            'doctors',
            'appointments'
        ));
    }

    public function store(ReviewRequest $request)
    {
        $data = $request->validated();

        Review::create($data);

        return redirect()->route('reviews.index');
    }

    public function show(Review $review)
    {
        return view('reviews.show', compact('review'));
    }

    public function edit(Review $review)
    {
        $users = User::all();
        $doctors = Doctor::all();
        $appointments = Appointment::all();

        return view('reviews.edit', compact(
            'review',
            'users',
            'doctors',
            'appointments'
        ));
    }

    public function update(ReviewRequest $request, Review $review)
    {
        $data = $request->validated();

        $review->update($data);

        return redirect()->route('reviews.index');
    }

    public function destroy(Review $review)
    {
        $review->delete();

        return redirect()->route('reviews.index');
    }
}