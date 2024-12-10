<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        stockLength: String,
        pieces: Array,
        measurementUnit: String,
        waste: Number,
        qty: Number,
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

        if(props.measurementUnit === "METERS"){
            displayUnits = "m";
        }
        if(props.measurementUnit === "MILLIMETERS"){
            displayUnits = "mm";
        }

        return displayUnits;
    }

    //Methods
    function getEfficiencyPct(){
        let used = props.stockLength - props.waste;

        return (used/props.stockLength*100).toFixed(1);
    }

</script>

<template>
    <div class="text=sm">
        {{qty}} off {{ stockLength }}{{ displayUnits() }}: <span v-for="(piece,index) in pieces" class="text-green-500 border-2 border-green-300 px-1">{{parseFloat(piece[0]).toFixed(1)}}{{displayUnits()}} (p{{piece[1]}})</span><span v-if="waste > 0" class="text-red-500 border-2 border-red-300 px-1">{{waste.toFixed(1)}}</span> {{getEfficiencyPct()}}%
    </div>
</template>
