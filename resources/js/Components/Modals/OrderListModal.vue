<script setup>
    /**
     * What to buy for a batch, a block per supplier group.
     *
     * The same lines the quotes/orders modal's "Email tables" buttons put in a mail - see
     * Shared/shared.js, where they are built, so the merchant's copy and this one cannot word the
     * same order differently. This is for reading and copying: nothing here sends, quotes or orders.
     */
    //General Imports
    import {computed, onBeforeUnmount, ref} from "vue";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Props
    const props = defineProps({
        //What the card is called: "Batch #12", or "Open batch" for the one with no row yet
        title: String,
        /**
         * The payload is fetched when the modal opens. Null while it is in flight, so this can say it
         * is working rather than showing the same empty state as a batch with nothing to buy.
         */
        orderList: Object,
        //Say so when that fetch failed, which is not the same as an empty batch either
        loadFailed: Boolean,
    });

    //Derived state
    const loading = computed(() => !props.orderList && !props.loadFailed);

    /*
     * Only the groups with something to buy. A supplier group whose products are all bundled or cut
     * from sheet has no stock lengths to list yet (see shared.orderListLines), and an empty heading
     * reads as a group somebody forgot to order for.
     */
    const groups = computed(() => (props.orderList?.groups ?? [])
        .map(group => ({
            ...group,
            lines: shared.orderListLines(group.batchGroup),
        }))
        .filter(group => group.lines.length > 0));

    //Variables
    //Which group was just copied, so only that button says so. Cleared on a timer
    const copiedGroup = ref(null);
    let copiedTimer = null;

    //Methods
    /**
     * This group's lines, onto the clipboard, to be pasted into a mail to that merchant.
     *
     * The clipboard API is unavailable outside a secure context and can be refused outright, so the
     * old selection trick stands behind it - a Copy button that silently does nothing is worse than
     * no button, because the list looks copied until it is pasted.
     */
    async function copyGroup(group) {
        const text = group.lines.join("\n");
        let copied = false;

        try {
            await navigator.clipboard.writeText(text);
            copied = true;
        } catch (error) {
            copied = copyBySelection(text);
        }

        if (!copied) {
            return;
        }

        copiedGroup.value = group.supplierGroup;

        clearTimeout(copiedTimer);
        copiedTimer = setTimeout(() => copiedGroup.value = null, 2000);
    }

    function copyBySelection(text) {
        const textarea = document.createElement('textarea');

        //Off screen rather than hidden: a display:none textarea cannot be selected
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';

        document.body.appendChild(textarea);
        textarea.select();

        let copied = false;

        try {
            copied = document.execCommand('copy');
        } catch (error) {
            console.error('Could not copy the order list:', error);
        }

        document.body.removeChild(textarea);

        return copied;
    }

    //Lifecycle
    //The modal can be closed while the "Copied" message is still on a timer
    onBeforeUnmount(() => clearTimeout(copiedTimer));
</script>

<template>
    <Modal @closeModal="$emit('closeModal')">
        <div class="px-4 sm:px-6 w-[90vw] max-w-3xl">
            <h3 id="modal-title" class="text-2xl font-bold text-center text-gray-900">
                Order list
            </h3>
            <p class="mt-1 text-sm text-center text-gray-500">
                {{ title }} · the stock this nest needs
            </p>

            <p v-if="loading" class="py-16 text-center text-gray-500">
                <i class="fa-solid fa-circle-notch fa-spin"></i>
                Working out what to buy...
            </p>

            <p v-else-if="loadFailed" class="py-16 text-center text-gray-600">
                We could not work out this batch's order list. Close this and try again.
            </p>

            <!--
                Nothing to buy. On a nested batch that means no nest was saved against it; on the
                pending card it means nothing in the Nesting column is cut from stock lengths yet.
            -->
            <p v-else-if="groups.length === 0" class="py-16 text-center text-gray-600">
                There is nothing to order on this batch.
            </p>

            <div v-else class="mt-5 space-y-4">
                <div
                    v-for="group in groups"
                    :key="group.supplierGroup"
                    class="p-3 border-2 border-gray-300 rounded-xl"
                >
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <h4 class="font-semibold text-gray-900 uppercase">
                                {{ shared.supplierGroupLabel(group.supplierGroup) }}
                            </h4>
                            <p v-if="group.includedProducts" class="text-sm text-gray-400">
                                {{ group.includedProducts }}
                            </p>
                        </div>

                        <!--
                            This group's lines, for pasting into a mail to that merchant. One button
                            per group rather than one for the modal: the lists go to different
                            suppliers, and nobody sends a timber merchant the steel.
                        -->
                        <button
                            type="button"
                            @click="copyGroup(group)"
                            :title="'Copy the ' + shared.supplierGroupLabel(group.supplierGroup) + ' list'"
                            class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold text-gray-600 transition-colors duration-150 bg-white border border-gray-300 rounded-lg shadow-sm shrink-0 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800"
                        >
                            <i
                                :class="copiedGroup === group.supplierGroup
                                    ? 'fa-solid fa-check text-green-600'
                                    : 'fa-regular fa-copy'"
                                class="text-[11px]"
                            ></i>
                            {{ copiedGroup === group.supplierGroup ? 'Copied' : 'Copy' }}
                        </button>
                    </div>

                    <!--
                        Monospaced and preserved, because this is text somebody copies into a mail or
                        reads down a column: proportional type puts the quantities out of line.
                    -->
                    <pre class="p-3 mt-3 overflow-x-auto text-sm text-gray-800 rounded-lg bg-gray-50">{{ group.lines.join('\n') }}</pre>
                </div>
            </div>
        </div>
    </Modal>
</template>
