<script setup>
    //Props
    const props = defineProps({
        label: String,
        highlight: Boolean,
    });
</script>

<template>
    <!-- Loading (inactive) -->
    <div
        :class="highlight ? 'bg-blue-100 hover:bg-blue-200 border-[1px] border-blue-300' : 'bg-blue-50 hover:bg-blue-100 border-[1px] border-blue-200'"
        class="w-full text-center h-5 px-2 text-xs font-semibold text-blue-400 hover:text-blue-500 rounded-full"
        style="cursor: pointer;"
    >
        {{label}}
    </div>
</template>
