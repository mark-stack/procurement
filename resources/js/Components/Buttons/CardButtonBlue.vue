<script setup>
    //Props
    const props = defineProps({
        label: String,
        highlight: Boolean,
        /**
         * The nesting icon, the way CardButtonGreen carries its own. Opt-in rather than always
         * drawn because this component is named for its colour, not for the one screen it happens
         * to link to today.
         */
        icon: Boolean,
        /**
         * A second line under the label, in smaller type - what this button is about to show you,
         * rather than what it does. Optional: without one the button keeps its single-line height,
         * which is what every board card draws.
         */
        sublabel: String,
        /*
         * Greyed and no longer offering to be pressed, the way CardButtonYellow beside it does it.
         * This component is a div inside whatever Link wraps it, so it cannot refuse the click
         * itself - the caller leaves the Link off (see NestingIndex.vue, where the open batch has
         * nothing to nest), and this is how the button then looks.
         */
        disabled: Boolean,
        //Optional - lets a disabled button say why it is disabled
        title: String,
    });
</script>

<template>
    <div
        :title="title"
        :class="[
            disabled
                ? 'cursor-not-allowed border-gray-200 bg-gray-50 text-gray-400'
                : (highlight
                    ? 'bg-blue-700 border-blue-700 text-white hover:bg-blue-800 hover:border-blue-800 cursor-pointer shadow-sm'
                    : 'bg-white border-gray-300 text-gray-700 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-800 cursor-pointer shadow-sm'),
            //A second line needs the room; a single one keeps the row height the board sets
            sublabel ? 'py-1' : 'h-8',
        ]"
        class="inline-flex w-full select-none items-center justify-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition-colors duration-150"
    >
        <!-- Not fa-layer-group: that is already the batch chip's icon at the top of the card -->
        <i v-if="icon" class="fa-solid fa-bars-staggered text-[11px] opacity-70"></i>
        <!--
            Centred, which only shows on a two-line button: the second line is a different width from
            the label, so left-aligned they sit as a ragged stack rather than one block.
        -->
        <span class="min-w-0 text-center">
            <span class="block truncate">{{label}}</span>
            <span v-if="sublabel" class="block truncate text-[10px] font-medium opacity-70">
                {{sublabel}}
            </span>
        </span>
    </div>
</template>
