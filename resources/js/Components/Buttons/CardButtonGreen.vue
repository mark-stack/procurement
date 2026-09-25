<script setup>
    //General Imports


    //Props
    const props = defineProps({
        label: String|Number,
        highlight: Boolean,
        icon: Boolean,
    });
</script>

<template>
    <div
        :class="highlight
            ? 'bg-green-700 border-green-700 text-white hover:bg-green-800 hover:border-green-800'
            : 'bg-green-50 border-green-200 text-green-800 hover:bg-green-100 hover:border-green-300'"
        class="inline-flex h-8 w-full cursor-pointer select-none items-center justify-center gap-1.5 rounded-lg border px-2.5 text-xs font-semibold transition-colors duration-150"
    >
        <i v-if="icon" class="fa-solid fa-list text-[11px] opacity-70"></i>
        <span class="truncate">{{label}}</span>
    </div>
</template>
