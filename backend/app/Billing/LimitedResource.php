<?php

namespace App\Billing;

/**
 * The things a plan puts a ceiling on. Deliberately only things a workspace
 * *accumulates*: what a plan sells is room, never a locked feature.
 */
enum LimitedResource: string
{
    case Links = 'links';
    case Domains = 'domains';
    case Members = 'members';

    /** The noun for "…: N", in the genitive plural, for error messages. */
    public function noun(): string
    {
        return match ($this) {
            self::Links => 'посилань',
            self::Domains => 'власних доменів',
            self::Members => 'учасників',
        };
    }
}
