<?php

namespace App\Enums;

/**
 * What a member may do inside a workspace. Deliberately three coarse roles
 * rather than a permission matrix: an agency needs "can look", "can work"
 * and "can administer", and every extra knob is one more thing to explain.
 */
enum WorkspaceRole: string
{
    case Owner = 'owner';
    case Editor = 'editor';
    case Viewer = 'viewer';

    /** Create and change sites and links. */
    public function canEdit(): bool
    {
        return $this !== self::Viewer;
    }

    /** Members, domains, webhooks and destructive actions. */
    public function isOwner(): bool
    {
        return $this === self::Owner;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
