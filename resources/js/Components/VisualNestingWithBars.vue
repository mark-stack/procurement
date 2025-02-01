<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        utilisedBars: Object,
        measurementUnit: String,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared Methods
    import shared from "@/Shared/shared.js";

    //Methods
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

    function getPieces(bar){
        let stockLength = bar.result['bar_length'];
        let pieces = bar.result.pieces

        let getPieces = [];
        Object.values(pieces).forEach(piece => {
            let lengthPercentage = piece.cutLength/stockLength*100;
            getPieces.push({
                lengthPercentage: lengthPercentage,
                length: piece.cutLength,
                projectId: piece.projectId,
                letter: piece.letter,
            });
        });

        return getPieces;
    }

    function getEfficiencyPct(stockLength,waste){
        let used = stockLength - waste;

        return (used/stockLength*100).toFixed(1);
    }
</script>

<template>
    <div v-for="bar in utilisedBars" class="pt-4 pb-4">
        <div>
            <span class="font-bold">{{bar.count}} off {{ parseFloat(bar.result['bar_length']).toLocaleString() }}{{ displayUnits() }}:</span> <span>Waste: {{bar.result.waste}}{{ displayUnits() }} (used {{getEfficiencyPct(bar.result['bar_length'],bar.result.waste)}}%)</span>
        </div>
        <div class="shadow w-full bg-red-500 flex flex-row">
            <div
                v-for="piece in getPieces(bar)"
                class="font-bold bg-blue-500 text-xs leading-none py-2 text-center text-blue-50 border-r-4 border-black"
                :style="'width: '+piece.lengthPercentage+'%'"
            >
                {{piece.length}} {{'('+piece.letter+')'}}
            </div>
        </div>
    </div>
</template>
