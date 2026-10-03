<script setup>
    /**
     * The day a batch's material has to be at the workshop, coloured by whether the batch is going to make it.
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
     * A batch that has not been priced yet has the quoting time and the delivery time still to find -
     * one being priced right now included, the quoting being its work in hand rather than work behind
     * it - where a batch already priced has only the delivery. On the same required-by date those two
     * are days apart, so the card with more to do goes red first, and does so while there is still
     * time to do something about it. The deadline each is held to is worked out server side, off the
     * business's own lead times; see NestingIndexController::criticalPathDeadline().
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //Null on a card where no job names a fabrication date - there is no deadline to print
        date: String,
        /*
         * How far past its own deadline this batch already is, in working days - see
         * NestingIndexController::daysBehindCriticalPath(). Positive is behind; zero or less is on
         * track, and a card with nothing to chase answers zero.
         *
         * Worked out server side rather than here, from a deadline date this no longer receives. Two
         * things read it - this colour and the action footer under the card - and a page that counted
         * it twice could draw a card that is green and tells you to go and do something about it.
         * Moment also has no notion of a working day, which is the unit the lead times are in.
         */
        daysBehind: Number,
    });

    /*
     * There is no "delivered" state in here, because a delivered or cut batch is not given a pill.
     *
     * Both used to be drawn, on time and in green, on the reading that the batch had met its path and
     * the date was still what the job worked to. What that actually put on the page was a run of green
     * at the bottom of every column saying nothing anybody had to act on, and a claim about a race that
     * had already finished. The step pill beside it says Delivered or Cut, which is the whole of the
     * news. See NestingIndex.vue for the v-if that leaves this off.
     */

    //Variables
    //The date spelled out, which the pill no longer prints but its tooltip always does - see title
    const exactDate = computed(() => props.date
        ? moment(props.date).format("D MMM YY")
        : null);

    //Days until the steel is wanted at the workshop, which is what the wording below is chosen off
    const days = computed(() => props.date
        ? moment(props.date).startOf('day').diff(moment().startOf('day'), 'days')
        : null);

    /**
     * That day said the way somebody in the shop would say it: "next Thursday", not "14 Oct 26".
     *
     * A card is read to decide what to do this week, and a calendar date has to be worked out before
     * it answers that - "14 Oct 26 (10d)" was the page printing the arithmetic and leaving the
     * reading to whoever was looking. The day name is the thing being asked for.
     *
     * Written out in full rather than abbreviated. The pill carries one short line and has the room,
     * and a day is read quicker whole than as three letters somebody has to expand - moment's own
     * abbreviations would also spell Thursday "Thu", which is not how it is written on a job sheet.
     *
     * Counted in days rather than off week boundaries, which is what keeps it unambiguous: each
     * weekday name falls exactly once in any seven-day window, so the coming Wednesday is the only
     * Wednesday "Wednesday" can mean and the one after it the only "next Wednesday" - whatever day
     * it is read on, and without depending on the day moment's locale starts a week on.
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
            return day.format('dddd');
        }

        if (days.value >= 7 && days.value <= 13) {
            return `next ${day.format('dddd')}`;
        }

        if (days.value <= -2 && days.value >= -6) {
            return `last ${day.format('dddd')}`;
        }

        return day.year() === moment().year()
            ? day.format('D MMM')
            : day.format('D MMM YY');
    });

    /*
     * Anything at or under zero is on time and draws green, the deadline being the last day it may be
     * met rather than the first one missed. A day behind is amber, two or more red.
     *
     * Defaulted rather than read straight off the prop, so a card the server sent nothing for draws
     * on time instead of being coloured late by a number that was never there.
     */
    const daysBehind = computed(() => props.daysBehind ?? 0);

    const slipping = computed(() => daysBehind.value === 1);
    const late = computed(() => daysBehind.value >= 2);

    /*
     * The tooltip spells the date out, the pill having stopped doing so.
     *
     * "next Thursday" is the right thing to read at a glance and the wrong thing to write a delivery
     * date into an order off, so the day it actually means is always one hover away.
     */
    const title = computed(() => {
        const required = `The material on this batch is wanted at the workshop on ${exactDate.value}`;

        if (late.value) {
            return `${required}. This batch is ${daysBehind.value} working days behind where it has to be to make that date`;
        }

        if (slipping.value) {
            return `${required}. This batch is a working day behind where it has to be to make that date`;
        }

        return `${required}, and this batch is on track to make it`;
    });
</script>

<template>
    <!--
        Shaped to sit beside StagePill, which it shares a row with: the same rounded-full, the same
        text-xs font-semibold, the same py-0.5 and the same ring drawn outside the box.

        The ring used to be ring-inset, which is what made this one read as the tighter of the two -
        an inset ring eats a pixel off each edge of the content rather than adding one outside it, so
        two pills with identical padding sat at different sizes. A half-step more horizontal padding
        on top of that, which is the room the truck takes out of the left-hand side.
    -->
    <span
        v-if="label"
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold ring-1"
        :class="late
            ? 'bg-red-50 text-red-800 ring-red-200'
            : (slipping
                ? 'bg-amber-50 text-amber-900 ring-amber-200'
                : 'bg-emerald-50 text-emerald-800 ring-emerald-200')"
        :title="title"
    >
        <i class="fa-solid fa-truck text-[10px] opacity-70"></i>
        <!--
            No countdown beside it any more: "next Thursday" is the countdown, said in the units the
            shop works in, and "(10d)" next to it was the same fact twice. See label.
        -->
        Required by {{ label }}
    </span>
</template>
