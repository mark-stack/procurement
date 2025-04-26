<script setup>
    //General Imports
    //...

    //Component Imports
    import VisualOrderList from "@/Components/VisualOrderList.vue";

    //Props
    const props = defineProps({
        item: Object,
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
    //...


</script>

<template>
    <div class="grid grid-cols-2 p-5 gap-3">
        <!-- Spec -->
        <div>
            <h2 class="font-bold">Material Spec</h2>
            {{shared.cropText(shared.capitalizeWords(item.product_derived_label),35)}}
            <p class="text-xs">
                <span class="block">Product: {{item.product_category}}</span>
                <span class="block">Material: {{item.material}}</span>
                <span class="block">Grade: {{item.grade}}</span>
                <span class="block">Surface: {{item.surface}}</span>
            </p>
        </div>
        <!--                                &lt;!&ndash; Pieces &ndash;&gt;-->
        <!--                                <div>-->
        <!--                                    <h2 class="font-bold">Pieces</h2>-->
        <!--                                    <DisplayPiecesList-->
        <!--                                        :nestingAlgo="item.algo"-->
        <!--                                        :measurementUnit="item.nominal_units"-->
        <!--                                        :pieces="item.pieces"-->
        <!--                                        :lettersProjectArray="lettersProjectArray"-->
        <!--                                    />-->
        <!--                                </div>-->

        <!-- order list -->
        <div>
            <h2 class="font-bold">Order List</h2>
            <template v-if="item.nested.orderList.length > 0">
                <template v-for="bar in item.nested.orderList">
                    <VisualOrderList
                        :stockLength="bar.result"
                        :pieces="bar.result.pieces"
                        :measurementUnit="item.nominal_units"
                        :waste="bar.result.waste"
                        :qty="bar.count"
                    />
                </template>
            </template>
            <p v-else>
                Stock offcuts only
            </p>
        </div>
    </div>
</template>

<style scoped>

</style>
