<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ContactMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            // SEC-11: bound the free-text body so a single submission can't
            // store megabytes of text (the DB column is `text`). 2000
            // characters comfortably fits long Arabic/English messages;
            // UTF-8 content passes untouched — nothing is stripped or
            // rewritten, HTML/script payloads are kept as plain text and
            // escaped wherever they are displayed (React text nodes, no
            // dangerouslySetInnerHTML anywhere in the frontend).
            'message' => 'required|string|max:2000',
        ];
    }
}
