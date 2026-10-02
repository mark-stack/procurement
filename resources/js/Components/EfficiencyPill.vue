<script setup>
    /**
     * How much of the steel this nest buys ends up in the job.
     *
     * One component because the board's Nesting card and the Nesting page both show it, and both get
     * it the same way: the page draws first and the percentage arrives afterwards, because working it
     * out means either walking a saved nest or running the nesting algorithm outright.
     *
     * So there are three states, not two - a figure, a figure still coming, and nothing to say. The
     * last one draws nothing at all: a batch nested before the nest was saved against it has no
     * efficiency to report, and a permanent spinner would promise one that is never coming.
     */
    //Props
    const props = defineProps({
        //Whole percent, as NestingFormatter::usageStats works it out. Null until it arrives
        efficiency: Number,
        //Still being worked out. Ignored once there is a figure to show
        loading: Boolean,
    });
</script>

<template>
    <span
        v-if="efficiency > 0"
        class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2 py-1 text-[11px] font-semibold text-green-800 ring-1 ring-inset ring-green-200"
    >
        <i class="fa-solid fa-arrow-trend-up text-[10px]"></i>
        {{ efficiency }}% efficiency
    </span>
    <span
        v-else-if="loading"
        class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-500 ring-1 ring-inset ring-gray-200"
    >
        <i class="fa-solid fa-circle-notch fa-spin text-[10px]"></i>
        Calculating efficiency
    </span>
</template>
