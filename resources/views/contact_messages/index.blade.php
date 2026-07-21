<h1>Contact Messages</h1>

@foreach($messages as $message)

    <p>
        {{ $message->name }}
        |
        {{ $message->email }}

        <a href="{{ route('contact-messages.show', $message->id) }}">
            View
        </a>

        <form action="{{ route('contact-messages.destroy', $message->id) }}"
              method="POST"
              style="display:inline;">
            @csrf
            @method('DELETE')

            <button type="submit">
                Delete
            </button>
        </form>
    </p>

@endforeach