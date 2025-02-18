<script setup>
    //Props
    const props = defineProps({
        label: String,
    });
</script>

<template>
    <button class="w-full text-center h-5 px-2 text-xs font-semibold text-gray-500 bg-gray-100 hover:bg-orange-100 rounded-full border-[1px] border-gray-200">
        {{label}}
    </button>
</template>
