<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link, useForm} from "@inertiajs/vue3";
    import moment from "moment/moment.js";

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
