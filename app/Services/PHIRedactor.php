<?php

namespace App\Services;

/**
 * Deterministic privacy filter applied to the user's message and the
 * replayed conversation history BEFORE anything is sent to Gemini.
 *
 * The assistant never asks for personal identifiers (see the system
 * instruction), but visitors still type them; phone numbers, emails and
 * national-ID-like digit runs are not needed to answer clinic questions,
 * so they are stripped on the server side as a safety net.
 *
 * Conservative on purpose:
 *  - Emails are removed.
 *  - Digit runs of 9+ digits (Egyptian mobiles are 10–11, national IDs 14)
 *    are removed, single separators (space/dash/dot) allowed.
 *  - Prices, years and shorter numbers are preserved, so normal questions
 *    ("How much is the 450 EGP service?") keep working.
 */
class PHIRedactor
{
    public const PLACEHOLDER = '[redacted]';

    public function redact(string $text): string
    {
        // Email addresses.
        $text = (string) preg_replace(
            '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u',
            self::PLACEHOLDER,
            $text
        );

        // Phone / ID-like digit runs: 9+ digits, optional single separators.
        $text = (string) preg_replace(
            '/\d(?:[ .\-]?\d){8,}/u',
            self::PLACEHOLDER,
            $text
        );

        return $text;
    }

    /**
     * @param  list<array{role: string, text: string}>  $history
     * @return list<array{role: string, text: string}>
     */
    public function redactHistory(array $history): array
    {
        return array_map(
            fn (array $entry) => [
                'role' => $entry['role'],
                'text' => $this->redact((string) ($entry['text'] ?? '')),
            ],
            $history
        );
    }
}
