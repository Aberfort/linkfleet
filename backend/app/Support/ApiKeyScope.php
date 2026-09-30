<?php

namespace App\Support;

use Laravel\Sanctum\PersonalAccessToken;

/**
 * An API key is an ordinary Sanctum token that is *not* a full session: it
 * carries "read" (and optionally "write") instead of "*", and may be pinned
 * to one workspace with a "workspace:<id>" ability. Keeping the whole scheme
 * in the token's ability list means no extra table and no custom token model,
 * and this class is the only place that knows the spelling.
 */
final class ApiKeyScope
{
    private const WORKSPACE = 'workspace:';

    /** @return array<int, string> */
    public static function abilities(string $access, ?int $workspaceId): array
    {
        return array_values(array_filter([
            'read',
            $access === 'write' ? 'write' : null,
            $workspaceId !== null ? self::WORKSPACE.$workspaceId : null,
        ]));
    }

    /** A real token that is not a full session. Anything else (no token, a test double) is not a key. */
    public static function isKey(mixed $token): bool
    {
        return $token instanceof PersonalAccessToken
            && is_array($token->abilities)
            && ! in_array('*', $token->abilities, true);
    }

    /** The workspace a key is pinned to; null for a key that spans all of them, and for non-keys. */
    public static function workspaceId(mixed $token): ?int
    {
        if (! self::isKey($token)) {
            return null;
        }

        foreach ($token->abilities as $ability) {
            if (str_starts_with($ability, self::WORKSPACE)) {
                return (int) substr($ability, strlen(self::WORKSPACE));
            }
        }

        return null;
    }

    /** 'write' or 'read'. */
    public static function access(PersonalAccessToken $token): string
    {
        return in_array('write', $token->abilities ?? [], true) ? 'write' : 'read';
    }
}
