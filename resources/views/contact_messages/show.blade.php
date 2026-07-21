<h1>Message Details</h1>

<p>Name: {{ $contactMessage->name }}</p>

<p>Email: {{ $contactMessage->email }}</p>

<p>Subject: {{ $contactMessage->subject }}</p>

<p>Message:</p>

<p>{{ $contactMessage->message }}</p>

<a href="{{ route('contact-messages.index') }}">
    Back
</a>