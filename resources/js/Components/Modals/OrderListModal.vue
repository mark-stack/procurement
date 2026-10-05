<script setup>
    /**
     * What to buy for a batch, a block per supplier group.
     *
     * The lines are built in Shared/shared.js, so the merchant's copy and this one cannot word the
     * same order differently. This is for reading and copying: nothing here sends, quotes or orders.
     *
     * Each block has the stock to buy, and - for the merchants whose material comes with one - the
     * certificates that arrived with it. They are one merchant's business either way, so they are one
     * card rather than two places to look.
     *
     * One block can also be marked quoted and then ordered from here, for the merchant somebody rang
     * rather than sent a quote request to - see markQuoted() and markOrdered(). Nothing is asked for
     * or placed by either.
     *
     * The open batch is the exception to all of it. That card is still taking material, its nest is a
     * suggestion that changes with the next upload, and anything bought off it would be bought twice -
     * so the list is drawn to be read and nothing else: no Copy, no Ordered, and the lines carry a
     * watermark saying so.
     */
    //General Imports
    import {computed, onBeforeUnmount, ref} from "vue";
    import {useForm} from "@inertiajs/vue3";

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

            return {
                ...group,
                lines,
                tabs: tabsFor(group),
            };
        })
        .filter(group => group.lines.length > 0));

    /*
     * The card with no batch row behind it - the one everything waiting is sitting on. Its nest is a
     * suggestion rather than a decision: the next upload changes it, and "Start quoting" is what turns
     * it into a batch worth buying. So the list is readable and nothing more. See the template.
     */
    const isOpenBatch = computed(() => (props.orderList?.batch_id ?? null) === null);

    /**
     * The tabs of one block, and whether each has anything behind it.
     *
     * Materials is every block's. Certificates belongs only to the merchants whose products come
     * with one - products.certificates, the same flag the BOM's certificate column reads - because
     * timber and fasteners are never going to have a mill certificate and a tab offering theirs is
     * a question about paperwork that does not exist.
     *
     * Where it is drawn and nothing has arrived it is disabled rather than hidden, and that is the
     * point of it: a greyed-out Certificates on the steel says the paperwork is still outstanding,
     * which is a thing somebody needs to know. The title says why it is dead, since a disabled
     * button cannot explain itself.
     */
    function tabsFor(group) {
        const tabs = [
            {
                key: 'materials',
                label: 'Materials',
                icon: 'fa-solid fa-list-ul',
                //Always: a group with no stock lengths to buy is filtered out of the modal entirely
                available: true,
                title: 'The stock lengths this merchant has to supply',
            },
        ];

        if (group.certificated) {
            tabs.push({
                key: 'certificates',
                label: 'Certificates',
                icon: 'fa-solid fa-file-lines',
                badge: group.certificates.length > 0 ? String(group.certificates.length) : null,
                available: group.certificates.length > 0,
                title: group.certificates.length > 0
                    ? 'The mill certificates that have come in for this order'
                    : 'No mill certificate has come in for this group yet',
            });
        }

        return tabs;
    }

    //Forms
    //The two presses this modal has - see markQuoted() and markOrdered()
    const formMarkQuoted = useForm({
        supplier_group: null,
    });

    const formMarkOrdered = useForm({
        supplier_group: null,
    });

    //Variables
    const emit = defineEmits(['closeModal', 'refresh']);

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
     * "Quoted" on one block - this merchant's price is in, from somewhere other than here.
     *
     * The step before the one below, and the one a block offers while the batch is still being
     * priced: a nested batch nobody has a price for used to show "Mark as ordered" on every block,
     * which is the press after next - and pressing it is how a card skips the Quoted pill entirely.
     *
     * It asks nobody for anything and writes no quote row, the same way the ordered mark places no
     * order - see BatchMarkGroupQuotedController. Marking every merchant on the batch is the card's
     * "All quoted" said one merchant at a time, and the card reads QUOTED once the last one is in.
     */
    function markQuoted(group) {
        if (formMarkQuoted.processing) {
            return;
        }

        formMarkQuoted.supplier_group = group.supplierGroup;

        formMarkQuoted.post(route('batch.group.quoted', props.orderList.batch_id), {
            preserveScroll: true,
            onSuccess: () => emit('refresh'),
        });
    }

    /**
     * "Ordered" on one block - this merchant has been bought from, somewhere other than here.
     *
     * The batch-wide mark on the Nesting card said of a single merchant, and for the shop that is
     * half on the application and half on the phone: the steel went through the quotes screen and the
     * timber did not, and until now the timber block read "Not ordered" for ever.
     *
     * It places nothing. No order is written, no merchant is contacted - see
     * BatchMarkGroupOrderedController - which is what the button's title says before it is pressed.
     *
     * The answer comes back as a page visit, so the modal refetches rather than editing the block in
     * place: the list behind it is an axios payload, and a mark that only lived in this component
     * would be gone on the next open.
     */
    function markOrdered(group) {
        if (formMarkOrdered.processing) {
            return;
        }

        formMarkOrdered.supplier_group = group.supplierGroup;

        formMarkOrdered.post(route('batch.group.ordered', props.orderList.batch_id), {
            preserveScroll: true,
            onSuccess: () => emit('refresh'),
        });
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
                            How far this merchant has got: priced, then bought. Green carries the
                            purchase order number where somebody has typed one in; an order placed
                            with the number still blank is just as ordered, and says so rather than
                            reading "Order: " with nothing after it.

                            A pill rather than a fourth tab: it is one fact, and a tab holding one
                            line would be a click to read what fits on the heading.
                        -->
                        <div class="flex items-center gap-2 shrink-0">
                            <!--
                                The press for a merchant somebody rang. Beside the pill it changes
                                rather than down in the tab, because it is the answer to the word
                                that pill is showing - and it says "Mark as", because nothing is
                                asked for or bought by pressing it.

                                One button, and which one is the step this block is actually at: a
                                merchant nobody has a price from is marked quoted, and a merchant
                                whose price is in is marked ordered. The block offered the ordered
                                one from the moment the batch was nested, which on a batch still out
                                for prices is the press after next, and taking it is how a card goes
                                from Quoting to Ordered without ever reading Quoted.

                                It is not a gate. A shop that rings one merchant and buys in the
                                same call presses twice here, or presses "All ordered" on the card
                                menu, which is offered from Quoting exactly as it was before - see
                                PrerequisiteConditions::markBatchOrdered.

                                Only where there is something to say: a group already bought has
                                both steps behind it, and the open batch must not be quoted or
                                ordered from at all. Hidden rather than greyed, unlike the card
                                menu's marks - this is a block in a list of blocks, and a dead
                                button on each of them would be the loudest thing in the modal.
                            -->
                            <button
                                v-if="!group.quoted && group.canMarkQuoted && !isOpenBatch"
                                type="button"
                                @click="markQuoted(group)"
                                :disabled="formMarkQuoted.processing"
                                :title="'Record that the price for the '
                                    + shared.supplierGroupLabel(group.supplierGroup)
                                    + ' material is in, without asking for a quote here'"
                                class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold text-blue-800 transition-colors duration-150 bg-white border border-blue-300 rounded-lg shadow-sm hover:bg-blue-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400"
                            >
                                <i class="fa-solid fa-tags text-[10px]"></i>
                                Mark as quoted
                            </button>

                            <button
                                v-else-if="!group.ordered && group.canMarkOrdered && !isOpenBatch"
                                type="button"
                                @click="markOrdered(group)"
                                :disabled="formMarkOrdered.processing"
                                :title="'Record that you have ordered the '
                                    + shared.supplierGroupLabel(group.supplierGroup)
                                    + ' material, without placing an order here'"
                                class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold text-indigo-800 transition-colors duration-150 bg-white border border-indigo-300 rounded-lg shadow-sm hover:bg-indigo-50 disabled:cursor-not-allowed disabled:border-gray-200 disabled:text-gray-400"
                            >
                                <i class="fa-solid fa-cart-shopping text-[10px]"></i>
                                Mark as ordered
                            </button>

                            <!--
                                And the word for where it has got to, in the colour the card's own
                                pill uses for that step: the price in but nothing bought is blue,
                                the way "All quoted" is on the card menu, and it is one state rather
                                than two pills because a bought merchant was priced first.
                            -->
                            <p
                                :class="group.ordered
                                    ? 'text-green-800 bg-green-100'
                                    : (group.quoted
                                        ? 'text-blue-800 bg-blue-100'
                                        : 'text-yellow-800 bg-yellow-100')"
                                class="inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold rounded-lg shrink-0"
                            >
                                <i
                                    :class="group.ordered || group.quoted
                                        ? 'fa-solid fa-check'
                                        : 'fa-regular fa-clock'"
                                    class="text-[10px]"
                                ></i>
                                <template v-if="group.ordered">
                                    {{ group.purchaseOrderNumber ? 'Order: ' + group.purchaseOrderNumber : 'Ordered' }}
                                </template>
                                <template v-else-if="group.quoted">
                                    Quoted
                                </template>
                                <template v-else>
                                    Not quoted
                                </template>
                            </p>
                        </div>
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

                            On the open batch it carries a watermark instead of a Copy button. The
                            lines are real - this is what the nest would need if it were bought today
                            - and that is exactly the danger: the card is still taking uploads, so
                            the list changes under anybody who acts on it. Over the lines rather than
                            beside them, because a note under a block of text that reads like an
                            order is a note nobody sees.

                            Which is why the block is held open to the stamp's height on that card: a
                            group can be one line long, and the stamp lies across it at an angle, so
                            an unheld block cropped the warning to its middle two words. The height
                            is the rotated stamp's, a word of slack either side, and it steps with
                            the stamp's own breakpoint. Nothing holds a closed batch's list open -
                            there is no stamp on it, and the lines are the whole of what it says.
                        -->
                        <div class="relative mt-3">
                            <!--
                                Room at the top right for the Copy button that sits in it, so the
                                first lines stop short of the button rather than running under it.
                                A line long enough to reach it is long enough to scroll, and the
                                button is opaque: scrolled far enough, text passes behind it and is
                                read by scrolling on. The padding is what keeps that rare rather
                                than routine - most sections and grades are well short of it.
                            -->
                            <pre
                                :class="isOpenBatch ? 'min-h-[5.5rem] sm:min-h-[6.5rem]' : null"
                                class="p-3 pr-24 overflow-x-auto text-sm text-gray-800 rounded-lg bg-gray-50"
                            >{{ group.lines.join('\n') }}</pre>

                            <!--
                                In the corner of the lines it copies, rather than under them or in
                                the heading. This is the one tab whose contents go into a mail, and a
                                Copy button up beside the supplier's name would sit next to the
                                Certificates tab as well - inviting somebody to think it copies those.

                                One button per group rather than one for the modal: the lists go to
                                different suppliers, and nobody sends a timber merchant the steel.

                                Not drawn on the open batch at all. Copying is how this list leaves
                                the screen and reaches a merchant, and that card's nest is a
                                suggestion the next upload changes - which is what the watermark
                                below occupies this same corner to say.
                            -->
                            <button
                                v-if="!isOpenBatch"
                                type="button"
                                @click="copyGroup(group)"
                                :title="'Copy the ' + shared.supplierGroupLabel(group.supplierGroup) + ' list'"
                                class="absolute inline-flex items-center h-8 gap-1.5 px-2.5 text-xs font-semibold text-gray-600 transition-colors duration-150 bg-white border border-gray-300 rounded-lg shadow-sm top-2 right-2 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-800"
                            >
                                <i
                                    :class="copiedGroup === group.supplierGroup
                                        ? 'fa-solid fa-check text-green-600'
                                        : 'fa-regular fa-copy'"
                                    class="text-[11px]"
                                ></i>
                                {{ copiedGroup === group.supplierGroup ? 'Copied' : 'Copy' }}
                            </button>

                            <div
                                v-if="isOpenBatch"
                                aria-hidden="true"
                                class="absolute inset-0 flex items-center justify-center overflow-hidden pointer-events-none"
                            >
                                <span class="px-4 py-1 text-lg font-black tracking-widest uppercase -rotate-12 rounded text-red-600/30 ring-4 ring-red-600/20 sm:text-2xl">
                                    Do not order
                                </span>
                            </div>
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

                </div>
            </div>
        </div>
    </Modal>
</template>
