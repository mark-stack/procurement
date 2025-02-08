<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        utilisedOffcutBars: Object,
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

    function getPieces(offcut){
        let offcutLength = offcut.offcutLength;
        let cuts = offcut.cuts;

        let getPieces = [];
        Object.values(cuts).forEach(cut => {
            let lengthPercentage = cut.length/offcutLength*100;
            getPieces.push({
                lengthPercentage: lengthPercentage,
                length: cut.length,
                projectId: cut.projectId,
                letter: cut.letter,
            });
        });

        return getPieces;
    }

    function getEfficiencyPct(stockLength,unused){
        let used = stockLength - unused;

        return (used/stockLength*100).toFixed(1);
    }
</script>

<template>
    <div class="grid grid-cols-1 gap-x-10">
        <div v-for="offcut in utilisedOffcutBars" class="w-full pt-4 pb-4">
            <div>
                <span class="font-bold">1 off {{offcut.offcutLength}}{{ displayUnits() }}:</span> <span>(Used {{Math.round(offcut.cutLength/offcut.offcutLength*100)}}%)</span>
            </div>

            <div class="shadow w-full bg-red-500 flex flex-row">
                <div
                    v-for="cut in getPieces(offcut)"
                    class="font-bold bg-blue-500 text-xs leading-none py-2 text-center text-blue-50 border-r-4 border-black"
                    :style="'width: '+cut.lengthPercentage+'%'"
                >
                    {{cut.length}} {{'('+cut.letter+')'}}
                </div>
                <!-- reusable -->
                <div
                    v-if="offcut.reusableLength > 0"
                    class="font-bold bg-green-500 text-xs leading-none py-2 text-center text-green-50 border-r-4 border-black"
                    :style="'width: '+(offcut.reusableLength/offcut.offcutLength*100)+'%'"
                >
                    Reuse
                </div>
            </div>

        </div>
    </div>
</template>
