<script setup>
    //Props
    const props = defineProps({
        label: String,
        fullWidth: Boolean,
    });
</script>

<template>
    <button
        type="button"
        :class="fullWidth ? 'w-full' : ''"
        class="inline-flex h-8 items-center justify-center rounded-lg border border-gray-300 bg-white px-2.5 text-xs font-semibold text-gray-600 shadow-sm transition-colors duration-150 hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-400 focus-visible:ring-offset-1"
    >
        <span class="truncate">{{label}}</span>
    </button>
</template>
