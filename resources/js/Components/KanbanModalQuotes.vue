<script setup>
    //General Imports
    import {ref, toRefs, watch} from "vue";
    import {Link, useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        width: Number,
        allData: Object,
        modalSelectedBatchId: Number|null,
        refreshModalQuotes: Boolean,
        quotesData: Object,
    });

    //Forms
    const formQuoteUpdate = useForm({
        quote_sent: null,
        supplier_quote_reference: null,
        quoted_price: null,
        quoted_lead_time: null,
    });

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    let showInputs = ref(null);
    const currentInputEditRow = ref(null);

    //Shared Methods
    import shared from '@/Shared/shared';

    //Methods
    function setupShowInputs(){
        let resultArray = [];

        Object.values(props.quotesData[0]?.data.quotesAndOrders).forEach(data => {
            Object.values(data.rows).forEach(row => {
                resultArray[row.supplier.id] = {
                    quoted_price: false,
                    quoted_lead_time: false,
                    supplier_quote_reference: false,
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

    function toggleShowInput(row,type){
        //Save any currently open
        if(currentInputEditRow.value){
            console.log("has open input. Save it.",currentInputEditRow.value);
            saveInput(currentInputEditRow.value,false);
        }

        //Set current row
        currentInputEditRow.value = row;
        console.log("currentInputEditRow",currentInputEditRow.value);

        //hide any currently open
        showInputs.value = setupShowInputs();

        //quoted_lead_time
        if(type === 'quoted_lead_time'){
            showInputs.value[row.supplier.id].quoted_lead_time = true;
        }

        //quotedPrice
        if(type === 'quoted_price'){
            showInputs.value[row.supplier.id].quoted_price = true;
        }

        //supplierQuoteReference
        if(type === 'supplier_quote_reference'){
            showInputs.value[row.supplier.id].supplier_quote_reference = true;
        }
    }

    function hideInput(){
        //hide any currently open
        showInputs.value = setupShowInputs();

        //Clear current input selection
        currentInputEditRow.value = null;
        console.log("cancel. current row",currentInputEditRow.value);
    }

    function saveInput(row,autoCloseAll){
        let url = route("quotes.update",row.quote.id);

        formQuoteUpdate.quote_sent = row.quote.quote_sent === "0" ? false : true;
        formQuoteUpdate.supplier_quote_reference = row.quote.supplier_quote_reference;
        formQuoteUpdate.quoted_price = row.quote.quoted_price;
        formQuoteUpdate.quoted_lead_time = row.quote.quoted_lead_time;

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
                console.log("saved completed. current row",currentInputEditRow.value);
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function quoteSentCheckbox(row){
        let url = route("quotes.update",row.quote.id);

        formQuoteUpdate.quote_sent = row.quote.quote_sent === "0" ? false : true;
        formQuoteUpdate.supplier_quote_reference = row.row.quote.supplier_quote_reference;
        formQuoteUpdate.quoted_price = row.quote.quoted_price;
        formQuoteUpdate.quoted_lead_time = row.quote.quoted_lead_time;

        formQuoteUpdate.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function orderSentCheckbox(row){
        console.log("orderSentCheckbox: quote id = ",row.isOrdered); //todo
    }

    function isLoaded(){
        return Object.values(props.quotesData).length === 0
    }

    //Watcher
    const { refreshModalQuotes } = toRefs(props);
    watch(refreshModalQuotes, (newVal) => {
        console.log('refreshModalQuotes changed:', newVal);

        //Initialise 'show inputs'
        showInputs.value = setupShowInputs();
    });
</script>

<template>
    <Modal>
        <div :style="'width:'+width+'px'">
            <div class="p-5">
                <div class="grid grid-cols-3">
                    <div class="col-span-2">
                        <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                            Quotes / Orders
                        </h3>
                    </div>
                    <div class="text-right">
                        <Link
                            :href="route('suppliers.index',business.id)"
                            class="underline text-blue-500"
                        >
                            Add/Edit suppliers
                        </Link>
                    </div>
                </div>

                <!-- Loading -->
                <div
                    v-if="isLoaded()"
                    class="p-20 text-gray-700 italic"
                >
                    <span class="block font-bold text-xl">Loading...</span>
                </div>

                <div v-else class="mt-3">

                    <div
                        v-for="(data,supplierGroup) in quotesData[0]?.data.quotesAndOrders"
                        class="border-2 border-gray-200 rounded-lg p-3 mb-2"
                    >
                        <!-- header -->
                        <div class="grid grid-cols-2">
                            <div>
                                <h2 class="font-semibold">{{supplierGroup}}</h2>
                                <p class="text-sm text-gray-400">{{data.categoryLevel.includedProducts.string}}</p>
                            </div>
                            <div class="text-right">
                                PO: <span class="font-semibold">{{data.categoryLevel.purchaseOrderNumber}}</span>
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
                                class="grid grid-cols-10 text-center mb-2"
                            >
                                <!-- supplier -->
                                <div class="col-span-2 text-left">
                                    {{ shared.cropText(row.supplier.name,20) }}
                                </div>
                                <!-- email button -->
                                <div>
                                    <button
                                        @click="shared.sendSupplierBatchEmail(data.categoryLevel.batchGroup)"
                                        class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"
                                    >
                                        <i class="fa-regular fa-envelope text-2xl"></i>
                                    </button>
                                </div>
                                <!-- is quoted -->
                                <div>
                                    <input
                                        v-model="row.quote.quote_sent"
                                        true-value="1"
                                        false-value="0"
                                        @change="quoteSentCheckbox(row)"
                                        type="checkbox"
                                    />
                                </div>
                                <!-- Price -->
                                <div>
                                    <!-- has quote details -->
                                    <div v-if="showQuotedPrice(row.supplier.id)">
                                        <input
                                            v-model="row.quote.quoted_price"
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
                                                <button @click="saveInput(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                            </template>
                                        </div>
                                    </div>

                                    <template v-else>
                                        <button
                                            v-if="row.quote.quoted_price"
                                            class="text-sm text-blue-500 underline italic"
                                            @click="toggleShowInput(row,'quoted_price')"
                                        >
                                            ${{ row.quote.quoted_price.toFixed(2) }}
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
                                <div>
                                    <div v-if="showQuotedLeadTime(row.supplier.id)">
                                        <input
                                            v-model="row.quote.quoted_lead_time"
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
                                                <button @click="saveInput(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                            </template>
                                        </div>
                                    </div>

                                    <template v-else>
                                        <button
                                            v-if="row.quote.quoted_lead_time"
                                            class="text-sm text-blue-500 underline italic"
                                            @click="toggleShowInput(row,'quoted_lead_time')"
                                        >
                                            {{ row.quote.quoted_lead_time }} days
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
                                <div class="col-span-2">
                                    <div class="italic text-sm">
                                        <div v-if="showSupplierQuoteReference(row.supplier.id)">
                                            <input
                                                v-model="row.quote.supplier_quote_reference"
                                                required
                                                type="text"
                                                class="w-full text-sm rounded"
                                                style="width:60px"
                                                minlength="1"
                                            />
                                            <div class="flex gap-x-1 justify-center">
                                                <template v-if="formQuoteUpdate.processing">
                                                    <span class="text-xs text-green-500 font-bold">Saving...</span>
                                                </template>
                                                <template v-else>
                                                    <button @click="saveInput(row,true)" class="text-xs underline text-green-500 font-bold">Save</button>
                                                    <button @click="hideInput()" class="text-xs underline">Cancel</button>
                                                </template>
                                            </div>
                                        </div>

                                        <template v-else>
                                            <button
                                                v-if="row.quote.supplier_quote_reference"
                                                class="text-sm text-blue-500 underline italic"
                                                @click="toggleShowInput(row,'supplier_quote_reference')"
                                            >
                                                {{ shared.cropText(row.quote.supplier_quote_reference,8) }}
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
                                <div>
                                    <input
                                        v-model="row.isOrdered"
                                        type="radio"
                                        name="isOrdered"
                                        :style="row.isOrdered === row.quote.id ? '' : 'bg-red-500'"
                                        :value="row.quote.id"
                                        @change="orderSentCheckbox(row)"
                                    />
                                    [{{row.isOrdered}}]
                                </div>
                                <!-- delivered -->
                                <div>
                                    <input
                                        v-model="data.categoryLevel.delivered"
                                        v-if="row.isOrdered"
                                        true-value="1"
                                        false-value="0"
                                        @change="quoteSentCheckbox(row.quote)"
                                        type="checkbox"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
