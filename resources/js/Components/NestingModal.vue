<script setup>
    //General Imports
    import {computed, ref, toRefs, watch} from "vue";
    import {usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import moment from "moment/moment.js";
    import VisualNestingWithText from "@/Components/VisualNestingWithText.vue";
    import VisualOrderList from "@/Components/VisualOrderList.vue";
    import ListPurchasables from "@/Components/ListPurchasables.vue";
    import VisualBundleNest from "@/Components/VisualBundleNest.vue";
    import DisplayPiecesList from "@/Components/DisplayPiecesList.vue";

    //Props
    const props = defineProps({
        width: String,
        nestingData: Object,
        refreshModalBom: Boolean,
        batchId: Number, //0 = no batch which is "ready for nesting" thats pre-batch
    });

    //Forms
    //

    //Shared data
    const warning = computed(() => usePage().props.flash.warning);

    //Variables
    const emit = defineEmits(['closeModalOnSuccess','redownload']);
    const isAdmin = usePage().props.auth.isAdmin;
    const business = usePage().props.auth.business;
    const freezeView = ref(false);

    //Shared Methods
    //

    //Methods
    function thisDownloadedNestingData(nestingData){
        let data = null;

        if(nestingData){
            let rawData = Object.values(nestingData).find(item => item.batch_id == props.batchId);
            if(rawData){
                data = rawData.data;
            }
        }

        return data;
    }

    function reloadAndDownloadModal(){
        console.log("freeze view and redownload");

        freezeView.value = true;

        emit("redownload",props.project.id);
    }

    //Watcher
    const { refreshModalBom } = toRefs(props);
    watch(refreshModalBom, (newVal) => {
        console.log("watch");
        freezeView.value = false;
    });
</script>

<template>
    <Modal>
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Nesting
                    </h1>

                    <div
                        v-if="freezeView"
                        style="height:400px"
                        class="p-20 text-gray-700 italic"
                    >
                        <span class="block font-bold text-xl">Loading...</span>
                        <span class="block text-lg">“Patience is bitter, but its fruit is sweet.”</span>
                    </div>


                    {{nestingData}}

<!--                    <div v-else class="pt-5">-->
<!--                        <p v-if="nestingData.projectsReadyForBatching.data.length > 0">-->
<!--                            <h2 class="font-semibold">Included Projects:</h2>-->
<!--                            <ul>-->
<!--                                <li v-for="(project,index) in nestingData.projectsReadyForBatching.data">-->
<!--                                    - project #{{project.id}}: <i>'{{project.name}}'</i> ({{project.projectManager.name}}'s project) - Quote request deadline: {{moment(project.quoteRequestDeadline).format("MMMM Do YYYY")}} ({{project.daysUntilQuoteRequestDeadline}}).-->
<!--                                </li>-->
<!--                            </ul>-->
<!--                        </p>-->
<!--                        <p v-else>-->
<!--                            There's no projects with materials ready to quote yet.-->
<!--                        </p>-->

<!--                        &lt;!&ndash; Usage stats&ndash;&gt;-->
<!--                        <div class="mt-5">-->
<!--                            <h2 class="font-semibold">Usage stats:</h2>-->
<!--                            Total Material = {{ (nestingData.usage.totalMaterial/1000).toLocaleString() }} m-->
<!--                            <br>-->
<!--                            Total Used Material = {{ (nestingData.usage.totalUsedMaterial/1000).toLocaleString() }} m-->
<!--                            <br>-->
<!--                            Total Waste = {{ (nestingData.usage.totalWaste/1000).toLocaleString() }} m-->
<!--                            <br>-->
<!--                            Efficiency = {{ nestingData[].usage.efficiency }}-->
<!--                            &lt;!&ndash; {{(Math.round(usage.totalUsedMaterial/usage.totalMaterial*100)) }}% &ndash;&gt;-->
<!--                        </div>-->


<!--                        <div class="mb-3 text-gray-600 mt-3">-->

<!--                            <div class="flex gap-x-3">-->
<!--                                <button-->
<!--                                    v-for="(batchGroup,batchLabel) in batchGroups.assigned"-->
<!--                                    class="rounded px-2 py-1 text-green-900"-->
<!--                                    :class="batchLabel === currentBatch ? 'bg-green-300 border-2 border-green-900' : 'bg-green-200'"-->
<!--                                    @click="currentBatch = batchLabel"-->
<!--                                >-->
<!--                                    <b>{{batchLabel}}</b> batch-->
<!--                                </button>-->
<!--                            </div>-->


<!--                            <template v-for="(batchGroup,batchLabel) in batchGroups.assigned">-->
<!--                                <div v-if="batchLabel === currentBatch" class="pt-5">-->

<!--                                    <div class="grid grid-cols-1 gap-5">-->
<!--                                        <div-->
<!--                                            v-for="item in batchGroup"-->
<!--                                            class="grid grid-cols-4 border-2 border-gray-300 rounded-xl p-5 gap-3"-->
<!--                                        >-->
<!--                                            &lt;!&ndash; Spec &ndash;&gt;-->
<!--                                            <div>-->
<!--                                                <h2 class="font-bold">Material Spec</h2>-->
<!--                                                {{item.product_derived_label}}-->
<!--                                                <p class="text-xs">-->
<!--                                                    <span class="block">Product: {{item.product_category}}</span>-->
<!--                                                    <span class="block">Material: {{item.material}}</span>-->
<!--                                                    <span class="block">Grade: {{item.grade}}</span>-->
<!--                                                    <span class="block">Surface: {{item.surface}}</span>-->
<!--                                                </p>-->
<!--                                            </div>-->
<!--                                            &lt;!&ndash; Pieces &ndash;&gt;-->
<!--                                            <div>-->
<!--                                                <h2 class="font-bold">Pieces</h2>-->
<!--                                                <DisplayPiecesList-->
<!--                                                    :nestingAlgo="item.algo"-->
<!--                                                    :measurementUnit="item.nominal_units"-->
<!--                                                    :pieces="item.pieces"-->
<!--                                                />-->
<!--                                            </div>-->
<!--                                            &lt;!&ndash; Purchasable &ndash;&gt;-->
<!--                                            <div>-->
<!--                                                <h2 class="font-bold">Purchasable options</h2>-->
<!--                                                <ListPurchasables-->
<!--                                                    :nestingAlgo="item.algo"-->
<!--                                                    :measurementUnit="item.nominal_units"-->
<!--                                                    :list="item.purchasable"-->
<!--                                                />-->
<!--                                            </div>-->
<!--                                            &lt;!&ndash; order list &ndash;&gt;-->
<!--                                            <div>-->
<!--                                                <h2 class="font-bold">Order List</h2>-->
<!--                                                <template v-for="bar in item.nested.orderList">-->
<!--                                                    <VisualOrderList-->
<!--                                                        :stockLength="bar.result"-->
<!--                                                        :pieces="bar.result.pieces"-->
<!--                                                        :measurementUnit="item.nominal_units"-->
<!--                                                        :waste="bar.result.waste"-->
<!--                                                        :qty="bar.count"-->
<!--                                                    />-->
<!--                                                </template>-->
<!--                                            </div>-->
<!--                                            &lt;!&ndash; Nesting &ndash;&gt;-->
<!--                                            <div class="col-span-4">-->
<!--                                                <h2 class="font-bold">{{item.algo}} Nesting</h2>-->
<!--                                                &lt;!&ndash; Nesting algorithm: meterage &ndash;&gt;-->
<!--                                                <div v-if="item.algo === 'METERAGE'">-->
<!--                                                    <p v-for="bar in item.nested.usedStockBars" class="mt-3">-->
<!--                                                        <VisualNestingWithText-->
<!--                                                            :stockLength="bar.result['stock_length']"-->
<!--                                                            :pieces="bar.result.pieces"-->
<!--                                                            :measurementUnit="item.nominal_units"-->
<!--                                                            :waste="bar.result.waste"-->
<!--                                                            :qty="bar.count"-->
<!--                                                        />-->
<!--                                                    </p>-->
<!--                                                    <p-->
<!--                                                        v-if="item.nested.unfitCuts.length > 0"-->
<!--                                                        class="text-red-500 font-bold mt-2"-->
<!--                                                    >-->
<!--                                                        Unused: <span v-for="unfit in item.nested.unfitCuts">{{ parseFloat(unfit.length).toLocaleString()}} mm (p{{unfit.project}}), </span>-->
<!--                                                    </p>-->
<!--                                                </div>-->
<!--                                                &lt;!&ndash; Nesting algorithm: bundle &ndash;&gt;-->
<!--                                                <div v-if="item.algo === 'BUNDLE'">-->
<!--                                                    <VisualBundleNest-->
<!--                                                        :nestData="item.nested"-->
<!--                                                    />-->
<!--                                                </div>-->
<!--                                            </div>-->
<!--                                        </div>-->
<!--                                    </div>-->
<!--                                </div>-->
<!--                            </template>-->
<!--                        </div>-->
<!--                    </div>-->
                </div>
            </div>
        </div>
    </Modal>
</template>
