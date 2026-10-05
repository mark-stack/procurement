<script setup>
    /**
     * What somebody has to go and do about this batch, under the card that is late.
     *
     * The pill above says a batch is behind; this says what being behind means in work. "Quoting",
     * amber, is two facts somebody then has to put together - that the batch is out with the
     * merchants, and that there is no longer enough time left to quote it and have the steel
     * delivered - and the conclusion they reach is always the same one. The card draws the
     * conclusion instead.
     *
     * Only on a card that is behind, and only where being behind is somebody's to fix. A footer
     * under every card would be a row of instructions to carry on as normal, and it would push the
     * three or four that need doing today off the screen - the point of it is that a card carrying
     * one is a card to act on, and that it stops carrying one when the thing has been done.
     *
     * The action is read off the step, because the step is what is outstanding: a batch that is late
     * and being priced is late at the quoting, whatever happens after. See
     * NestingIndexController::milestoneOf() for the steps and criticalPathDeadline() for which of the
     * lead times each of them still owes - the two are the same list read from opposite ends.
     *
     * The instruction can be carried out from here, where the page has a press that carries it out:
     * the #action slot holds that press, right-aligned at the far end of the strip. The slot is the
     * caller's rather than this component's because which press clears a footer is the page's
     * business - this one only knows what is outstanding, not what on the screen would fix it - and
     * most steps have no single press at all, so the slot is usually empty and the strip is the
     * sentence it has always been.
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //NESTING | QUOTING | QUOTED | ORDERING - the steps a late batch can still be moved off
        stage: String,
        //Working days past this card's own deadline, always 1 or more where this is drawn at all
        daysBehind: Number,
        //The day the step should have been finished by, for the line underneath
        deadline: String,
    });

    //Variables
    /*
     * The step that is outstanding, said as the thing to do rather than as the state it is in.
     *
     * An imperative, because it is an instruction: "Complete quoting", not "quoting incomplete". The
     * difference matters on a page somebody is scanning for what to pick up next.
     *
     * NESTING is the open batch, which is not a batch yet - what it is waiting on is the press that
     * closes it, which is the same card's "Start quoting".
     *
     * Four steps, not five: ORDERED is not in here and gets no footer at all - see
     * NestingIndex.vue::actionRequired(). Everything on it has been bought and the only thing left
     * between the batch and its date is a merchant's lorry, so the honest instruction would be
     * "chase the delivery", which is not something this page can be used to do. ORDERING is a
     * different case and does belong here: some of its material is still on nobody's order.
     */
    const action = computed(() => ({
        NESTING: 'Start quoting this batch',
        QUOTING: 'Complete quoting',
        QUOTED: 'Place the order',
        ORDERING: 'Finish ordering the outstanding material',
    }[props.stage] ?? 'Move this batch on'));

    /*
     * And how late it is, in the units the deadline was set in. Spelled "working day" rather than
     * left as "day", because the two are different numbers over a weekend and the business set these
     * lead times as working days - see Project::quotingDeadline().
     */
    const behindLabel = computed(() => props.daysBehind === 1
        ? '1 working day behind'
        : `${props.daysBehind} working days behind`);

    //The day it should have been done by, which is what "behind" is behind
    const deadlineLabel = computed(() => props.deadline
        ? moment(props.deadline).format('D MMM')
        : null);

    /*
     * Red once the batch cannot make its date by working normally, amber on the first day, matching
     * the pill above it so the card reads as one thing rather than two opinions.
     */
    const late = computed(() => props.daysBehind >= 2);
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-x-2 gap-y-1 px-4 py-2 text-xs border-t rounded-b-xl"
        :class="late
            ? 'bg-red-50 border-red-100 text-red-800'
            : 'bg-amber-50 border-amber-100 text-amber-900'"
    >
        <i class="fa-solid fa-triangle-exclamation text-[11px] opacity-80"></i>

        <span class="font-semibold">
            Action required: {{ action }}
        </span>

        <!--
            The evidence for it, quieter than the instruction. Somebody who disagrees with the card
            needs to see what it counted - the day the step was due and how far past it we are -
            without that competing with the thing to do.
        -->
        <span class="opacity-80">
            <template v-if="deadlineLabel">
                &middot; due {{ deadlineLabel }},
            </template>
            {{ behindLabel }}
        </span>

        <!--
            And the press that clears it, if the page has one. Pushed to the other end of the strip
            rather than left beside the sentence: the instruction is read left to right and the
            thing to press is where a card's buttons already are, so the footer reads as "this, and
            here is how" instead of as a sentence with a button in the middle of it.
        -->
        <div v-if="$slots.action" class="ml-auto">
            <slot name="action"></slot>
        </div>
    </div>
</template>
