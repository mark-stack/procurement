<script setup>
    /**
     * The day a batch's material has to be on site.
     *
     * One working day before the earliest fabrication start among the jobs on it: the steel has to be
     * in the shop before the saw starts, and a Monday start wants it there on the Friday rather than
     * on a Sunday nobody can take a delivery on. The date is computed server side
     * (NestingIndexController::materialsRequiredDate), so the weekend is already stepped over by the
     * time it gets here and the template never does date arithmetic of its own.
     *
     * Deliberately not OrderByPill, which the board draws and which counts down to a different day:
     * that one is when the Nesting column has to stop waiting and be quoted, five days before
     * fabrication, and it is the day the warning emails chase. This is the deadline the work has -
     * which is why every card on the Nesting page carries one, nested or not, where only the batch
     * still waiting has an ordering deadline left to spend.
     *
     * The colour is the warning, and it is read off the same date on every card so a column of them
     * can be scanned down the page for what is late.
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //Null on a card where no job names a fabrication date - there is no deadline to print
        date: String,
        /*
         * Drawn plain whatever the date says, for a batch whose steel is already in.
         *
         * A delivered or cut batch cannot be late for its own delivery, and a red pill on one would be
         * the page raising an alarm about something that has already happened. The date stays - it is
         * still what the job is working to - it just stops being a warning.
         */
        muted: Boolean,
    });

    //Variables
    const label = computed(() => props.date
        ? moment(props.date).format("D MMM YY")
        : null);

    const days = computed(() => props.date
        ? moment(props.date).startOf('day').diff(moment().startOf('day'), 'days')
        : null);

    //Past the day, or close enough to it that somebody should be chasing the delivery
    const late = computed(() => ! props.muted && days.value <= 0);
    const soon = computed(() => ! props.muted && days.value > 0 && days.value <= 2);
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset"
        :class="late
            ? 'bg-red-50 text-red-800 ring-red-200'
            : (soon
                ? 'bg-amber-50 text-amber-900 ring-amber-200'
                : 'bg-white text-gray-600 ring-gray-200')"
        :title="muted
            ? 'The material on this batch was wanted on site by this date - one working day before the earliest fabrication date on the card'
            : (late
                ? 'This batch\'s material is wanted on site now - the earliest fabrication date on the card is the next working day'
                : 'The day this batch\'s material has to be on site, so the shop can start cutting - one working day before the earliest fabrication date on the card')"
    >
        <i class="fa-solid fa-truck text-[10px] opacity-70"></i>
        Required by {{ label }}
        <span v-if="days > 0" class="font-medium opacity-75">
            ({{ days }}d)
        </span>
        <span v-else-if="! muted" class="font-medium opacity-75">(now)</span>
    </span>
</template>
