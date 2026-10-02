<script setup>
    /**
     * The day the Nesting column stops waiting and buys.
     *
     * The one thing about that column nobody can work out by looking at it: it tells you to hold off as
     * long as you can, because every day more material arrives is a better nest and a better price -
     * and what it never said is when holding off stops being your decision. On this date the fabrication
     * deadline sweep nests everything waiting into one batch, owned by whoever's job starts first, and
     * emails the rest of the business to say so.
     *
     * One component because two screens draw it now - the board's Nesting card and the Nesting page -
     * and the colour is a warning: a pill that turns red two days earlier on one screen than the other
     * would be telling people different things about the same deadline.
     *
     * The date itself is always computed server side, off the constant the sweep actually uses
     * (KanbanFormatter::orderingTriggerDate), so neither screen can promise a date the schedule has
     * stopped keeping.
     */
    //General Imports
    import {computed} from 'vue';
    import moment from 'moment';

    //Props
    const props = defineProps({
        //Null on a card where no project has a fabrication date - nothing will auto-quote it
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
            : 'On this date these projects are nested into one batch automatically, so the materials can be quoted, ordered and delivered before fabrication starts'"
    >
        <i class="fa-solid fa-cart-shopping text-[10px] opacity-70"></i>
        Order by {{ label }}
        <span v-if="days > 0" class="font-medium opacity-75">
            ({{ days }}d)
        </span>
        <span v-else class="font-medium opacity-75">(now)</span>
    </span>
</template>
