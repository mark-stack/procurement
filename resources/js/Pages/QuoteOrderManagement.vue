<script setup>
    //General Imports
    import {ref} from "vue";
    import {Link, useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        width: Number,
        quotesData: Object,
    });

    //Forms
    const formQuoteUpdate = useForm({
        batch_id: null,
        quote_sent: null,
        supplier_quote_reference: null,
        quoted_price: null,
        quoted_lead_time: null,
    });

    const formOrderUpdate = useForm({
        batch_id: null,
        order_id: null,
        supplier_group: null,
        ordered_quote_id: null,
        purchase_order_number: null,
    });

    const formUndoOrderSent = useForm({});

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    let showInputs = ref(setupShowInputs());
    const currentInputEditRow = ref(null);
    const height = window.innerHeight - 250;

    //Shared Methods
    import shared from '@/Shared/shared';
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";

    //Methods
    function setupShowInputs(){
        let resultArray = [];

        Object.values(props.quotesData.supplierGroupCards).forEach(data => {
            Object.values(data.rows).forEach(row => {
                resultArray[row.info.supplier.id] = {
                    quoted_price: false,
                    quoted_lead_time: false,
                    supplier_quote_reference: false,
                    add_purchase_order: false,
                };
            });
        });


        return resultArray;
    }

    function showQuotedLeadTime(supplierId){
        let showQuotedLeadTime = false;

        if(showInputs.value.hasOwnProperty(supplierId)){
            showQuotedLeadTime = showInputs.value[supplierId].quoted_lead_time;
        }

        return showQuotedLeadTime;
    }

    function showQuotedPrice(supplierId){
        let showQuotedPrice = false;

        if(showInputs.value.hasOwnProperty(supplierId)){
            showQuotedPrice = showInputs.value[supplierId].quoted_price;
        }

        return showQuotedPrice;
    }

    function showSupplierQuoteReference(supplierId){
        let showSupplierQuoteReference = false;

        if(showInputs.value.hasOwnProperty(supplierId)){
            showSupplierQuoteReference = showInputs.value[supplierId].supplier_quote_reference;
        }

        return showSupplierQuoteReference;
    }

    function showAddPurchaseOrder(supplierId){
        let showAddPurchaseOrder = false;

        if(showInputs.value.hasOwnProperty(supplierId)){
            showAddPurchaseOrder = showInputs.value[supplierId].add_purchase_order;
        }

        return showAddPurchaseOrder;
    }

    function toggleShowInput(row,type){
        //Save any currently open
        if(currentInputEditRow.value){
            console.log("has open input. Save it.",currentInputEditRow.value);
            updateQuote(currentInputEditRow.value,false);
        }

        //Set current row
        currentInputEditRow.value = row;

        //hide any currently open
        showInputs.value = setupShowInputs();

        //quoted_lead_time
        if(type === 'quoted_lead_time'){
            showInputs.value[row.info.supplier.id].quoted_lead_time = true;
        }

        //quotedPrice
        if(type === 'quoted_price'){
            showInputs.value[row.info.supplier.id].quoted_price = true;
        }

        //supplierQuoteReference
        if(type === 'supplier_quote_reference'){
            showInputs.value[row.info.supplier.id].supplier_quote_reference = true;
        }

        //add_purchase_order
        if(type === 'add_purchase_order'){
            showInputs.value[row.info.supplier.id].add_purchase_order = true;
        }
    }

    function hideInput(){
        //hide any currently open
        showInputs.value = setupShowInputs();

        //Clear current input selection
        currentInputEditRow.value = null;
        console.log("cancel. current row",currentInputEditRow.value);
    }

    function updateQuote(row,autoCloseAll){
        let url = route("quotes.update",row.formQuoteUpdate.quote_id);

        formQuoteUpdate.batch_id = row.formQuoteUpdate.batch_id;
        formQuoteUpdate.quote_sent = row.formQuoteUpdate.quote_sent === "0" ? false : true;
        formQuoteUpdate.supplier_quote_reference = row.formQuoteUpdate.supplier_quote_reference;
        formQuoteUpdate.quoted_price = row.formQuoteUpdate.quoted_price;
        formQuoteUpdate.quoted_lead_time = row.formQuoteUpdate.quoted_lead_time;

        formQuoteUpdate.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');

                //Close all inputs
                if(autoCloseAll){
                    console.log("autoCloseAll because there's NO open input");
                    showInputs.value = setupShowInputs();
                }

                //Clear current input selection
                currentInputEditRow.value = null;
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function updateOrder(row){
        let url = route("orders.update",row.formOrderUpdate.order_id);

        formOrderUpdate.purchase_order_number = row.formOrderUpdate.purchase_order_number;

        formOrderUpdate.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');

                //Close all inputs
                showInputs.value = setupShowInputs();

                //Clear current input selection
                currentInputEditRow.value = null;
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function quoteSentCheckbox(row){
        let url = route("quotes.update",row.formQuoteUpdate.quote_id);

        formQuoteUpdate.batch_id = row.formQuoteUpdate.batch_id;
        formQuoteUpdate.quote_sent = row.formQuoteUpdate.quote_sent === 0 ? false : true;
        formQuoteUpdate.supplier_quote_reference = row.formQuoteUpdate.supplier_quote_reference;
        formQuoteUpdate.quoted_price = row.formQuoteUpdate.quoted_price;
        formQuoteUpdate.quoted_lead_time = row.formQuoteUpdate.quoted_lead_time;

        formQuoteUpdate.put(url, {
            preserveScroll: true,
            onSuccess: (page) => {
                console.log('success',page);
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function orderSentCheckbox(row){
        let url = route("order.sent",row.formOrderUpdate.batch_id);

        formOrderUpdate.order_id = row.formOrderUpdate.order_id;
        formOrderUpdate.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function deliveredCheckbox(row){
        console.log("delivered checkbox");
    }

    function undoOrderSent(row){
        let url = route("order.undo.sent",row.formUndoOrderSent.order_id);

        formUndoOrderSent.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function projectManagersApprovalBeforeOrderSent(row) {
        // Show the confirmation dialog
        const isConfirmed = confirm(props.quotesData.info.projectManagerApprovalMessage);
        if (isConfirmed) {
            row.formOrderUpdate.ordered_quote_id = row.formQuoteUpdate.quote_id;

            orderSentCheckbox(row);
        }
        else{
            row.formOrderUpdate.ordered_quote_id = null;
        }
    }
</script>

<template>
    <AuthenticatedLayout>
        <Modal :fakeModal="true">
            <!-- header -->
            <div class="grid grid-cols-3 pt-2 pr-5 pb-2 pl-5">
                <div class="col-span-2">
                    <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                        Quotes / Orders
                    </h3>
                </div>
                <div class="text-right">
                    <p>
                        Quote coverage: <b>{{ quotesData.info.sentQuotesQty }}/{{ quotesData.info.totalQuotesQty }}</b>
                    </p>
                    <p>
                        Order coverage: <b>{{ quotesData.info.sentOrdersQty }}/{{ quotesData.info.totalOrdersQty }}</b>
                    </p>
                </div>
            </div>
            <!-- body -->
            <div :style="'width:'+width+'px; height:'+height+'px'" class="overflow-y-auto pt-2 pr-5 pb-5 pl-5">
                <div class="mt-3">
                    <div
                        v-for="(data,supplierGroup) in quotesData?.supplierGroupCards"
                        class="border-2 border-gray-200 rounded-lg p-3 mb-2"
                    >
                        <!-- header -->
                        <div class="grid grid-cols-2">
                            <div>
                                <h2 class="font-semibold">{{supplierGroup}}</h2>
                                <p class="text-sm text-gray-400">{{data.info.includedProducts.string}}</p>
                            </div>
                            <div class="text-right">
                                <span v-if="data.info.order" class="block text-green-500 font-semibold text-lg">ORDERED</span>
                                <span v-else class="block text-orange-500 font-semibold text-lg">NOT ORDERED</span>
                                <span v-if="data.info.purchaseOrderNumber" class="text-sm">PO: <span class="font-semibold">{{data.info.purchaseOrderNumber}}</span></span>
                            </div>
                        </div>

                        <!-- Main-->
                        <div class="mt-3">
                            <!-- heading row -->
                            <div class="grid grid-cols-10 text-xs text-gray-500 text-center mb-2 font-semibold">
                                <div class="col-span-2 text-left">
                                    Supplier
                                </div>
                                <div>
                                    Email tables
                                </div>
                                <div>
                                    Sent Quote
                                </div>
                                <div>
                                    Price
                                </div>
                                <div>
                                    Lead time (days)
                                </div>
                                <div class="col-span-2">
                                    Quote reference
                                </div>
                                <div>
                                    Sent order
                                </div>
                                <div>
                                    Delivered
                                </div>
                            </div>
                            <!-- rows -->
                            <div
                                v-for="row in data.rows"
                                :class="row.info.order_sent ? 'bg-green-50' : ''"
                                class="grid grid-cols-10 text-center mb-2 p-1 rounded"
                            >
                                <!-- supplier -->
                                <div class="col-span-2 text-left pt-1">
                                    {{ shared.cropText(row.info.supplier.name,20) }}
                                </div>
                                <!-- email button -->
                                <div>
                                    <button
                                        @click="shared.sendSupplierBatchEmail(data.info.batchGroup)"
                                        class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"
                                    >
                                        <i class="fa-regular fa-envelope text-2xl"></i>
                                    </button>
                                </div>
                                <!-- is quoted? -->
                                <div class="pt-1">
                                    <!-- formQuoteUpdate -->
                                    <input
                                        v-model="row.formQuoteUpdate.quote_sent"
                                        :true-value="1"
                                        :false-value="0"
                                        @change="quoteSentCheckbox(row)"
                                        type="checkbox"
                                    />
                                </div>
                                <!-- Price -->
                                <div class="pt-1">
                                    <!-- has quote details -->
                                    <div v-if="showQuotedPrice(row.info.supplier.id)">
                                        <!-- formQuoteUpdate -->
                                        <input
                                            v-model="row.formQuoteUpdate.quoted_price"
                                            type="number"
                                            class="w-full text-sm rounded"
                                            style="width:80px"
                                            min="1"
                                            max="99"
                                        />
                                        <div class="flex gap-x-1 justify-center">
                                            <template v-if="formQuoteUpdate.processing">
                                                <span class="text-xs text-green-500 font-bold">Saving...</span>
                                            </template>
                                            <template v-else>
                                                <button @click="updateQuote(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                            </template>
                                        </div>
                                    </div>

                                    <template v-else>
                                        <!-- formQuoteUpdate -->
                                        <button
                                            v-if="row.formQuoteUpdate.quoted_price"
                                            class="text-sm text-blue-500 underline italic"
                                            @click="toggleShowInput(row,'quoted_price')"
                                        >
                                            ${{ row.formQuoteUpdate.quoted_price.toFixed(2) }}
                                        </button>
                                        <button
                                            v-else
                                            class="text-xs text-blue-500 underline"
                                            @click="toggleShowInput(row,'quoted_price')"
                                        >
                                            Add
                                        </button>
                                    </template>
                                </div>

                                <!-- Lead time -->
                                <div class="pt-1">
                                    <div v-if="showQuotedLeadTime(row.info.supplier.id)">
                                        <!-- formQuoteUpdate -->
                                        <input
                                            v-model="row.formQuoteUpdate.quoted_lead_time"
                                            required
                                            type="number"
                                            class="w-full text-sm rounded"
                                            style="width:60px"
                                            min="1"
                                            max="99"
                                        />
                                        <div class="flex gap-x-1 justify-center">
                                            <template v-if="formQuoteUpdate.processing">
                                                <span class="text-xs text-green-500 font-bold">Saving...</span>
                                            </template>
                                            <template v-else>
                                                <button @click="updateQuote(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                            </template>
                                        </div>
                                    </div>

                                    <template v-else>
                                        <!-- formQuoteUpdate -->
                                        <button
                                            v-if="row.formQuoteUpdate.quoted_lead_time"
                                            class="text-sm text-blue-500 underline italic"
                                            @click="toggleShowInput(row,'quoted_lead_time')"
                                        >
                                            {{ row.formQuoteUpdate.quoted_lead_time }} days
                                        </button>
                                        <button
                                            v-else
                                            class="text-xs text-blue-500 underline"
                                            @click="toggleShowInput(row,'quoted_lead_time')"
                                        >
                                            Add
                                        </button>
                                    </template>
                                </div>
                                <!-- Quote reference -->
                                <div class="col-span-2 pt-2">
                                    <div class="italic text-sm">
                                        <!-- formQuoteUpdate -->
                                        <div v-if="showSupplierQuoteReference(row.info.supplier.id)">
                                            <input
                                                v-model="row.formQuoteUpdate.supplier_quote_reference"
                                                required
                                                type="text"
                                                class="w-full text-sm rounded"
                                                style="width:90px"
                                                minlength="1"
                                            />
                                            <div class="flex gap-x-1 justify-center">
                                                <template v-if="formQuoteUpdate.processing">
                                                    <span class="text-xs text-green-500 font-bold">Saving...</span>
                                                </template>
                                                <template v-else>
                                                    <button @click="updateQuote(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                    <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                                </template>
                                            </div>
                                        </div>

                                        <!-- formQuoteUpdate -->
                                        <template v-else>
                                            <button
                                                v-if="row.formQuoteUpdate.supplier_quote_reference"
                                                class="text-sm text-blue-500 underline italic"
                                                @click="toggleShowInput(row,'supplier_quote_reference')"
                                            >
                                                {{ shared.cropText(row.formQuoteUpdate.supplier_quote_reference,8) }}
                                            </button>
                                            <button
                                                v-else
                                                class="text-xs text-blue-500 underline"
                                                @click="toggleShowInput(row,'supplier_quote_reference')"
                                            >
                                                Add
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <!-- Sent order -->
                                <div class="pt-1">
                                    <!-- formOrderUpdate -->
                                    <template v-if="!showAddPurchaseOrder(row.info.supplier.id)">
                                        <input
                                            v-if="row.info.order_sent"
                                            @click="undoOrderSent(row)"
                                            type="checkbox"
                                            checked
                                        />
                                        <input
                                            v-else
                                            @click.prevent="projectManagersApprovalBeforeOrderSent(row)"
                                            type="checkbox"
                                        />
                                    </template>

                                    <button
                                        v-if="row.info.order_sent && !showAddPurchaseOrder(row.info.supplier.id)"
                                        @click="toggleShowInput(row,'add_purchase_order')"
                                        class="text-xs underline text-blue-500"
                                    >
                                        {{row.formOrderUpdate.purchase_order_number ? 'Edit PO number' :'Add PO number'}}
                                    </button>

                                    <div v-if="showAddPurchaseOrder(row.info.supplier.id)">
                                        <input
                                            v-model="row.formOrderUpdate.purchase_order_number"
                                            required
                                            type="text"
                                            class="w-full text-sm rounded"
                                            style="width:90px"
                                            minlength="1"
                                        />
                                        <div class="flex gap-x-1 justify-center">
                                            <template v-if="formOrderUpdate.processing">
                                                <span class="text-xs text-green-500 font-bold">Saving...</span>
                                            </template>
                                            <template v-else>
                                                <button @click="updateOrder(row)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                            </template>
                                        </div>
                                    </div>
                                </div>
                                <!-- delivered -->
                                <div>
                                    <input
                                        v-model="data.info.delivered"
                                        :true-value="1"
                                        :false-value="0"
                                        @change="deliveredCheckbox(row)"
                                        type="checkbox"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <!-- add suppliers -->
                    <div class="pl-2">
                        <Link
                            :href="route('suppliers.index',business.id)"
                            class="underline text-blue-500"
                        >
                            Add/Edit suppliers
                        </Link>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>
</template>
