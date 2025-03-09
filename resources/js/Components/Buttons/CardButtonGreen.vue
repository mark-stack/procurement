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
        :class="highlight ? 'bg-green-100 hover:bg-green-200 border-[1px] border-green-300' : 'bg-green-50 hover:bg-green-100 border-[1px] border-green-200'"
        class="w-full text-center h-{{icon ? 5 : 5}} px-2 font-semibold text-green-500 hover:text-green-600 rounded-full"
        style="cursor: pointer;"
        :style="icon ? 'padding-top:2px' : 'padding-bottom:2px'"
    >
        <i v-if="icon" class="fa-solid fa-list text-sm pr-1"></i>
        <span v-if="icon" class="text-sm">{{label}}</span>
        <p v-else class="text-xs">{{label}}</p>
    </div>
</template>
