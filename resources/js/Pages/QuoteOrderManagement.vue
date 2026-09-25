<script setup>
    //General Imports
    import {ref} from "vue";
    import {Link, useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";

    //Shared Methods
    import shared from '@/Shared/shared';
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        width: Number,
        quotesData: Object,
    });

    //Forms
    const formQuoteUpdate = useForm({
        quote_sent: null,
    });

    const formOrderUpdate = useForm({
        order_id: null,
        purchase_order_number: null,
        material_cert_numbers: null,
    });

    const formUndoOrderSent = useForm({});
    const formDelivered = useForm({});

    //Variables
    let showInputs = ref(setupShowInputs());
    const currentInputEditRow = ref(null);
    const height = window.innerHeight - 250;

    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Methods
    function setupShowInputs(){
        /*
         * Keyed by order id, not supplier id. A supplier can sit in more than one supplier group, and
         * those rows were sharing one open/closed state - opening an input on one opened it on the other.
         */
        const result = {};

        //hasSuppliersForThisGroup
        Object.values(props.quotesData.supplierGroupCards).forEach(data => {
            if(data.rows !== undefined){
                Object.values(data.rows).forEach(row => {
                    result[row.formOrderUpdate.order_id] = {
                        add_purchase_order: false,
                        material_cert_numbers: false,
                    };
                });
            }
        });

        return result;
    }

    function showCertNumbers(orderId){
        return showInputs.value[orderId]?.material_cert_numbers ?? false;
    }

    function showAddPurchaseOrder(orderId){
        return showInputs.value[orderId]?.add_purchase_order ?? false;
    }

    function toggleShowInput(row,type){
        /*
         * Save whatever is already open. Both editable fields belong to the order, so this saves the
         * order - it used to save the quote, and the quote save forced quote_sent to true on that row.
         */
        if(currentInputEditRow.value){
            updateOrder(currentInputEditRow.value);
        }

        //Set current row
        currentInputEditRow.value = row;

        //hide any currently open
        showInputs.value = setupShowInputs();

        showInputs.value[row.formOrderUpdate.order_id][type] = true;
    }

    function hideInput(){
        //hide any currently open
        showInputs.value = setupShowInputs();

        //Clear current input selection
        currentInputEditRow.value = null;
    }

    function updateOrder(row){
        let url = route("orders.update",row.formOrderUpdate.order_id);

        formOrderUpdate.order_id = row.formOrderUpdate.order_id;
        formOrderUpdate.purchase_order_number = row.formOrderUpdate.purchase_order_number;
        formOrderUpdate.material_cert_numbers = row.formOrderUpdate.material_cert_numbers;

        formOrderUpdate.put(url, {
            preserveScroll: true,
            onSuccess: () => {
                //Close all inputs
                showInputs.value = setupShowInputs();

                //Clear current input selection
                currentInputEditRow.value = null;
            },
        });
    }

    function quoteSentCheckbox(row){
        let url = route("quotes.update",row.formQuoteUpdate.quote_id);

        //quote_sent is a real boolean on both sides of the wire now
        formQuoteUpdate.quote_sent = row.formQuoteUpdate.quote_sent;

        formQuoteUpdate.put(url, {preserveScroll: true});
    }

    function orderSentCheckbox(row){
        let url = route("order.sent",row.formOrderUpdate.batch_id);

        formOrderUpdate.order_id = row.formOrderUpdate.order_id;
        formOrderUpdate.post(url, {preserveScroll: true});
    }

    function deliveredCheckbox(row){
        let url = route("order.mark.delivered",row.formDelivered.order_id);

        formDelivered.post(url, {preserveScroll: true});
    }

    function undoOrderSent(row){
        let url = route("order.undo.sent",row.formUndoOrderSent.order_id);

        formUndoOrderSent.post(url, {preserveScroll: true});
    }

    function projectManagersApprovalBeforeOrderSent(row) {
        // Show the confirmation dialog
        askToConfirm({
            title: "Approval to order",
            message: props.quotesData.info.projectManagerApprovalMessage,
            confirmLabel: "Yes, approved",
            cancelLabel: "Not yet",
            tone: "primary",
            onConfirmed: () => orderSentCheckbox(row),
        });
    }

    function shouldDisableQuoteSent(row){
        /**
         * 1) If checked (sent) && cannot undo
         * 2) If not checked (not sent) && cannot mark sent
         */
        let shouldDisableQuoteSent = false;
        let currentlySent = row.formQuoteUpdate.quote_sent;

        //1) If checked (sent) && cannot undo
        if(currentlySent && !row.formQuoteUpdate.canUndoMarkQuoteAsSent){
            shouldDisableQuoteSent = true;
        }
        //2) If not checked (not sent) && cannot mark sent
        if(!currentlySent && !row.formQuoteUpdate.canMarkQuoteAsSent){
            shouldDisableQuoteSent = true;
        }

        return shouldDisableQuoteSent;
    }

    function format(string) {
        return string.replace(/_/g, " ");
    }
</script>

<template>
    <AuthenticatedLayout>
        <Modal :fakeModal="true" redirect="current">
            <!-- header -->
            <div class="grid grid-cols-3 pt-2 pr-5 pb-2 pl-5">
                <div class="col-span-2">
                    <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="modal-title">
                        Quotes / Orders
                    </h3>
                </div>
                <div class="text-right">
                    <p>
                        Quoted: <b>{{ quotesData.info.sentQuotesQty }}/{{ quotesData.info.totalQuotesQty }}</b>
                    </p>
                    <p>
                        Ordered: <b>{{ quotesData.info.sentOrdersQty }}/{{ quotesData.info.totalOrdersQty }}</b>
                    </p>
                    <p>
                        Delivered: <b>{{ quotesData.info.deliveredQty }}/{{ quotesData.info.totalOrdersQty }}</b>
                    </p>
                </div>
            </div>
            <!-- body -->
            <div :style="'width:'+width+'px; height:'+height+'px'" class="overflow-y-auto pt-2 pr-5 pb-5 pl-5">
                <div class="mt-3">
                    <div
                        v-for="(data,supplierGroup) in quotesData?.supplierGroupCards"
                        class="border-2 border-gray-300 rounded-xl p-3 mb-4"
                    >
                        <!-- header -->
                        <div class="grid grid-cols-2">
                            <div>
                                <h2 class="font-semibold">{{ format(supplierGroup)}}</h2>
                                <p class="text-sm text-gray-400">{{data.info.includedProducts}}</p>
                            </div>
                            <div class="text-right">
                                <!-- status -->
                                <div class="flex justify-end gap-x-3">
                                    <p
                                        :class="data.info.order ? 'text-green-500' : 'text-orange-500'"
                                        class="block text-green-500 font-semibold text-lg"
                                    >
                                        ORDERED
                                    </p>
                                    /
                                    <p
                                        :class="data.info.order?.is_delivered ? 'text-green-500' : 'text-orange-500'"
                                        class="font-semibold text-lg"
                                    >
                                        DELIVERED
                                    </p>
                                </div>
                                <!-- purchase order number -->
                                <span v-if="data.info.purchaseOrderNumber" class="text-sm">PO: <span class="font-semibold">{{data.info.purchaseOrderNumber}}</span></span>
                            </div>
                        </div>

                        <!-- Main-->
                        <div v-if="data.hasSuppliersForThisGroup" class="mt-3">
                            <!-- heading row -->
                            <div class="grid grid-cols-7 text-xs text-gray-500 text-center mb-2 font-semibold">
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
                                    Sent order
                                </div>
                                <div>
                                    Delivered
                                </div>
                                <div>
                                    Material Certs
                                </div>
                            </div>
                            <!-- rows -->
                            <div
                                v-for="row in data.rows"
                                :class="row.info.order_sent ? 'bg-green-50' : ''"
                                class="grid grid-cols-7 text-center mb-2 p-1 rounded"
                            >
                                <!-- supplier -->
                                <div class="col-span-2 text-left pt-1">
                                    {{ shared.cropText(row.info.supplier.name,20) }}
                                </div>
                                <!-- email button -->
                                <div>
                                    <button
                                        @click="shared.sendSupplierBatchEmail(data.info.batchGroup,supplierGroup)"
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
                                        :disabled="shouldDisableQuoteSent(row)"
                                        :class="shouldDisableQuoteSent(row) ? 'bg-gray-300 checked:bg-gray-400 hover:checked:bg-gray-400' : ''"
                                        @change="quoteSentCheckbox(row)"
                                        type="checkbox"
                                    />
                                </div>
                                <!-- Sent order -->
                                <div class="pt-1">
                                    <!-- formOrderUpdate -->
                                    <template v-if="!showAddPurchaseOrder(row.formOrderUpdate.order_id)">
                                        <input
                                            v-if="row.info.order_sent"
                                            @click="undoOrderSent(row)"
                                            :disabled="row.info.is_delivered"
                                            :class="row.info.is_delivered ? 'text-gray-500' : ''"
                                            type="checkbox"
                                            checked
                                        />
                                        <input
                                            v-else
                                            @click.prevent="projectManagersApprovalBeforeOrderSent(row)"
                                            type="checkbox"
                                        />

                                        <button
                                            v-if="row.info.order_sent"
                                            @click="toggleShowInput(row,'add_purchase_order')"
                                            class="text-xs underline text-blue-500"
                                        >
                                            {{row.formOrderUpdate.purchase_order_number ? 'Edit PO number' :'Add PO number'}}
                                        </button>
                                    </template>

                                    <div v-if="showAddPurchaseOrder(row.formOrderUpdate.order_id)">
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
                                <div class="pt-1">
                                    <input
                                        v-if="row.info.order_sent"
                                        v-model="row.info.is_delivered"
                                        @change="deliveredCheckbox(row)"
                                        :disabled="row.info.is_delivered"
                                        type="checkbox"
                                        :class="row.info.is_delivered ? 'text-gray-500' : ''"
                                    />
                                </div>
                                <!-- Certs -->
                                <div class="col-span-1 pt-2">
                                    <div v-if="row.info.is_delivered" class="italic text-sm">
                                        <div v-if="showCertNumbers(row.formOrderUpdate.order_id)">
                                            <input
                                                v-model="row.formOrderUpdate.material_cert_numbers"
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
                                        <div v-else>
                                            <p
                                                class="text-blue-500 underline text-xs"
                                                @click="toggleShowInput(row,'material_cert_numbers')"
                                            >
                                                {{row.formOrderUpdate.material_cert_numbers ?? 'Add certs'}}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <!-- need to add suppliers -->
                        <div v-else class="mt-3">
                            <Link
                                :href="route('suppliers.index')"
                                class="underline text-blue-500"
                            >
                                Add/Edit suppliers
                            </Link>
                        </div>
                    </div>
                    <!-- add suppliers -->
                    <div class="pl-2 mt-5">
                        <Link
                            :href="route('suppliers.index')"
                            class="underline text-blue-500"
                        >
                            Add/Edit suppliers
                        </Link>
                    </div>
                </div>
            </div>
        </Modal>
    </AuthenticatedLayout>

    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :confirmLabel="confirmDialog.confirmLabel"
        :cancelLabel="confirmDialog.cancelLabel"
        :tone="confirmDialog.tone"
        @confirm="confirmDialogAccepted()"
        @cancel="confirmDialogCancelled()"
    />
</template>
