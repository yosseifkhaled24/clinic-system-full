<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when the Gemini chatbot cannot produce an answer (missing API
 * key, network/timeout failure, upstream 4xx/5xx, malformed payload…).
 *
 * The message is intentionally a static, user-safe string: it is returned
 * verbatim to the API client, so it must never contain secrets, stack
 * traces or upstream response bodies.
 */
class GeminiUnavailableException extends RuntimeException
{
}
