<?php

namespace App\Support;

/**
 * Adds the click token to a destination URL - the thread a conversion is
 * later pulled back along.
 *
 * The rest of the URL is left exactly as the owner wrote it: a destination
 * can be picky about its own parameters, and re-encoding them to add ours
 * would be a way to break someone's landing page.
 */
final class ConversionUrl
{
    public const PARAM = 'lf_click';

    public static function withClick(string $url, string $token): string
    {
        $hashAt = strpos($url, '#');
        $fragment = $hashAt === false ? '' : substr($url, $hashAt);
        $rest = $hashAt === false ? $url : substr($url, 0, $hashAt);

        $queryAt = strpos($rest, '?');
        $base = $queryAt === false ? $rest : substr($rest, 0, $queryAt);
        $query = $queryAt === false ? '' : substr($rest, $queryAt + 1);

        // A URL that already carries one (a re-shared destination, say) gets
        // this click's, not two.
        $kept = array_values(array_filter(
            explode('&', $query),
            fn (string $pair) => $pair !== '' && ! str_starts_with($pair, self::PARAM.'=') && $pair !== self::PARAM
        ));

        $kept[] = self::PARAM.'='.rawurlencode($token);

        return $base.'?'.implode('&', $kept).$fragment;
    }
}
