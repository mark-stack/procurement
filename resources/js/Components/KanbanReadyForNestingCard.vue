<script setup>
    //General Imports
    import {Link, usePage} from "@inertiajs/vue3";
    import {computed, ref} from "vue";
    import moment from "moment/moment.js";

    //Component Imports
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonBlue from "@/Components/CardButtonBlue.vue";
    import CardButtonForward from "@/Components/CardButtonForward.vue";
    import CardButtonExpand from "@/Components/CardButtonExpand.vue";

    //Props
    const props = defineProps({
        projects: Object,
        usageStats: Number,
    });

    //Form
    //

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','quoteNow','orderNow','showBom','pageLoadingOn','showNesting']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);

    //Shared methods
    import shared from '@/Shared/shared';

    //Methods
    function toggleExpandProject(index){
        //Is same card
        if(expandProject.value === index){
            expandProject.value = null;
        }
        //Is different card
        else{
            expandProject.value = index;
        }
    }
</script>

<template>
    <!-- card -->
    <div
        :class="shared.atLeastOneProjectIsYours(projects,user.id) ? 'bg-white' : 'bg-gray-200'"
        class="relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group border-[1px] border-gray-300 shadow-lg"
    >

        <div
            v-if="shared.atLeastOneProjectIsYours(projects,user.id)"
            class="w-full mb-2 text-center"
        >
            <p
                v-if="usageStats && usageStats.METERAGE?.efficiency > 0"
                class="text-sm text-green-500"
            >
                <b>{{usageStats.METERAGE.efficiency}}%</b> efficiency
            </p>
            <p
                v-else
                class="text-sm text-green-500"
            >
                calculating efficiency...
            </p>
        </div>

        <div class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-900">
            <div
                v-for="(project,index) in projects"
                class="w-full border-gray-200 rounded-lg border-[1px] p-2 bg-gray-50"
            >
                <div class="grid grid-cols-6">
                    <h4 class="col-span-4 text-base font-medium">
                        {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
                    </h4>
                    <span class="col-span-2 text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,5)}}</span>
                </div>

                <div class="grid grid-cols-2 gap-x-1 w-full mt-3 text-xs font-medium text-gray-900">
                    <CardButtonExpand
                        label="Details/Edit"
                        :expandedIndex="expandProject"
                        :thisIndex="999"
                        @click="toggleExpandProject(index)"
                    />
                    <div class="text-right text-gray-800">
                        {{project.qtyMaterialRows}} pieces
                    </div>
                </div>
                <div
                    v-if="expandProject === index"
                    class="flex justify-between w-full mt-5 text-xs font-medium text-gray-900"
                >
                    <div class="flex items-center">
                        <table>
                            <tr>
                                <td colspan="2" class="text-xs text-gray-400">Target dates</td>
                            </tr>
                            <tr>
                                <td>Quote:</td>
                                <td><b>{{ moment(project.quotingDeadline).format("D MMM YY")}}</b></td>
                            </tr>
                            <tr>
                                <td>Order:</td>
                                <td><b>{{ moment(project.orderingDeadline).format("D MMM YY")}}</b></td>
                            </tr>
                            <tr>
                                <td>Delivery:</td>
                                <td><b>{{ moment(project.deliveryDeadline).format("D MMM YY")}}</b></td>
                            </tr>
                        </table>
                    </div>
                    <div v-if="shared.isYourProject(project,user.id)">
                        <!--                <CardButtonGreen-->
                        <!--                    @click="$emit('pageLoadingOn',null);$emit('showBom',[project,true])"-->
                        <!--                    :label="project.qtyMaterialRows"-->
                        <!--                    :highlight="false"-->
                        <!--                    :icon="true"-->
                        <!--                />-->
                        <CardButtonGreen
                            @click="$emit('pageLoadingOn',null);$emit('showBom',[project,true])"
                            :label="project.qtyMaterialRows + ' pieces'"
                            :highlight="false"
                            :icon="false"
                            class="mt-1"
                        />
                        <CardButtonYellow
                            @click="$emit('editMode',project)"
                            label="Edit"
                            class="mt-2"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- Nesting details -->
        <div
            v-if="shared.atLeastOneProjectIsYours(projects,user.id)"
            class="flex w-full justify-center mt-2"
        >
            <Link
                :href="route('suggested.nesting')"
                class="w-full"
                @click="loadingButton = 'NESTING_DETAILS'"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting details'"
                    :highlight="false"
                />
            </Link>
        </div>

        <!-- Actions -->
        <div
            v-if="shared.atLeastOneProjectIsYours(projects,user.id)"
            class="mt-3 w-full flex gap-x-2 justify-between items-center"
        >
            <CardButtonGreen
                @click="$emit('pageLoadingOn',null);$emit('quoteNow')"
                label="Start quoting/ordering"
                :highlight="true"
                :icon="false"
            />
        </div>
        <p
            v-if="shared.atLeastOneProjectIsYours(projects,user.id)"
            class="w-full mt-1 text-xs block text-center text-orange-300"
        >
            {{ shared.criticalPathDeadlineMessage(projects,true) }}
        </p>
    </div>


    <!-- card -->
<!--    <div class="border-2 border-blue-500 rounded-lg">-->
<!--        &lt;!&ndash; Body &ndash;&gt;-->
<!--        <div class="p-3">-->
<!--            <span class="text-sm block text-gray-500">Auto Batched Nesting</span>-->
<!--            <span class="text-sm block text-green-500">Saves 13% waste</span>-->
<!--            <span class="text-xs block text-orange-500">Suggest to wait [3 more days] to allow for more possible materials.</span>-->
<!--            <Link :href="route('suggested.nesting')" class="font-bold">Nesting details <i class="fa-solid fa-list"/></Link>-->

<!--            <div class="mt-3 grid grid-cols-1 gap-y-2">-->
<!--                &lt;!&ndash; project mini card &ndash;&gt;-->
<!--                <div v-for="project in projects" class="p-2 rounded-lg border-2 border-blue-200 bg-blue-50">-->
<!--                    <h3>{{ shared.cropText(project.name,16) }}</h3>-->
<!--                    <h6 class="text-xs text-gray-500">Quote/order by [4/2/24]</h6>-->
<!--                    <div class="text-xs">-->
<!--                        <div class="grid grid-cols-3 justify-between">-->
<!--&lt;!&ndash;                            <Link :href="route('products.index',project.id)">&ndash;&gt;-->
<!--&lt;!&ndash;                                BOM (page)&ndash;&gt;-->
<!--&lt;!&ndash;                            </Link>&ndash;&gt;-->
<!--                            <button @click="$emit('showBom',project)">-->
<!--                                BOM (modal)-->
<!--                            </button>-->
<!--                            <button-->
<!--                                @click="$emit('toggleArchive',projects[0])"-->
<!--                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"-->
<!--                            >-->
<!--                                Archive-->
<!--                            </button>-->
<!--                            <button-->
<!--                                v-if="isYourProject(project)"-->
<!--                                class="text-gray-500"-->
<!--                                @click="$emit('editMode',project)"-->
<!--                            >-->
<!--                                Edit-->
<!--                            </button>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->
<!--        &lt;!&ndash; Footer &ndash;&gt;-->
<!--        <div class="grid grid-cols-2 border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">-->
<!--            <button @click="$emit('quoteNow')">-->
<!--                Quote now-->
<!--            </button>-->
<!--            <button @click="$emit('orderNow')">-->
<!--                Order now-->
<!--            </button>-->
<!--        </div>-->
<!--    </div>-->
</template>
