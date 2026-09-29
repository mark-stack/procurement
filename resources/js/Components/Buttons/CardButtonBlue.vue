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
    });
</script>

<template>
    <div
        :class="highlight
            ? 'bg-blue-700 border-blue-700 text-white hover:bg-blue-800 hover:border-blue-800'
            : 'bg-white border-gray-300 text-gray-700 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-800'"
        class="inline-flex h-8 w-full cursor-pointer select-none items-center justify-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold shadow-sm transition-colors duration-150"
    >
        <!-- Not fa-layer-group: that is already the batch chip's icon at the top of the card -->
        <i v-if="icon" class="fa-solid fa-bars-staggered text-[11px] opacity-70"></i>
        <span class="truncate">{{label}}</span>
    </div>
</template>
