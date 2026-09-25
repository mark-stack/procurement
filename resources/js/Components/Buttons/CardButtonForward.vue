<script setup>
    //General Imports

    //Props
    const props = defineProps({
        label: String|Number,

    });
</script>

<template>
    <!-- The "move this along" action - always the loudest button on a card -->
    <div
        class="inline-flex h-8 w-full cursor-pointer select-none items-center justify-center gap-1.5 rounded-lg border border-green-700 bg-green-700 px-2.5 text-xs font-semibold text-white shadow-sm transition-colors duration-150 hover:border-green-800 hover:bg-green-800"
    >
        <span class="truncate">{{label}}</span>
        <i class="fa-regular fa-circle-right text-sm"></i>
    </div>
</template>
