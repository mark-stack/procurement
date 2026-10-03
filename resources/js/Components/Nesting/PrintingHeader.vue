<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        item: Object,
        //Null on the open batch, which has no batch row to be numbered - see NestingPrintFriendly
        batch: {
            type: Object,
            default: null,
        },
        index: Number,
        qty: Number,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //...

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Methods
    function subPageQty(){
        let display = "";
        if(props.qty > 1){
            display = " (" + (props.index + 1) + " of " + props.qty + ")";
        }

        return display;
    }
</script>

<template>
    <div class="grid grid-cols-5 pt-4 pb-4 pr-5 pl-5">
        <div class="col-span-2">
            <b>{{shared.cropText(shared.capitalizeWords(item.product_derived_label),23)}}</b>{{subPageQty()}}
        </div>
        <div class="text-center">
            {{ batch ? 'Batch: ' + batch.id : 'Open batch - not yet quoted' }}
        </div>
        <div class="col-span-2 text-right">
            SteelNesting.com.au
        </div>
    </div>
</template>
