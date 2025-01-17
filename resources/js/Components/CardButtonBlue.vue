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
        :class="highlight ? 'bg-blue-100 hover:bg-blue-200' : 'bg-blue-50 hover:bg-blue-100'"
        class="w-full text-center pt-1 h-6 px-3 text-xs font-semibold text-blue-400 hover:text-blue-500 rounded-full"
        style="cursor: pointer;"
    >
        {{label}}
    </div>
</template>
