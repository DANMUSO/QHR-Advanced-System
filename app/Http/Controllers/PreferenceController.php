<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PreferenceController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'theme' => ['sometimes', 'in:light,dark,system'],
            'sidebar_collapsed' => ['sometimes', 'boolean'],
            'dashboard' => ['sometimes', 'array'],
            'dashboard.order' => ['sometimes', 'array'],
            'dashboard.order.*' => ['string', 'max:60'],
            'dashboard.hidden' => ['sometimes', 'array'],
            'dashboard.hidden.*' => ['string', 'max:60'],
            // Super admin's last company scope: a company id, or "all". Restored at the next sign-in.
            'company_scope' => ['sometimes', 'nullable', 'regex:/^(all|\d+)$/'],
        ]);

        $user = $request->user();
        $user->forceFill(['preferences' => array_replace($user->preferences ?? [], $data)])->saveQuietly();

        return response()->json(['preferences' => $user->preferences]);
    }
}
