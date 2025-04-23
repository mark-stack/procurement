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

    function getEfficiencyPct(stockLength,unused){
        let used = stockLength - unused;

        return (used/stockLength*100).toFixed(1);
    }

    function smallCuts(bar){
        let smallCuts = [];
        let pieces = getPieces(bar);
        Object.values(pieces).forEach(piece => {
            if(piece.lengthPercentage < 3){
                smallCuts.push(piece);
            }
        });

        return smallCuts;
    }
</script>

<template>
    <div v-for="bar in utilisedBars" class="pt-4 pb-4">
        <div class="grid grid-cols-2">
            <div>
                <span class="font-bold">{{bar.count}} off {{ parseFloat(bar.result['bar_length']).toLocaleString() }}{{ displayUnits() }}:</span> <span>Unused: {{bar.result.unused.toLocaleString()}}{{ displayUnits() }} (used {{getEfficiencyPct(bar.result['bar_length'],bar.result.unused)}}%)</span>
            </div>
            <div v-if="smallCuts(bar).length > 0" class="text-right">
                <span class="font-semibold">Small cuts* </span>
                <span v-for="(piece,index) in smallCuts(bar)">{{index > 0 ? ', ' : ''}}<b>{{piece.length}}</b> {{'('+piece.letter+')'}}</span>
            </div>
        </div>
        <div class="shadow w-full bg-red-200 flex flex-row border-2 border-black" style="height:30px">
            <div
                v-for="piece in getPieces(bar)"
                class="font-bold bg-blue-100 text-xs leading-none py-2 text-center border-r-4 border-black"
                :style="'width: '+piece.lengthPercentage+'%'"
            >
                <p
                    v-if="piece.lengthPercentage >= 5"
                    class="text-black"
                >
                    {{piece.length}} {{'('+piece.letter+')'}}
                </p>
                <p
                    v-else-if="piece.lengthPercentage >= 3 && piece.lengthPercentage < 6"
                    class="relative top-7 right-2 text-black"
                >
                    {{piece.length}} {{'('+piece.letter+')'}}
                </p>
                <p
                    v-else-if="piece.lengthPercentage < 3"
                    class="relative top-7 right-1 text-black"
                >
                    *
                </p>
            </div>
            <!-- reusable -->
            <div
                v-if="bar.result.unused > bar.result.scrap_threshold_mm"
                class="bg-green-100 text-xs leading-none py-2 text-center text-black border-r-4 border-black"
                :style="'width: '+(bar.result.unused/bar.result['bar_length']*100)+'%'"
            >
                <b><i>"{{bar.result.unique_scrap_id}}"</i></b>
            </div>
        </div>
    </div>
</template>
