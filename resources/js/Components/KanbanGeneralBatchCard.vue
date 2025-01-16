<script setup>
    //General Imports
    import {Link, useForm} from "@inertiajs/vue3";
    import moment from "moment/moment.js";

    //Component Imports
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";

    //Props
    const props = defineProps({
        batch: Object,
        projects: Object,
        type: String,
        otherData: Object,
    });

    //Form
    const formBreakBatch = useForm({});
    const formOrdersStore = useForm({
        batch_id: props.batch.id,
    });
    const formCancelBatchOrders = useForm({
        orders: Object,
    });
    const formApproveAllProjectManagers = useForm({});
    const formMarkAsOrdered = useForm({});
    const formMarkOrderConfirmationReceived = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','showQuotesModal','showOrdersModal']);

    //Shared methods
    import shared from "@/Shared/shared.js";
    import CardButtonRed from "@/Components/CardButtonRed.vue";

    //Methods
    function cropText(text, maxLength = 5) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }

    function breakBatch(batch){
        let url = route("batches.destroy",batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function quoteToOrder(){
        let url = route("orders.store");

        formOrdersStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function cancelBatchOrders(orders,batch){
        let url = route("cancel.batch.orders",batch.id);

        formCancelBatchOrders.orders = orders;
        formCancelBatchOrders.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function approveAllProjectManagers(batch){
        let url = route("approve.all.project.managers",batch.id);

        formApproveAllProjectManagers.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function markAsOrdered(order){
        let url = route("mark.as.ordered",order.id);

        formMarkAsOrdered.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function markOrderConfirmationReceived(order){
        let url = route("mark.order.confirmation.received",order.id);

        formMarkOrderConfirmationReceived.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }
</script>

<template>
    <!-- card -->
    <div class="relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 bg-white rounded-lg cursor-pointer bg-opacity-90 group hover:bg-opacity-100" draggable="true">
        <div class="w-full mb-2 text-center">
            <p class="text-sm text-green-500">Saves <b>[13%]</b> waste</p>
        </div>

        <div class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-500">
            <div
                v-for="project in projects"
                class="w-full rounded-lg border-2 border-gray-300 p-2"
            >
                <h4 class="text-base font-medium">
                    {{ shared.cropText(shared.capitalizeWords(project.name)) }}
                </h4>
                <div class="flex">
                    <div class="flex items-center">
                        <i class="fa-regular fa-calendar-days text-base"></i>
                        <div>
                            <span class="ml-1 text-xs">Quote by:</span>
                            <span class="block ml-1 leading-none text-xs">{{ moment(project.quoteRequestDeadline).format("DD-MM-YYYY")}}</span>
                        </div>
                    </div>
                    <div class="flex items-center ml-4">
                        <i class="fa-solid fa-file-lines text-base"></i>
                        <span class="ml-1 leading-none text-sm">[9]</span>
                    </div>
                    <div class="flex items-center ml-4">
                        <i class="fa-solid fa-user text-base"></i>
                        <span class="ml-1 leading-none text-sm">{{project.projectManager.name}}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex w-full justify-center mt-2">
            <CardButtonGreen
                label="Nesting details"
                :highlight="false"
            />
        </div>

        <div class="mt-3 w-full flex gap-x-2 justify-between items-center">
            <CardButtonRed
                v-if="type === 'QUOTES'"
                @click="breakBatch(batch)"
                label="Re-nest"
            />
            <CardButtonYellow
                @click="$emit('orderNow')"
                label="Order now"
            />
            <CardButtonGreen
                @click="$emit('showQuotesModal',batch.id)"
                label="Manage Quotes"
                :highlight="true"
            />
        </div>
        <p class="mt-1 text-xs block text-center text-orange-300">Suggest to wait [3 more days] to allow for more possible materials.</p>

    </div>

    <!-- card -->
    <div class="border-2 border-blue-500 rounded-lg">
        <!-- Body -->
        <div class="p-3">
            <span class="text-sm block text-gray-500">Batch ID: {{batch.id}}</span>
            <!-- Quotes -->
            <p v-if="type === 'QUOTES'" class="text-sm block text-gray-800">Quote deadline: {{ moment(otherData.batchQuotingDeadline).fromNow() }}</p>
            <p v-if="type === 'QUOTES'" class="text-sm block text-gray-800">Quote coverage: {{ otherData.sentQuotesQty }}/{{ otherData.totalQuotesQty }}</p>

            <!-- Orders -->
            <p v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order deadline: [1/2/24]</p>
            <p
                v-if="type === 'ORDERS'"
                :class="otherData.all_project_manager_approvals ? 'text-green-600' : 'text-orange-800'"
                class="text-sm block "
            >
                All PM approval: {{otherData.all_project_manager_approvals ? 'Yes' : 'No'}}
            </p>
            <p
                v-if="type === 'ORDERS'"
                class="text-sm block text-gray-800"
            >
                Order coverage: {{ otherData.sentOrdersQty }}/{{ otherData.totalOrdersQty }}
            </p>
            <p
                v-if="type === 'ORDERS'"
                class="text-sm block text-gray-800"
            >
                Delivery approx: [1/2/24]
            </p>
            <Link :href="route('batch.nesting',batch.id)" class="font-bold">Nesting details <i class="fa-solid fa-list"/></Link>

            <span v-for="project in projects" class="block">{{ cropText(project.name) }}</span>
        </div>
        <!-- Footer -->
        <div class="border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">
            <!-- actions -->
            <div class="flex justify-center items-center gap-x-3 mt-1">
                <!-- Quote actions -->
                <button
                    v-if="type === 'QUOTES'"
                    @click="breakBatch(batch)"
                    class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                >
                    Break Batch (re-nest)
                </button>
                <button
                    v-if="type === 'QUOTES'"
                    @click="$emit('showQuotesModal',batch.id)"
                >
                    Manage Quotes
                </button>
                <button
                    v-if="type === 'QUOTES'"
                    @click="quoteToOrder()"
                >
                    Start Ordering
                </button>

                <!-- Order actions -->
                <button
                    v-if="type === 'ORDERS' && !otherData.all_project_manager_approvals"
                    @click="approveAllProjectManagers(batch)"
                >
                    All Project<br>Managers approved
                </button>
                <button
                    v-if="type === 'ORDERS' && !otherData.all_project_manager_approvals"
                    @click="cancelBatchOrders(otherData.orders,batch)"
                >
                    Cancel<br><small>(Back to quoting)</small>
                </button>
                <button
                    v-if="type === 'ORDERS' && otherData.all_project_manager_approvals"
                    @click="$emit('showOrdersModal',batch.id)"
                >
                    Manage Orders
                </button>
<!--                <button-->
<!--                    v-if="type === 'ORDERS' && otherData.order.order_sent && !otherData.order.order_confirmation_received"-->
<!--                    @click="markOrderConfirmationReceived(otherData.order)"-->
<!--                >-->
<!--                    Received order confirmation-->
<!--                </button>-->
                <button v-if="type === 'ORDERS' && otherData.orders.order_confirmation_received && !otherData.order.is_delivered">
                    Is Delivered
                </button>

                <!-- Delivered actions -->
                <button v-if="type === 'DELIVERED'">
                    Not Delivered
                </button>
                <button v-if="type === 'DELIVERED'">
                    Done (Archive)
                </button>
            </div>
        </div>
    </div>
</template>
