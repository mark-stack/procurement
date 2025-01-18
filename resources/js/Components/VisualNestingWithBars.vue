<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import VisualNestingWithText from "@/Components/VisualNestingWithText.vue";

    const props = defineProps({
        usedStockBars: Object,
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
        let stockLength = bar.result['stock_length'];
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
    <div v-for="bar in usedStockBars" class="pt-4 pb-4">
        <div>
            <span class="font-bold">{{bar.count}} off {{ parseFloat(bar.result['stock_length']).toLocaleString() }}{{ displayUnits() }}:</span> <span>Waste: {{bar.result.waste}}{{ displayUnits() }} (used {{getEfficiencyPct(bar.result['stock_length'],bar.result.waste)}}%)</span>
        </div>
        <div class="shadow w-full bg-red-500 flex flex-row">
            <div
                v-for="piece in getPieces(bar)"
                class="font-bold bg-blue-500 text-xs leading-none py-2 text-center text-blue-50 border-r-4 border-black"
                :style="'width: '+piece.lengthPercentage+'%'"
            >
<!--                {{piece.length}} {{shared.getUniqueRef(index,projects.length)}}-->
                {{piece.length}} {{piece.letter ? ('('+piece.letter+')') : piece.projectId}}
            </div>
        </div>

<!--        <div class="shadow w-full bg-grey-light mt-10 flex flex-row">-->
<!--            <div class="bg-blue-500 text-xs leading-none py-2 text-center text-white border-r-4 border-black" style="width: 20%"></div>-->
<!--            <div class="bg-blue-500 text-xs leading-none py-2 text-center text-white border-r-4 border-black" style="width: 50%"></div>-->
<!--            <div class="bg-red-500 text-xs leading-none py-2 text-center text-white" style="width: 30%"></div>-->
<!--        </div>-->


    </div>

</template>

<style scoped>
    .progress-bar {
        margin: 0;
        display: flex;
        box-shadow: 2px 2px 8px #999;
        justify-content: space-between;
        width: 0;
        height: 0;
        position: relative;
        border: 2px solid #36f;
        border-radius: 2px;
        transition-property: width;
        -webkit-animation: 1s 0.2s scaleIt ease-in-out forwards;
        animation: 1s 0.2s scaleIt ease-in-out forwards;
    }
    .progress-bar li {
        list-style: none;
        content: " ";
        width: 12px;
        height: 12px;
        border-radius: 100%;
        margin: 0 -5px;
        background: #36f;
        border: 2px solid #36f;
        transform: translate(0%, -50%);
    }

    @-webkit-keyframes scaleIt {
        from {
            width: 0;
        }
        to {
            width: 100%;
        }
    }

    @keyframes scaleIt {
        from {
            width: 0;
        }
        to {
            width: 100%;
        }
    }
    .tooltip {
        transform: scale(0);
        box-shadow: 1px 1px 5px #cfcfcf;
        position: relative;
        -webkit-animation: 0s 1.2s appearToolTip ease-in-out forwards;
        animation: 0s 1.2s appearToolTip ease-in-out forwards;
        background: #fff;
        border: 1px solid #ccc;
        padding: 1px 5px;
        position: absolute;
        margin: 15px -8px;
        border-radius: 3px;
        font-size: 11px;
        white-space: nowrap;
    }
    .tooltip.multiline {
        white-space: normal;
    }
    .tooltip:before, .tooltip:after {
        content: " ";
        width: 0;
        height: 0;
        border: 5px solid transparent;
        border-bottom-color: #aaa;
        position: absolute;
        bottom: 100%;
        left: 5px;
    }
    .tooltip:after {
        border: 3px solid transparent;
        border-bottom-color: #eee;
        margin: 0 2px;
    }

    @-webkit-keyframes appearToolTip {
        from {
            transform: scale(0);
        }
        to {
            transform: scale(1);
        }
    }

    @keyframes appearToolTip {
        from {
            transform: scale(0);
        }
        to {
            transform: scale(1);
        }
    }
    .progress-bar li:nth-child(odd) .tooltip {
        bottom: 100%;
        box-shadow: 1px -1px 5px #cfcfcf;
    }
    .progress-bar li:nth-child(odd) .tooltip:after, .progress-bar li:nth-child(odd) .tooltip:before {
        top: 100%;
        bottom: auto;
        border: 5px solid transparent;
        border-top-color: #aaa;
    }
    .progress-bar li:nth-child(odd) .tooltip:after {
        border: 3px solid transparent;
        border-top-color: #eee;
        margin: 0 2px;
    }
</style>
