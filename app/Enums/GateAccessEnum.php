<?php

namespace App\Enums;

use App\Traits\WithEnumHelpers;

/**
 * How far an administrator may go inside one area of the admin workspace.
 *
 * The cases are a ladder, not a set: FULL contains CREATE, CREATE contains MODIFY,
 * MODIFY contains VIEW. That is what covers() reads, and it is why a screen only ever
 * has to ask for the *lowest* level it needs — a listing asks for VIEW and an admin
 * with FULL passes without the listing knowing FULL exists.
 */
enum GateAccessEnum: string
{
    use WithEnumHelpers;

    case NONE = 'none';
    case VIEW = 'view';
    case MODIFY = 'modify';
    case CREATE = 'create';
    case FULL = 'full';

    public function isNone(): bool
    {
        return $this === self::NONE;
    }

    public function isView(): bool
    {
        return $this === self::VIEW;
    }

    public function isModify(): bool
    {
        return $this === self::MODIFY;
    }

    public function isCreate(): bool
    {
        return $this === self::CREATE;
    }

    public function isFull(): bool
    {
        return $this === self::FULL;
    }

    /**
     * Where this level sits on the ladder. Only covers() should read it — the numbers
     * are ordering, not identity, and nothing may be stored or compared by them.
     */
    public function rank(): int
    {
        return match ($this) {
            self::NONE => 0,
            self::VIEW => 1,
            self::MODIFY => 2,
            self::CREATE => 3,
            self::FULL => 4,
        };
    }

    /**
     * Does the access this account holds reach the level being asked for?
     */
    public function covers(self $required): bool
    {
        // NONE is the floor, so it must never satisfy a request — including a
        // request for NONE itself, which no caller has any business making.
        if ($this->isNone() || $required->isNone()) {
            return false;
        }

        return $this->rank() >= $required->rank();
    }

    /**
     * What each level actually permits, for the gate editor.
     */
    public function description(): string
    {
        return match ($this) {
            self::NONE => 'Cannot open the page at all. It disappears from the sidebar.',
            self::VIEW => 'Read-only. Can open the page and search it, but every write is refused.',
            self::MODIFY => 'Can edit records that already exist, but cannot add or delete any.',
            self::CREATE => 'Can add new records and edit existing ones. Deleting is still refused.',
            self::FULL => 'Everything the page offers, deleting included.',
        };
    }

    /**
     * NONE is how a gate is switched off rather than a level anybody is granted, so
     * it is never offered as a choice — the editor renders it as the empty state.
     */
    protected static function forSelectValuesArg(): array
    {
        return [self::NONE->value];
    }
}
