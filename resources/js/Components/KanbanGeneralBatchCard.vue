<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed, ref} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/CardButtonBlue.vue";

    //Props
    const props = defineProps({
        info: Object,
        type: String,
    });

    //Form
    const formBreakBatch = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);

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
</script>

<template>
    <!-- card -->
    <div
        :class="shared.atLeastOneProjectIsYours(info.projects.data,user.id) ? 'bg-white' : 'bg-gray-200'"
        class="relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group"
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

        <div
            :class="shared.atLeastOneProjectIsYours(info.projects.data,user.id) ? '' : 'mt-2'"
            class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-500"
        >
            <div
                v-for="project in info.projects.data"
                :class="shared.isYourProject(project,user.id) ? 'border-green-100' : 'border-gray-300'"
                class="w-full rounded-lg border-2 p-2"
            >
                <div class="grid grid-cols-6">
                    <h4 class="col-span-4 text-base font-medium">
                        {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
                    </h4>
                    <span class="col-span-2 text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.isYourProject(project,user.id) ? 'Yours' : shared.cropText(project.projectManager.name,5)}}</span>
                </div>

                <div
                    v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
                    class="flex justify-between mt-2"
                >
                    <div  class="flex items-center">
                        <div>
                            <table>
                                <tr>
                                    <td colspan="2" class="text-xs text-gray-400">Target dates</td>
                                </tr>
                                <tr v-if="type === 'QUOTES' || type === 'ORDERS'">
                                    <td>Quote:</td>
                                    <td><b>{{ moment(project.quotingDeadline).format("D MMM YY")}}</b></td>
                                </tr>
                                <tr v-if="type === 'QUOTES' || type === 'ORDERS'">
                                    <td>Order:</td>
                                    <td><b>{{ moment(project.orderingDeadline).format("D MMM YY")}}</b></td>
                                </tr>
                                <tr>
                                    <td>Delivery:</td>
                                    <td><b>{{ moment(project.deliveryDeadline).format("D MMM YY")}}</b></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    <div
                        v-if="shared.isYourProject(project,user.id)"
                        class="flex items-center ml-4"
                    >
                        <CardButtonGreen
                            @click="$emit('pageLoadingOn',null);$emit('showBom',[project,false])"
                            :label="project.qtyMaterialRows"
                            :highlight="false"
                            :icon="true"
                        />
                    </div>
                </div>
                <div
                    v-if="type === 'QUOTES' || type === 'ORDERS'"
                    class="mt-2 flex justify-between"
                >
                    <p>
                        Quoted: {{project.percentageOfMaterialsQuoted}}%
                    </p>
                    <p>
                        Ordered: {{project.percentageOfMaterialsOrdered}}%
                    </p>
                </div>
            </div>
        </div>

        <div
            v-if="shared.atLeastOneProjectIsYours(info.projects.data,user.id)"
            class="flex w-full justify-center mt-2"
        >
            <Link
                :href="route('batch.nesting',props.info.batch.id)"
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
                v-if="type === 'ORDERS' || type === 'DELIVERED'"
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
