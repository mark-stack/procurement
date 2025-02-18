<script setup>
    //General Imports

    //Props
    const props = defineProps({
        label: String|Number,

    });
</script>

<template>
    <div
        class="w-full flex justify-between bg-green-100 hover:bg-green-200 border-[1px] border-green-300 text-center h-5 font-semibold text-green-500 hover:text-green-600 rounded-full"
        style="cursor: pointer;"
    >
        <span class="w-full text-xs pl-2" style="padding-top:1px;">{{label}}</span>
        <i class="fa-regular fa-circle-right text-sm pl-1" style="padding-right:3px;"></i>
    </div>
</template>
