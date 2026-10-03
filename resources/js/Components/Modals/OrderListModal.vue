<script setup>
    /**
     * What to buy for a batch, a block per supplier group.
     *
     * The same lines the quotes/orders modal's "Email tables" buttons put in a mail - see
     * Shared/shared.js, where they are built, so the merchant's copy and this one cannot word the
     * same order differently. This is for reading and copying: nothing here sends, quotes or orders.
     *
     * Each block has three tabs, because an order is read at three different moments: the stock to
     * buy, the certificates that came with it, and what happened when the truck arrived. They are one
     * merchant's business either way, so they are one card rather than three places to look.
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
    /*
     * Only the groups with something to buy. A supplier group whose products are all bundled or cut
     * from sheet has no stock lengths to list yet (see shared.orderListLines), and an empty heading
     * reads as a group somebody forgot to order for.
     */
    const loading = computed(() => !props.orderList && !props.loadFailed);

    const groups = computed(() => (props.orderList?.groups ?? [])
        .map(group => {
            const lines = shared.orderListLines(group.batchGroup);
            const receipt = receiptState(group.goodsReceipt);

            return {
                ...group,
                lines,
                receipt,
                tabs: tabsFor(group, receipt),
            };
        })
        .filter(group => group.lines.length > 0));

    /**
     * What this group's delivery amounts to: the icon the Delivery tab carries, and the line its panel
     * opens with.
     *
     * Four states, and they are four different things to do about them. Booked in and accepted is
     * finished. Booked in with a problem is somebody's phone call to the merchant. Booked in with the
     * checks unanswered is a receipt that satisfies nobody's auditor. Ordered and not arrived is a
     * delivery still owed. Null where no order has been placed, and then there is nothing to ask.
     *
     * The green/orange/grey reading of accepted is the quotes/orders receipt panel's, so the same
     * colours mean the same things on both screens.
     */
    function receiptState(receipt) {
        if (!receipt) {
            return null;
        }

        if (receipt.received) {
            if (receipt.accepted === true) {
                return {
                    icon: 'fa-solid fa-circle-check',
                    iconClass: 'text-green-600',
                    statusClass: 'border-green-200 bg-green-50 text-green-800',
                    statusLine: 'Checked and accepted.',
                };
            }

            if (receipt.accepted === false) {
                return {
                    icon: 'fa-solid fa-triangle-exclamation',
                    iconClass: 'text-orange-500',
                    statusClass: 'border-orange-200 bg-orange-50 text-orange-800',
                    statusLine: 'Booked in with a problem recorded. The steel counts as delivered and '
                        + 'the nest will use it — chase this with the supplier separately.',
                };
            }

            return {
                icon: 'fa-regular fa-circle-question',
                iconClass: 'text-gray-400',
                statusClass: 'border-gray-200 bg-gray-50 text-gray-700',
                statusLine: 'Booked in, and the checks were not answered.',
            };
        }

        /*
         * Arrived with nothing behind it, which is how every delivery marked before the goods receipt
         * existed reads. Not an error state: it is an honest "arrived, unverified", and the tab says
         * so rather than opening an empty record.
         */
        if (receipt.deliveredWithoutReceipt) {
            return {
                icon: 'fa-solid fa-triangle-exclamation',
                iconClass: 'text-amber-500',
                statusClass: 'border-amber-200 bg-amber-50 text-amber-800',
                statusLine: 'Marked as arrived, with no goods receipt recorded against it — no date, '
                    + "nobody's name, and no record of the load being checked. It cannot be filled in "
                    + 'after the fact.',
            };
        }

        return {
            icon: 'fa-regular fa-clock',
            iconClass: 'text-yellow-600',
            statusClass: 'border-yellow-200 bg-yellow-50 text-yellow-800',
            statusLine: 'Ordered, and nothing has been booked in yet.',
        };
    }

    /**
     * The three tabs of one block, and which of them have anything behind them.
     *
     * A tab with nothing to show is disabled rather than hidden, and that is the point of it: a
     * greyed-out Certificates says the paperwork has not come in, where a tab that simply was not
     * drawn says nothing at all and leaves three blocks on the screen with three different shapes.
     * The title on each says why it is dead, since a disabled button cannot explain itself.
     */
    function tabsFor(group, receipt) {
        return [
            {
                key: 'materials',
                label: 'Materials',
                icon: 'fa-solid fa-list-ul',
                //Always: a group with no stock lengths to buy is filtered out of the modal entirely
                available: true,
                title: 'The stock lengths this merchant has to supply',
            },
            {
                key: 'certificates',
                label: 'Certificates',
                icon: 'fa-solid fa-file-lines',
                badge: group.certificates.length > 0 ? String(group.certificates.length) : null,
                available: group.certificates.length > 0,
                title: certificatesTitle(group),
            },
            {
                key: 'delivery',
                label: 'Delivery',
                //The state's own icon, so the tab says how the delivery went before it is opened
                icon: receipt?.icon ?? 'fa-solid fa-truck',
                iconClass: receipt?.iconClass,
                /*
                 * Available on a placed order, not on a received one. "Ordered and still owed" is an
                 * answer worth reading, and so is "arrived with no receipt" - what cannot be asked is
                 * how a delivery went for a merchant nobody has ordered from.
                 */
                available: receipt !== null,
                title: receipt?.statusLine ?? 'Nothing has been ordered from this merchant yet',
            },
        ];
    }

    /**
     * Why the Certificates tab is dead, in the words that match the reason.
     *
     * Two different nothings: a group whose products never come with a mill certificate (timber,
     * fasteners - products.certificates, the same flag the BOM reads) is not waiting on anything,
     * while a steel group with none attached is.
     */
    function certificatesTitle(group) {
        if (group.certificates.length > 0) {
            return 'The mill certificates that have come in for this order';
        }

        return group.certificated
            ? 'No mill certificate has come in for this group yet'
            : "This group's products do not come with a mill certificate";
    }

    //Variables
    //Which group was just copied, so only that button says so. Cleared on a timer
    const copiedGroup = ref(null);
    let copiedTimer = null;

    /*
     * The tab each block is on, keyed by supplier group - one card at a time rather than one choice
     * for the modal, because the blocks are different merchants at different stages: the steel is
     * delivered while the bolts have not been ordered.
     *
     * Nothing resets it, and nothing needs to: the page mounts this modal when the button is pressed
     * and drops it on close, so one mount is one batch's list.
     */
    const openTabs = ref({});

    //Methods
    function activeTab(group) {
        return openTabs.value[group.supplierGroup] ?? 'materials';
    }

    function selectTab(group, key) {
        openTabs.value[group.supplierGroup] = key;
    }

    /**
     * A tri-state check, in words.
     *
     * "Not checked" rather than "No": an unanswered check is not a failed one, and the column is
     * nullable precisely so the two can be told apart - see the goods receipt panel, which says the
     * same three words.
     */
    function checkLabel(value) {
        if (value === true) {
            return 'Yes';
        }

        return value === false ? 'No' : 'Not checked';
    }

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
                Order
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
                            Whether this merchant has been ordered from yet. Green carries the
                            purchase order number where somebody has typed one in; an order placed
                            with the number still blank is just as ordered, and says so rather than
                            reading "Order: " with nothing after it.

                            A pill rather than a fourth tab: it is one fact, and a tab holding one
                            line would be a click to read what fits on the heading.
                        -->
                        <p
                            :class="group.ordered
                                ? 'text-green-800 bg-green-100'
                                : 'text-yellow-800 bg-yellow-100'"
                            class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold rounded-lg shrink-0"
                        >
                            <i
                                :class="group.ordered ? 'fa-solid fa-check' : 'fa-regular fa-clock'"
                                class="text-[10px]"
                            ></i>
                            <template v-if="group.ordered">
                                {{ group.purchaseOrderNumber ? 'Order: ' + group.purchaseOrderNumber : 'Ordered' }}
                            </template>
                            <template v-else>
                                Not ordered
                            </template>
                        </p>
                    </div>

                    <!-- The three questions asked of one merchant's order -->
                    <div
                        class="flex flex-wrap gap-2 mt-3"
                        role="group"
                        :aria-label="shared.supplierGroupLabel(group.supplierGroup) + ' order'"
                    >
                        <button
                            v-for="tab in group.tabs"
                            :key="tab.key"
                            type="button"
                            :disabled="!tab.available"
                            :title="tab.title"
                            :aria-pressed="activeTab(group) === tab.key"
                            @click="selectTab(group, tab.key)"
                            :class="activeTab(group) === tab.key
                                ? 'border-blue-300 bg-blue-50 text-blue-900'
                                : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
                            class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold transition-colors duration-150 border rounded-lg focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-1 disabled:cursor-not-allowed disabled:border-gray-200 disabled:bg-gray-50 disabled:text-gray-400"
                        >
                            <!-- The state's colour only while the tab can be opened: a dead tab is grey all through -->
                            <i
                                :class="[tab.icon, tab.available ? tab.iconClass : null]"
                                class="text-[11px]"
                            ></i>
                            {{ tab.label }}
                            <span v-if="tab.badge" class="font-normal">({{ tab.badge }})</span>
                        </button>
                    </div>

                    <div v-if="activeTab(group) === 'materials'">
                        <!--
                            Monospaced and preserved, because this is text somebody copies into a mail
                            or reads down a column: proportional type puts the quantities out of line.
                        -->
                        <pre class="p-3 mt-3 overflow-x-auto text-sm text-gray-800 rounded-lg bg-gray-50">{{ group.lines.join('\n') }}</pre>

                        <!--
                            Under the lines it copies, rather than in the heading: this is the one tab
                            whose contents go into a mail, and a Copy button sitting beside a goods
                            receipt invites somebody to think it copies that.

                            One button per group rather than one for the modal - the lists go to
                            different suppliers, and nobody sends a timber merchant the steel. Below
                            rather than over the lines, which scroll sideways: a bar section and its
                            grade is a long line, and a button floating on top of it hides the end.
                        -->
                        <div class="flex justify-end mt-2">
                            <button
                                type="button"
                                @click="copyGroup(group)"
                                :title="'Copy the ' + shared.supplierGroupLabel(group.supplierGroup) + ' list'"
                                class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold text-gray-600 transition-colors duration-150 bg-white border border-gray-300 rounded-lg shadow-sm hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800"
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
                    </div>

                    <!--
                        The certificates, by the name the file arrived under - a heat number is how
                        somebody picks the one they are after. Links, not contents: they live on the
                        private disk, and the route checks the certificate's order belongs to you.
                    -->
                    <div
                        v-else-if="activeTab(group) === 'certificates'"
                        class="mt-3 overflow-hidden border border-green-200 divide-y divide-green-100 rounded-lg"
                    >
                        <a
                            v-for="certificate in group.certificates"
                            :key="certificate.id"
                            :href="certificate.url"
                            class="flex items-center gap-2 px-3 py-2 text-xs font-medium text-green-900 transition-colors duration-150 bg-green-50 hover:bg-green-100"
                        >
                            <i class="fa-solid fa-file-arrow-down text-[11px] shrink-0"></i>
                            <span class="truncate">{{ certificate.filename }}</span>
                        </a>
                    </div>

                    <!--
                        What came off the truck, as somebody at the gate saw it. The same fields the
                        quotes/orders receipt panel shows, read-only here and read-only there - a
                        receipt records a moment, so there is nothing on it to edit from either screen.

                        The line above the record is there whether or not a receipt was written, and
                        says which of the four states this delivery is in.
                    -->
                    <div v-else-if="activeTab(group) === 'delivery'" class="mt-3">
                        <p
                            :class="group.receipt.statusClass"
                            class="flex gap-2 p-2.5 text-xs leading-relaxed border rounded-lg"
                        >
                            <i :class="[group.receipt.icon, group.receipt.iconClass]" class="mt-0.5 flex-none"></i>
                            <span>{{ group.receipt.statusLine }}</span>
                        </p>

                        <!--
                            Docket and name can both be blank on a receipt saved in a hurry, and the
                            dash is deliberate: an empty row still says the question was asked.
                        -->
                        <dl
                            v-if="group.goodsReceipt.received"
                            class="grid grid-cols-[auto,1fr] gap-x-4 gap-y-2 p-3 mt-2 text-sm border border-gray-200 rounded-lg bg-gray-50"
                        >
                            <dt class="text-gray-500">Received</dt>
                            <dd class="text-gray-900">{{ group.goodsReceipt.received_at }}</dd>

                            <dt class="text-gray-500">By</dt>
                            <dd class="text-gray-900">{{ group.goodsReceipt.received_by ?? '—' }}</dd>

                            <dt class="text-gray-500">Docket</dt>
                            <dd class="text-gray-900">{{ group.goodsReceipt.docket_number ?? '—' }}</dd>

                            <dt class="text-gray-500">Quantity correct</dt>
                            <dd class="text-gray-900">{{ checkLabel(group.goodsReceipt.quantity_verified) }}</dd>

                            <dt class="text-gray-500">Grade correct</dt>
                            <dd class="text-gray-900">{{ checkLabel(group.goodsReceipt.grade_verified) }}</dd>

                            <template v-if="group.goodsReceipt.nonconformance">
                                <dt class="text-gray-500">Problem</dt>
                                <dd class="text-gray-900">{{ group.goodsReceipt.nonconformance }}</dd>
                            </template>

                            <template v-if="group.goodsReceipt.note">
                                <dt class="text-gray-500">Note</dt>
                                <dd class="text-gray-900 whitespace-pre-line">{{ group.goodsReceipt.note }}</dd>
                            </template>
                        </dl>
                    </div>
                </div>
            </div>
        </div>
    </Modal>
</template>
