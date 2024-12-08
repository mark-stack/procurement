<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        stockLength: Number,
        pieces: Array,
        measurementUnit: String,
        waste: Number,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    function displayUnits(){
        let displayUnits = props.measurementUnit;

        if(props.measurementUnit === "SINGLE"){
            displayUnits = " off";
        }
        if(props.measurementUnit === "METERS"){
            displayUnits = "m";
        }
        if(props.measurementUnit === "MILLIMETERS"){
            displayUnits = "mm";
        }

        return displayUnits;
    }

    //Methods
    //...

</script>

<template>
    <div class="text=sm">
        {{ stockLength }}{{ displayUnits() }}: <span v-for="piece in pieces" class="text-green-500 border-2 border-green-300 px-1">{{parseFloat(piece).toFixed(1)}}</span><span class="text-red-500 border-2 border-red-300 px-1">{{waste.toFixed(1)}}</span>
    </div>
</template>
