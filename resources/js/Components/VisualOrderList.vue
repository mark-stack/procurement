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
    //...

    //Methods
    function displayUnits(){
        let displayUnits = props.measurementUnit;

        if(props.measurementUnit === "METERS"){
            displayUnits = " m";
        }
        if(props.measurementUnit === "MILLIMETERS"){
            displayUnits = " mm";
        }

        return displayUnits;
    }

</script>

<template>
    <div class="text-sm">
        {{qty}} off {{ parseInt(stockLength).toLocaleString() }}{{ displayUnits() }}
    </div>
</template>
