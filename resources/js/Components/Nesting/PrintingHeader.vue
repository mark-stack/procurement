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

    //Shared Methods
    //...

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
    <div class="grid grid-cols-3 p-4">
        <div>
            <b>{{item.product_derived_label}}</b>{{subPageQty()}}
        </div>
        <div class="text-center">
            Batch: {{batch.id}}
        </div>
        <div class="text-right">
            SteelNesting.com.au
        </div>
    </div>
</template>

<style scoped>

</style>
