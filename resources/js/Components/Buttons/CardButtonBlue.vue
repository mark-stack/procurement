<script setup>
    //Props
    const props = defineProps({
        label: String,
        highlight: Boolean,
    });
</script>

<template>
    <div
        :class="highlight
            ? 'bg-blue-700 border-blue-700 text-white hover:bg-blue-800 hover:border-blue-800'
            : 'bg-white border-gray-300 text-gray-700 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-800'"
        class="inline-flex h-8 w-full cursor-pointer select-none items-center justify-center rounded-lg border px-2.5 text-xs font-semibold shadow-sm transition-colors duration-150"
    >
        <span class="truncate">{{label}}</span>
    </div>
</template>
