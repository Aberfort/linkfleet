<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreApiKeyRequest;
use App\Support\ApiKeyScope;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class ApiKeyController extends Controller
{
    private const MAX_KEYS = 25;

    public function index(Request $request)
    {
        return $request->user()->tokens()
            ->latest('id')
            ->get()
            ->filter(fn (PersonalAccessToken $token) => ApiKeyScope::isKey($token))
            ->map(fn (PersonalAccessToken $token) => $this->present($token))
            ->values();
    }

    /**
     * The only time the plaintext key is ever available: Sanctum stores just
     * its hash, so a lost key cannot be shown again, only replaced.
     */
    public function store(StoreApiKeyRequest $request)
    {
        $user = $request->user();

        $existing = $user->tokens->filter(fn ($token) => ApiKeyScope::isKey($token))->count();

        if ($existing >= self::MAX_KEYS) {
            throw ValidationException::withMessages([
                'name' => 'Досягнуто ліміту в '.self::MAX_KEYS.' ключів. Видаліть непотрібні.',
            ]);
        }

        $created = $user->createToken(
            $request->validated('name'),
            ApiKeyScope::abilities($request->validated('access'), $request->validated('workspace_id'))
        );

        return response()->json([
            ...$this->present($created->accessToken),
            'token' => $created->plainTextToken,
        ], 201);
    }

    public function destroy(Request $request, int $id)
    {
        // Session tokens live in the same table but are not managed here:
        // deleting one would silently sign a browser out.
        $token = $request->user()->tokens()->whereKey($id)->get()
            ->first(fn (PersonalAccessToken $token) => ApiKeyScope::isKey($token));

        abort_if($token === null, 404);

        $token->delete();

        return response()->noContent();
    }

    /** @return array<string, mixed> */
    private function present(PersonalAccessToken $token): array
    {
        return [
            'id' => $token->id,
            'name' => $token->name,
            'access' => ApiKeyScope::access($token),
            'workspace_id' => ApiKeyScope::workspaceId($token),
            'last_used_at' => $token->last_used_at,
            'created_at' => $token->created_at,
        ];
    }
}
