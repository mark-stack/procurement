<script setup>
    //General Imports
    //

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import {useForm, usePage} from "@inertiajs/vue3";

    //Props
    const props = defineProps({
        width: String,
        allData: Object,
        modalSelectedBatchId: Number|null,
    });

    //Forms
    const formOrderUpdate = useForm({
        order_sent: null,
    });
    const form = useForm({
        items: props.allData,
    });

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    //

    //Shared Methods
    import shared from '@/Shared/shared';

    //Methods
    function orderSentCheckbox(order){
        let url = route("orders.update",order.id);

        formOrderUpdate.order_sent = order.order_sent === "0" ? false : true;
        formOrderUpdate.put(url, {
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
    <Modal>
        <div :style="'width:'+width+'px'">
            <div class="p-5">
                <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                    Manage orders
                </h3>

                <div class="mt-3">
                    <div class="w-full grid grid-cols-1 gap-y-3">
                        <div class="grid grid-cols-7">
                            <div class="col-span-2 font-semibold">Procurement Category</div>
                            <div class="col-span-1 font-semibold text-center">Quotes</div>
                            <div class="col-span-2 font-semibold text-left">Preferred Quote/Supplier</div>
                            <div class="col-span-1 font-semibold text-center">Email Tables</div>
                            <div class="col-span-1 font-semibold text-center">Sent Order?</div>
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
                                class="col-span-1 text-center font-bold pt-2"
                                :class="data.qtyQuotes === 0 ? 'text-orange-600' : 'text-green-600'"
                            >
                                {{data.qtyQuotes}}
                            </div>
                            <div class="col-span-2 text-center">
                                <!-- Has quotes to pick from -->
                                <div v-if="data.qtyQuotes > 0">
                                    <select class="w-full rounded">
                                        <option value="quote_1">quote_1</option>
                                        <option value="quote_2">quote_2</option>
                                    </select>
                                </div>
                                <!-- No quotes > pick supplier -->
                                <div v-else>
                                    <select class="w-full rounded">
                                        <option value="supplier_1">supplier_1</option>
                                        <option value="supplier_2">supplier_2</option>
                                    </select>
                                </div>

                            </div>
                            <div class="col-span-1 text-center">
                                <button
                                    v-if="data.qtyQuotes === 0"
                                    @click="shared.sendSupplierBatchEmail(data.batchGroup)"
                                    class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"
                                >
                                    <i class="fa-regular fa-envelope text-2xl"></i>
                                </button>
                            </div>
                            <div class="col-span-1 text-center">
                                <input
                                    type="checkbox"
                                    true-value="1"
                                    false-value="0"
                                    @change="orderSentCheckbox(data.order)"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>



<!--<script setup>-->
<!--    //General Imports-->
<!--    import {ref} from "vue";-->
<!--    import {useForm, usePage} from "@inertiajs/vue3";-->

<!--    //Component Imports-->
<!--    //...-->

<!--    //Props-->
<!--    const props = defineProps({-->
<!--        allData: Object,-->
<!--        modalSelectedBatchId: Number|null,-->
<!--    });-->

<!--    //Forms-->
<!--    const formOrderUpdate = useForm({-->
<!--        order_sent: null,-->
<!--    });-->
<!--    const form = useForm({-->
<!--        items: props.allData,-->
<!--    });-->

<!--    //Shared data-->
<!--    const business = usePage().props.auth.business;-->

<!--    //Variables-->
<!--    const emit = defineEmits(['closeModal']);-->
<!--    const clickCount = ref(0);-->

<!--    //Shared Methods-->
<!--    //-->

<!--    //Methods-->
<!--    function orderSentCheckbox(order){-->
<!--        let url = route("orders.update",order.id);-->

<!--        formOrderUpdate.order_sent = order.order_sent === "0" ? false : true;-->
<!--        formOrderUpdate.put(url, {-->
<!--            preserveScroll: true,-->
<!--            onSuccess: () => {-->
<!--                console.log('success');-->
<!--            },-->
<!--            onError: errors => {-->
<!--                console.log('errors',errors);-->
<!--            },-->
<!--        });-->
<!--    }-->
<!--</script>-->

<!--<template>-->

<!--    &lt;!&ndash; Modal (https://codepen.io/npmhieu/pen/mdxaEbE?editors=1000) &ndash;&gt;-->
<!--    <div id="basicModal" v-show="showModal">-->
<!--        <div-->
<!--            x-show="open"-->
<!--            class="relative z-10"-->
<!--            aria-labelledby="modal-title"-->
<!--            x-ref="dialog"-->
<!--            aria-modal="true"-->
<!--        >-->
<!--            <div-->
<!--                x-show="open"-->
<!--                x-transition:enter="ease-out duration-300"-->
<!--                x-transition:enter-start="opacity-0"-->
<!--                x-transition:enter-end="opacity-100"-->
<!--                x-transition:leave="ease-in duration-200"-->
<!--                x-transition:leave-start="opacity-100"-->
<!--                x-transition:leave-end="opacity-0"-->
<!--                x-description="Background backdrop, show/hide based on modal state."-->
<!--                class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity"-->
<!--            ></div>-->


<!--            <div class="fixed z-10 inset-0 overflow-y-auto">-->
<!--                <div class="flex items-end sm:items-center justify-center min-h-full p-4 text-center sm:p-0">-->
<!--                    <div-->
<!--                        v-click-away="onClickAway"-->
<!--                        x-show="open"-->
<!--                        x-transition:enter="ease-out duration-300"-->
<!--                        x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"-->
<!--                        x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"-->
<!--                        x-transition:leave="ease-in duration-200"-->
<!--                        x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"-->
<!--                        x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"-->
<!--                        x-description="Modal panel, show/hide based on modal state."-->
<!--                        class="relative bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-3xl sm:w-full"-->
<!--                    >-->
<!--                        <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">-->

<!--                            <div class="p-5">-->
<!--                                <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">-->
<!--                                    Manage orders-->
<!--                                </h3>-->

<!--                                <div class="mt-3">-->
<!--                                    <div class="w-full grid grid-cols-1 gap-y-3">-->
<!--                                        <div class="grid grid-cols-7">-->
<!--                                            <div class="col-span-2 font-semibold">Procurement Category</div>-->
<!--                                            <div class="col-span-1 font-semibold text-center">Quotes</div>-->
<!--                                            <div class="col-span-2 font-semibold text-left">Preferred Quote/Supplier</div>-->
<!--                                            <div class="col-span-1 font-semibold text-center">Email Tables</div>-->
<!--                                            <div class="col-span-1 font-semibold text-center">Sent Order?</div>-->
<!--                                        </div>-->
<!--                                        <div-->
<!--                                            v-for="(data,supplierCategory) in allData[modalSelectedBatchId]?.modalData?.currentQuoteCoverage"-->
<!--                                            class="grid grid-cols-7"-->
<!--                                        >-->
<!--                                            <div class="col-span-2">-->
<!--                                                <h3>{{supplierCategory}}</h3>-->
<!--                                                <p class="text-xs text-gray-600">{{data.includedProducts.string}}</p>-->
<!--                                            </div>-->
<!--                                            <div-->
<!--                                                class="col-span-1 text-center font-bold pt-2"-->
<!--                                                :class="data.qtyQuotes === 0 ? 'text-orange-600' : 'text-green-600'"-->
<!--                                            >-->
<!--                                                {{data.qtyQuotes}}-->
<!--                                            </div>-->
<!--                                            <div class="col-span-2 text-center">-->
<!--                                                &lt;!&ndash; Has quotes to pick from &ndash;&gt;-->
<!--                                                <div v-if="data.qtyQuotes > 0">-->
<!--                                                    <select class="w-full rounded">-->
<!--                                                        <option value="quote_1">quote_1</option>-->
<!--                                                        <option value="quote_2">quote_2</option>-->
<!--                                                    </select>-->
<!--                                                </div>-->
<!--                                                &lt;!&ndash; No quotes > pick supplier &ndash;&gt;-->
<!--                                                <div v-else>-->
<!--                                                    <select class="w-full rounded">-->
<!--                                                        <option value="supplier_1">supplier_1</option>-->
<!--                                                        <option value="supplier_2">supplier_2</option>-->
<!--                                                    </select>-->
<!--                                                </div>-->

<!--                                            </div>-->
<!--                                            <div class="col-span-1 text-center">-->
<!--                                                <button-->
<!--                                                    v-if="data.qtyQuotes === 0"-->
<!--                                                    @click="shared.sendSupplierBatchEmail(data.batchGroup)"-->
<!--                                                    class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"-->
<!--                                                >-->
<!--                                                    <i class="fa-regular fa-envelope text-2xl"></i>-->
<!--                                                </button>-->
<!--                                            </div>-->
<!--                                            <div class="col-span-1 text-center">-->
<!--                                                <input-->
<!--                                                    type="checkbox"-->
<!--                                                    true-value="1"-->
<!--                                                    false-value="0"-->
<!--                                                    @change="orderSentCheckbox(data.order)"-->
<!--                                                />-->
<!--                                            </div>-->
<!--                                        </div>-->
<!--                                    </div>-->
<!--                                </div>-->
<!--                            </div>-->
<!--                        </div>-->
<!--                        <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">-->
<!--                            <button-->
<!--                                type="button"-->
<!--                                class="mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm"-->
<!--                                @click="$emit('closeModal'); clickCount = 0;"-->
<!--                            >-->
<!--                                Done-->
<!--                            </button>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->
<!--    </div>-->
<!--</template>-->
