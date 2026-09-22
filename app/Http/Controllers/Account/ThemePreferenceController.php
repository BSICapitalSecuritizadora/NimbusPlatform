<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persists the theme chosen in the panel's quick switcher.
 *
 * The switcher itself is client-side (Filament Alpine store); this endpoint
 * keeps the database — the source of truth — in sync.
 */
class ThemePreferenceController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate([
            'theme' => ['required', 'string', Rule::in(['light', 'dark', 'system'])],
        ]);

        $user = $request->user();

        abort_unless($user instanceof User, Response::HTTP_FORBIDDEN);

        $user->preferences()->updateOrCreate(
            ['user_id' => $user->getKey()],
            ['theme' => $validated['theme']],
        );

        return response()->noContent();
    }
}
