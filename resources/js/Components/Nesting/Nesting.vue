<script setup>
    //General Imports
    import {computed, ref} from "vue";

    //Component Imports
    import ListPurchasables from "@/Components/ListPurchasables.vue";
    import DisplayPiecesList from "@/Components/DisplayPiecesList.vue";
    import VisualNestingWithBars from "@/Components/VisualNestingWithBars.vue";
    import VisualNestingOffcuts from "@/Components/VisualNestingOffcuts.vue";
    import VisualOrderList from "@/Components/VisualOrderList.vue";
    import VisualBundleNest from "@/Components/VisualBundleNest.vue";
    import {Link} from "@inertiajs/vue3";

    //Props
    const props = defineProps({
        width: Number,
        projectsReadyForBatching: Object,
        lettersProjectArray: Object,
        usage: Object,
        piecesGroupedBySupplierGroup: Object,
        currentSupplierGroup: String,
        redirect: String,
        batch: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const selectedSupplierGroup = ref(props.currentSupplierGroup);

    //A nest with no meterage in it (bolts only, say) has nothing to report here
    const meterageUsage = computed(() => props.usage?.METERAGE ?? {
        totalPurchasedMaterial: 0,
        totalUsedMaterial: 0,
        totalReusable: 0,
        totalScrap: 0,
        efficiency: 0,
    });


    /*
        Materials that fall outside every supplier group this business buys from. They are nested and
        ordered either way, so they are named rather than silently left off the screen.
     */
    const unassignedMaterials = computed(() => Object.values(props.piecesGroupedBySupplierGroup.unassigned ?? {}));

    //Shared Methods
    //...

    //Methods
    //Bundle and area materials store their purchasable options under a different key to meterage
    function purchasableOptions(item){
        return item.purchasableLengths ?? item.purchasable ?? [];
    }
</script>

<template>
    <div class="overflow-x-auto mx-auto" :style="'width:'+width+'px'">
        <!-- header -->
        <div class="grid grid-cols-10">
            <div class="col-span-2"></div>
            <div class="col-span-6">
                <h2 class="text-center font-bold text-lg">NESTING</h2>
            </div>
            <div class="col-span-2 pr-5 flex justify-end gap-x-2">
                <div v-if="batch">
                    <Link
                        type="button"
                        :href="route('batch.nesting',[batch.id,'current',1])"
                    >
                        Print friendly
                    </Link>
                </div>
                <div v-if="redirect">
                    <Link
                        type="button"
                        :href="redirect === 'current' ? route('dashboard') : route('past.projects.index')"
                    >
                        <i class="fa-solid fa-xmark"></i>
                    </Link>
                </div>
            </div>
        </div>
        <!-- body -->
        <div class="overflow-y-auto p-5">
            <!-- Pieces -->
            <section class="container mt-5">
                <div
                    v-if="projectsReadyForBatching.data.length > 0"
                    class="grid grid-cols-5 text-left"
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
                            <thead>
                                <tr>
                                    <th>Total Material</th>
                                    <th>Total Used Material</th>
                                    <th>Total Reusable</th>
                                    <th>Total Scrap</th>
                                    <th>Efficiency</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td>{{ (meterageUsage.totalPurchasedMaterial/1000).toLocaleString() }}m</td>
                                    <td>{{ (meterageUsage.totalUsedMaterial/1000).toLocaleString() }}m</td>
                                    <td>{{ (meterageUsage.totalReusable/1000).toLocaleString() }}m</td>
                                    <td>{{ (meterageUsage.totalScrap/1000).toLocaleString() }}m</td>
                                    <td>{{ meterageUsage.efficiency }}%</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div v-else>
                    There's no projects with materials ready to quote yet.
                </div>

                <!-- materials outside every supplier group -->
                <div
                    v-if="unassignedMaterials.length > 0"
                    class="mt-3 rounded border-2 border-orange-200 bg-orange-50 p-3 text-left text-orange-900"
                >
                    <h2 class="font-bold">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Not covered by any of your supplier groups:
                    </h2>
                    <ul class="text-sm">
                        <li v-for="item in unassignedMaterials">
                            - {{item.product_derived_label ?? item.product_category}}
                        </li>
                    </ul>
                    <p class="mt-1 text-xs">
                        These are nested but have no supplier to quote them, so they are not on any of the batches below.
                    </p>
                </div>

                <div class="mb-3 text-gray-600 mt-3">
                    <!-- buttons to toggle supplier groups. e.g "steel merchant" -->
                    <div
                        v-if="Object.keys(piecesGroupedBySupplierGroup.assigned).length > 1"
                        class="flex gap-x-3"
                    >
                        <button
                            v-for="(batchGroup,supplierGroupLabel) in piecesGroupedBySupplierGroup.assigned"
                            class="rounded px-2 py-1 text-green-900"
                            :class="supplierGroupLabel === selectedSupplierGroup ? 'bg-green-300 border-2 border-green-900' : 'bg-green-200'"
                            @click="selectedSupplierGroup = supplierGroupLabel"
                        >
                            <b>{{supplierGroupLabel}}</b> batch
                        </button>
                    </div>

                    <template v-for="(batchGroup,batchLabel) in piecesGroupedBySupplierGroup.assigned">
                        <div v-if="batchLabel === selectedSupplierGroup" class="pt-5">

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
                                            :list="purchasableOptions(item)"
                                        />
                                    </div>
                                    <!-- order list -->
                                    <div>
                                        <h2 class="font-bold">Order List</h2>
                                        <template v-for="bar in item.nested.orderList">
                                            <VisualOrderList
                                                :stockLength="bar.result"
                                                :measurementUnit="item.nominal_units"
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
                                                <h2 class="font-bold text-xl">Offcut usage</h2>
                                                <VisualNestingOffcuts
                                                    v-for="offcut in item.nested.bestResultOffcuts.utilisedOffcutBars"
                                                    :offcut="offcut"
                                                    :measurementUnit="item.nominal_units"
                                                    :batched="false"
                                                />
                                            </div>

                                            <template v-if="Object.values(item.nested.utilisedBars).length > 0">
                                                <h2 class="font-bold text-xl mt-5">New stock usage</h2>
                                                <!-- new stock nesting -->
                                                <VisualNestingWithBars
                                                    :utilisedBars="item.nested.utilisedBars"
                                                    :measurementUnit="item.nominal_units"
                                                    :batched="false"
                                                />
                                                <p
                                                    v-if="item.nested.tooLong.length > 0"
                                                    class="text-red-500 font-bold mt-2"
                                                >
                                                    Pieces too long: <span v-for="unfit in item.nested.tooLong">{{ parseFloat(unfit.length).toLocaleString()}} mm ({{unfit.letter}}), </span>
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
    </div>

</template>

<style scoped>

</style>
