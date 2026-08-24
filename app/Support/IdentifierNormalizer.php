<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The one definition of what makes two textual identifications "the same".
 *
 * Contract codes and installment numbers are both free text that operators type
 * by hand, and both need an identity that does not depend on how a particular
 * database happens to compare strings. This class is that rule, in one place, so
 * a form, an import, a lookup and a unique index can never disagree about
 * whether "A606" and " a606 " are the same thing.
 *
 * Two values come out of it:
 *
 * - {@see display()} is what the operator wrote, minus the surrounding
 *   whitespace. It is what gets shown back and what the paperwork says.
 * - {@see identity()} is what comparison runs on: trimmed, inner whitespace
 *   collapsed, uppercased.
 *
 * Accents survive on purpose. "INTERMEDIÁRIA" and "INTERMEDIARIA" are different
 * identifications, and no rule in this domain says otherwise -- so folding them
 * would silently merge records nobody asked to merge.
 *
 * Deliberately computed in PHP rather than by the database. `UPPER()` is
 * accent-aware in MySQL and ASCII-only in SQLite, and a utf8mb4_unicode_ci
 * column compares case *and* accents as equal while SQLite compares bytes. Any
 * rule expressed in SQL would therefore mean something different in production
 * than it means in the test suite. Deciding here, and storing the result in a
 * binary column, is what makes the two agree.
 */
final class IdentifierNormalizer
{
    /**
     * The value as typed, trimmed. Never re-cased and never re-spaced: this is
     * what the user sees, and rewriting it would change what the document says.
     *
     * An empty result is null, so a blank cell never becomes an identification.
     */
    public static function display(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * The comparison value. Idempotent: feeding it its own output returns the
     * same string, so a value read back from the database keys to itself.
     */
    public static function identity(?string $value): ?string
    {
        $value = self::display($value);

        if ($value === null) {
            return null;
        }

        // \s under /u leaves the non-breaking space alone, and spreadsheets are
        // full of them, so it is matched explicitly.
        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', $value);

        return Str::upper($collapsed ?? $value);
    }
}
