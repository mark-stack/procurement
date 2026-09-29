<script setup>
    //General Imports
    //...

    //Props
    const props = defineProps({
        icon: {
            type: String,
            default: "fa-regular fa-folder-open",
        },
    });
</script>

<template>
    <!-- Placeholder for a column with nothing in it yet -->
    <!-- Deliberately softer than a real card: a dashed edge and a part-transparent fill, so an
         empty column reads as empty rather than as a card with nothing written on it -->
    <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-400 bg-white/70 px-4 py-8 text-center">
        <i :class="icon" class="text-xl text-gray-400"></i>
        <p class="mt-3 text-xs leading-relaxed text-gray-500">
            <slot/>
        </p>
    </div>
</template>
