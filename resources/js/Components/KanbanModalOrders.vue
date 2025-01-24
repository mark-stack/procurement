<script setup>
    //General Imports
    import {toRefs, watch} from "vue";
    import {useForm, usePage} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        width: String,
        allData: Object,
        modalSelectedBatchId: Number|null,
        refreshModalOrders: Boolean,
        ordersData: Object,
    });

    //Forms
    let formOrderUpdate = useForm({});

    //Shared data
    const business = usePage().props.auth.business;

    //Variables
    //

    //Shared Methods
    //

    //Methods
    function initialiseForm(){
        let formObject = {};
        if(!isLoading()){
            Object.values(props.ordersData[0]?.data.currentQuoteCoverage).forEach(item => {
                formObject[item.supplier_category] = {
                    supplier_id: item.selectedSupplierId,
                    order_sent: item.orderSent,
                };
            });
        }

        formOrderUpdate = useForm(formObject);
    }

    function orderSentCheckbox(){
        let url = route("order.sent.checkbox",props.modalSelectedBatchId);

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

    function isLoading(){
        return Object.values(props.ordersData).length === 0;
    }

    //Watcher
    const { refreshModalOrders } = toRefs(props);
    watch(refreshModalOrders, (newVal) => {
        console.log('refreshModalOrders changed:', newVal);

        console.log("initialise Form");
        initialiseForm();
    });
</script>

<template>
    <Modal>
        <div :style="'width:'+width+'px'">
            <div class="p-5">
                <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                    Manage orders
                </h3>

                <!-- Loading -->
                <div
                    v-if="isLoading()"
                    class="p-20 text-gray-700 italic"
                >
                    <span class="block font-bold text-xl">Loading...</span>
                </div>

                <div v-else class="mt-3">
                    <div class="w-full grid grid-cols-1 gap-y-3">
                        <div class="grid grid-cols-6">
                            <div class="col-span-2 font-semibold">Procurement Category</div>
                            <div class="col-span-1 font-semibold text-center">Quotes</div>
                            <div class="col-span-2 font-semibold text-left">Preferred Quote/Supplier</div>
<!--                            <div class="col-span-1 font-semibold text-center">Email Tables</div>-->
                            <div class="col-span-1 font-semibold text-center">Sent Order?</div>
                        </div>
                        <div
                            v-for="(data,supplierCategory) in ordersData[0]?.data.currentQuoteCoverage"
                            class="grid grid-cols-6"
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
                                    <select
                                        v-model="formOrderUpdate[supplierCategory].supplier_id"
                                        class="w-full rounded"
                                        :disabled="formOrderUpdate[supplierCategory].order_sent"
                                        :class="formOrderUpdate[supplierCategory].order_sent ? 'bg-gray-200' : ''"
                                    >
                                        <option :value="null" disabled>Select quote</option>
                                        <option
                                            v-for="quote in data.quotes"
                                            :value="quote.supplier.id"
                                        >
                                            {{quote.supplier.name}}
                                        </option>
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
<!--                            <div class="col-span-1 text-center">-->
<!--                                <button-->
<!--                                    v-if="data.qtyQuotes === 0"-->
<!--                                    @click="shared.sendSupplierBatchEmail(data.batchGroup)"-->
<!--                                    class="bg-green-50 rounded px-1 border-2 border-green-100 hover:bg-green-100"-->
<!--                                >-->
<!--                                    <i class="fa-regular fa-envelope text-2xl"></i>-->
<!--                                </button>-->
<!--                            </div>-->
                            <div class="col-span-1 text-center">
                                <input
                                    v-model="formOrderUpdate[supplierCategory].order_sent"
                                    :disabled="!formOrderUpdate[supplierCategory].supplier_id"
                                    :class="formOrderUpdate[supplierCategory].supplier_id ? '' : 'bg-gray-300'"
                                    class="mt-3 w-5 h-5"
                                    type="checkbox"
                                    @change="orderSentCheckbox()"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
