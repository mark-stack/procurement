<script setup>
    /**
     * The day the Nesting column has to stop waiting and buy.
     *
     * The one thing about that column nobody can work out by looking at it: it tells you to hold off as
     * long as you can, because every day more material arrives is a better nest and a better price -
     * and what it never said is when holding off starts costing the job its critical path. On this date
     * the materials still have to be quoted, ordered and delivered before the saw starts, so this is
     * the last day pressing "Start quoting" is early enough. Nothing presses it for you; the
     * fabrication deadline warnings email and bell whoever's job starts first on the day.
     *
     * The board's Nesting card is the one place it is drawn. The Nesting page's cards print the
     * deadline the work has instead - the day the steel is wanted at the workshop, one working day before
     * fabrication starts (RequiredByPill) - which is days later and about a different thing: this is
     * the last day to press the button, that is the day the material has to be there.
     *
     * The date itself is always computed server side, off the constant the warnings actually use
     * (KanbanFormatter::orderingTriggerDate), so neither screen can promise a date nothing is keeping
     * to.
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //Null on a card where no project has a fabrication date - nothing will chase it
        date: String,
    });

    //Variables
    const label = computed(() => props.date
        ? moment(props.date).format("D MMM YY")
        : null);

    const days = computed(() => props.date
        ? moment(props.date).startOf('day').diff(moment().startOf('day'), 'days')
        : null);
</script>

<template>
    <span
        v-if="label"
        class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset"
        :class="days <= 0
            ? 'bg-red-50 text-red-800 ring-red-200'
            : (days <= 2
                ? 'bg-amber-50 text-amber-900 ring-amber-200'
                : 'bg-white text-gray-600 ring-gray-200')"
        :title="days <= 0
            ? 'This batch is due to be quoted now - the earliest fabrication date on this card is within the ordering window'
            : 'Start quoting by this date, so the materials can be quoted, ordered and delivered before fabrication starts. You will be emailed on the day if the column is still waiting'"
    >
        <i class="fa-solid fa-cart-shopping text-[10px] opacity-70"></i>
        Order by {{ label }}
        <span v-if="days > 0" class="font-medium opacity-75">
            ({{ days }}d)
        </span>
        <span v-else class="font-medium opacity-75">(now)</span>
    </span>
</template>
