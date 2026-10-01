<script setup>
    //General Imports
    import {computed, nextTick, ref, watch} from "vue";
    import {Link, useForm} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import MaterialCertificatesModal from "@/Components/Modals/MaterialCertificatesModal.vue";
    import GoodsReceiptModal from "@/Components/Modals/GoodsReceiptModal.vue";

    //Shared Methods
    import shared from '@/Shared/shared';
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    const props = defineProps({
        show: Boolean,
        width: {
            type: Number,
            default: 850,
        },
        /**
         * Null while the fetch is in flight. This used to arrive as a page prop, so it could be
         * counted on to exist before anything rendered; now every read of it has to tolerate the gap.
         */
        quotesData: Object,
        loadFailed: Boolean,
    });

    //Forms
    const formQuoteUpdate = useForm({
        quote_sent: null,
    });

    /*
     * The PO number and nothing else. material_cert_numbers used to ride along here so that saving a
     * PO would not blank it - the two shared one inline editor. They no longer do, and a field left
     * out of the request is a field orders.update does not touch, so leaving it out is the stronger
     * guarantee: this form cannot reach the certificates at all.
     */
    const formOrderUpdate = useForm({
        order_id: null,
        purchase_order_number: null,
    });

    const formUndoOrderSent = useForm({});
    //The delivery form lives in GoodsReceiptModal now - it has fields, so it needs its own state

    //Variables
    const emit = defineEmits(['closeModal','refresh']);

    /*
     * Which row's PO number is open, and what has been typed into it.
     *
     * The draft is held here rather than in row.formOrderUpdate. Typing straight onto the row
     * mutated the board's own copy of the data, so a cancelled edit stayed on screen looking saved -
     * the cell flipped from "Add PO number" to the number you had just abandoned.
     */
    const editingPurchaseOrderId = ref(null);
    const purchaseOrderDraft = ref('');
    //Which row is actually in flight - formOrderUpdate.processing is shared by every row on the board
    const savingPurchaseOrderId = ref(null);
    //The open input itself. Not reactive: nothing renders off it, it is only focused
    let purchaseOrderInput = null;

    /*
     * Held by order id, not as the row object. Every save in the certificates panel re-fetches the
     * batch, which replaces every row object in quotesData - a captured row would go on showing the
     * files as they were when the panel opened.
     */
    const certsOrderId = ref(null);
    const showCertsModal = ref(false);

    //Held by order id for the same reason the certs panel is - booking in re-fetches the batch
    const receiptOrderId = ref(null);
    const showReceiptModal = ref(false);

    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    const certsRow = computed(() => rowForOrderId(certsOrderId.value));

    const receiptRow = computed(() => rowForOrderId(receiptOrderId.value));

    const receiptSupplierName = computed(() => receiptRow.value?.info?.supplier?.name ?? '');

    const editingPurchaseOrderRow = computed(() => rowForOrderId(editingPurchaseOrderId.value));

    //Nothing to save when the box still holds what is already on the order
    const purchaseOrderChanged = computed(() => {
        const saved = editingPurchaseOrderRow.value?.formOrderUpdate?.purchase_order_number ?? '';

        return purchaseOrderDraft.value.trim() !== saved;
    });

    const certsSupplierName = computed(() => certsRow.value?.info?.supplier?.name ?? '');

    //Methods
    /*
     * Rows are addressed by order id, not by supplier. A supplier can sit in more than one supplier
     * group, and those rows were sharing one open/closed state - opening an input on one opened it
     * on the other.
     */
    function rowForOrderId(orderId){
        if(!orderId){
            return null;
        }

        let found = null;

        Object.values(props.quotesData?.supplierGroupCards ?? {}).forEach(data => {
            Object.values(data.rows ?? {}).forEach(row => {
                if(row.formOrderUpdate.order_id === orderId){
                    found = row;
                }
            });
        });

        return found;
    }

    /*
     * What the certs cell says, in a seventh of a row.
     *
     * Two ways for an order to be certified and the cell has to name which one is in play, because
     * the whole reason the panel exists is that "Material Certs" over a text box read as though it
     * held the certificate itself.
     */
    function certFileCount(row){
        return (row.materialCertificateFiles ?? []).length;
    }

    function certReference(row){
        return row.formOrderUpdate.material_cert_numbers ?? null;
    }

    function hasCerts(row){
        return certFileCount(row) > 0 || !!certReference(row);
    }

    function certsSummary(row){
        const files = certFileCount(row);
        const reference = certReference(row);

        //A reference is the more specific thing to show; the paperclip beside it says files too
        if(reference){
            return shared.cropText(reference,14);
        }

        if(files > 0){
            return files + (files === 1 ? ' file' : ' files');
        }

        return 'Add certs';
    }

    function certsTitle(row){
        const files = certFileCount(row);
        const reference = certReference(row);

        if(!files && !reference){
            return 'No material certificates recorded - attach the certificate or note where it is filed';
        }

        const parts = [];

        if(files){
            parts.push(files + (files === 1 ? ' certificate attached' : ' certificates attached'));
        }
        if(reference){
            parts.push('Reference: ' + reference);
        }

        return parts.join(' - ');
    }

    function openCerts(row){
        /*
         * Save a PO number left open in the cell alongside. Both write to the same order, and the
         * panel's own save would otherwise post the stale number back over the one being typed.
         */
        if(purchaseOrderChanged.value && editingPurchaseOrderRow.value){
            savePurchaseOrder(editingPurchaseOrderRow.value);
        }

        certsOrderId.value = row.formOrderUpdate.order_id;
        showCertsModal.value = true;
    }

    function closeCerts(){
        showCertsModal.value = false;
        certsOrderId.value = null;
    }

    function editingPurchaseOrder(orderId){
        return editingPurchaseOrderId.value === orderId;
    }

    function openPurchaseOrder(row){
        const orderId = row.formOrderUpdate.order_id;

        if(editingPurchaseOrderId.value === orderId){
            return;
        }

        /*
         * Save an edit left open on another row rather than dropping it. People work down the column
         * filling these in and move on without pressing Save; what they typed has to survive that.
         */
        if(purchaseOrderChanged.value && editingPurchaseOrderRow.value){
            savePurchaseOrder(editingPurchaseOrderRow.value);
        }

        editingPurchaseOrderId.value = orderId;
        purchaseOrderDraft.value = row.formOrderUpdate.purchase_order_number ?? '';

        //Open ready to type in, with any existing number selected - editing one is nearly always
        //replacing it. Once, here, rather than on every render of the box
        nextTick(() => {
            purchaseOrderInput?.focus();
            purchaseOrderInput?.select();
        });
    }

    function closePurchaseOrder(){
        editingPurchaseOrderId.value = null;
        purchaseOrderDraft.value = '';
    }

    /**
     * Keeps hold of the open input. Storing it is all this does.
     *
     * Vue re-invokes a function ref on every patch, not only on mount - and v-model re-renders on
     * every keystroke. Focusing from in here therefore re-ran select() after each letter, so the
     * next one replaced what was highlighted and the box never held more than one character.
     * Whatever the box should do when it opens is driven by opening it, below.
     */
    function bindPurchaseOrderInput(element){
        purchaseOrderInput = element;
    }

    function savePurchaseOrder(row){
        const orderId = row.formOrderUpdate.order_id;
        //An emptied box clears the number rather than storing a blank one
        const value = purchaseOrderDraft.value.trim() || null;

        formOrderUpdate.order_id = orderId;
        formOrderUpdate.purchase_order_number = value;
        savingPurchaseOrderId.value = orderId;

        formOrderUpdate.put(route("orders.update",orderId), {
            preserveScroll: true,
            onFinish: () => {
                if(savingPurchaseOrderId.value === orderId){
                    savingPurchaseOrderId.value = null;
                }
            },
            onSuccess: () => {
                /*
                 * Only close the editor if it is still this row's. Opening another row saves this one
                 * on the way past, and that response must not close the box just opened.
                 */
                if(editingPurchaseOrderId.value === orderId){
                    closePurchaseOrder();
                }

                emit('refresh');
            },
        });
    }

    function quoteSentCheckbox(row){
        let url = route("quotes.update",row.formQuoteUpdate.quote_id);

        //quote_sent is a real boolean on both sides of the wire now
        formQuoteUpdate.quote_sent = row.formQuoteUpdate.quote_sent;

        formQuoteUpdate.put(url, {preserveScroll: true, onSuccess: () => emit('refresh')});
    }

    function orderSentCheckbox(row){
        let url = route("order.sent",row.formOrderUpdate.batch_id);

        formOrderUpdate.order_id = row.formOrderUpdate.order_id;
        formOrderUpdate.post(url, {preserveScroll: true, onSuccess: () => emit('refresh')});
    }

    /*
     * Delivery used to be this, and nothing else:
     *
     *     formDelivered.post(route("order.mark.delivered", row.formDelivered.order_id))
     *
     * One empty POST that set one boolean. No date, nobody's name, no docket, and no way to say that
     * what came off the truck was not what was ordered - so a yard that took 38 bars of GR250 against
     * an order for 40 of GR300 had nowhere to record it, and the board went on reading the order as
     * filled. The receipt panel asks those questions instead; see GoodsReceiptModal.
     */
    function openReceipt(row){
        /*
         * Save a PO number left open in the cell alongside, for the reason openCerts does: both write
         * to the same order, and the panel's own post would otherwise carry the stale number with it.
         */
        if(purchaseOrderChanged.value && editingPurchaseOrderRow.value){
            savePurchaseOrder(editingPurchaseOrderRow.value);
        }

        receiptOrderId.value = row.formDelivered.order_id;
        showReceiptModal.value = true;
    }

    function closeReceipt(){
        showReceiptModal.value = false;
        receiptOrderId.value = null;
    }

    /**
     * What the delivery cell says, which is a different question from whether the steel arrived.
     */
    function receiptSummary(row){
        const receipt = row.goodsReceipt;

        if(receipt?.received){
            return receipt.accepted === false ? 'Received, with a problem' : 'Received';
        }

        if(receipt?.deliveredWithoutReceipt){
            return 'Arrived, unverified';
        }

        return 'Book in';
    }

    function receiptTitle(row){
        const receipt = row.goodsReceipt;

        if(receipt?.received){
            return 'Open the goods receipt for this delivery';
        }

        if(receipt?.deliveredWithoutReceipt){
            return 'Marked as arrived with no goods receipt recorded against it';
        }

        return 'Record what came off the truck - docket, checks and heat numbers';
    }

    function undoOrderSent(row){
        let url = route("order.undo.sent",row.formUndoOrderSent.order_id);

        formUndoOrderSent.post(url, {preserveScroll: true, onSuccess: () => emit('refresh')});
    }

    /**
     * This used to ask "Do Bruce,Matt and yourself approve ordering materials?" - one person
     * answering for three, after which every project on the batch was recorded as approved by its
     * own manager. Bruce and Matt were neither asked nor told. The message now says what is
     * actually happening (see BatchService::projectManagerApprovalMessage), so the title and the
     * buttons have to stop claiming an approval was collected as well.
     */
    function projectManagersApprovalBeforeOrderSent(row) {
        askToConfirm({
            title: "Mark this order as placed?",
            message: props.quotesData.info.projectManagerApprovalMessage,
            confirmLabel: "Mark as placed",
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

    /**
     * Why the checkbox above is dead.
     *
     * It greyed itself out and said nothing. Marking a quote sent needs you to be the manager of
     * at least one project on the batch, and this modal opens on every batch the business has -
     * so a colleague looking at somebody else's batch got a disabled checkbox and no reason for
     * it. Every button on the board behind this modal explains itself this way; this one did not.
     */
    function quoteSentTitle(row){
        if(!shouldDisableQuoteSent(row)){
            return row.formQuoteUpdate.quote_sent
                ? "Undo - this quote has not been sent after all"
                : "Mark this supplier's quote request as sent";
        }

        return row.formQuoteUpdate.quote_sent
            ? "This can no longer be undone - the order has been sent or delivered, or no project on this batch is yours"
            : "Only a project manager on this batch can mark its quotes as sent";
    }

    function format(string) {
        return string.replace(/_/g, " ");
    }

    //Watchers
    /*
     * Each fetch brings a fresh set of rows. The open editor is addressed by order id and the rows
     * are looked up through that, so it survives a refresh - but an order that has gone from the
     * batch takes its editor with it rather than leaving a box attached to nothing.
     */
    watch(() => props.quotesData, () => {
        if(editingPurchaseOrderId.value && !rowForOrderId(editingPurchaseOrderId.value)){
            closePurchaseOrder();
        }
    }, {immediate: true});
</script>

<template>
    <Modal
        v-if="show"
        :fakeModal="false"
        labelledby="quote-order-modal-title"
        @closeModal="$emit('closeModal')"
    >
        <!-- header -->
        <div class="grid grid-cols-3 pt-2 pr-5 pb-2 pl-5">
            <div class="col-span-2">
                <h3 class="text-2xl leading-6 font-medium text-gray-900 mb-5" id="quote-order-modal-title">
                    Quotes / Orders
                </h3>
            </div>
            <div v-if="quotesData" class="text-right">
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
        <!--
            Sized in CSS, not from window.innerHeight. This component stays mounted on the board
            between openings, so a height measured once at setup would be whatever the window was
            when the board loaded - a resize before opening left the modal the wrong size.
        -->
        <div
            :style="{
                width: 'min(' + width + 'px, calc(100vw - 2rem))',
                height: 'max(320px, calc(100vh - 250px))',
            }"
            class="overflow-y-auto pt-2 pr-5 pb-5 pl-5"
        >
            <!-- loading -->
            <div
                v-if="!quotesData && !loadFailed"
                class="flex h-full flex-col items-center justify-center gap-3 text-gray-500"
                role="status"
                aria-live="polite"
            >
                <div class="w-12 h-12 rounded-full animate-spin border-4 border-solid border-blue-500 border-t-transparent"></div>
                <span>Loading...</span>
            </div>

            <!-- the fetch is the only way in, so a failure has to say so rather than draw an empty modal -->
            <div
                v-else-if="loadFailed"
                class="flex h-full flex-col items-center justify-center gap-3 text-center"
                role="status"
                aria-live="polite"
            >
                <p class="text-gray-700">Could not load the quotes and orders for this batch.</p>
                <button
                    type="button"
                    @click="$emit('refresh')"
                    class="text-sm font-semibold text-blue-600 underline hover:text-blue-700"
                >
                    Try again
                </button>
            </div>

            <div v-else class="mt-3">
                <div
                    v-for="(data,supplierGroup) in quotesData?.supplierGroupCards"
                    :key="supplierGroup"
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
                                Material certs
                            </div>
                        </div>
                        <!-- rows -->
                        <div
                            v-for="row in data.rows"
                            :key="row.formOrderUpdate.order_id"
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
                                    :title="quoteSentTitle(row)"
                                    :class="shouldDisableQuoteSent(row) ? 'cursor-not-allowed bg-gray-300 checked:bg-gray-400 hover:checked:bg-gray-400' : ''"
                                    @change="quoteSentCheckbox(row)"
                                    type="checkbox"
                                />
                            </div>
                            <!-- Sent order -->
                            <div class="pt-1">
                                <!--
                                    The checkbox is outside the editor, not swapped out for it. It
                                    used to vanish the moment you opened the PO box, so the one thing
                                    the column is there to report - whether this order has been
                                    placed - was unreadable exactly while you were working on the row.
                                -->
                                <input
                                    v-if="row.info.order_sent"
                                    @click="undoOrderSent(row)"
                                    :disabled="row.info.is_delivered"
                                    :title="row.info.is_delivered
                                        ? 'This order has been delivered - it cannot be un-sent'
                                        : 'Undo - this order has not been placed after all'"
                                    :class="row.info.is_delivered ? 'cursor-not-allowed text-gray-500' : ''"
                                    type="checkbox"
                                    checked
                                />
                                <input
                                    v-else
                                    @click.prevent="projectManagersApprovalBeforeOrderSent(row)"
                                    title="Mark this supplier's order as placed, for every project on this batch"
                                    type="checkbox"
                                />

                                <!-- PO number -->
                                <div v-if="row.info.order_sent" class="mt-1">
                                    <template v-if="editingPurchaseOrder(row.formOrderUpdate.order_id)">
                                        <label :for="'po-' + row.formOrderUpdate.order_id" class="sr-only">
                                            Purchase order number
                                        </label>
                                        <input
                                            :id="'po-' + row.formOrderUpdate.order_id"
                                            :ref="bindPurchaseOrderInput"
                                            v-model="purchaseOrderDraft"
                                            type="text"
                                            maxlength="60"
                                            placeholder="PO number"
                                            class="w-full rounded border-gray-300 px-1.5 py-1 text-xs placeholder:text-gray-400 focus:border-blue-500 focus:ring-2 focus:ring-blue-500/30"
                                            @keyup.enter="purchaseOrderChanged && savePurchaseOrder(row)"
                                            @keyup.esc="closePurchaseOrder()"
                                        />
                                        <div class="mt-1 flex justify-center gap-x-2">
                                            <span
                                                v-if="savingPurchaseOrderId === row.formOrderUpdate.order_id"
                                                class="text-xs font-bold text-green-600"
                                            >
                                                Saving...
                                            </span>
                                            <template v-else>
                                                <button
                                                    type="button"
                                                    @click="savePurchaseOrder(row)"
                                                    :disabled="!purchaseOrderChanged"
                                                    class="text-xs font-bold underline text-green-600 disabled:cursor-not-allowed disabled:text-gray-400 disabled:no-underline"
                                                    :title="purchaseOrderChanged
                                                        ? 'Save this PO number'
                                                        : 'Nothing to save - the number has not changed'"
                                                >
                                                    Save
                                                </button>
                                                <button
                                                    type="button"
                                                    @click="closePurchaseOrder()"
                                                    class="text-xs underline text-gray-500 hover:text-gray-700"
                                                >
                                                    Cancel
                                                </button>
                                            </template>
                                        </div>
                                    </template>

                                    <!--
                                        The number itself, not just an invitation to go and look at
                                        it. "Edit PO number" said one had been recorded but never
                                        which, so checking a PO against a supplier's invoice meant
                                        opening the editor on every row in turn.
                                    -->
                                    <button
                                        v-else
                                        type="button"
                                        @click="openPurchaseOrder(row)"
                                        :title="row.formOrderUpdate.purchase_order_number
                                            ? 'PO ' + row.formOrderUpdate.purchase_order_number + ' - click to edit'
                                            : 'Record the purchase order number for this supplier'"
                                        class="mx-auto flex max-w-full items-center gap-1 rounded px-1.5 py-0.5 text-xs hover:bg-gray-100"
                                        :class="row.formOrderUpdate.purchase_order_number ? 'text-gray-700' : 'text-blue-600 underline'"
                                    >
                                        <template v-if="row.formOrderUpdate.purchase_order_number">
                                            <span class="flex-none text-[10px] uppercase tracking-wide text-gray-400">PO</span>
                                            <span class="truncate">
                                                {{ row.formOrderUpdate.purchase_order_number }}
                                            </span>
                                        </template>
                                        <template v-else>
                                            Add PO number
                                        </template>
                                    </button>
                                </div>
                            </div>
                            <!--
                                Delivered. A checkbox here could only say "it turned up"; booking a
                                delivery in is a form, so this opens one - and once it is booked in the
                                same button shows the record rather than a tick nobody can read.
                            -->
                            <div class="pt-1">
                                <button
                                    v-if="row.info.order_sent"
                                    type="button"
                                    @click="openReceipt(row)"
                                    :title="receiptTitle(row)"
                                    class="mx-auto flex max-w-full items-center gap-1 rounded px-1.5 py-1 text-xs hover:bg-gray-100"
                                    :class="row.goodsReceipt?.received
                                        ? (row.goodsReceipt?.accepted === false ? 'text-orange-700' : 'text-gray-700')
                                        : (row.goodsReceipt?.deliveredWithoutReceipt ? 'text-amber-700' : 'text-blue-600 underline')"
                                >
                                    <i
                                        v-if="row.goodsReceipt?.received"
                                        class="fa-solid flex-none text-[10px]"
                                        :class="row.goodsReceipt?.accepted === false
                                            ? 'fa-triangle-exclamation text-orange-500'
                                            : 'fa-circle-check text-green-600'"
                                    ></i>
                                    <span class="truncate">{{ receiptSummary(row) }}</span>
                                </button>
                            </div>
                            <!--
                                Certs. A summary that opens the panel where the work happens - this
                                cell is a seventh of an 850px modal, which was room for one text box
                                and no room at all to say what belongs in it.
                            -->
                            <div class="col-span-1 pt-1">
                                <button
                                    v-if="row.info.is_delivered"
                                    type="button"
                                    @click="openCerts(row)"
                                    :title="certsTitle(row)"
                                    class="mx-auto flex max-w-full items-center gap-1 rounded px-1.5 py-1 text-xs hover:bg-gray-100"
                                    :class="hasCerts(row) ? 'text-gray-700' : 'text-blue-600 underline'"
                                >
                                    <i
                                        v-if="certFileCount(row) > 0"
                                        class="fa-solid fa-paperclip flex-none text-[10px] text-gray-400"
                                    ></i>
                                    <span class="truncate">{{ certsSummary(row) }}</span>
                                </button>
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

    <!--
        Opens on top of this modal rather than replacing the cell's contents. Modal.vue stacks and
        makes whatever is underneath inert, so the row behind stays readable without being clickable.
    -->
    <MaterialCertificatesModal
        :show="showCertsModal && !!certsRow"
        :row="certsRow"
        :supplierName="certsSupplierName"
        @closeModal="closeCerts()"
        @refresh="$emit('refresh')"
    />

    <GoodsReceiptModal
        :show="showReceiptModal && !!receiptRow"
        :row="receiptRow"
        :supplierName="receiptSupplierName"
        :nonconformanceOptions="quotesData.info.receiptNonconformanceOptions ?? []"
        @closeModal="closeReceipt()"
        @refresh="$emit('refresh')"
    />

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
