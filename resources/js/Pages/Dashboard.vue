<script setup>
    //General Imports
    import {Head, Link, router, useForm, usePage} from '@inertiajs/vue3';
    import {computed, onUnmounted, ref, toRefs, watch} from "vue";
    import useConfirm from "@/Shared/useConfirm.js";
    import shared from "@/Shared/shared.js";
    import axios from 'axios';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import KanbanColumn from "@/Components/KanbanColumn.vue";
    import KanbanEmptyState from "@/Components/KanbanEmptyState.vue";
    import NewProjectModal from "@/Components/Modals/NewProjectModal.vue";
    import BomEditModal from "@/Components/Modals/BomEditModal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import PageLoadingOverlay from "@/Components/PageLoadingOverlay.vue";
    import KanbanMinimalCard from "@/Components/KanbanMinimalCard.vue";
    import QuoteOrderModal from "@/Components/Modals/QuoteOrderModal.vue";

    //Props
    const props = defineProps({
        projects: Object,
        batches: Object,
        archivedProjects: Object,
        prerequisiteStartQuoting: Boolean,
        /**
         * The business's other staff, for the new-project modal's "whose job is this" select -
         * see User::colleagueOptions. Empty on a one-person business, and the modal then asks
         * nothing.
         */
        colleagues: {
            type: Array,
            default: () => [],
        },
    });

    //Forms
    const formProjectDelete = useForm({});
    const formQuoteStore = useForm({});

    //Shared data
    //

    //Variables
    const editProject = ref(null);
    const bomProject = ref(null);
    const showArchivedProjects = ref(false);
    const showNewProjectModal = ref(false);
    const showBomEditModal = ref(false);
    const refreshModalBom = ref(false);
    const refreshNewProject = ref(false);
    const bomData = ref([]);
    const bomLoadFailed = ref(false);
    const pageLoading = ref(false);
    const usageData = ref(null);
    const modalCanUpload = ref(false);
    const projectAfterUpload = ref(null);
    const showQuoteOrderModal = ref(false);
    const quotesData = ref(null);
    const quotesDataBatchId = ref(null);
    const quotesLoadFailed = ref(false);

    //Computed
    //Card counts per column, for the badge in each column header
    const nestingProjects = computed(() => props.projects['READY_FOR_NESTING'].projects.data);

    /*
     * Projects whose import stopped at a price book clarification. They are excluded from the
     * column above - and from every other screen - so until now they were on nobody's board at
     * all, their owner's included. They count towards the column's badge because they are work
     * sitting in this step.
     */
    const unfinishedImports = computed(() => props.projects['READY_FOR_NESTING'].unfinishedImports?.data ?? []);
    const quotedBatches = computed(() => props.batches['QUOTED']);
    const orderedBatches = computed(() => props.batches['ORDERED']);
    const deliveredBatches = computed(() => props.batches['DELIVERED']);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();


    //Methods
    /**
     * Whose unfinished import this is, and who has the spreadsheet it stopped on.
     *
     * The manager first, because the project is theirs - then the uploader, where somebody uploaded
     * it for them. Both of them can finish it, and the point of the line is to tell a colleague who
     * to go and ask.
     */
    function unfinishedImportOwnerLabel(project){
        const authUserId = usePage().props.auth.user?.id;
        const owner = project.user_id === authUserId
            ? 'You'
            : shared.capitalizeWords(project.projectManagerName);

        if(!project.uploadedByName){
            return owner;
        }

        const uploader = project.created_by_user_id === authUserId
            ? 'you'
            : shared.capitalizeWords(project.uploadedByName);

        return owner + ', uploaded by ' + uploader;
    }

    function sendRefreshModalBom(){
        refreshModalBom.value = !refreshModalBom.value; // Toggle refreshModalBom
    }

    function sendRefreshNewProject(project){
        console.log("sendRefreshNewProject");
        refreshNewProject.value = !refreshNewProject.value; // Toggle refreshNewProject

        projectAfterUpload.value = project;
        console.log("projectAfterUpload",projectAfterUpload.value);
    }

    function submitArchiveToggle(id){
        /*
         * One at a time. Inertia cancels a visit when the next one starts, and a cancelled
         * visit reports nothing back - so restoring two projects in quick succession left
         * the first one looking like it never happened.
         */
        if(formProjectDelete.processing){
            return;
        }

        //page loader ON
        pageLoading.value = true;

        let url = route("projects.destroy",id);
        formProjectDelete.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                //Hide archived projects if now zero items
                if(props.archivedProjects.data.length === 0){
                    showArchivedProjects.value = false;
                }
            },
            /*
             * A restore can be refused - the name it wants back may have been given to another
             * project while it was archived. This used to console.log it and nothing else, so the
             * loader cleared, the project stayed in the archived list, and no reason was given.
             */
            onError: errors => {
                console.log('errors',errors);

                askToConfirm({
                    title: "Could not do that",
                    message: errors.archive ?? "Something went wrong and nothing has changed. Please try again.",
                    confirmLabel: "OK",
                    tone: "danger",
                    acknowledgeOnly: true,
                    onConfirmed: () => {},
                });
            },
            /*
             * onFinish, not onSuccess/onError. The prerequisite gate aborts 403 and Inertia
             * calls neither for that, so the full-page overlay stayed up over a board that
             * had not changed, with nothing left to dismiss it.
             */
            onFinish: () => {
                //page loader OFF
                pageLoading.value = false;
            },
        });
    }

    function toggleArchive(project) {
        //The card and the archived list both show the name this way
        const name = shared.capitalizeWords(project.name);

        askToConfirm(project.archive
            ? {
                title: "Restore this project?",
                //Not necessarily back to nesting - a project archived while it was on a batch
                //returns to that batch, and saying "ready for nesting" promised otherwise
                message: `“${name}” will move back onto your board.`,
                confirmLabel: "Restore project",
                tone: "primary",
                onConfirmed: () => submitArchiveToggle(project.id),
            }
            : {
                title: "Archive this project?",
                message: `“${name}” will be removed from your board. You can restore it later if you choose.`,
                confirmLabel: "Archive project",
                tone: "danger",
                onConfirmed: () => submitArchiveToggle(project.id),
            }
        );
    }

    function editMode(project){
        editProject.value = project;

        showNewProjectModal.value = true;
    }

    function quoteNow(){
        let url = route("quotes.store");

        formQuoteStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            /*
             * The board redraws either way, so a rolled back "start quoting" used to look exactly
             * like a successful one: the loader cleared, nothing moved to Quoting, no reason given.
             */
            onError: errors => {
                console.log('errors',errors);

                askToConfirm({
                    title: "Could not start quoting",
                    message: errors.batch ?? "Something went wrong and the nesting was not saved, so nothing has changed. Please try again.",
                    confirmLabel: "OK",
                    tone: "danger",
                    acknowledgeOnly: true,
                    onConfirmed: () => {},
                });
            },
            /*
             * onFinish, not onSuccess/onError - the prerequisite gate aborts 403 and Inertia calls
             * neither for that, which would leave the overlay up with nothing to dismiss it.
             */
            onFinish: () => {
                //Remove page loader
                pageLoading.value = false;
            },
        });
    }

    function addProject(){
        //Modal visibility
        showNewProjectModal.value = true;

        //Disable edit mode
        editProject.value = null;
    }

    function showBom(args){
        let project = args;
        console.log("project",project);
        let canUpload = project.prerequisiteUploadMaterials;

        //Set project
        bomProject.value = project;
        modalCanUpload.value = canUpload;

        downloadProjectBomData(bomProject.value,"BOM");
    }

    /**
     * Quotes and orders for one batch. The modal opens straight away on its own spinner rather than
     * behind the full-page overlay - the board stays readable underneath, and the batch you picked
     * is still visible while its data arrives.
     */
    function showQuoteOrders(batchId){
        quotesDataBatchId.value = batchId;
        quotesData.value = null;
        showQuoteOrderModal.value = true;

        downloadQuotesData(batchId);
    }

    async function downloadQuotesData(batchId){
        quotesLoadFailed.value = false;

        try {
            const response = await axios.get(route("download.quotes.data",batchId));

            /*
             * Only accept an answer for the batch still on screen. Opening one card, closing it and
             * opening another leaves the first request in flight, and it used to win the race and
             * draw the wrong batch's suppliers.
             */
            if(quotesDataBatchId.value !== batchId){
                return;
            }

            quotesData.value = response.data.quotesData;
        } catch (error) {
            console.error('Error fetching quotes data:', error);

            if(quotesDataBatchId.value === batchId){
                quotesLoadFailed.value = true;
            }
        }
    }

    function closeQuoteOrders(){
        showQuoteOrderModal.value = false;
        quotesData.value = null;
        quotesDataBatchId.value = null;
        quotesLoadFailed.value = false;
    }

    async function getUsageData(){
        /**
         Axios
         */
        try {
            const response = await axios.get(route("download.usage.data"));

            if(response.data.usageData){
                usageData.value = response.data.usageData;
            }
        } catch (error) {

        } finally {

        }
    }
    getUsageData();

    async function downloadProjectBomData(project,whichModal){
        /**
            Axios request that returns:
            - project
            - unimported BOM items
            - partialProductMatches
            - requiring custom
            - nesting
            - quote and order statuses
         */
        bomLoadFailed.value = false;
        let loaded = false;

        try {
            const response = await axios.get(route("download.bom",project.id));

            if(response.data.downloadedBomData){
                bomData.value = response.data.downloadedBomData;
                loaded = true;
            }
        } catch (error) {
            console.error('Error fetching data:', error);
        }

        //Remove page loader
        pageLoading.value = false;
        bomLoadFailed.value = !loaded;

        /**
         * Signal the modal either way. A modal that asked for this redownload has frozen
         * itself on "Calculating...", and only this signal releases it - so a failed
         * fetch used to leave it spinning with no way back.
         */
        if(whichModal === 'BOM'){
            sendRefreshModalBom();
            showBomEditModal.value = true;
        }
        if(whichModal === 'NEW_PROJECT'){
            sendRefreshNewProject(project);
            showNewProjectModal.value = true;
        }
    }

    function pageLoaderTimer(seconds){
        pageLoading.value = true;

        if(seconds){
            let milliseconds = seconds*1000;
            setTimeout(() => {
                pageLoading.value = false;
            }, milliseconds);
        }
    }


    /**
     * The board is drawn once and never refreshes itself, and every action on it is answered by a
     * prerequisite gate that aborts 403. Nothing here is per-user: a colleague pressing "Start
     * quoting" moves every project in the Nesting column, so the most likely reason your button
     * just failed is that somebody else got there first.
     *
     * A bare abort() is not an Inertia response, so it reaches neither onSuccess nor onError - the
     * overlay cleared (the calls all use onFinish for exactly this reason) and then nothing
     * happened at all, with no reason given and a board still showing the state that no longer
     * exists. Inertia raises "invalid" for that response, which is the one place it can be caught.
     */
    const stopListeningForStaleBoard = router.on('invalid', (event) => {
        if(event.detail.response?.status !== 403){
            return;
        }

        //Don't let the error modal Inertia would otherwise show take over the page
        event.preventDefault();

        pageLoading.value = false;

        askToConfirm({
            title: "This board has moved on",
            message: "That is no longer possible - someone else in your business has changed this board "
                + "since it was loaded. Reloading it now so you can see where things stand.",
            confirmLabel: "OK",
            tone: "danger",
            acknowledgeOnly: true,
            onConfirmed: () => router.reload({preserveScroll: true}),
        });
    });

    onUnmounted(() => stopListeningForStaleBoard());

    //Watcher
    const { batches } = toRefs(props);
    watch(batches, (newVal) => {
        getUsageData();
    });

    const { projects } = toRefs(props);
    watch(projects, (newVal) => {
        //Remove page loader
        pageLoading.value = false;
    });

</script>

<template>
    <Head title="Projects" />

    <AuthenticatedLayout>
        <PageLoadingOverlay
            v-if="pageLoading"
        />

        <!-- Mobile view -->
        <div class="flex justify-center px-6 py-16 md:hidden">
            <div class="w-full max-w-sm rounded-xl border border-gray-200 bg-white p-8 text-center shadow-sm">
                <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-blue-50 text-blue-800">
                    <i class="fa-solid fa-display"></i>
                </div>
                <h1 class="mt-4 text-lg font-semibold text-gray-900">Desktop required</h1>
                <p class="mt-2 text-sm leading-relaxed text-gray-500">
                    The nesting board needs a wider screen. Please sign in from a desktop computer.
                </p>
                <Link
                    :href="route('logout')"
                    method="post"
                    class="mt-6 inline-flex items-center justify-center gap-2 rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-semibold text-gray-700 shadow-sm transition-colors duration-150 hover:bg-gray-50"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-4 w-4">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6a2.25 2.25 0 00-2.25 2.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15M12 9l-3 3m0 0l3 3m-3-3h12.75" />
                    </svg>
                    Logout
                </Link>
            </div>
        </div>

        <!-- Desktop view -->
        <!--
            Fills what the layout gives it, rather than measuring the screen and subtracting the
            nav: that arithmetic knew nothing about the impersonation and billing banners, so the
            board hung exactly one banner below the bottom of the window whenever one was up.
        -->
        <section class="mx-auto hidden min-h-0 flex-1 w-full max-w-[1800px] flex-col md:flex">

            <!--
                No page header. The board is the whole of this page and its four columns carry
                their own titles, so a "Projects" heading above them named nothing the user could
                not already see, and took a row of height off a board that has to fit the viewport.
                Its "New project" button went with it - the dashed "Add project to nesting" card at
                the top of the first column is the same addProject() call, sitting in the column
                the new project actually lands in.
            -->

            <!-- kanban -->
            <!-- Wider gutters on the single-row layout, to give the columns' flow arrows room -->
            <div class="grid min-h-0 flex-1 grid-cols-2 grid-rows-2 gap-4 py-5 xl:grid-cols-4 xl:grid-rows-1 xl:gap-6">

                <!-- Ready for auto nesting -->
                <KanbanColumn
                    step="1"
                    title="Nesting"
                    tooltip="Projects waiting to be bought for. Starting quoting nests everything in this column into one batch, so the longer you hold off, the more the nest has to work with."
                    :count="nestingProjects.length + unfinishedImports.length"
                    :flowsOn="true"
                >
                    <!-- new project -->
                    <button
                        type="button"
                        @click="addProject()"
                        class="group/add flex w-full flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-400 bg-white px-4 py-6 text-center transition-colors duration-150 hover:border-blue-400 hover:bg-blue-50"
                    >
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-gray-500 transition-colors duration-150 group-hover/add:bg-blue-100 group-hover/add:text-blue-800">
                            <i class="fa-solid fa-plus text-xs"></i>
                        </span>
                        <span class="text-sm font-semibold text-gray-700 group-hover/add:text-blue-800">
                            Add project to nesting
                        </span>
                    </button>

                    <!-- card -->
                    <KanbanMinimalCard
                        v-if="nestingProjects.length > 0"
                        :projects="nestingProjects"
                        kanbanColumn="NESTING"
                        :usageStats="usageData"
                        :prerequisiteStartQuoting="prerequisiteStartQuoting"
                        @toggleArchive="p => toggleArchive(p)"
                        @editMode="p => editMode(p)"
                        @quoteNow="quoteNow()"
                        @showBom="args => showBom(args)"
                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                        @pageLoadingOff="pageLoading = false"
                        @addProject="addProject()"
                    />

                    <!--
                        Imports that stopped at a clarification.

                        A project with an unconfirmed price book match is excluded from the card
                        above, and nothing else in the app lists a project that has not been
                        nested - so closing the upload modal half way through left it on no screen
                        at all. Its owner had no route back to it and a colleague could not so
                        much as discover it existed. Drawn as a list rather than a card because
                        none of a card's actions apply to it yet: there is one thing to do here,
                        and only its owner can do it.
                    -->
                    <ul v-if="unfinishedImports.length > 0" class="space-y-1.5 pt-1">
                        <li
                            v-for="project in unfinishedImports"
                            :key="'unfinished-'+project.id"
                            class="rounded-lg border border-orange-200 bg-orange-50 px-2.5 py-2"
                        >
                            <div class="flex items-start gap-2">
                                <i class="fa-solid fa-circle-half-stroke mt-0.5 flex-none text-[11px] text-orange-500"></i>
                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-xs font-semibold text-gray-900" :title="project.name">
                                        {{ shared.capitalizeWords(project.name) }}
                                    </p>
                                    <!--
                                        Whose it is, and who has the spreadsheet.

                                        This used to read the upload gate and print "You" when it
                                        passed, which is no longer the same question: that gate now
                                        also passes for a draftsman who uploaded the list for a
                                        colleague, and printing "You" on a colleague's project would
                                        name the wrong manager. The owner is the owner; the uploader
                                        gets their own clause.
                                    -->
                                    <p class="mt-0.5 text-[11px] text-orange-800">
                                        Import unfinished ·
                                        {{ unfinishedImportOwnerLabel(project) }}
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    @click="pageLoaderTimer(null); showBom(project)"
                                    class="flex-none text-[11px] font-semibold text-blue-800 transition-colors duration-150 hover:text-blue-900 hover:underline"
                                    :title="project.prerequisiteUploadMaterials
                                        ? 'Confirm the remaining products so this project can be nested'
                                        : 'See what is still to be confirmed on this project'"
                                >
                                    {{ project.prerequisiteUploadMaterials ? 'Finish import' : 'View' }}
                                </button>
                            </div>
                        </li>
                    </ul>

                    <!-- toggle archived projects -->
                    <div v-if="archivedProjects.data.length > 0" class="pt-1">
                        <button
                            type="button"
                            @click="showArchivedProjects = !showArchivedProjects"
                            class="flex w-full items-center justify-between gap-2 rounded-lg px-2 py-1.5 text-xs font-semibold text-gray-600 transition-colors duration-150 hover:bg-gray-200/60 hover:text-gray-800"
                        >
                            <span>
                                {{archivedProjects.data.length}} archived project{{archivedProjects.data.length > 1 ? 's' : ''}}
                            </span>
                            <i
                                class="fa-solid fa-chevron-down text-[10px] transition-transform duration-200"
                                :class="showArchivedProjects ? 'rotate-180' : ''"
                            ></i>
                        </button>
                        <ul v-if="showArchivedProjects" class="mt-1.5 space-y-1">
                            <li
                                v-for="project in archivedProjects.data"
                                :key="project.id"
                                class="flex items-center justify-between gap-2 rounded-lg border border-gray-200 bg-white px-2.5 py-2"
                            >
                                <span class="truncate text-xs text-gray-700" :title="project.name">
                                    {{ shared.capitalizeWords(project.name) }}
                                </span>
                                <button
                                    type="button"
                                    @click="toggleArchive(project)"
                                    class="flex-none text-xs font-semibold text-blue-800 transition-colors duration-150 hover:text-blue-900 hover:underline"
                                >
                                    Restore
                                </button>
                            </li>
                        </ul>
                    </div>
                </KanbanColumn>

                <!-- Quoted -->
                <KanbanColumn
                    step="2"
                    title="Quoting"
                    tooltip="Nested batches out with your suppliers for pricing. Open Quotes to send the batch out and record what comes back."
                    :count="quotedBatches.length"
                    :flowsOn="true"
                >
                    <!-- card -->
                    <KanbanMinimalCard
                        v-for="batch in quotedBatches"
                        :key="batch.info.batch.id"
                        :projects="batch.info.projects.data"
                        kanbanColumn="QUOTING"
                        :batchInfo="batch.info"
                        @toggleArchive="p => toggleArchive(p)"
                        @editMode="p => editMode(p)"
                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                        @pageLoadingOff="pageLoading = false"
                        @showBom="args => showBom(args)"
                        @showQuoteOrders="id => showQuoteOrders(id)"
                        @addProject="addProject()"
                    />

                    <KanbanEmptyState
                        v-if="quotedBatches.length === 0"
                        icon="fa-regular fa-file-lines"
                    >
                        Nested batches arrive here once you select <i>“Start quoting”</i>.
                    </KanbanEmptyState>
                </KanbanColumn>

                <!-- Ordered -->
                <KanbanColumn
                    step="3"
                    title="Ordering"
                    tooltip="Batches where at least one order has gone in. Open Orders to place the rest and see which suppliers are still outstanding."
                    :count="orderedBatches.length"
                    :flowsOn="true"
                >
                    <!-- card -->
                    <KanbanMinimalCard
                        v-for="batch in orderedBatches"
                        :key="batch.info.batch.id"
                        :projects="batch.info.projects.data"
                        kanbanColumn="ORDERING"
                        :batchInfo="batch.info"
                        @toggleArchive="p => toggleArchive(p)"
                        @editMode="p => editMode(p)"
                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                        @pageLoadingOff="pageLoading = false"
                        @showBom="args => showBom(args)"
                        @showQuoteOrders="id => showQuoteOrders(id)"
                    />

                    <KanbanEmptyState
                        v-if="orderedBatches.length === 0"
                        icon="fa-regular fa-paper-plane"
                    >
                        Nested batches arrive here once the first order is placed.
                    </KanbanEmptyState>
                </KanbanColumn>

                <!-- Delivered -->
                <KanbanColumn
                    step="4"
                    title="Delivering"
                    tooltip="Every order placed, now waiting on the yard. Once it has all arrived — with material certs for steel — move the batch to done."
                    :count="deliveredBatches.length"
                >
                    <!-- card -->
                    <KanbanMinimalCard
                        v-for="batch in deliveredBatches"
                        :key="batch.info.batch.id"
                        :projects="batch.info.projects.data"
                        kanbanColumn="DELIVERED"
                        :batchInfo="batch.info"
                        @toggleArchive="p => toggleArchive(p)"
                        @editMode="p => editMode(p)"
                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                        @pageLoadingOff="pageLoading = false"
                        @showBom="args => showBom(args)"
                        @showQuoteOrders="id => showQuoteOrders(id)"
                        @addProject="addProject()"
                    />

                    <KanbanEmptyState
                        v-if="deliveredBatches.length === 0"
                        icon="fa-solid fa-truck-fast"
                    >
                        Nested batches arrive here once every order is complete.
                    </KanbanEmptyState>
                </KanbanColumn>
            </div>
        </section>
    </AuthenticatedLayout>

    <!-- Modals -->
    <QuoteOrderModal
        :show="showQuoteOrderModal"
        :quotesData="quotesData"
        :loadFailed="quotesLoadFailed"
        :width="850"
        @closeModal="closeQuoteOrders()"
        @refresh="downloadQuotesData(quotesDataBatchId)"
    />
    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :confirmLabel="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
        :acknowledgeOnly="confirmDialog.acknowledgeOnly ?? false"
        @confirm="confirmDialogAccepted()"
        @cancel="confirmDialogCancelled()"
    />
    <NewProjectModal
        v-show="showNewProjectModal"
        :show="showNewProjectModal"
        width="550"
        :editProject="editProject"
        :bomData="bomData"
        :refreshNewProject="refreshNewProject"
        :projectAfterUpload="projectAfterUpload"
        :colleagues="colleagues"
        @closeModal="showNewProjectModal = false; bomData = null;"
        @closeModalOnSuccess="showNewProjectModal = false; "
        @redownload="project => downloadProjectBomData(project,'NEW_PROJECT')"
    />
    <BomEditModal
        v-if="showBomEditModal"
        width="800"
        :project="bomProject"
        :bomData="bomData"
        :refreshModalBom="refreshModalBom"
        :modalCanUpload="modalCanUpload"
        :loadFailed="bomLoadFailed"
        @closeModal="showBomEditModal = false"
        @redownload="project => downloadProjectBomData(project,'BOM')"
    />
</template>
