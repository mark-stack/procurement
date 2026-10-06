<script setup>
    //General Imports
    import {ref} from "vue";
    import {Head, Link} from "@inertiajs/vue3";
    import axios from "axios";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import BatchBomModal from "@/Components/Modals/BatchBomModal.vue";
    import OrderListModal from "@/Components/Modals/OrderListModal.vue";

    //Props
    const props = defineProps({
        pastBatches: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    /**
     * Which card is opening its nest, by batch id - not a single flag for the page.
     *
     * One shared flag put "Calculating..." on every button at once, which on a list that only
     * ever grows is most of the screen saying it is busy because one row is. The board does the
     * same thing with loadingBatchId, for the same reason.
     */
    const loadingBatchId = ref(null);

    /*
     * The two modals the cards open, and the state each needs - NestingIndex.vue's, to the letter.
     * Both are fetched when the modal opens rather than sent with the page: this list only grows,
     * and shipping every closed batch's material list with it would be the one unbounded thing on
     * the screen. See the comments on showBom() and showOrderList() below.
     */
    const showBomModal = ref(false);
    const bomTitle = ref(null);
    const bomData = ref(null);
    const bomLoadFailed = ref(false);
    //Which card's answer is wanted, so a slow one for a card since closed cannot draw into another
    const bomBatchId = ref(null);

    const showOrderListModal = ref(false);
    const orderListTitle = ref(null);
    const orderListData = ref(null);
    const orderListLoadFailed = ref(false);
    const orderListBatchId = ref(null);

    //Shared Methods
    //...

    //Methods
    /**
     * The jobs on the batch, as the card's heading.
     *
     * A batch is bought as one and can carry several projects, so the heading is a list - the same
     * shape the Nesting page's cards use. Held together here as well as drawn below, because it is
     * the hover title too: the names are truncated to the card's width and a batch of a dozen jobs
     * would otherwise be unreadable at exactly the moment you want to know what is on it.
     *
     * No pencil beside any of them, which is the one thing this heading drops. Editing a job from
     * the card moves the date its batch buys to, and this batch has bought - see
     * PrerequisiteConditions::editProject for the rule the pencil is drawn from on the live page.
     */
    function projectNames(batch){
        return (batch.projects ?? []).map(project => project.name).join(", ");
    }

    //What the card is called, for a modal's heading. Every card here has a batch behind it
    function modalTitle(batch){
        return 'Batch #' + batch.id;
    }

    /*
     * The BOM button's second line: the steel behind that table, counted as parts.
     *
     * Not the number of rows in it. Each row is a line of demand with a quantity on it, so a dozen
     * rows can be a hundred cuts off the saw - which is the number that says how big the job was.
     */
    function cutLabel(batch){
        return batch.cutCount.toLocaleString() + (batch.cutCount === 1 ? ' cut' : ' cuts');
    }

    /*
     * The Material order button's second line: how many blocks that list comes in.
     *
     * Supplier categories - steel merchant, timber merchant, fasteners - not the product categories
     * inside them, because each block was a different merchant to send a list to.
     */
    function categoryLabel(batch){
        return batch.categoryCount + (batch.categoryCount === 1 ? ' Category' : ' Categories');
    }

    /**
     * What this batch was costed at, to the dollar.
     *
     * Whole dollars: it is material plus shop-floor time at a rate somebody typed into their
     * profile, so the cents are arithmetic rather than a price anybody was charged.
     */
    function costLabel(batch){
        return '$' + Math.round(Number(batch.cost)).toLocaleString();
    }

    /**
     * The parts this nest could not place, said as parts rather than as a figure.
     */
    function unmadeCutsLabel(batch){
        return batch.unmadeCuts + (batch.unmadeCuts === 1 ? ' cut unplaced' : ' cuts unplaced');
    }

    /**
     * How the delivery went against the day the steel was wanted.
     *
     * Early is reported as on time rather than as "3 days early", because the measure is whether
     * the workshop was held up and nothing was gained by the steel arriving on the Tuesday.
     */
    function deliveryLabel(batch){
        if(batch.daysLate > 0){
            return batch.daysLate + (batch.daysLate === 1 ? ' day late' : ' days late');
        }

        return 'On time';
    }

    /**
     * The batch's material list, across every project on it.
     *
     * The modal opens straight away on its own spinner rather than behind a full-page overlay - the
     * list of cards stays readable underneath, and the card you picked is still visible while its
     * data arrives. The same component the Nesting page opens, because it is the same question:
     * what steel is on this batch. Nothing in it writes here - the one thing it can change is an
     * upload, and the server refuses that the moment the material is on a batch (see
     * MaterialListFile::isDeletable), which every batch on this page is.
     */
    function showBom(batch){
        bomBatchId.value = batch.id;
        bomTitle.value = modalTitle(batch);
        bomData.value = null;
        bomLoadFailed.value = false;
        showBomModal.value = true;

        downloadBom(batch);
    }

    async function downloadBom(batch){
        const id = batch.id;

        try {
            const response = await axios.get(route("download.batch.bom", [id]));

            /*
             * Only accept an answer for the card still on screen. Opening one, closing it and opening
             * another leaves the first request in flight, and it would otherwise win the race and draw
             * the wrong batch's materials.
             */
            if(bomBatchId.value !== id){
                return;
            }

            bomData.value = response.data.batchBom;
        } catch (error) {
            console.error('Error fetching batch BOM:', error);

            if(bomBatchId.value === id){
                bomLoadFailed.value = true;
            }
        }
    }

    function closeBom(){
        showBomModal.value = false;
        bomData.value = null;
        bomTitle.value = null;
        bomBatchId.value = null;
        bomLoadFailed.value = false;
    }

    /**
     * What this batch was bought as, a block per supplier group - with each merchant's certificates.
     *
     * The record of the job rather than a thing to act on: every press that modal carries is gated
     * on the batch being live (PrerequisiteConditions::canChangeBatchItself asks for it last), so a
     * closed batch draws the lists, the deliveries and the paperwork and offers no marks.
     */
    function showOrderList(batch){
        orderListBatchId.value = batch.id;
        orderListTitle.value = modalTitle(batch);
        orderListData.value = null;
        orderListLoadFailed.value = false;
        showOrderListModal.value = true;

        downloadOrderList(batch);
    }

    async function downloadOrderList(batch){
        const id = batch.id;

        try {
            const response = await axios.get(route("batch.order.list", [id]));

            //Only accept an answer for the card still on screen - see downloadBom() above
            if(orderListBatchId.value !== id){
                return;
            }

            orderListData.value = response.data.orderList;
        } catch (error) {
            console.error('Error fetching order list:', error);

            if(orderListBatchId.value === id){
                orderListLoadFailed.value = true;
            }
        }
    }

    function closeOrderList(){
        showOrderListModal.value = false;
        orderListData.value = null;
        orderListTitle.value = null;
        orderListBatchId.value = null;
        orderListLoadFailed.value = false;
    }
</script>

<template>
    <Head title="Past Batches" />

    <AuthenticatedLayout>
        <!--
            The Nesting page's own container and card shape - see NestingIndex.vue. This screen was a
            four-column table with the same spanner icon repeated in three of the cells, and read as
            a different application to the one page the rest of the app now is.
        -->
        <section class="container max-w-4xl px-4 mx-auto py-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-800">
                    Past batches
                </h1>

                <!-- The list only grows, so say how big it is rather than leaving it to scrolling -->
                <span v-if="pastBatches.length" class="text-xs text-gray-500">
                    {{ pastBatches.length }} closed {{ pastBatches.length === 1 ? 'batch' : 'batches' }}
                </span>
            </div>

            <!-- One card per closed batch, full width, newest first - the order the controller sends -->
            <div class="flex flex-col gap-3 mt-4">
                <div
                    v-for="batch in pastBatches"
                    :key="batch.id"
                    class="bg-white border border-gray-200 shadow-sm rounded-xl"
                >
                    <div class="flex items-center justify-between gap-4 px-4 py-3">
                        <!-- The jobs, who they belonged to, and what the batch was - the Nesting card's left half -->
                        <div class="min-w-0">
                            <span
                                :title="projectNames(batch)"
                                class="block text-sm font-semibold text-gray-800 truncate"
                            >
                                {{ projectNames(batch) || 'No projects on this batch' }}
                            </span>

                            <!--
                                The owners of the projects, not whoever pressed "Start quoting" -
                                see PastBatchesController, which joins the distinct managers into
                                this line precisely because a batch can span two of them.
                            -->
                            <span class="block text-xs text-gray-500 truncate">
                                {{ batch.projectManagers }}'s
                            </span>

                            <!-- Who nested it, which is a different question and only worth a line when the answer differs -->
                            <span
                                v-if="batch.batchedBy && batch.batchedBy !== batch.projectManagers"
                                class="block text-xs text-gray-400 truncate"
                            >
                                Nested by {{ batch.batchedBy }}
                            </span>

                            <!--
                                When the batch was nested, and nothing else.

                                The Nesting card's pill row says where a batch has got to and whether
                                it will make its date. Neither is a question here: every card on this
                                page is at the same place - finished - and a step pill that says
                                "Completed" on every row down the page is a column of the same word.
                                The batch's own number went with it: it is on each of the two modals
                                the buttons open, which is where it is any use.
                            -->
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <!-- Which is when created_at was written - see the controller -->
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200">
                                    <i class="fa-regular fa-calendar text-[10px] text-gray-400"></i>
                                    Nested {{ batch.createdAt }}
                                </span>

                                <!--
                                    What the nest achieved, as it was measured at the time. Only on
                                    the batches that have a measurement - every batch nested before
                                    they were recorded prints nothing rather than a zero.
                                -->
                                <span
                                    v-if="batch.efficiency !== null && batch.efficiency !== undefined"
                                    title="The share of the steel this nest consumed that left as a finished part, as it was measured when the nest was saved"
                                    class="inline-flex items-center gap-1.5 rounded-md bg-emerald-50 px-2 py-1 text-[11px] font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-200"
                                >
                                    <i class="fa-solid fa-chart-pie text-[10px] text-emerald-500"></i>
                                    {{ Number(batch.efficiency).toFixed(1) }}% yield
                                </span>

                                <!--
                                    And what it was costed at - the figure the search actually picked
                                    this plan on, not what the same plan would cost at today's rates.
                                    A batch whose rates were not kept with it says so in the hover
                                    rather than quietly reading as a record.
                                -->
                                <span
                                    v-if="batch.cost !== null && batch.cost !== undefined"
                                    :title="batch.costRetained
                                        ? 'Material and labour, costed on the rates in force when this batch was nested'
                                        : 'Nested before the rates were kept with the nest, so this is today\'s valuation of that plan rather than what it was costed at'"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset"
                                    :class="batch.costRetained
                                        ? 'bg-gray-50 text-gray-600 ring-gray-200'
                                        : 'bg-gray-50 text-gray-400 ring-gray-200'"
                                >
                                    <i class="fa-solid fa-coins text-[10px] text-gray-400"></i>
                                    {{ costLabel(batch) }}
                                </span>

                                <!--
                                    And the reason a nested batch may show no cost at all: cuts no
                                    bar or offcut could hold. The nest ranks one of those with a
                                    billion-dollar penalty so nothing can buy its way past a part
                                    the workshop does not get - a ranking device, not money, so it
                                    is reported as what it is.
                                -->
                                <span
                                    v-if="batch.unmadeCuts > 0"
                                    title="These cuts were longer than any length the supplier sells, so the nest could not place them. The yield beside this is of the steel that was nested."
                                    class="inline-flex items-center gap-1.5 rounded-md bg-amber-50 px-2 py-1 text-[11px] font-semibold text-amber-700 ring-1 ring-inset ring-amber-200"
                                >
                                    <i class="fa-solid fa-triangle-exclamation text-[10px] text-amber-500"></i>
                                    {{ unmadeCutsLabel(batch) }}
                                </span>

                                <!--
                                    Whether the steel turned up on the day. Measured when it landed,
                                    against the date that stood then - see BatchMeasurements, and
                                    why that date cannot be looked up again afterwards.
                                -->
                                <span
                                    v-if="batch.daysLate !== null && batch.daysLate !== undefined"
                                    :title="'Delivered ' + batch.deliveredOn"
                                    class="inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-semibold ring-1 ring-inset"
                                    :class="batch.daysLate > 0
                                        ? 'bg-red-50 text-red-700 ring-red-200'
                                        : 'bg-blue-50 text-blue-700 ring-blue-200'"
                                >
                                    <i class="fa-regular fa-clock text-[10px] opacity-60"></i>
                                    {{ deliveryLabel(batch) }}
                                </span>
                            </div>
                        </div>

                        <!--
                            The three reads the Nesting card carries, and only those: a closed batch is
                            a record, so there is no menu beside them and no footer under them asking
                            for anything to be done about it.

                            Wraps rather than squeezes, like the heading beside it - the buttons have
                            fixed widths, which on a phone is more than fits on one line.
                        -->
                        <div class="flex flex-wrap items-center justify-end gap-2 shrink-0">
                            <CardButtonYellow
                                label="BOM"
                                :sublabel="cutLabel(batch)"
                                title="The material list for every project on this batch"
                                class="w-28"
                                @click="showBom(batch)"
                            />

                            <CardButtonYellow
                                label="Material order"
                                :sublabel="categoryLabel(batch)"
                                title="What this batch's nest needed from each merchant, with their certificates and deliveries"
                                class="w-32"
                                @click="showOrderList(batch)"
                            />

                            <!--
                                No usage figure under this one, unlike the live card's. That line is
                                read off the nest saved against the batch, and unpacking one per card
                                for a list with no ceiling is the thing NestingEfficiencyController
                                exists to keep off a page - see the comment there. The nest itself is
                                one press away, where the figure is drawn on the sheet.
                            -->
                            <Link
                                :href="route('batch.nesting',[batch.id,'past',1])"
                                class="w-28"
                                @click="loadingBatchId = batch.id"
                            >
                                <CardButtonBlue
                                    :label="loadingBatchId === batch.id ? 'Calculating...' : 'Nest'"
                                    :highlight="false"
                                />
                            </Link>
                        </div>
                    </div>
                </div>

                <!--
                    The nav only offers this page once a batch has closed (see hasPastBatches), so
                    this is all but unreachable - but "all but" is why it is here rather than an
                    empty page that looks like it failed to load.
                -->
                <div
                    v-if="!pastBatches.length"
                    class="px-4 py-8 text-sm text-center text-gray-500 bg-white border border-gray-200 shadow-sm rounded-xl"
                >
                    Nothing here yet. A batch lands in past batches once its steel is all in and it
                    is marked done.
                </div>
            </div>
        </section>
    </AuthenticatedLayout>

    <!--
        The same two modals the Nesting page opens, drawn outside the layout the way that page draws
        them. No refresh handler on either: both of them only change a live batch, and nothing on
        this page is one.
    -->
    <BatchBomModal
        v-if="showBomModal"
        :title="bomTitle"
        :bom="bomData"
        :loadFailed="bomLoadFailed"
        @closeModal="closeBom()"
    />

    <OrderListModal
        v-if="showOrderListModal"
        :title="orderListTitle"
        :orderList="orderListData"
        :loadFailed="orderListLoadFailed"
        @closeModal="closeOrderList()"
    />
</template>
