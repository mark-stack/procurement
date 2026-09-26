<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        utilisedBars: Object,
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

    function offcutMarks(bar){
        /*
         * Identical bars are consolidated into one row with a count, but each of them is cut for real
         * and each drop becomes its own offcut record with its own mark. Only the first mark was ever
         * drawn, so on a "3 off" bar two offcuts existed in inventory under marks that were never
         * written on any steel.
         */
        let marks = bar.result.unique_marks;

        if(Array.isArray(marks) && marks.length > 0){
            return marks;
        }

        //Batches nested before the marks were stored as a list carry a single mark
        return bar.result.unique_mark ? [bar.result.unique_mark] : [];
    }

    function offcutMarksLabel(bar){
        return offcutMarks(bar).map(mark => '"'+mark+'"').join(', ');
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
                <span class="font-bold">{{bar.count}} off {{ parseFloat(bar.result['bar_length']).toLocaleString() }}{{ displayUnits() }}:</span> <span>Unused: {{bar.result.unused.toLocaleString()}}{{ displayUnits() }} <small class="ml-2">used {{getEfficiencyPct(bar.result['bar_length'],bar.result.unused)}}%</small></span>
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
            <!-- ">=" to match the backend and Actions/Bar/CreateBarsAndOffcuts, which decide which
                 drops actually become offcut records. ">" drew a drop exactly on the threshold as scrap
                 while an offcut record was banked for it. -->
            <div
                v-if="bar.result.unused >= bar.result.scrap_threshold_mm"
                class="bg-green-100 text-xs leading-none py-2 text-center text-black border-r-4 border-black"
                :style="'width: '+(bar.result.unused/bar.result['bar_length']*100)+'%'"
            >
                <!-- One mark per bar in the count, not just the first one -->
                <p v-if="batched" class="font-bold italic px-1 break-words">
                    <small>{{offcutMarks(bar).length > 1 ? 'marks' : 'mark'}}</small> {{offcutMarksLabel(bar)}}
                </p>
                <p v-else class="font-bold italic">
                    Reuse
                </p>
            </div>
        </div>
    </div>
</template>
