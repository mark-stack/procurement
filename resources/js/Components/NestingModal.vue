<script setup>
    //General Imports
    import { computed, ref, toRefs, watch } from "vue";
    import { usePage, Link } from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import VisualOrderList from "@/Components/VisualOrderList.vue";
    import VisualNestingWithBars from "@/Components/VisualNestingWithBars.vue";

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
    const currentBatch = ref(Object.keys(thisDownloadedNestingData(props.nestingData).batchGroups.assigned)[0]);
    let projects = thisDownloadedNestingData(props.nestingData).projectsReadyForBatching.data;

    //Shared Methods
    import shared from "@/Shared/shared.js";

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
                <div class="pt-2 pb-4 mx-auto text-center">
                    <Link v-if="isAdmin" :href="batchId === 0 ? route('suggested.nesting') : route('batch.nesting',batchId)">
                        <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100 mb-2">
                            Nesting Details
                        </h1>
                    </Link>
                    <h1 v-else class="text-3xl font-semibold text-gray-800 dark:text-gray-100 mb-2">
                        Nesting Details
                    </h1>

                    <div
                        v-if="freezeView"
                        style="height:400px"
                        class="p-20 text-gray-700 italic"
                    >
                        <span class="block font-bold text-xl">Loading...</span>
                        <span class="block text-lg">“Patience is bitter, but its fruit is sweet.”</span>
                    </div>

                    <div
                        v-else
                        class="text-left p-5 overflow-y-auto"
                        style="height:400px"
                    >
                        <!-- included projects -->
                        <div class="flex gap-2">
                            <h2 class="font-semibold">Included Projects:</h2>
                            <div
                                v-for="(project,index) in projects"
                                class="bg-green-50 text-center h-6 px-3 font-semibold text-green-500 rounded-full"
                            >
                                {{shared.cropText(project.name)}} {{shared.getUniqueRef(index,projects.length)}}
                            </div>
                        </div>

                        <!-- Usage stats-->
                        <div class="mt-3 flex justify-between">
                            <h2 class="font-semibold">Usage stats:</h2>
                            <span>Total Material: {{ (thisDownloadedNestingData(nestingData).usage.totalMaterial/1000).toLocaleString() }}m</span>
                            |
                            <span>Total Used Material: {{ (thisDownloadedNestingData(nestingData).usage.totalUsedMaterial/1000).toLocaleString() }}m</span>
                            |
                            <span>Total Waste: {{ (thisDownloadedNestingData(nestingData).usage.totalWaste/1000).toLocaleString() }}m</span>
                            |
                            <span>Efficiency: {{ thisDownloadedNestingData(nestingData).usage.efficiency }}%</span>
                        </div>


                        <div class="mb-3 text-gray-600 mt-3">

                            <div class="flex gap-x-3">
                                <button
                                    v-for="(batchGroup,batchLabel) in thisDownloadedNestingData(nestingData).batchGroups.assigned"
                                    class="rounded px-2 py-1 text-green-900"
                                    :class="batchLabel === currentBatch ? 'bg-green-300 border-2 border-green-900' : 'bg-green-200'"
                                    @click="currentBatch = batchLabel"
                                >
                                    <b>{{batchLabel}}</b> batch
                                </button>
                            </div>

                            <section class="">
                                <div class="flex flex-col mt-6">
                                    <div class="overflow-x-auto">
                                        <div class="inline-block min-w-full py-2 align-middle">
                                            <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                    <thead class="bg-gray-50 dark:bg-gray-800">
                                                        <tr>
                                                            <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                Material
                                                            </th>

                                                            <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                                Order List
                                                            </th>

                                                            <th scope="col" class="relative py-3.5 px-4">
                                                                <span class="sr-only">Edit</span>
                                                            </th>
                                                        </tr>
                                                    </thead>
                                                    <!-- bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900 -->
                                                    <tbody class="">
                                                        <template v-for="(batchGroup,batchLabel) in thisDownloadedNestingData(nestingData).batchGroups.assigned">
                                                            <template v-if="batchLabel === currentBatch">
                                                                <template v-for="item in batchGroup">
                                                                    <tr>
                                                                        <td class="px-4 py-4 text-sm font-medium whitespace-nowrap">
                                                                            <div>
                                                                                <h2 class="text-xl font-medium text-gray-800 ">{{item.product_derived_label}}</h2>
                                                                                <p class="text-xs text-gray-500">
                                                                                    <span class="block">Material: {{item.material}}</span>
                                                                                    <span class="block">Grade: {{item.grade}}</span>
                                                                                    <span class="block">Surface: {{item.surface}}</span>
                                                                                </p>
                                                                            </div>
                                                                        </td>
                                                                        <td class="flex gap-2 px-4 py-4 text-sm whitespace-nowrap">
                                                                            <template v-for="bar in item.nested.orderList">
                                                                                <div class="px-3 py-1 text-sm font-normal rounded-full text-emerald-700 gap-x-2 bg-emerald-100/60 dark:bg-gray-800">
                                                                                    <VisualOrderList
                                                                                        :stockLength="bar.result"
                                                                                        :pieces="bar.result.pieces"
                                                                                        :measurementUnit="item.nominal_units"
                                                                                        :waste="bar.result.waste"
                                                                                        :qty="bar.count"
                                                                                    />
                                                                                </div>
                                                                            </template>
                                                                        </td>
                                                                    </tr>
                                                                    <tr class="border-b-2 border-black">
                                                                        <td class="pt-4 pb-4 pl-5 pr-5" colspan="4">
                                                                            <div v-if="item.algo === 'METERAGE'" >
                                                                                <!-- visual bars -->
                                                                                <VisualNestingWithBars
                                                                                    :usedStockBars="item.nested.usedStockBars"
                                                                                    :measurementUnit="item.nominal_units"
                                                                                />

                                                                                <!-- unused -->
                                                                                <p
                                                                                    v-if="item.nested.unfitCuts.length > 0"
                                                                                    class="text-red-500 font-bold mt-8"
                                                                                >
                                                                                    Unused: <span v-for="unfit in item.nested.unfitCuts">{{ parseFloat(unfit.length).toLocaleString()}} mm ({{unfit.letter}}), </span>
                                                                                </p>
                                                                            </div>
                                                                        </td>
                                                                    </tr>
                                                                </template>
                                                            </template>
                                                        </template>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </section>





                                                                                <!--                            <template v-for="(batchGroup,batchLabel) in thisDownloadedNestingData(nestingData).batchGroups.assigned">-->
<!--                                <div v-if="batchLabel === currentBatch" class="pt-5">-->

<!--                                    <div class="grid grid-cols-1 gap-5">-->
<!--                                        <div v-for="item in batchGroup">-->

<!--                                            <div>-->
<!--                                                &lt;!&ndash; condensed &ndash;&gt;-->
<!--                                                <div v-if="1==2">-->
<!--                                                    condensed-->
<!--                                                </div>-->
<!--                                                &lt;!&ndash; expanded &ndash;&gt;-->
<!--                                                <div v-else class="grid grid-cols-4 border-2 border-gray-300 rounded-xl p-5 gap-3">-->
<!--                                                    &lt;!&ndash; Spec &ndash;&gt;-->
<!--                                                    <div>-->
<!--                                                        <h2 class="font-bold">Material Spec</h2>-->
<!--                                                        {{item.product_derived_label}}-->
<!--                                                        <p class="text-xs">-->
<!--                                                            <span class="block">Product: {{item.product_category}}</span>-->
<!--                                                            <span class="block">Material: {{item.material}}</span>-->
<!--                                                            <span class="block">Grade: {{item.grade}}</span>-->
<!--                                                            <span class="block">Surface: {{item.surface}}</span>-->
<!--                                                        </p>-->
<!--                                                    </div>-->
<!--                                                    &lt;!&ndash; Pieces &ndash;&gt;-->
<!--                                                    <div>-->
<!--                                                        <h2 class="font-bold">Pieces</h2>-->
<!--                                                        <DisplayPiecesList-->
<!--                                                            :nestingAlgo="item.algo"-->
<!--                                                            :measurementUnit="item.nominal_units"-->
<!--                                                            :pieces="item.pieces"-->
<!--                                                        />-->
<!--                                                    </div>-->
<!--                                                    &lt;!&ndash; Purchasable &ndash;&gt;-->
<!--                                                    <div>-->
<!--                                                        <h2 class="font-bold">Purchasable options</h2>-->
<!--                                                        <ListPurchasables-->
<!--                                                            :nestingAlgo="item.algo"-->
<!--                                                            :measurementUnit="item.nominal_units"-->
<!--                                                            :list="item.purchasable"-->
<!--                                                        />-->
<!--                                                    </div>-->
<!--                                                    &lt;!&ndash; order list &ndash;&gt;-->
<!--                                                    <div>-->
<!--                                                        <h2 class="font-bold">Order List</h2>-->
<!--                                                        <template v-for="bar in item.nested.orderList">-->
<!--                                                            <VisualOrderList-->
<!--                                                                :stockLength="bar.result"-->
<!--                                                                :pieces="bar.result.pieces"-->
<!--                                                                :measurementUnit="item.nominal_units"-->
<!--                                                                :waste="bar.result.waste"-->
<!--                                                                :qty="bar.count"-->
<!--                                                            />-->
<!--                                                        </template>-->
<!--                                                    </div>-->
<!--                                                    &lt;!&ndash; Nesting &ndash;&gt;-->
<!--                                                    <div class="col-span-4">-->
<!--                                                        <h2 class="font-bold">{{item.algo}} Nesting</h2>-->
<!--                                                        &lt;!&ndash; Nesting algorithm: meterage &ndash;&gt;-->
<!--                                                        <div v-if="item.algo === 'METERAGE'">-->
<!--                                                            <p v-for="bar in item.nested.usedStockBars" class="mt-3">-->
<!--                                                                <VisualNestingWithText-->
<!--                                                                    :stockLength="bar.result['stock_length']"-->
<!--                                                                    :pieces="bar.result.pieces"-->
<!--                                                                    :measurementUnit="item.nominal_units"-->
<!--                                                                    :waste="bar.result.waste"-->
<!--                                                                    :qty="bar.count"-->
<!--                                                                />-->
<!--                                                            </p>-->
<!--                                                            <p-->
<!--                                                                v-if="item.nested.unfitCuts.length > 0"-->
<!--                                                                class="text-red-500 font-bold mt-2"-->
<!--                                                            >-->
<!--                                                                Unused: <span v-for="unfit in item.nested.unfitCuts">{{ parseFloat(unfit.length).toLocaleString()}} mm (p{{unfit.project}}), </span>-->
<!--                                                            </p>-->
<!--                                                        </div>-->
<!--                                                        &lt;!&ndash; Nesting algorithm: bundle &ndash;&gt;-->
<!--                                                        <div v-if="item.algo === 'BUNDLE'">-->
<!--                                                            <VisualBundleNest-->
<!--                                                                :nestData="item.nested"-->
<!--                                                            />-->
<!--                                                        </div>-->
<!--                                                    </div>-->
<!--                                                </div>-->
<!--                                            </div>-->
<!--                                        </div>-->
<!--                                    </div>-->
<!--                                </div>-->
<!--                            </template>-->
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
