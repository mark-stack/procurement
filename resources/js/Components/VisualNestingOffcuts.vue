<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        offcut: Object,
        measurementUnit: String,
        batched: Boolean,
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
        let offcutLength = offcut.sourceOffcut.offcutLength;
        let cuts = offcut.sourceOffcut.cuts;

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

    // function getEfficiencyPct(stockLength,unused){
    //     let used = stockLength - unused;
    //
    //     return (used/stockLength*100).toFixed(1);
    // }
</script>

<template>
    <div class="grid grid-cols-1 gap-x-10">
        <div class="w-full pt-4 pb-4">
            <div>
                <span class="font-bold">1 off {{offcut.sourceOffcut.offcutLength}}{{ displayUnits() }}:</span> <span class="ml-1 italic"><small>marked</small> "{{offcut.sourceOffcut.unique_mark}}"</span> <small class="ml-3">Used {{Math.round(offcut.sourceOffcut.cutLength/offcut.sourceOffcut.offcutLength*100)}}%</small>
            </div>
            <div class="shadow w-full bg-red-200 flex flex-row border-2 border-black">
                <div
                    v-for="cut in getPieces(offcut)"
                    class="font-bold bg-blue-100 text-xs leading-none py-2 text-center text-black border-r-4 border-black"
                    :style="'width: '+cut.lengthPercentage+'%'"
                >
                    <p :class="cut.lengthPercentage < 5 ? 'relative top-7 right-2 text-black' : 'text-black'">
                        {{cut.length}} {{'('+cut.letter+')'}}
                    </p>
                </div>
                <!-- reusable (there's "reusableLength" and "scrapLength") -->
                <div
                    v-if="offcut.offcutFromOffcut.reusableLength > 0"
                    class="font-bold bg-green-100 text-xs leading-none py-2 text-center text-black border-r-4 border-black"
                    :style="'width: '+(offcut.offcutFromOffcut.reusableLength/offcut.sourceOffcut.offcutLength*100)+'%'"
                >
                    <p v-if="batched" class="italic">
                        <small>mark</small> "{{offcut.offcutFromOffcut.unique_mark}}"
                    </p>
                    <p v-else>
                        Reuse
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
