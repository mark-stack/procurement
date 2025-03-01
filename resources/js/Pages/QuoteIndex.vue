<script setup>
    //General Imports
    import {ref} from "vue";
    import moment from "moment";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import DisplayPiecesList from "@/Components/DisplayPiecesList.vue";
    import ListPurchasables from "@/Components/ListPurchasables.vue";
    import VisualBundleNest from "@/Components/VisualBundleNest.vue";
    import VisualOrderList from "@/Components/VisualOrderList.vue";
    import VisualNestingWithBars from "@/Components/VisualNestingWithBars.vue";
    import Modal from "@/Layouts/Modal.vue";
    import VisualNestingOffcuts from "@/Components/VisualNestingOffcuts.vue";

    //Props
    const props = defineProps({
        pieces: Object,
        projectsReadyForBatching: Object,
        piecesGroupedBySupplierGroup: Object,
        usage: Object,
        type: String,
        width: Number,
        lettersProjectArray: Object,
        redirect: String,
    });

    //Form
    //

    //Variables
    const currentBatch = ref(Object.keys(props.piecesGroupedBySupplierGroup.assigned)[0]);
    const height = window.innerHeight - 250;

    //Shared data

    //Methods
    //
</script>

<template>
    <AuthenticatedLayout>
        <Modal :fakeModal="true" :redirect="redirect">
            <!-- header -->
            <div>
                <h2 class="text-center font-bold text-lg">NESTING</h2>
            </div>
            <!-- body -->
            <div :style="'width:'+width+'px; height:'+height+'px'" class="overflow-y-auto p-5">
                <!-- Pieces -->
                <section class="container max-w-5xl mx-auto mt-5">
                    <div
                        v-if="projectsReadyForBatching.data.length > 0"
                        class="grid grid-cols-5"
                    >
                        <!-- included projects -->
                        <div class="col-span-2">
                            <h2 class="font-bold">Included Projects:</h2>
                            <ul>
                                <li v-for="(project,index) in projectsReadyForBatching.data">
                                    - Project '<b>{{lettersProjectArray[project.id]}}</b>': <i>{{project.name}}</i> ({{project.projectManager.name}}'s project)
                                </li>
                            </ul>
                        </div>
                        <!-- Usage stats-->
                        <div class="col-span-3">
                            <table class="w-full">
                               <tr>
                                   <th>Total Material</th>
                                   <th>Total Used Material</th>
                                   <th>Total Reusable</th>
                                   <th>Total Scrap</th>
                                   <th>Efficiency</th>
                               </tr>
                                <tr>
                                    <td>{{ (usage.METERAGE.totalPurchasedMaterial/1000).toLocaleString() }}m</td>
                                    <td>{{ (usage.METERAGE.totalUsedMaterial/1000).toLocaleString() }}m</td>
                                    <td>{{ (usage.METERAGE.totalReusable/1000).toLocaleString() }}m</td>
                                    <td>{{ (usage.METERAGE.totalScrap/1000).toLocaleString() }}m</td>
                                    <td>{{ usage.METERAGE.efficiency }}%</td>
                                </tr>
                            </table>
                        </div>
                    </div>

                    <div v-else>
                        There's no projects with materials ready to quote yet.
                    </div>

                    <div class="mb-3 text-gray-600 mt-3">
                        <!-- buttons to toggle supplier groups. e.g "steel merchant" -->
                        <div v-if="Object.keys(piecesGroupedBySupplierGroup.assigned).length > 1" class="flex gap-x-3">
                            <button
                                v-for="(batchGroup,batchLabel) in piecesGroupedBySupplierGroup.assigned"
                                class="rounded px-2 py-1 text-green-900"
                                :class="batchLabel === currentBatch ? 'bg-green-300 border-2 border-green-900' : 'bg-green-200'"
                                @click="currentBatch = batchLabel"
                            >
                                <b>{{batchLabel}}</b> batch
                            </button>
                        </div>


                        <template v-for="(batchGroup,batchLabel) in piecesGroupedBySupplierGroup.assigned">
                            <div v-if="batchLabel === currentBatch" class="pt-5">

                                <div class="grid grid-cols-1 gap-5">
                                    <div
                                        v-for="item in batchGroup"
                                        class="grid grid-cols-4 border-2 border-gray-300 rounded-xl p-5 gap-3 bg-white"
                                    >
                                        <!-- Spec -->
                                        <div>
                                            <h2 class="font-bold">Material Spec</h2>
                                            {{item.product_derived_label}}
                                            <p class="text-xs">
                                                <span class="block">Product: {{item.product_category}}</span>
                                                <span class="block">Material: {{item.material}}</span>
                                                <span class="block">Grade: {{item.grade}}</span>
                                                <span class="block">Surface: {{item.surface}}</span>
                                            </p>
                                        </div>
                                        <!-- Pieces -->
                                        <div>
                                            <h2 class="font-bold">Pieces</h2>
                                            <DisplayPiecesList
                                                :nestingAlgo="item.algo"
                                                :measurementUnit="item.nominal_units"
                                                :pieces="item.pieces"
                                                :lettersProjectArray="lettersProjectArray"
                                            />
                                        </div>
                                        <!-- Purchasable -->
                                        <div>
                                            <h2 class="font-bold">Purchasable options</h2>
                                            <ListPurchasables
                                                :nestingAlgo="item.algo"
                                                :measurementUnit="item.nominal_units"
                                                :list="item.purchasableLengths"
                                            />
                                        </div>
                                        <!-- order list -->
                                        <div>
                                            <h2 class="font-bold">Order List</h2>
                                            <template v-for="bar in item.nested.orderList">
                                                <VisualOrderList
                                                    :stockLength="bar.result"
                                                    :pieces="bar.result.pieces"
                                                    :measurementUnit="item.nominal_units"
                                                    :waste="bar.result.waste"
                                                    :qty="bar.count"
                                                />
                                            </template>
                                        </div>
                                        <!-- Nesting -->
                                        <div class="col-span-4">

                                            <!-- Nesting algorithm: meterage -->
                                            <div v-if="item.algo === 'METERAGE'">

                                                <!-- offcuts -->
                                                <div v-if="Object.values(item.nested.bestResultOffcuts.utilisedOffcutBars).length > 0">
                                                    <h2 class="font-bold">Offcut usage</h2>
                                                    <VisualNestingOffcuts
                                                        :utilisedOffcutBars="item.nested.bestResultOffcuts.utilisedOffcutBars"
                                                        :measurementUnit="item.nominal_units"
                                                    />
                                                </div>

                                                <template v-if="Object.values(item.nested.utilisedBars).length > 0">
                                                    <h2 class="font-bold">New stock usage</h2>
                                                    <!-- new stock nesting -->
                                                    <VisualNestingWithBars
                                                        :utilisedBars="item.nested.utilisedBars"
                                                        :measurementUnit="item.nominal_units"
                                                    />
                                                    <p
                                                        v-if="item.nested.tooLong.length > 0"
                                                        class="text-red-500 font-bold mt-2"
                                                    >
                                                        Pieces too long: <span v-for="unfit in item.nested.tooLong">{{ parseFloat(unfit.length).toLocaleString()}} mm (p{{unfit.letter}}), </span>
                                                    </p>
                                                </template>
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
        </Modal>
    </AuthenticatedLayout>
</template>

