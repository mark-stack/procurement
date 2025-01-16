<script setup>
    //Props
    const props = defineProps({
        label: String,
        highlight: Boolean,
    });
</script>

<template>
    <button
        :class="highlight ? 'bg-green-100 hover:bg-green-200' : 'bg-green-50 hover:bg-green-100'"
        class="w-full text-center h-6 px-3 text-xs font-semibold text-green-500 hover:text-green-600 rounded-full"
    >
        {{label}}
    </button>
</template>
