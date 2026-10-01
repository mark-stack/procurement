<?php

namespace App\Http\Requests;

use App\Enums\GoodsReceiptNonconformanceEnums;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Booking a delivery in at the gate.
 *
 * Every field is optional except the two checks, and they are optional in the sense that matters: the
 * request may say "not checked" by sending null, and that is recorded as not checked rather than
 * silently as a pass. What is refused is a contradiction - a clean receipt that also names something
 * wrong with the load.
 *
 * The heat numbers ride along here rather than on a screen of their own. They arrive on the same
 * docket as the steel, read off the same piece of paper by the same person at the same moment, and a
 * separate form for them is a form that is filled in later, by somebody else, or never. See
 * App\Models\Bar::heat_number.
 */
class ReceiveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        //Whose order this is, and whether it has been sent, are both settled in the controller - this
        //is only the shape of the body
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            //What the driver handed over. Merchants' docket formats vary wildly, so this is a string
            'docket_number' => ['nullable', 'string', 'max:191'],

            /*
             * Tri-state, and not even required to be present.
             *
             * Null is a real answer here - "the steel arrived and nobody has checked it yet" - so a
             * body that omits these books the delivery in with both checks unanswered, which is
             * exactly what Order::receiptAccepted() then reports. Demanding the keys would buy
             * nothing, since null is permitted anyway, and would turn a bare "it's here" into a 422
             * at a gate with a truck waiting.
             */
            'quantity_verified' => ['nullable', 'boolean'],
            'grade_verified' => ['nullable', 'boolean'],

            'nonconformance' => ['nullable', Rule::enum(GoodsReceiptNonconformanceEnums::class)],
            //Long enough for "two bars short, Kev is chasing Monday's load", short enough to stay a note
            'note' => ['nullable', 'string', 'max:500'],

            /*
             * Heat numbers, keyed by the id of the bar they belong to. Which bars are actually this
             * order's is checked in the controller - an id in a request body is not a claim this
             * request can settle.
             */
            'heat_numbers' => ['nullable', 'array'],
            'heat_numbers.*' => ['nullable', 'string', 'max:191'],
        ];
    }

    public function after(): array
    {
        return [
            /*
             * "Other" with nothing beside it records that something was wrong and nothing about what -
             * the one entry nobody can act on later. Same rule, and the same reason, as
             * RemoveOffcutRequest.
             */
            function (Validator $validator) {
                $reason = $this->nonconformance();

                if ($reason?->requiresNote() && $this->note() === null) {
                    $validator->errors()->add('note', 'Say what was wrong with the delivery.');
                }
            },

            /*
             * A receipt that passes both checks and still names a fault is two answers to one question,
             * and whichever of them a later reader believes, the other one was recorded for nothing.
             * The person at the gate meant one of the two, so they are asked which.
             */
            function (Validator $validator) {
                $bothPassed = $this->input('quantity_verified') === true
                    && $this->input('grade_verified') === true;

                if ($bothPassed && $this->nonconformance() !== null) {
                    $validator->errors()->add(
                        'nonconformance',
                        'This receipt says the quantity and the grade were both correct, so it cannot '
                        .'also record a problem with the load. Clear whichever one is wrong.',
                    );
                }
            },
        ];
    }

    public function docketNumber(): ?string
    {
        return $this->trimmedOrNull('docket_number');
    }

    public function quantityVerified(): ?bool
    {
        $value = $this->validated('quantity_verified');

        return $value === null ? null : (bool) $value;
    }

    public function gradeVerified(): ?bool
    {
        $value = $this->validated('grade_verified');

        return $value === null ? null : (bool) $value;
    }

    public function nonconformance(): ?GoodsReceiptNonconformanceEnums
    {
        return GoodsReceiptNonconformanceEnums::tryFrom((string) $this->input('nonconformance'));
    }

    public function note(): ?string
    {
        return $this->trimmedOrNull('note');
    }

    /**
     * The heat numbers, as bar id => number, with the blanks dropped.
     *
     * A blank means "not recorded" and never "clear what is there": the form posts every bar on the
     * order whether or not it has been filled in, so an empty box on a second visit would wipe the
     * number typed on the first. Removing one is not something this screen offers - a wrong heat
     * number is corrected by typing the right one.
     *
     * @return array<int, string>
     */
    public function heatNumbers(): array
    {
        $heatNumbers = [];

        foreach ((array) $this->validated('heat_numbers') as $barId => $heatNumber) {
            $heatNumber = trim((string) $heatNumber);

            if (! is_numeric($barId) || $heatNumber === '') {
                continue;
            }

            $heatNumbers[(int) $barId] = $heatNumber;
        }

        return $heatNumbers;
    }

    private function trimmedOrNull(string $key): ?string
    {
        $value = trim((string) $this->validated($key));

        return $value === '' ? null : $value;
    }
}
