<script setup>
    //General Imports

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import shared from "@/Shared/shared.js";
    import VisualNestingWithText from "@/Components/VisualNestingWithText.vue";
    import DisplayPiecesList from "@/Components/DisplayPiecesList.vue";
    import ListPurchasables from "@/Components/ListPurchasables.vue";

    //Props
    const props = defineProps({
        pieces: Object,
    });

    //Form

    //Variables

    //Shared data

    //Methods

</script>

<template>
    <Head title="Quotes" />

    <AuthenticatedLayout>
        <div class="py-5">

            <!-- Pieces -->
            <section class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Materials</h2>
                <p>
                    Eligible Projects: [aaa,bbb,ccc]
                </p>
                <div class="grid grid-cols-1 gap-y-5 mb-3 text-gray-600 mt-3">
                    <template v-for="nestingGroup in pieces">
                        <template v-for="item in nestingGroup">
                            <div class="grid grid-cols-4 border-2 border-gray-300 rounded-xl p-5 gap-3">
                                <!-- Spec -->
                                <div>
                                    <h2 class="font-bold">Material Spec</h2>
                                    {{shared.formatProduct(item.product,item.size,item.grade, item.surface,item.length)}}
                                    <p class="text-xs">
                                        <span class="block">Product: {{item.product}}</span>
                                        <span class="block">Material: {{item.material}}</span>
                                        <span class="block">Grade: {{item.grade}}</span>
                                        <span class="block">Surface: {{item.surface}}</span>
                                        <span class="block" v-if="item.measurement_unit">Unit: {{item.measurement_unit}}</span>
                                        <span class="block">Size: {{item.size}}</span>
                                    </p>
                                </div>
                                <!-- Pieces -->
                                <div>
                                    <h2 class="font-bold">Pieces</h2>
                                    <DisplayPiecesList
                                        :nestingAlgo="item.algo"
                                        :measurementUnit="item.measurement_unit"
                                        :pieces="item.pieces"
                                    />
                                </div>
                                <!-- Purchasable -->
                                <div>
                                    <h2 class="font-bold">Purchasable</h2>
                                    <ListPurchasables
                                        :nestingAlgo="item.algo"
                                        :measurementUnit="item.measurement_unit"
                                        :list="item.purchasable"
                                    />
                                </div>
                                <!-- suppliers -->
                                <div>
                                    <h2 class="font-bold">Suppliers</h2>
                                    suppliers...
                                </div>
                                <!-- Nesting -->
                                <div class="col-span-4">
                                    <h2 class="font-bold">{{item.algo}} Nesting</h2>
                                    <!-- Nesting algorithm: meterage -->
                                    <div v-if="item.algo === 'METERAGE'">

                                        <p v-for="bar in item.nested.usedStockBars" class="mt-3">
                                            <VisualNestingWithText
                                                :stockLength="bar.result['stock length']"
                                                :pieces="bar.result.pieces"
                                                :measurementUnit="item.measurement_unit"
                                                :waste="bar.result.waste"
                                                :qty="bar.count"
                                            />
                                        </p>
                                        <p
                                            v-if="item.nested.unfitCuts.length > 0"
                                            class="text-red-500 font-bold"
                                        >
                                            Unused: <span v-for="unfit in item.nested.unfitCuts">{{unfit.length}} (p{{unfit.project}}), </span>
                                        </p>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

</template>

