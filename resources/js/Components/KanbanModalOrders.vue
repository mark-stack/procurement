<script setup>
//General Imports
import {ref} from "vue";
import {Link, useForm, usePage} from "@inertiajs/vue3";

//Component Imports
    //...

    //Props
    const props = defineProps({
        showModal: Boolean,
        allData: Object,
        modalSelectedBatchId: Number|null,
    });

    //Forms
    const formQuoteUpdate = useForm({
        order_sent: null,
    });
    const form = useForm({
        items: props.allQuoteData,
    });

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    const emit = defineEmits(['closeModal']);
    const clickCount = ref(0);

    //Shared Methods
    //...

    //Methods
    function onClickAway(event) {
        if(props.showModal){
            //This is to exclude initial button click
            clickCount.value = clickCount.value + 1;
            if(clickCount.value > 1){
                //Reset
                clickCount.value = 0;

                //Close modal
                emit('closeModal');
            }
        }
    }

    function sendSupplierBatchEmail(batchGroup) {
        // Email details
        const emailAddress = ""; //"example@example.com";
        const subject = "xxxxxxxxx"; //todo
        let materialList = ""; // Headers

        Object.values(batchGroup).forEach(item => {
            //Meterage
            if(item.algo === 'METERAGE'){
                // Build the material list

                let description = item.product_derived_label;

                item.nested.orderList.forEach(bar => {
                    let text = " - " + description + ": " + bar.count + " off " + parseFloat(bar.result).toLocaleString() + "mm"; // + item.nominal_units.toLowerCase();
                    materialList += text + "\n"; // Rows
                });
            }
            //Area
            if(item.algo === 'AREA'){
                //todo
            }
            //Bundle
            if(item.algo === 'BUNDLE'){
                //todo
            }
        });

        // Create the mailto link
        let row1 = "Hi, I'm seeking a quote for the following:";
        let row2 = materialList;
        let row3 = "Thank you.";

        const body = encodeURIComponent(`${row1}\n\n${row2}\n\n${row3}`);
        const mailtoLink = `mailto:${emailAddress}?subject=${encodeURIComponent(subject)}&body=${body}`;

        // Open the email client
        window.location.href = mailtoLink;
    }

    function quoteSentCheckbox(quote){
        let url = route("quotes.update",quote.id);

        formQuoteUpdate.quote_sent = quote.quote_sent === "0" ? false : true;
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
</script>

<template>

    <!-- Modal (https://codepen.io/npmhieu/pen/mdxaEbE?editors=1000) -->
    <div id="basicModal" v-show="showModal">
        <div
            x-show="open"
            class="relative z-10"
            aria-labelledby="modal-title"
            x-ref="dialog"
            aria-modal="true"
        >
            <div
                x-show="open"
                x-transition:enter="ease-out duration-300"
                x-transition:enter-start="opacity-0"
                x-transition:enter-end="opacity-100"
                x-transition:leave="ease-in duration-200"
                x-transition:leave-start="opacity-100"
                x-transition:leave-end="opacity-0"
                x-description="Background backdrop, show/hide based on modal state."
                class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"
            ></div>


            <div class="fixed z-10 inset-0 overflow-y-auto">
                <div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">
                    <div
                        v-click-away="onClickAway"
                        x-show="open"
                        x-transition:enter="ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave="ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                        x-description="Modal panel, show/hide based on modal state."
                        class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-3xl sm:w-full"
                    >
                        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">

                            <div class="p-5">
                                <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                                    Add orders
                                </h3>

                                <div class="mt-3">
                                    <div class="w-full grid grid-cols-1 gap-y-3">
                                        <div class="grid grid-cols-7">
                                            <div class="col-span-2 font-semibold">Supplier Category</div>
                                            <div class="col-span-1 font-semibold text-center">Quotes</div>
                                            <div class="col-span-1 font-semibold text-center">Preferred Supplier</div>
                                            <div class="col-span-1 font-semibold text-center">Email tables</div>
                                            <div class="col-span-1 font-semibold text-center">Sent Order?</div>
                                            <div class="col-span-1 font-semibold text-center">Coverage</div>
                                        </div>
                                        <div
                                            v-for="(data,supplierCategory) in allData[modalSelectedBatchId]?.modalData?.currentQuoteCoverage"
                                            class="grid grid-cols-7"
                                        >
                                            <div class="col-span-2">
                                                <h3>{{supplierCategory}}</h3>
                                                <p class="text-xs text-gray-600">{{data.includedProducts.string}}</p>
                                            </div>
                                            <div
                                                class="col-span-1 text-sm text-center"
                                                :class="data.qtyQuotes === 0 ? 'text-orange-600' : 'text-green-600'"
                                            >
                                                {{data.qtyQuotes}}
                                            </div>
                                            <div class="col-span-1 text-center">
                                                Preferred Supplier
                                            </div>
                                            <div class="col-span-1 text-center">
                                                Email tables
                                            </div>
                                            <div class="col-span-1 text-center">
                                                <input type="checkbox"/>
                                            </div>
                                            <div class="col-span-1 text-center">
                                                coverage
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                            <button
                                type="button"
                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"
                                @click="$emit('closeModal'); clickCount = 0;"
                            >
                                Done
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
