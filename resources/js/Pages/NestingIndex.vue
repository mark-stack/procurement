<script setup>
    //General Imports
    import {computed, onMounted, ref} from "vue";
    import {Head, Link} from "@inertiajs/vue3";
    import axios from "axios";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import StagePill from "@/Components/StagePill.vue";
    import OrderByPill from "@/Components/OrderByPill.vue";
    import BatchBomModal from "@/Components/Modals/BatchBomModal.vue";
    import OrderListModal from "@/Components/Modals/OrderListModal.vue";
    import NewProjectModal from "@/Components/Modals/NewProjectModal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Props
    const props = defineProps({
        batches: Array,
        //The colleagues a new project can be created for, for the modal below
        colleagues: {
            type: Array,
            default: () => [],
        },
    });

    //Variables
    /*
     * Which card's button was pressed, not whether any was. Nesting a batch is the slowest read in
     * the app, so the card that was clicked says "Calculating..." and the rest of the column is left
     * alone - a shared flag would relabel every button on the page at once.
     */
    const loadingBatchId = ref(null);

    /*
     * How well each batch nested, keyed by batch id, and the pending card's own figure.
     *
     * Both arrive after the page has drawn, because both are expensive in their own way: the batches
     * mean walking the nest saved against every one of them, and the pending card has no saved nest at
     * all, so its figure comes from running the nesting algorithm - the slowest read in the app. The
     * cards are worth looking at before the percentages land, so they are not held back for them.
     */
    const batchEfficiency = ref(null);
    const pendingUsage = ref(null);

    /*
     * Whether to show only the batches carrying your own jobs, on by default.
     *
     * On, because this is a list that only grows - a business that has been running a year has every
     * batch it ever bought on this page, most of them somebody else's. Your own work is what you came
     * for, and the switch is there for the times you want the shop's whole picture.
     *
     * A batch is yours when one of the projects on it is yours - you are its project manager - which is
     * the same line the board draws when it floats your cards to the top of a column. The server says
     * so per card; see NestingIndexController.
     */
    const onlyMine = ref(true);

    //Computed
    const visibleBatches = computed(() => onlyMine.value
        ? props.batches.filter(batch => batch.mine)
        : props.batches);

    /*
     * How many cards the switch is holding back, so turning it off is an offer rather than a guess.
     * Named for what the user would see, which is why it counts against the whole list.
     */
    const hiddenCount = computed(() => props.batches.length - visibleBatches.value.length);

    //BOM modal
    const showBomModal = ref(false);
    const bomTitle = ref(null);
    const bomData = ref(null);
    const bomLoadFailed = ref(false);
    //Which card the open modal belongs to, so a late answer cannot be drawn into another card's table
    const bomCardKey = ref(null);
    //And the card itself, so the modal can ask for the same batch again after removing a file from it
    const bomBatch = ref(null);

    //Order list modal, which follows the same pattern as the BOM one above
    const showOrderListModal = ref(false);
    const orderListTitle = ref(null);
    const orderListData = ref(null);
    const orderListLoadFailed = ref(false);
    const orderListCardKey = ref(null);

    //New project modal, which is the board's - see addProject()
    const showNewProjectModal = ref(false);
    const newProjectBomData = ref(null);
    const projectAfterUpload = ref(null);
    const refreshNewProject = ref(false);

    //Methods
    /*
     * The pending card has no batch row behind it, so it has no id to key on or to print. Keyed on
     * the stage instead, which is unique: there is at most one pending batch - everything waiting is
     * nested into a single batch when quoting starts.
     */
    function cardKey(batch) {
        return batch.id ?? batch.stage;
    }

    /**
     * Which of the two labels the list is split by belongs above this card, if either.
     *
     * The split is the thing about this page a material list depends on, and nothing on it said so: the
     * pending card is the only batch an upload can still join. Everything waiting is nested into one
     * batch when quoting starts, and from that moment the batch takes no more material - which is why
     * "+ Materials" belongs to the page and not to any card (see addProject()). Two cards that look
     * alike are not where somebody would expect to find that out.
     *
     * Each label is drawn only when the half below it has a card, because the "Only my batches" switch
     * hides whole halves: an "open batch" heading over nothing but closed batches would be labelling
     * them as something they are not.
     */
    function dividerAbove(index) {
        //The pending card, which the server always sends first - the one batch still taking material
        if (visibleBatches.value[index].id === null) {
            return 'OPEN';
        }

        //And the first card below it, pending card or no pending card
        return index === 0 || visibleBatches.value[index - 1].id === null ? 'CLOSED' : null;
    }

    /**
     * The card's efficiency, and whether it is still coming.
     *
     * Null for a batch the efficiency request answered nothing for: those are batches with no nest
     * saved against them (see NestingEfficiencyController), which have no figure to wait for - the
     * button loses its second line rather than claiming forever to be calculating one.
     */
    function efficiencyOf(batch) {
        return batch.stage === 'NESTING'
            ? pendingUsage.value?.METERAGE?.efficiency
            : batchEfficiency.value?.[batch.id];
    }

    function efficiencyLoading(batch) {
        return batch.stage === 'NESTING'
            ? pendingUsage.value === null
            : batchEfficiency.value === null;
    }

    /**
     * The Nest button's second line.
     *
     * Three states, the way EfficiencyPill draws them on the board: a figure, a figure still coming,
     * and nothing to say - the last for a batch with no nest saved against it, which has no percentage
     * on the way and must not sit there claiming to be calculating one.
     */
    function efficiencyLabel(batch) {
        const efficiency = efficiencyOf(batch);

        if (efficiency > 0) {
            return efficiency + '% usage';
        }

        return efficiencyLoading(batch) ? 'calculating...' : null;
    }

    /*
     * The BOM button's second line: the steel behind that table, counted as parts.
     *
     * Not the number of rows in it. Each row is a line of demand with a quantity on it, so a dozen
     * rows can be a hundred cuts off the saw - which is the number that says how big the job is.
     */
    function cutLabel(batch) {
        return batch.cutCount.toLocaleString() + (batch.cutCount === 1 ? ' cut' : ' cuts');
    }

    /*
     * The Order list button's second line: how many blocks that list comes in.
     *
     * Supplier categories - steel merchant, timber merchant, fasteners - not the product categories
     * inside them, because each block is a different merchant to send a list to, and that is the work
     * the number is describing.
     */
    function categoryLabel(batch) {
        return batch.categoryCount + (batch.categoryCount === 1 ? ' Category' : ' Categories');
    }

    /**
     * The projects the card is headed by.
     *
     * Your own work on the batch, all of it - a card headed by one job while another of yours is
     * quietly inside it is a card you would scroll past looking for that job. Several names make a long
     * heading, so it truncates and the whole of it is on hover.
     *
     * A batch with none of yours on it - which only the "Only my batches" switch can show you - is
     * headed by the oldest job on it instead, named with its manager. Every card then says what the
     * work is rather than what number it was given.
     *
     * The pending card is deliberately not headed by a job: it stands for everything waiting, which is
     * about to become one batch, and no one of those jobs is more that batch than the others.
     */
    function headedProjects(batch) {
        if (batch.id === null) {
            return [];
        }

        const mine = batch.projects.filter(project => project.mine);

        return mine.length ? mine : batch.projects.slice(0, 1);
    }

    /*
     * The rest of the batch - what the "other projects" label counts and lists. Everything the heading
     * does not already name, which on the pending card is all of it.
     */
    function otherProjects(batch) {
        const headed = headedProjects(batch);

        return batch.projects.filter(project => !headed.includes(project));
    }

    function projectNames(projects) {
        return projects.map(project => project.name).join(', ');
    }

    function cardTitle(batch) {
        if (batch.id === null) {
            return 'Open batch';
        }

        /*
         * A batch with no job on it at all falls back to its number. Nothing in the app makes one -
         * every batch is nested out of projects - but a card has to say something, and a blank heading
         * on a card carrying BOM and Order list buttons would read as a broken page.
         */
        return projectNames(headedProjects(batch)) || ('Batch #' + batch.id);
    }

    /*
     * Whose job the heading is naming, printed only when it is not yours - the board says the owner the
     * same way, because one manager's steel ending up on another's cutting list starts with not knowing
     * whose job you are looking at.
     */
    function headingManager(batch) {
        const headed = headedProjects(batch);

        if (headed.length !== 1 || headed[0].mine) {
            return null;
        }

        return headed[0].manager
            ? shared.capitalizeWords(headed[0].manager)
            : 'Another project manager';
    }

    function otherProjectsLabel(batch) {
        const others = otherProjects(batch);

        /*
         * "Other" than the jobs in the heading - so on the pending card, whose heading names none of
         * them, they are not other than anything and the line is simply the count.
         */
        if (batch.id === null) {
            return others.length + (others.length === 1 ? ' project' : ' projects');
        }

        return others.length + (others.length === 1 ? ' other project' : ' other projects');
    }

    /*
     * The modals are a whole batch's material and a whole batch's stock to buy, across every job on it,
     * so they are headed by the batch rather than by the one job the card is headed by - see showBom().
     */
    function modalTitle(batch) {
        //Nothing to number until "Start quoting" writes the batch row
        return batch.id ? ('Batch #' + batch.id) : 'Open batch';
    }

    /*
     * Where the Nest button goes.
     *
     * The same destinations the rest of the app uses, so this page is a way into the nesting screens
     * rather than another copy of them:
     *
     *  - NESTING has no batch to ask for yet, so suggested-nesting re-nests everything waiting.
     *  - A live batch opens its own saved nest, closing back to the board (KanbanMinimalCard).
     *
     * Always "current", there being no finished batch on this page to close back to /past-projects -
     * those are read from /past-projects itself. See NestingIndexController.
     */
    function nestingHref(batch) {
        if (batch.stage === 'NESTING') {
            return route('suggested.nesting');
        }

        return route('batch.nesting', [batch.id, 'current', 1]);
    }

    /**
     * The batch's material list, across every project on it.
     *
     * The modal opens straight away on its own spinner rather than behind a full-page overlay - the
     * list of cards stays readable underneath, and the card you picked is still visible while its
     * data arrives. Same shape as the board's quotes modal, for the same reason.
     */
    function showBom(batch) {
        bomCardKey.value = cardKey(batch);
        bomBatch.value = batch;
        bomTitle.value = modalTitle(batch);
        bomData.value = null;
        bomLoadFailed.value = false;
        showBomModal.value = true;

        downloadBom(batch);
    }

    /**
     * Fetch the open modal's batch again, after it has changed something.
     *
     * Removing an uploaded file takes its materials off the batch, so the table it is listed above is
     * now wrong - and the card behind the modal is too, since its second line counts the cuts. The
     * delete itself is an Inertia request, so the page props have already come back with the new
     * count by the time this runs; this is only the modal's own fetch, which Inertia knows nothing
     * about.
     */
    function refreshBom() {
        if (bomBatch.value === null) {
            return;
        }

        bomData.value = null;
        bomLoadFailed.value = false;

        downloadBom(bomBatch.value);
    }

    async function downloadBom(batch) {
        const key = cardKey(batch);

        try {
            //No id on the pending card: the route's batch is optional, and absent means "what is waiting"
            const response = await axios.get(route("download.batch.bom", batch.id ? [batch.id] : []));

            /*
             * Only accept an answer for the card still on screen. Opening one, closing it and opening
             * another leaves the first request in flight, and it would otherwise win the race and draw
             * the wrong batch's materials.
             */
            if (bomCardKey.value !== key) {
                return;
            }

            bomData.value = response.data.batchBom;
        } catch (error) {
            console.error('Error fetching batch BOM:', error);

            if (bomCardKey.value === key) {
                bomLoadFailed.value = true;
            }
        }
    }

    /**
     * What to buy for this batch, a block per supplier group.
     *
     * The same lists the quotes/orders modal writes into a supplier email, read off the nest the
     * batch was bought on. Opens on its own spinner like the BOM above - for the pending card the
     * answer means running the nesting algorithm, which is not a wait to put a blank modal in front
     * of.
     */
    function showOrderList(batch) {
        orderListCardKey.value = cardKey(batch);
        orderListTitle.value = modalTitle(batch);
        orderListData.value = null;
        orderListLoadFailed.value = false;
        showOrderListModal.value = true;

        downloadOrderList(batch);
    }

    async function downloadOrderList(batch) {
        const key = cardKey(batch);

        try {
            //No id on the pending card: absent means "what the Nesting column would need"
            const response = await axios.get(route("batch.order.list", batch.id ? [batch.id] : []));

            //Only accept an answer for the card still on screen - see downloadBom() above
            if (orderListCardKey.value !== key) {
                return;
            }

            orderListData.value = response.data.orderList;
        } catch (error) {
            console.error('Error fetching order list:', error);

            if (orderListCardKey.value === key) {
                orderListLoadFailed.value = true;
            }
        }
    }

    function closeOrderList() {
        showOrderListModal.value = false;
        orderListData.value = null;
        orderListTitle.value = null;
        orderListCardKey.value = null;
        orderListLoadFailed.value = false;
    }

    /*
     * Two requests rather than one, each answering what it alone can: the saved nests of the batches,
     * and - from the endpoint the board's Nesting card already uses - the suggestion for the pending
     * card. Keeping them apart means the cheap half is not held up behind the nesting algorithm, and
     * the pending figure is read off the same place the board reads it.
     *
     * A failure leaves the button saying "calculating" rather than saying something wrong about a
     * nest. The console carries the reason, as it does for the board's own usage request.
     */
    async function downloadEfficiency() {
        try {
            const response = await axios.get(route("nesting.efficiency"));

            batchEfficiency.value = response.data.efficiency ?? {};
        } catch (error) {
            console.error('Error fetching batch efficiency:', error);
        }
    }

    async function downloadPendingUsage() {
        try {
            const response = await axios.get(route("download.usage.data"));

            pendingUsage.value = response.data.usageData ?? {};
        } catch (error) {
            console.error('Error fetching usage data:', error);
        }
    }

    function closeBom() {
        showBomModal.value = false;
        bomData.value = null;
        bomTitle.value = null;
        bomCardKey.value = null;
        bomBatch.value = null;
        bomLoadFailed.value = false;
    }

    /**
     * A new project and the material list that starts it - the board's own modal, opened from here.
     *
     * It is the same component the board draws because it is the same job, clarifications and custom
     * products included: a second upload form would be a second place for a BOM to half-import. What
     * it creates always lands on the pending card at the top of this page, which is why the button
     * belongs to the page rather than to any one card - a batch that has been quoted cannot take new
     * material.
     *
     * The post goes to projects.store and comes back to this page, so the cards redraw with the new
     * project counted - there is nothing to reload by hand.
     */
    function addProject() {
        newProjectBomData.value = null;
        projectAfterUpload.value = null;
        showNewProjectModal.value = true;
    }

    /**
     * The BOM of the project just created, which the modal asks for by emitting "redownload".
     *
     * That modal freezes itself on "Calculating..." until this answers, so it has to be signalled
     * either way - a failed fetch would otherwise leave it spinning with no way back. Same handshake
     * as ProjectsBoard::downloadProjectBomData.
     */
    async function downloadNewProjectBom(project) {
        try {
            const response = await axios.get(route("download.bom", project.id));

            if (response.data.downloadedBomData) {
                newProjectBomData.value = response.data.downloadedBomData;
            }
        } catch (error) {
            console.error('Error fetching data:', error);
        }

        projectAfterUpload.value = project;
        //Toggled rather than set: the modal watches it, and a second upload has to signal again
        refreshNewProject.value = !refreshNewProject.value;
        showNewProjectModal.value = true;
    }

    //Lifecycle
    onMounted(() => {
        downloadEfficiency();

        //Only when there is a card waiting for it - this one runs the nesting algorithm
        if (props.batches.some(batch => batch.stage === 'NESTING')) {
            downloadPendingUsage();
        }
    });
</script>

<template>
    <Head title="Nesting" />

    <AuthenticatedLayout>
        <section class="container max-w-4xl px-4 mx-auto py-6">
            <!-- Wraps rather than squeezes: the switch and the button do not fit a phone beside the title -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-800">
                    Nesting
                </h1>

                <div class="flex items-center gap-3">
                    <!--
                        Your own jobs only, on by default - see onlyMine. A switch rather than a
                        checkbox because it is a view the page is held in, not a value being filled in.
                    -->
                    <button
                        type="button"
                        role="switch"
                        :aria-checked="onlyMine"
                        :title="onlyMine
                            ? 'Showing only batches carrying your own projects'
                            : 'Showing every batch in the business'"
                        @click="onlyMine = !onlyMine"
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
                        Only my batches
                    </button>

                    <!--
                        Whatever this creates joins the card at the top of the page, so the button
                        belongs to the page and not to any one card: a batch that has been quoted cannot
                        take new material. See addProject().
                    -->
                    <button
                        type="button"
                        @click="addProject()"
                        class="inline-flex items-center gap-2 px-3 py-2 text-sm font-semibold text-gray-700 transition-colors duration-150 bg-white border-2 border-gray-400 border-dashed rounded-xl hover:border-blue-400 hover:bg-blue-50 hover:text-blue-800"
                    >
                        <i class="fa-solid fa-plus text-xs"></i>
                        Materials
                    </button>
                </div>
            </div>

            <!-- One card per batch, full width, stacked in the order the board's columns run -->
            <div class="flex flex-col gap-3 mt-4">
                <template v-for="(batch, index) in visibleBatches" :key="cardKey(batch)">
                    <!--
                        The two halves of the list, labelled - see dividerAbove(). The rule runs out to the
                        right of the words so the label reads as a line drawn across the page rather than as
                        another card.
                    -->
                    <div v-if="dividerAbove(index) === 'OPEN'" class="flex items-center gap-2">
                        <i class="fa-solid fa-lock-open text-[11px] text-emerald-600"></i>
                        <span class="text-xs font-semibold text-gray-500">
                            Open batch still accepting materials
                        </span>
                        <span class="flex-1 h-px bg-gray-200"></span>
                    </div>

                    <div v-if="dividerAbove(index) === 'CLOSED'" class="flex items-center gap-2">
                        <i class="fa-solid fa-lock text-[11px] text-gray-400"></i>
                        <span class="text-xs font-semibold text-gray-500">
                            Closed batches locked to further material lists
                        </span>
                        <span class="flex-1 h-px bg-gray-200"></span>
                    </div>

                    <div
                        class="flex items-center justify-between gap-4 px-4 py-3 bg-white border border-gray-200 shadow-sm rounded-xl"
                    >
                        <!-- Wraps rather than squeezes: three chips and a name do not fit a phone in one line -->
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 min-w-0">
                            <div class="min-w-0">
                                <!--
                                    The jobs on the batch, not its number - see headedProjects(). The
                                    full heading is on hover, because several of your own projects on
                                    one batch is a heading too long for the card.
                                -->
                                <span
                                    :title="cardTitle(batch)"
                                    class="block text-sm font-semibold text-gray-800 truncate"
                                >
                                    {{ cardTitle(batch) }}
                                </span>

                                <!-- Whose job that is, when it is not yours - see headingManager() -->
                                <span
                                    v-if="headingManager(batch)"
                                    class="block text-xs text-gray-500 truncate"
                                >
                                    {{ headingManager(batch) }}'s
                                </span>

                                <!--
                                    And the rest of the batch. A count rather than the names, because a
                                    batch is bought as one and can carry a dozen jobs - with the names
                                    on hover, so finding out which they are is not a page away.
                                -->
                                <span
                                    v-if="otherProjects(batch).length"
                                    class="relative block w-fit group"
                                >
                                    <span class="text-xs text-gray-500 underline cursor-help decoration-dotted">
                                        {{ otherProjectsLabel(batch) }}
                                    </span>

                                    <span class="absolute left-0 z-20 hidden p-2 mt-1 bg-white border border-gray-200 shadow-lg pointer-events-none top-full rounded-lg w-max max-w-xs group-hover:block">
                                        <span
                                            v-for="project in otherProjects(batch)"
                                            :key="project.id"
                                            class="block text-xs text-gray-700 truncate"
                                        >
                                            {{ project.name }}
                                            <!-- Named like the heading above: yours says so, a colleague's says who -->
                                            <span class="text-gray-400">
                                                ·
                                                {{ project.mine
                                                    ? 'you'
                                                    : (project.manager
                                                        ? shared.capitalizeWords(project.manager)
                                                        : 'another project manager') }}
                                            </span>
                                        </span>
                                    </span>
                                </span>
                            </div>

                            <!--
                                How far the batch has actually got - the last step it has passed, not the
                                column it is sitting in. Coloured as the board and the dashboard colour
                                that step - see StagePill and NestingIndexController::milestoneOf().
                            -->
                            <StagePill :stage="batch.stage" class="shrink-0" />

                            <!--
                                And, on the pending card only, the day it stops being pending. Only that
                                card has one: the server sends null for every batch that has been nested,
                                because the deadline it was waiting on is spent. See OrderByPill.
                            -->
                            <OrderByPill :date="batch.orderingTriggerDate" class="shrink-0" />
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <!--
                                Everything on the batch, read-only and across all of its projects.
                                CardButtonYellow is itself the button, so the click goes straight on it.
                            -->
                            <CardButtonYellow
                                label="BOM"
                                :sublabel="cutLabel(batch)"
                                title="The material list for every project on this batch"
                                class="w-28"
                                @click="showBom(batch)"
                            />

                            <!-- What to buy, the way the supplier emails word it -->
                            <CardButtonYellow
                                label="Order list"
                                :sublabel="categoryLabel(batch)"
                                title="The stock lengths this batch's nest needs, by supplier group"
                                class="w-32"
                                @click="showOrderList(batch)"
                            />

                            <!--
                                How well it nested goes under the Nest button rather than beside it: it is
                                the result of pressing that button, not another label for the batch. It
                                arrives after the page does, so it says "calculating" to begin with, and
                                nothing at all for a batch nested before the nest was saved against it.
                            -->
                            <Link
                                :href="nestingHref(batch)"
                                class="w-28"
                                @click="loadingBatchId = cardKey(batch)"
                            >
                                <CardButtonBlue
                                    :label="loadingBatchId === cardKey(batch) ? 'Calculating...' : 'Nest'"
                                    :sublabel="efficiencyLabel(batch)"
                                    :highlight="false"
                                />
                            </Link>
                        </div>
                    </div>
                </template>

                <!--
                    Nothing live, which is also what a shop that has finished everything looks like -
                    those batches are on /past-projects, so the message points there rather than
                    claiming the business has never nested anything.
                -->
                <p
                    v-if="batches.length === 0"
                    class="px-4 py-6 text-sm text-center text-gray-500 bg-white border border-gray-200 border-dashed rounded-xl"
                >
                    Nothing to nest. Upload a material list, and what it is waiting on appears here -
                    batches already delivered are under
                    <Link :href="route('past.projects.index')" class="font-semibold text-blue-700 underline hover:text-blue-900">
                        Past projects
                    </Link>.
                </p>

                <!--
                    Or: the business has batches and none of them are yours. Said apart from the message
                    above, because the page is not empty - the switch is holding the rest back, and
                    saying "nothing to nest" to somebody whose colleagues have a dozen jobs on would be
                    a lie. It offers the way out rather than leaving the switch to be found.
                -->
                <div
                    v-else-if="visibleBatches.length === 0"
                    class="px-4 py-6 text-sm text-center text-gray-500 bg-white border border-gray-200 border-dashed rounded-xl"
                >
                    <p>
                        None of your projects are on a live batch.
                    </p>
                    <button
                        type="button"
                        @click="onlyMine = false"
                        class="mt-2 font-semibold text-blue-700 underline hover:text-blue-900"
                    >
                        Show the business's {{ hiddenCount }}
                        {{ hiddenCount === 1 ? 'batch' : 'batches' }}
                    </button>
                </div>
            </div>

            <!--
                And when the switch is hiding some but not all of them, it says so under the list: a
                page that quietly drops a colleague's batch is how two people end up buying the same
                steel. Nothing to say when the switch is off, or when it happens to be hiding nothing.
            -->
            <p
                v-if="onlyMine && hiddenCount > 0 && visibleBatches.length > 0"
                class="mt-3 text-xs text-center text-gray-500"
            >
                {{ hiddenCount }} {{ hiddenCount === 1 ? 'other batch' : 'other batches' }} in the business
                <button
                    type="button"
                    @click="onlyMine = false"
                    class="font-semibold text-blue-700 underline hover:text-blue-900"
                >
                    Show {{ hiddenCount === 1 ? 'it' : 'them' }}
                </button>
            </p>
        </section>
    </AuthenticatedLayout>

    <BatchBomModal
        v-if="showBomModal"
        :title="bomTitle"
        :bom="bomData"
        :loadFailed="bomLoadFailed"
        @closeModal="closeBom()"
        @refresh="refreshBom()"
    />

    <OrderListModal
        v-if="showOrderListModal"
        :title="orderListTitle"
        :orderList="orderListData"
        :loadFailed="orderListLoadFailed"
        @closeModal="closeOrderList()"
    />

    <!--
        v-show, not v-if: this modal carries the upload through clarifications and custom products,
        and unmounting it between those steps would throw away the attempt. The board keeps it
        mounted for the same reason, and tells it when it is reopened through "show".
    -->
    <NewProjectModal
        v-show="showNewProjectModal"
        :show="showNewProjectModal"
        width="550"
        :bomData="newProjectBomData"
        :refreshNewProject="refreshNewProject"
        :projectAfterUpload="projectAfterUpload"
        :colleagues="colleagues"
        @closeModal="showNewProjectModal = false; newProjectBomData = null;"
        @closeModalOnSuccess="showNewProjectModal = false;"
        @redownload="project => downloadNewProjectBom(project)"
    />
</template>
