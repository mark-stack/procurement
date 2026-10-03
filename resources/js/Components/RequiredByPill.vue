<script setup>
    /**
     * The day a batch's material has to be on site, coloured by whether the batch is going to make it.
     *
     * One working day before the earliest fabrication start among the jobs on it: the steel has to be
     * in the shop before the saw starts, and a Monday start wants it there on the Friday rather than
     * on a Sunday nobody can take a delivery on. The date is computed server side
     * (NestingIndexController::materialsRequiredDate), so the weekend is already stepped over by the
     * time it gets here and the template never does date arithmetic of its own beyond counting days.
     *
     * Deliberately not OrderByPill, which the board draws and which counts down to a different day:
     * that one is when the Nesting column has to stop waiting and be quoted, five days before
     * fabrication, and it is the day the warning emails chase. This is the deadline the work has -
     * which is why every card on the Nesting page carries one, nested or not, where only the batch
     * still waiting has an ordering deadline left to spend.
     *
     * The date is the same fact on every card. The colour is not: it is how this batch is tracking
     * against the work it still owes, which is the critical path less whatever it has already done.
     * A batch still waiting to be quoted has the quoting time and the delivery time to find, one
     * already bought has only the delivery time, and on the same required-by date those two are days
     * apart - so the card still waiting goes red first, and does so while there is still time to do
     * something about it. The deadline each is held to is worked out server side, off the business's
     * own lead times; see NestingIndexController::criticalPathDeadline().
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //Null on a card where no job names a fabrication date - there is no deadline to print
        date: String,
        /*
         * The day this batch has to have moved on by, given where it has got to - not the day above.
         *
         * Null for a batch with nothing left to chase, which reads as on time: the steel is in, or
         * there is no required-by date to count back from and no pill is drawn at all.
         */
        deadline: String,
        /*
         * Whether the steel is already in, for the wording.
         *
         * A delivered or cut batch kept its date, so it is drawn on time like any other card that is
         * where it should be - but it is on time because it is finished, not because it still has
         * room, and the tooltip says which.
         */
        done: Boolean,
    });

    //Variables
    /**
     * The short day names the pill prints, indexed the way moment numbers the days (Sunday is 0).
     *
     * Written out here rather than taken from moment's 'ddd', which abbreviates every day to three
     * letters flat and so spells Thursday "Thu" - which is not how anybody writing on a job sheet
     * shortens it. Spelling it is cheaper than re-registering moment's locale, which would change
     * every other date on the application to suit this one pill.
     */
    const DAY_NAMES = ['Sun', 'Mon', 'Tue', 'Wed', 'Thurs', 'Fri', 'Sat'];

    //The date spelled out, which the pill no longer prints but its tooltip always does - see title
    const exactDate = computed(() => props.date
        ? moment(props.date).format("D MMM YY")
        : null);

    //Days until the steel is wanted on site, which is what the wording below is chosen off
    const days = computed(() => props.date
        ? moment(props.date).startOf('day').diff(moment().startOf('day'), 'days')
        : null);

    /**
     * That day said the way somebody in the shop would say it: "next Wed" rather than "14 Oct 26".
     *
     * A card is read to decide what to do this week, and a calendar date has to be worked out before
     * it answers that - "14 Oct 26 (10d)" was the page printing the arithmetic and leaving the
     * reading to whoever was looking. The day name is the thing being asked for.
     *
     * Counted in days rather than off week boundaries, which is what keeps it unambiguous: each
     * weekday name falls exactly once in any seven-day window, so the coming Wednesday is the only
     * Wednesday "Wed" can mean and the one after it the only "next Wed" - whatever day of the week
     * it happens to be read on, and without depending on which day moment's locale starts a week on.
     *
     * Past a fortnight either way the day name stops helping - nobody counts three Wednesdays ahead -
     * so it falls back to the date, with the year on it only when it is not this one.
     */
    const label = computed(() => {
        if (! props.date) {
            return null;
        }

        const day = moment(props.date).startOf('day');

        if (days.value === 0) {
            return 'today';
        }

        if (days.value === 1) {
            return 'tomorrow';
        }

        if (days.value === -1) {
            return 'yesterday';
        }

        if (days.value >= 2 && days.value <= 6) {
            return DAY_NAMES[day.day()];
        }

        if (days.value >= 7 && days.value <= 13) {
            return `next ${DAY_NAMES[day.day()]}`;
        }

        if (days.value <= -2 && days.value >= -6) {
            return `last ${DAY_NAMES[day.day()]}`;
        }

        return day.year() === moment().year()
            ? day.format('D MMM')
            : day.format('D MMM YY');
    });

    /*
     * And how far past its own deadline this batch is, which is what the colour reads.
     *
     * Positive is behind. A batch with no deadline left to miss is zero rather than null, so that the
     * three below stay a plain comparison and the on-time colour is what anything unaccounted for
     * falls to - a card is never coloured as late by a date the page could not work out.
     */
    const daysBehind = computed(() => props.deadline
        ? moment().startOf('day').diff(moment(props.deadline).startOf('day'), 'days')
        : 0);

    /*
     * Anything at or under zero is on time and draws green, the deadline being the last day it may be
     * met rather than the first one missed. A day behind is amber, two or more red.
     */
    const slipping = computed(() => daysBehind.value === 1);
    const late = computed(() => daysBehind.value >= 2);

    /*
     * The tooltip spells the date out, the pill having stopped doing so.
     *
     * "next Wed" is the right thing to read at a glance and the wrong thing to write a delivery date
     * into an order off, so the day it actually means is always one hover away.
     */
    const title = computed(() => {
        const required = `The material on this batch is wanted on site on ${exactDate.value}`;

        if (props.done) {
            return `${required}, and it is in - this batch is off the critical path`;
        }

        if (late.value) {
            return `${required}. This batch is ${daysBehind.value} days behind where it has to be to make that date`;
        }

        if (slipping.value) {
            return `${required}. This batch is a day behind where it has to be to make that date`;
        }

        return `${required}, and this batch is on track to make it`;
    });
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset"
        :class="late
            ? 'bg-red-50 text-red-800 ring-red-200'
            : (slipping
                ? 'bg-amber-50 text-amber-900 ring-amber-200'
                : 'bg-emerald-50 text-emerald-800 ring-emerald-200')"
        :title="title"
    >
        <i class="fa-solid fa-truck text-[10px] opacity-70"></i>
        <!--
            No countdown beside it any more: "next Wed" is the countdown, said in the units the shop
            works in, and "(10d)" next to it was the same fact twice. See label.
        -->
        Required by {{ label }}
    </span>
</template>
