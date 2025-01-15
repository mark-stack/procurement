<script setup>
    //General Imports
    import {ref, toRefs, watch} from "vue";
    import {Link, useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    //...

    //Props
    const props = defineProps({
        showModal: Boolean,
        allData: Object,
        modalSelectedBatchId: Number|null,
        signal: Boolean,
    });

    //Forms
    const formQuoteUpdate = useForm({
        quote_sent: null,
    });
    let form = useForm({
        items: props.allData,
    });

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    const emit = defineEmits(['closeModal']);
    const clickCount = ref(0);

    //Shared Methods
    import shared from '@/Shared/shared';

    //Watcher
    const { signal } = toRefs(props);
    watch(signal, (newVal) => {
        console.log('Signal changed:', newVal);
        form = useForm({
            items: props.allData,
        });
    });

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
                            <div class="grid grid-cols-2">
                                <!-- left-->
                                <div class="p-5 border-r-2 border-gray-300">
                                    <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                                        Add quote requests
                                    </h3>

                                    <div class="mt-3">
                                        <div class="w-full grid grid-cols-1 gap-y-3">
                                            <div class="grid grid-cols-3">
                                                <div class="font-semibold">Supplier</div>
                                                <div class="font-semibold text-center">Email Tables</div>
                                                <div class="font-semibold text-center">Sent RFQ?</div>
                                            </div>
                                            <form
                                                v-for="row in form.items[modalSelectedBatchId]?.modalData?.addQuoteRequests"
                                                class="grid grid-cols-3"
                                            >
                                                <div>
                                                    {{row.supplierName}}
                                                    <br>
                                                    <span class="text-xs">{{row.supplierCategory}}</span>
                                                </div>
                                                <div class="text-center">
                                                    <!-- has batch group -->
                                                    <button
                                                        v-if="row.batchGroup"
                                                        @click="shared.sendSupplierBatchEmail(row.batchGroup)"
                                                        class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"
                                                    >
                                                        <i class="fa-regular fa-envelope text-2xl"></i>
                                                    </button>
                                                    <!-- no batch group -->
                                                    <div v-else class="text-red-500 text-xs">
                                                        no batch group
                                                    </div>
                                                </div>
                                                <div class="text-center">
                                                    <input
                                                        v-model="row.quote.quote_sent"
                                                        true-value="1"
                                                        false-value="0"
                                                        @change="quoteSentCheckbox(row.quote)"
                                                        type="checkbox"
                                                    />
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                </div>
                                <!-- right -->
                                <div class="pt-5 pr-5 pb-5 pl-10">
                                    <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                                        Current quote coverage
                                    </h3>

                                    <div class="mt-3 grid grid-cols-1 gap-y-3">
<!--                                        <p class="text-sm text-gray-500">-->
<!--                                            Are you sure you want to deactivate your account? All of your data will be permanently removed. This action cannot be undone.-->
<!--                                        </p>-->
                                        <div class="grid grid-cols-4">
                                            <div class="col-span-3 font-semibold">Procurement Category</div>
                                            <div class="col-span-1 font-semibold text-center">Quotes</div>
                                        </div>
                                        <div
                                            v-for="(data,supplierCategory) in allData[modalSelectedBatchId]?.modalData?.currentQuoteCoverage"
                                            class="grid grid-cols-4"
                                        >
                                            <div class="col-span-3">
                                                <h3>{{supplierCategory}}</h3>
                                                <p class="text-xs text-gray-600">{{data.includedProducts.string}}</p>
                                            </div>
                                            <div
                                                class="col-span-1 text-center font-bold pt-2"
                                                :class="data.qtyQuotes === 0 ? 'text-orange-600' : 'text-green-600'"
                                            >
                                                {{data.qtyQuotes}}
                                            </div>
                                        </div>

                                    </div>
                                </div>
                                <!-- edit suppliers -->
                                <div class="pl-5 pt-2">
                                    <Link
                                        :href="route('suppliers.index',business.id)"
                                        class="underline text-blue-500"
                                    >
                                        Edit suppliers
                                    </Link>
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
