<script setup>
    //General Imports

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import shared from "@/Shared/shared.js";
    import VisualNestingWithText from "@/Components/VisualNestingWithText.vue";

    //Props
    const props = defineProps({
        pieces: Object,
    });

    //Form

    //Variables

    //Shared data

    //Methods
    function displayQty(piece){
        let display = "";

        //Single (15 off M16 bolt)
        if(piece.measurement_unit === "SINGLE"){
            display = piece.length + " off";
        }
        //Meterage (4 off 12m PFC)
        else{
            display = "1 off " + piece.length + " " + piece.measurement_unit;
        }

        return display;
    }
</script>

<template>
    <Head title="Quotes" />

    <AuthenticatedLayout>
        <div class="py-5">

            <!-- Pieces -->
            <section class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Quotes</h2>
                <div class="grid grid-cols-1 gap-y-2 mb-3 text-gray-600">
                    <div v-for="piece in pieces" class="grid grid-cols-5">
                        <!-- Spec -->
                        <div>
                            <h2 class="font-bold">Material</h2>
                            {{shared.formatProduct(piece.product,piece.size,piece.grade, piece.surface,piece.length)}}
                            <p class="text-xs">
                                Product: {{piece.product}}
                                <br>Material: {{piece.material}}
                                <br>Grade: {{piece.grade}}
                                <br>Surface: {{piece.surface}}
                                <br>Unit: {{piece.measurement_unit}}
                                <br>Size: {{piece.size}}
                            </p>
                        </div>
                        <!-- Pieces -->
                        <div>
                            <h2 class="font-bold">Pieces</h2>
                            <p v-for="p in piece.lengthsUnits">
                                {{displayQty(p)}}
                            </p>
                        </div>
                        <!-- Purchasable -->
                        <div>
                            <h2 class="font-bold">Purchasable</h2>
                            {{piece.purchasable}}
                        </div>
                        <!-- Nesting -->
                        <div>
                            <h2 class="font-bold">Nested Result</h2>
                            <p v-for="bar in piece.nested.usedStockBars" class="mt-3">
                                <VisualNestingWithText
                                    :stockLength="bar['stock length']"
                                    :pieces="bar.pieces"
                                    :measurementUnit="piece.measurement_unit"
                                    :waste="bar['waste']"
                                />
                                <span v-if="piece.nested.unfitCuts.length > 0" class="text-red-500 font-bold">Unused: {{piece.nested.unfitCuts}}</span>
                            </p>
                        </div>
                        <!-- suppliers -->
                        <div>
                            <h2 class="font-bold">Suppliers</h2>
                            suppliers...
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

</template>

