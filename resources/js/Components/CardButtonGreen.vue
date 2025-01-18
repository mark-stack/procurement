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
        :class="highlight ? 'bg-green-100 hover:bg-green-200' : 'bg-green-50 hover:bg-green-100'"
        class="w-full text-center h-6 px-3 font-semibold text-green-500 hover:text-green-600 rounded-full"
        style="cursor: pointer;"
        :style="icon ? 'padding-top:2px' : ''"
    >
        <i v-if="icon" class="fa-solid fa-list text-sm pr-1"></i>
        <span v-if="icon" class="text-sm">{{label}}</span>
        <p v-else class="text-xs pt-1">{{label}}</p>
    </div>
</template>
