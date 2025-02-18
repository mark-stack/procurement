<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed, ref} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";
    import CardButtonExpand from "@/Components/CardButtonExpand.vue";
    import CardButtonForward from "@/Components/CardButtonForward.vue";

    //Props
    const props = defineProps({
        info: Object,
        type: String,
    });

    //Form
    const formBreakBatch = useForm({});
    const formMarkAsPastProject = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);
    const expandBatchDetails = ref(null);

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Methods
    function breakBatch(){
        let url = route("batches.destroy",props.info.batch.id);
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
        return props.info.sentOrdersQty === props.info.totalOrdersQty;
    }

    function allDelivered(){
        return props.info.allDelivered;
    }

    function markAsPastProject(){
        let url = route("mark.as.past.project",props.info.batch.id);
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
</script>

<template>
    <!-- card -->
    <div
        :class="shared.atLeastOneProjectIsYours(info.projects.data,user.id) ? '' : 'pt-3'"
        class="bg-white relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group border-[1px] border-gray-300 shadow-lg"
    >
        <!-- quoting card -->
        <div
            v-if="type === 'QUOTES' && shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mb-2 text-center"
        >
            <p class="text-sm text-gray-700">
                Quoted: <b>{{ info.sentQuotesQty }}/{{ info.totalQuotesQty }}</b>
            </p>
        </div>

        <!-- ordering card -->
        <div
            v-if="type === 'ORDERS' && shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mb-2 text-center"
        >
            <p class="text-sm text-gray-700">
                Ordered: <b>{{ info.sentOrdersQty }}/{{ info.totalOrdersQty }}</b>
            </p>
            <p v-if="info.all_project_manager_approvals && type === 'ORDERS'" class="text-sm text-green-700">
                All project managers approved
            </p>
        </div>

        <!-- delivery card -->
        <div
            v-if="type === 'DELIVERED' && shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mb-2 text-center"
        >
            <p class="text-sm text-gray-700">
                Delivered: <b>{{ info.totalDeliveredQty }}/{{ info.totalOrdersQty }}</b>
            </p>
        </div>

<!--        <div class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-900">-->
<!--            <div-->
<!--                v-for="project in info.projects.data"-->
<!--                class="w-full border-gray-200 rounded-lg border-[1px] p-2 bg-gray-50"-->
<!--            >-->
<!--                <div class="grid grid-cols-6">-->
<!--                    <h4 class="col-span-4 text-base font-medium">-->
<!--                        {{ shared.cropText(shared.capitalizeWords(project.name),15) }}-->
<!--                    </h4>-->
<!--                    <span class="col-span-2 text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,5)}}</span>-->
<!--                </div>-->

<!--                <div-->
<!--                    v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"-->
<!--                    class="flex justify-between mt-2"-->
<!--                >-->
<!--                    <div  class="flex items-center">-->
<!--                        <div>-->
<!--                            <table>-->
<!--                                <tr>-->
<!--                                    <td colspan="2" class="text-xs text-gray-400">Target dates</td>-->
<!--                                </tr>-->
<!--                                <tr v-if="type === 'QUOTES' || type === 'ORDERS'">-->
<!--                                    <td>Quote:</td>-->
<!--                                    <td><b>{{ moment(project.quotingDeadline).format("D MMM YY")}}</b></td>-->
<!--                                </tr>-->
<!--                                <tr v-if="type === 'QUOTES' || type === 'ORDERS'">-->
<!--                                    <td>Order:</td>-->
<!--                                    <td><b>{{ moment(project.orderingDeadline).format("D MMM YY")}}</b></td>-->
<!--                                </tr>-->
<!--                                <tr>-->
<!--                                    <td>Delivery:</td>-->
<!--                                    <td><b>{{ moment(project.deliveryDeadline).format("D MMM YY")}}</b></td>-->
<!--                                </tr>-->
<!--                            </table>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                    <div-->
<!--                        v-if="shared.isYourProject(project,user.id)"-->
<!--                        class="flex items-center ml-4"-->
<!--                    >-->
<!--                        <CardButtonGreen-->
<!--                            @click="$emit('pageLoadingOn',null);$emit('showBom',[project,false])"-->
<!--                            :label="project.qtyMaterialRows"-->
<!--                            :highlight="false"-->
<!--                            :icon="true"-->
<!--                        />-->
<!--                    </div>-->
<!--                </div>-->
<!--                <div-->
<!--                    v-if="type === 'QUOTES' || type === 'ORDERS'"-->
<!--                    class="mt-2 flex justify-between"-->
<!--                >-->
<!--                    <p>-->
<!--                        Quoted: {{project.percentageOfMaterialsQuoted}}%-->
<!--                    </p>-->
<!--                    <p>-->
<!--                        Ordered: {{project.percentageOfMaterialsOrdered}}%-->
<!--                    </p>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->


        <div class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-900">
            <div
                v-for="(project,index) in  info.projects.data"
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
                        :thisIndex="index"
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

        <div class="flex justify-between gap-x-1 w-full mt-3 text-xs font-medium text-gray-900">
            <CardButtonExpand
                label="Details/Edit"
                :expandedIndex="expandBatchDetails"
                :thisIndex="999"
                @click="toggleExpandBatchDetails()"
            />
            <CardButtonForward
                v-if="type === 'QUOTES'"
                label="Orders"
                :href="route('quote.order.management',props.info.batch.id)"
            />
            <CardButtonForward
                v-if="type === 'ORDERS'"
                label="Orders"
                :href="route('quote.order.management',props.info.batch.id)"
            />
        </div>

        <div
            v-if="expandBatchDetails"
            class="flex w-full justify-center mt-2"
        >
            <Link
                :href="route('batch.nesting',[props.info.batch.id,'current'])"
                class="w-full"
                @click="loadingButton = 'NESTING_DETAILS'"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting details'"
                    :highlight="false"
                />
            </Link>
        </div>

        <div
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="mt-3 w-full flex gap-x-2 justify-between items-center"
        >
            <!-- Quote actions -->
            <CardButtonRed
                v-if="type === 'QUOTES'"
                @click="$emit('pageLoadingOn',3); breakBatch()"
                label="Re-nest"
            />

            <Link
                v-if="type === 'QUOTES'"
                :href="route('quote.order.management',props.info.batch.id)"
                class="w-full"
                @click="loadingButton = 'QUOTES'"
            >
                <CardButtonGreen
                    :label="loadingButton === 'QUOTES' ? 'Opening...' : 'Quotes'"
                    :highlight="true"
                    :icon="false"
                />
            </Link>

            <!-- order actions -->
            <Link
                v-if="type === 'ORDERS'"
                :href="route('quote.order.management',props.info.batch.id)"
                class="w-full"
                @click="loadingButton = 'ORDERS'"
            >
                <CardButtonGreen
                    :label="loadingButton === 'ORDERS' ? 'Opening...' : 'Orders'"
                    :highlight="true"
                    :icon="false"
                />
            </Link>

            <!-- delivery actions -->
            <Link
                v-if="type === 'DELIVERED'"
                :href="route('quote.order.management',props.info.batch.id)"
                class="w-full"
                @click="loadingButton = 'ORDERS'"
            >
                <CardButtonGreen
                    :label="loadingButton === 'ORDERS' ? 'Opening...' : 'Orders'"
                    :highlight="!allDelivered()"
                    :icon="false"
                />
            </Link>

            <p
                v-if="type === 'DELIVERED' && allDelivered()"
                class="w-full"
                @click="markAsPastProject()"
            >
                <CardButtonGreen
                    :label="formMarkAsPastProject.processing ? 'Moving...' : 'Move to done'"
                    :highlight="true"
                    :icon="false"
                />
            </p>
        </div>
        <p
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="w-full mt-2 text-xs block text-center text-orange-300"
        >
            <span v-if="type === 'QUOTES'">{{ shared.criticalPathDeadlineMessage(info.projects.data,false) }}</span>
            <span v-if="type === 'ORDERS'">{{ shared.criticalPathDeadlineMessage(info.projects.data,false)}}</span>
        </p>
    </div>
</template>
