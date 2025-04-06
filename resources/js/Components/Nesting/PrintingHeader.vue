<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    const props = defineProps({
        item: Object,
        batch: Object,
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
            Batch: {{batch.id}}
        </div>
        <div class="col-span-2 text-right">
            SteelNesting.com.au
        </div>
    </div>
</template>
