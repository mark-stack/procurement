<script setup>
    //General Imports
    import {ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import shared from "@/Shared/shared.js";
    import VisualNestingWithText from "@/Components/VisualNestingWithText.vue";
    import DisplayPiecesList from "@/Components/DisplayPiecesList.vue";
    import ListPurchasables from "@/Components/ListPurchasables.vue";
    import VisualBundleNest from "@/Components/VisualBundleNest.vue";
    import VisualOrderList from "@/Components/VisualOrderList.vue";

    //Props
    const props = defineProps({
        pieces: Object,
        projectsForQuoting: Object,
        batchGroups: Object,
    });

    //Form

    //Variables
    const currentBatch = ref(Object.keys(props.batchGroups.assigned)[0]);

    //Shared data

    //Methods
    function sendSupplierBatchEmail(batchGroup) {
        // Email details
        const emailAddress = ""; //"example@example.com";
        const subject = "xxxxxxxxx"; //todo
        let materialList = ""; // Headers

        Object.values(batchGroup).forEach(item => {
            //Meterage
            if(item.algo === 'METERAGE'){
                // Build the materual list

                let description = shared.formatProduct(item.product,item.size,item.grade, item.surface,item.length)

                item.nested.orderList.forEach(bar => {
                    let text = " - " + description + ": " + bar.count + " off " + bar.result + " " + item.measurement_unit.toLowerCase();
                    materialList += text + "\n"; // Rows
                });
            }
            //Area
            if(item.algo === 'AREA'){
                //todo
            }
            //Bundle
            if(item.algo === 'BUNDLE'){
                //todo
            }
        });

        // Create the mailto link
        let row1 = "Hi, I'm seeking a quote for the following:";
        let row2 = materialList;
        let row3 = "Thank you.";

        const body = encodeURIComponent(`${row1}\n\n${row2}\n\n${row3}`);
        const mailtoLink = `mailto:${emailAddress}?subject=${encodeURIComponent(subject)}&body=${body}`;

        // Open the email client
        window.location.href = mailtoLink;
    }
</script>

<template>
    <Head title="Quotes" />

    <AuthenticatedLayout>
        <div class="py-5">

            <!-- Pieces -->
            <section class="container max-w-5xl mx-auto mt-5">
                <h2 class="font-bold text-lg">Material nesting & batching</h2>
                <p class="mb-3">
                    Criteria of materials ready to batch:
                    <ul>
                        <li> - Project is awarded (not tender phase)</li>
                        <li> - Project is active (not archived)</li>
                        <li> - Materials are imported and clarified against pricebook</li>
                    </ul>
                </p>

                <p v-if="projectsForQuoting.length > 0">
                    <h2 class="font-semibold">Projects with ready-to-quote materials:</h2>
                    <ul>
                        <li v-for="(project,index) in projectsForQuoting"> - p{{project.id}}: {{project.name}} (PM is {{project.user.name}}) ([8] days till quote deadline])</li>
                    </ul>
                </p>
                <p v-else>
                    There's no projects with materials ready to quote yet.
                </p>
                <div class="mb-3 text-gray-600 mt-3">

                    <div class="flex gap-x-3">
                        <button
                            v-for="(batchGroup,batchLabel) in batchGroups.assigned"
                            class="rounded px-2 py-1 text-green-900"
                            :class="batchLabel === currentBatch ? 'bg-green-300 border-2 border-green-900' : 'bg-green-200'"
                            @click="currentBatch = batchLabel"
                        >
                            <b>{{batchLabel}}</b> batch
                        </button>
                    </div>


                    <template v-for="(batchGroup,batchLabel) in batchGroups.assigned">

                        <div v-if="batchLabel === currentBatch" class="pt-5">
                            <div>
                                <b>Suppliers:</b>
                                <ul>
                                    <li>
                                        Surdex Steel:
                                        <button
                                            @click="sendSupplierBatchEmail(batchGroup)"
                                            class="ml-2 mr-2 bg-deep-purple-accent-400 rounded px-2 py-1 text-white text-sm"
                                        >
                                            Prepare Surdex email
                                        </button>
                                        (does not send anything)
                                    </li>
                                    <li>ABC Steel:</li>
                                    <li>XYZ Steel:</li>
                                </ul>
                            </div>
                            <div class="grid grid-cols-1 gap-5">
                                <div
                                    v-for="item in batchGroup"
                                    class="grid grid-cols-4 border-2 border-gray-300 rounded-xl p-5 gap-3"
                                >
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
                                        <h2 class="font-bold">Purchasable options</h2>
                                        <ListPurchasables
                                            :nestingAlgo="item.algo"
                                            :measurementUnit="item.measurement_unit"
                                            :list="item.purchasable"
                                        />
                                    </div>
                                    <!-- order list -->
                                    <div>
                                        <h2 class="font-bold">Order List</h2>
                                        <template v-for="bar in item.nested.orderList">
                                            <VisualOrderList
                                                :stockLength="bar.result"
                                                :pieces="bar.result.pieces"
                                                :measurementUnit="item.measurement_unit"
                                                :waste="bar.result.waste"
                                                :qty="bar.count"
                                            />
                                        </template>
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
                                        <!-- Nesting algorithm: bundle -->
                                        <div v-if="item.algo === 'BUNDLE'">
                                            <VisualBundleNest
                                                :nestData="item.nested"
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </section>
        </div>
    </AuthenticatedLayout>

</template>

