<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\Request;

class ContactMessageController extends Controller
{
    public function index()
    {
        $messages = ContactMessage::all();

        return view('contact_messages.index', compact('messages'));
    }

    public function show(ContactMessage $contactMessage)
    {
        return view('contact_messages.show', compact('contactMessage'));
    }

    public function destroy(ContactMessage $contactMessage)
    {
        $contactMessage->delete();

        return redirect()->route('contact-messages.index');
    }
}