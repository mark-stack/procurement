<script setup>
    //Props
    const props = defineProps({
        label: String,
    });
</script>

<template>
    <button class="w-full text-center h-6 px-3 text-xs font-semibold text-gray-500 bg-gray-100 hover:bg-red-100 rounded-full">
        {{label}}
    </button>
</template>
