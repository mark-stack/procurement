<script setup>
    //General Imports
    import {computed, ref} from "vue";
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
     * Whether to show only the batches carrying your own jobs, on by default - the Nesting page's
     * switch, on the page that needs it more.
     *
     * Nothing ever leaves this list: a business a year in has every batch it has ever bought here,
     * and all but a few of them are somebody else's. Your own finished work is what somebody opens
     * this page to look back at, and the switch is there for the times the whole shop's record is
     * what is wanted.
     *
     * A batch is yours when one of the projects on it is yours: a job you manage, or one you
     * uploaded for a colleague. The server says so per card - see PastBatchesController::mineBatchIds.
     *
     * Remembered per browser, under this page's own key: it is a view somebody chooses, and the
     * choice they make about the record of finished work is not the one they make about the live
     * page. Fails quiet, the way the Nesting page's does - a browser that refuses localStorage still
     * has the switch, it just opens on the default next time.
     */
    const ONLY_MINE_KEY = 'pastBatches.onlyMine';

    const onlyMine = ref(readOnlyMinePreference());

    function readOnlyMinePreference(){
        try {
            return window.localStorage.getItem(ONLY_MINE_KEY) !== 'false';
        } catch (error) {
            return true;
        }
    }

    function toggleOnlyMine(value){
        onlyMine.value = value;

        try {
            window.localStorage.setItem(ONLY_MINE_KEY, value ? 'true' : 'false');
        } catch (error) {
            //A browser that will not keep it still has the switch; it is just back on next visit
        }
    }

    //Computed
    /*
     * The cards the switch leaves on the page. Every card here is a closed batch - there is no open
     * batch to hold back the way the Nesting page holds one - so the filter is the whole list.
     *
     * A filter over a list the page already holds, so flicking the switch costs nothing: the server
     * sends every batch whatever it is set to, and each card says whether it is one of yours.
     */
    const visibleBatches = computed(() => onlyMine.value
        ? props.pastBatches.filter(batch => batch.mine)
        : props.pastBatches);

    /*
     * How many cards the switch is holding back, so turning it off is an offer rather than a guess.
     * Named for what the user would see, which is why it counts against the whole list.
     */
    const hiddenCount = computed(() => props.pastBatches.length - visibleBatches.value.length);

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
            <!-- Wraps rather than squeezes: the switch and the count do not fit a phone beside the title -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-800">
                    Past batches
                </h1>

                <div class="flex items-center gap-3">
                    <!--
                        The list only grows, so say how big it is rather than leaving it to scrolling -
                        and say it of what is on the page, the switch having a say in that.
                    -->
                    <span v-if="visibleBatches.length" class="text-xs text-gray-500">
                        {{ visibleBatches.length }} closed {{ visibleBatches.length === 1 ? 'batch' : 'batches' }}
                    </span>

                    <!--
                        Your own jobs only, on by default - see onlyMine. The Nesting page's switch, to
                        the markup: the two pages are read one after the other and a control that
                        looked or behaved differently here would be a second thing to learn.
                    -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="onlyMine"
                        :title="onlyMine
                            ? 'Showing only closed batches carrying your own projects'
                            : 'Showing every closed batch in the business'"
                        @click="toggleOnlyMine(!onlyMine)"
                        class="inline-flex items-center gap-2 text-sm font-medium text-gray-600 transition-colors duration-150 hover:text-gray-800"
                    >
                        <span
                            class="relative inline-flex items-center h-5 transition-colors duration-150 rounded-full w-9 shrink-0"
                            :class="onlyMine ? 'bg-blue-600' : 'bg-gray-300'"
                        >
                            <span
                                class="inline-block w-4 h-4 transition-transform duration-150 bg-white rounded-full shadow"
                                :class="onlyMine ? 'translate-x-[1.125rem]' : 'translate-x-0.5'"
                            ></span>
                        </span>
                        Only my materials
                    </button>
                </div>
            </div>

            <!-- One card per closed batch, full width, newest first - the order the controller sends -->
            <div class="flex flex-col gap-3 mt-4">
                <div
                    v-for="batch in visibleBatches"
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

                <!--
                    The business has closed batches and none of them are yours. The page is not empty -
                    the switch is holding the rest back - so this offers the way out rather than
                    leaving the switch to be found, which on a record nobody presses anything on is
                    the difference between an empty page and a filtered one.
                -->
                <div
                    v-else-if="onlyMine && !visibleBatches.length"
                    class="px-4 py-6 text-sm text-center text-gray-500 bg-white border border-gray-200 border-dashed rounded-xl"
                >
                    <p>
                        None of your projects are on a closed batch.
                    </p>
                    <button
                        type="button"
                        @click="toggleOnlyMine(false)"
                        class="mt-2 font-semibold text-blue-700 underline hover:text-blue-900"
                    >
                        Show the business's {{ hiddenCount }}
                        {{ hiddenCount === 1 ? 'batch' : 'batches' }}
                    </button>
                </div>
            </div>

            <!--
                And when the switch is hiding some but not all of them, it says so under the list: a
                page that quietly drops a colleague's batch is how somebody comes to believe a job was
                never bought. Nothing to say when the switch is off, or when it happens to be hiding
                nothing - and nothing here when it has hidden every batch, which the box above says at
                more length.
            -->
            <p
                v-if="onlyMine && hiddenCount > 0 && visibleBatches.length"
                class="mt-3 text-xs text-center text-gray-500"
            >
                {{ hiddenCount }} {{ hiddenCount === 1 ? 'other batch' : 'other batches' }} in the business
                <button
                    type="button"
                    @click="toggleOnlyMine(false)"
                    class="font-semibold text-blue-700 underline hover:text-blue-900"
                >
                    Show {{ hiddenCount === 1 ? 'it' : 'them' }}
                </button>
            </p>
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
