<h1>Reviews List</h1>

<a href="{{ route('reviews.create') }}">
    Add Review
</a>

<hr>

@foreach($reviews as $review)

    <p>
        User: {{ $review->user_id }}
        |
        Doctor: {{ $review->doctor_id }}
        |
        Rating: {{ $review->rating }}

        <a href="{{ route('reviews.show', $review->id) }}">
            View
        </a>

        <a href="{{ route('reviews.edit', $review->id) }}">
            Edit
        </a>

        <form action="{{ route('reviews.destroy', $review->id) }}"
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