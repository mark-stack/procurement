<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link, useForm} from "@inertiajs/vue3";

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
    const formCancelOrder = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode']);

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

    function cancelOrder(order){
        let url = route("orders.destroy",order.id);

        formCancelOrder.delete(url, {
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
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">Quote deadline: [1/2/24]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Steel merchant] quotes: [3]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Fasteners] quotes: [0]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Timber merchant] quotes: [1]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order deadline: [1/2/24]</span>

            <!-- Orders -->
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">All PM approval: {{otherData.allProjectManagerApprovals ? 'Yes' : 'No'}}</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order sent: {{otherData.orderSent ? 'Yes' : 'No'}}</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order confirmation: {{otherData.orderConfirmation ? 'Yes' : 'No'}}</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Supplier: {{otherData.supplier ? supplier.name : 'not yet'}}</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Purchase Order #: {{otherData.purchaseOrderNumber ? otherData.purchaseOrderNumber : 'not yet'}}</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Due approx: [1/2/24]</span>
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
                >
                    Add quote request
                </button>
                <button
                    v-if="type === 'QUOTES'"
                    @click="quoteToOrder()"
                >
                    Order
                </button>

                <!-- Order actions -->
                <button v-if="type === 'ORDERS' && !otherData.allProjectManagerApprovals">
                    All Project<br>Managers approved
                </button>
                <button
                    v-if="type === 'ORDERS' && !otherData.allProjectManagerApprovals"
                    @click="cancelOrder(otherData.order)"
                >
                    Cancel<br><small>(Back to quoting)</small>
                </button>
                <button v-if="type === 'ORDERS' && otherData.allProjectManagerApprovals && !otherData.orderSent && !otherData.orderConfirmation">
                    Is ordered (add PO)
                </button>
                <button v-if="type === 'ORDERS' && otherData.orderConfirmation && !otherData.isDelivered">
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
