<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        nestData: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    //...

    //Methods
    function parseToFloatWithOneDecimal(input) {
        // Parse the string to a float
        const floatVal = parseFloat(input);

        // Round to one decimal place
        return Math.round(floatVal * 10) / 10;
    }
</script>

<template>
    <p v-for="(count,box_size) in nestData.boxes" class="mt-1">
        {{count}}x pack{{count > 1 ? 's' : ''}} of {{box_size}}
    </p>
    <p class="font-semibold mt-3">
        Total bought: {{nestData.totalBought}} (used {{ parseToFloatWithOneDecimal(nestData.efficiency)}}%)
    </p>
</template>
