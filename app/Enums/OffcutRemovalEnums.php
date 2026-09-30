<?php

namespace App\Enums;

/**
 * Why a piece of steel that the system still has in inventory is not in the yard any more.
 *
 * Offcuts are created by the nest and consumed by the nest, and until now that was the only way one
 * could leave inventory. The yard does not work like that: a boilermaker walks past a 2.4m offcut and
 * cuts it up for a handrail, another project manager takes one for a job that was never nested, a
 * offcut gets bent by a forklift. Inventory then promises material nobody can find, and the nest that
 * believes it saves the price of a bar the business has to buy anyway.
 *
 * The reason is recorded rather than inferred because these are not the same event. "Taken" means
 * the steel exists and somebody else has it; "missing" means nobody knows; "damaged" means it is
 * gone for good. Which of them keeps happening is the thing worth knowing.
 *
 * SCRAPPED is the odd one out, and deliberately so. The other four describe something that happened
 * TO a piece of steel and was discovered afterwards; this one is a decision the business made about
 * steel that is exactly where it should be. It is what the quarterly cleanout proposes - see
 * Services\OffcutCleanout - and the only reason that says the material was weighed in on purpose
 * rather than lost.
 */
enum OffcutRemovalEnums: string
{
    case TAKEN = 'TAKEN';
    case CUT_WITHOUT_NESTING = 'CUT_WITHOUT_NESTING';
    case DAMAGED = 'DAMAGED';
    case MISSING = 'MISSING';
    case SCRAPPED = 'SCRAPPED';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::TAKEN => 'Taken by someone else',
            self::CUT_WITHOUT_NESTING => 'Cut up without a nest',
            self::DAMAGED => 'Damaged beyond use',
            self::MISSING => 'Cannot be found',
            self::SCRAPPED => 'Scrapped - weighed in',
            self::OTHER => 'Other',
        };
    }

    /**
     * The bit of help under the option in the picker - what this reason means, in the words the yard
     * would use, so the five are told apart on sight rather than by guessing.
     */
    public function hint(): string
    {
        return match ($this) {
            self::TAKEN => 'Still steel, but somebody else has it.',
            self::CUT_WITHOUT_NESTING => 'Used on a job that never went through a nest.',
            self::DAMAGED => 'Bent, cropped or otherwise no longer usable.',
            self::MISSING => 'Nobody can find it, and nobody is saying where it went.',
            self::SCRAPPED => 'Sat too long to be worth keeping, and gone in the scrap bin.',
            self::OTHER => 'Anything else - say what happened.',
        };
    }

    /**
     * Whether the note under the picker has to be filled in.
     *
     * Only "Other" demands one: a reason of "Other" with nothing beside it records that something
     * happened and nothing about what, which is the one removal nobody can make sense of later.
     */
    public function requiresNote(): bool
    {
        return $this === self::OTHER;
    }

    /**
     * Every reason, as the picker needs it.
     *
     * @return list<array{value: string, label: string, hint: string, requiresNote: bool}>
     */
    public static function options(): array
    {
        return array_map(fn (self $reason) => [
            'value' => $reason->value,
            'label' => $reason->label(),
            'hint' => $reason->hint(),
            'requiresNote' => $reason->requiresNote(),
        ], self::cases());
    }
}
