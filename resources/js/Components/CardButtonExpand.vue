<script setup>
    //General Imports

    //Props
    const props = defineProps({
        label: String|Number,
        expandedIndex: Number,
        thisIndex: Number,
    });
</script>

<template>
    <div
        class="w-full flex justify-between bg-gray-100 hover:bg-gray-200 border-[1px] border-gray-300 text-center h-5 font-semibold text-gray-500 hover:text-gray-600 rounded-full"
        style="cursor: pointer;"
    >
        <i :class="'fa-regular fa-circle-' + (expandedIndex === thisIndex ? 'up' : 'down') + ' text-sm pr-1'" style="padding-left:3px;"></i>
        <span class="w-full text-xs pr-2" style="padding-top:1px;">{{label}}</span>
    </div>
</template>
