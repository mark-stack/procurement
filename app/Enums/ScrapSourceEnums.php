<?php

namespace App\Enums;

/**
 * The two ways steel stops being steel.
 *
 * Kept apart because they are not the same quantity and they do not answer the same question.
 *
 * NEST_DROP is arithmetic. A bar is 9,000mm and the parts that fit on it come to 8,400mm, so 600mm
 * goes in the skip - nobody chose it, the cut plan did, and the only lever on it is nesting better.
 * It belongs to the jobs that were cut off that bar, which is why it can be apportioned to a project.
 *
 * CLEANOUT is a decision. The steel was long enough to bank, it was banked, and a quarter or more
 * later somebody looked at it and said it is never going to be used - see Services\OffcutCleanout.
 * It belongs to no job: the project that produced it moved on long ago and the one that might have
 * consumed it never existed. Reported against a month and a section, and against nothing else.
 *
 * Adding the two together is the yard's total write-off, and that is the only figure they share.
 */
enum ScrapSourceEnums: string
{
    case NEST_DROP = 'NEST_DROP';
    case CLEANOUT = 'CLEANOUT';

    public function label(): string
    {
        return match ($this) {
            self::NEST_DROP => 'Cut-off from a nest',
            self::CLEANOUT => 'Weighed in off the rack',
        };
    }

    /**
     * What the figure under this heading actually is, in the words the yard would use.
     */
    public function hint(): string
    {
        return match ($this) {
            self::NEST_DROP => 'Too short to bank once the parts were cut off it.',
            self::CLEANOUT => 'Banked, sat past its shelf life, and written off.',
        };
    }

    /**
     * Whether scrap of this kind can be put against a job.
     *
     * Only a nest drop can. See the class docblock - and Services\ScrapReport, which reports the
     * other kind as attributable to no project rather than spreading it over the jobs that happened
     * to be running.
     */
    public function attributableToProject(): bool
    {
        return $this === self::NEST_DROP;
    }
}
