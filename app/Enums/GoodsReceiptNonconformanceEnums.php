<?php

namespace App\Enums;

/**
 * What was wrong with a delivery, recorded at the gate.
 *
 * Until now a delivery had one bit: is_delivered. The steel turned up, and that was the whole record -
 * no date, nobody's name, and nothing at all about whether what came off the truck was what the order
 * asked for. A yard that takes 40 bars of GR300 and receives 38 of GR250 had nowhere to say so, so it
 * said so on the phone and the system went on believing the order was filled.
 *
 * ISO 9001 8.6 is the clause: material is not released for use until verification is complete, and the
 * record of that release - including who authorised it - is retained. 8.7 is the other half, and it is
 * why these are reasons rather than a single "problem" flag: what has to be decided about a short
 * delivery is different from what has to be decided about a bent bar, and which of them keeps
 * happening to which merchant is the thing worth knowing. That is the same argument
 * OffcutRemovalEnums makes about steel leaving inventory, and for the same reason.
 *
 * Recording one of these does not block anything. The steel is in the yard whatever this says, and a
 * system that refused to book in a short delivery would simply be lied to - see the note on
 * requiresNote(). It marks the delivery as not accepted (Order::receiptAccepted) so that the board,
 * and whoever is answering for the order, can see it.
 */
enum GoodsReceiptNonconformanceEnums: string
{
    case SHORT_DELIVERY = 'SHORT_DELIVERY';
    case OVER_DELIVERY = 'OVER_DELIVERY';
    case WRONG_GRADE = 'WRONG_GRADE';
    case WRONG_SIZE = 'WRONG_SIZE';
    case DAMAGED = 'DAMAGED';
    case CERTIFICATE_MISSING = 'CERTIFICATE_MISSING';
    case OTHER = 'OTHER';

    public function label(): string
    {
        return match ($this) {
            self::SHORT_DELIVERY => 'Short delivery',
            self::OVER_DELIVERY => 'Over delivery',
            self::WRONG_GRADE => 'Wrong grade or material',
            self::WRONG_SIZE => 'Wrong size or section',
            self::DAMAGED => 'Damaged in transit',
            self::CERTIFICATE_MISSING => 'No certificate with the load',
            self::OTHER => 'Other',
        };
    }

    /**
     * The hint under the option, in the words the yard would use - so the reasons are told apart on
     * sight rather than by guessing which one the last person meant.
     */
    public function hint(): string
    {
        return match ($this) {
            self::SHORT_DELIVERY => 'Fewer bars, or less length, than the order asked for.',
            self::OVER_DELIVERY => 'More than was ordered - it still has to be accounted for.',
            self::WRONG_GRADE => 'Not the grade or material on the order.',
            self::WRONG_SIZE => 'Right grade, wrong section or wrong length.',
            self::DAMAGED => 'Bent, cropped or otherwise not usable as delivered.',
            self::CERTIFICATE_MISSING => 'Steel is fine; the paperwork did not come with it.',
            self::OTHER => 'Anything else - say what happened.',
        };
    }

    /**
     * Whether the note has to be filled in.
     *
     * "Other" demands one, for the reason OffcutRemovalEnums::requiresNote gives: a reason of "Other"
     * with nothing beside it records that something happened and nothing about what, which is the one
     * entry nobody can act on later.
     *
     * The rest do not, and that is a decision rather than an oversight. This form is filled in at a
     * gate with a truck waiting, and a required field at that moment is a field that gets "x" typed
     * into it. A reason on its own is already far more than the tick box it replaces.
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
