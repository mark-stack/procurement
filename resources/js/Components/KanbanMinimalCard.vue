<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed, ref} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/Buttons/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/Buttons/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import CardButtonExpand from "@/Components/Buttons/CardButtonExpand.vue";
    import CardButtonForward from "@/Components/Buttons/CardButtonForward.vue";

    //Props
    const props = defineProps({
        projects: Object,
        usageStats: Object,
        prerequisiteStartQuoting: Object,
        info: Object,
        kanbanColumn: String,
    });

    //Form
    const formBreakBatch = useForm({});
    const formMarkAsPastProject = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting','addProject']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);
    const expandBatchDetails = ref(null);

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Methods
    function breakBatch(){
        let url = route("batches.destroy",props.info?.batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success after re-nest');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function allOrdersSent(){
        return props.info?.sentOrdersQty === props.info?.totalOrdersQty;
    }

    function allDelivered(){
        return props.info?.allDelivered;
    }

    function markAsPastProject(){
        let url = route("mark.as.past.project",props.info?.batch.id);
        formMarkAsPastProject.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

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
    function toggleExpandBatchDetails(){
        if(expandBatchDetails.value === 999){
            expandBatchDetails.value = null;
        }
        else{
            expandBatchDetails.value = 999;
        }
    }

    function atLeastOneProjectIsYours(){
        return props.info
            ? shared.atLeastOneProjectIsYours(props.info.projects.data,user.id)
            : true;
    }
</script>

<template>
    <!-- Simple card -->
    <div
        class="bg-white relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group border-[1px] border-gray-300 shadow-lg"
    >
        <div
            v-if="atLeastOneProjectIsYours"
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
                Calculating efficiency...
            </p>
        </div>

        <div
            v-for="(project,index) in projects"
            class="w-full border-gray-200 rounded-lg border-[1px] p-2 bg-gray-50"
        >
            <h4 class="col-span-4 text-base font-medium">
                {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
            </h4>

            <div class="flex gap-x-1 w-full mt-3 text-xs font-medium text-gray-900">
                <CardButtonGreen
                    @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                    :label="project.qtyMaterialRows + ' pieces'"
                    :highlight="false"
                    :icon="false"
                />
                <CardButtonRed
                    @click="$emit('toggleArchive',project)"
                    label="Archive"
                    :fullWidth="false"
                    :disabled="true"
                />
                <CardButtonYellow
                    @click="$emit('editMode',project)"
                    label="Edit"
                    :fullWidth="false"
                />
            </div>
            <div
                v-if="expandProject === index"
                class="flex justify-between w-full mt-5 text-xs font-medium text-gray-900"
            >
                <div class="">
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
                    <p class="mt-2">
                        Quoted: {{project.percentageOfMaterialsQuoted}}%
                    </p>
                    <p>
                        Ordered: {{project.percentageOfMaterialsOrdered}}%
                    </p>
                </div>
                <div v-if="shared.isYourProject(project,user.id)">
                    <CardButtonGreen
                        @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                        :label="project.qtyMaterialRows + ' pieces'"
                        :highlight="false"
                        :icon="false"
                        class="mt-1"
                    />
                    <CardButtonYellow
                        @click="$emit('editMode',project)"
                        label="Edit"
                        class="mt-2"
                        :fullWidth="true"
                    />
                </div>
            </div>
        </div>
        <div class="mt-3">
            <p
                class="text-blue-700 font-semibold text-sm"
                @click="$emit('addProject')"
                style="cursor: pointer;"
            >
                + add project
            </p>
        </div>
        <div class="w-full grid grid-cols-5 gap-x-2 border-t-[1px] border-gray-200 mt-2 pt-3 pb-3">
            <Link
                v-if="kanbanColumn === 'NESTING'"
                :href="route('suggested.nesting')"
                class="w-full col-span-2"
                @click="loadingButton = 'NESTING_DETAILS'"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>

            <Link
                v-if="kanbanColumn === 'QUOTING'"
                :href="route('batch.nesting',[info.batch.id,'current'])"
                @click="loadingButton = 'NESTING_DETAILS'"
                class="w-full col-span-2"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>

            <!-- email buttons (should be just one for steel merchant) -->
            <template v-for="supplierGroup in info?.quotesData?.supplierGroupCards">
                <button
                    @click="shared.sendSupplierBatchEmail(supplierGroup.info.batchGroup)"
                    class="col-span-3 bg-green-50 hover:bg-green-100 border-[1px] border-green-200 w-full text-center pt-1 h-6 px-2 text-xs font-semibold text-green-400 hover:text-green-500 rounded-full"
                    style="cursor: pointer;padding-top: 2px;"
                >
                    <i class="fa-regular fa-envelope text-sm pr-1"></i>
                    <span class="text-xs">Email Template</span>
                </button>
            </template>
        </div>

        <div
            v-if="info?.batch.id"
            class="w-full grid grid-cols-4 gap-x-2 border-t-[1px] border-gray-200 pt-3 pb-3 text-center"
        >
            <div>
                <label
                    :for="info.batch.id + '-quoted'"
                    class="text-xs text-gray-700"
                >
                    Quoted
                </label>
                <input
                    :id="info.batch.id + '-quoted'"
                    type="checkbox"
                />
            </div>
            <div>
                <label
                    :for="info.batch.id + '-ordered'"
                    class="text-xs text-gray-700"
                >
                    Ordered
                </label>
                <input
                    :id="info.batch.id + '-ordered'"
                    type="checkbox"
                />
            </div>
            <div>
                <label
                    :for="info.batch.id + '-delivered'"
                    class="text-xs text-gray-700"
                >
                    Delivered
                </label>
                <input
                    :id="info.batch.id + '-delivered'"
                    type="checkbox"
                />
            </div>
            <div>
                <label
                    :for="info.batch.id + '-done'"
                    class="text-xs text-gray-700"
                >
                    Done
                </label>
                <input
                    :id="info.batch.id + '-done'"
                    type="checkbox"
                />
            </div>

            <div class="relative inline-block">
                <!-- Info Icon -->
                <div class="w-6 h-6 flex items-center justify-center rounded-full border border-gray-400 text-gray-600 text-sm cursor-pointer relative hover:bg-gray-200">
                    X<i class="fas fa-info"></i>

                    <!-- Tooltip -->
                    <div class="absolute left-1/2 -translate-x-1/2 mt-2 w-40 bg-gray-800 text-white text-xs rounded-md p-2 opacity-0 invisible hover:opacity-100 hover:visible transition-opacity">
                        This is a tooltip message.
                    </div>
                </div>
            </div>

        </div>
    </div>
</template>
