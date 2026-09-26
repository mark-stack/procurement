<script setup>
    //General Imports
    import {Head, Link, useForm} from '@inertiajs/vue3';
    import {computed, ref, toRefs, watch} from "vue";
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

    //Props
    const props = defineProps({
        projects: Object,
        batches: Object,
        archivedProjects: Object,
        prerequisiteStartQuoting: Boolean,
    });

    //Forms
    const formProjectDelete = useForm({});
    const formQuoteStore = useForm({});
    const formOrdersStore = useForm({
        batch_id: null,
    });

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

    //Computed
    //Card counts per column, for the badge in each column header
    const nestingProjects = computed(() => props.projects['READY_FOR_NESTING'].projects.data);
    const quotedBatches = computed(() => props.batches['QUOTED']);
    const orderedBatches = computed(() => props.batches['ORDERED']);
    const deliveredBatches = computed(() => props.batches['DELIVERED']);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();


    //Methods
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
        //page loader ON
        pageLoading.value = true;

        let url = route("projects.destroy",id);
        formProjectDelete.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');

                //Hide archived projects if now zero items
                if(props.archivedProjects.data.length === 0){
                    showArchivedProjects.value = false;
                }

                //page loader OFF
                pageLoading.value = false;
            },
            onError: errors => {
                console.log('errors',errors);

                //page loader OFF
                pageLoading.value = false;
            },
        });
    }

    function toggleArchive(project) {
        const name = project.name;

        askToConfirm(project.archive
            ? {
                title: "Restore this project?",
                message: `“${name}” will move back onto your board, ready for nesting.`,
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

                //Remove page loader
                pageLoading.value = false;
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function orderNow(batchId){
        let url = route("orders.store");

        //Page loader
        pageLoading.value = true;

        formOrdersStore.batch_id = batchId;
        formOrdersStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log("success after 'formOrdersStore'");

                //Remove page loader
                pageLoading.value = false;
            },
            onError: errors => {
                console.log('errors',errors);

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
        <section class="mx-auto hidden h-[calc(100vh-68px)] w-full max-w-[1800px] flex-col md:flex">

            <!-- page header -->
            <header class="flex flex-none flex-wrap items-end justify-between gap-4 py-5">
                <div>
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">Projects</h1>
                    <p class="mt-1 text-sm text-gray-500">
                        Follow every project from nesting through to delivery.
                    </p>
                </div>
                <button
                    type="button"
                    @click="addProject()"
                    class="inline-flex items-center gap-2 rounded-lg bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition-colors duration-150 hover:bg-blue-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2"
                >
                    <i class="fa-solid fa-plus text-xs"></i>
                    New project
                </button>
            </header>

            <!-- kanban -->
            <div class="grid min-h-0 flex-1 grid-cols-2 grid-rows-2 gap-4 pb-5 xl:grid-cols-4 xl:grid-rows-1">

                <!-- Ready for auto nesting -->
                <KanbanColumn
                    step="1"
                    title="Nesting"
                    :count="nestingProjects.length"
                >
                    <!-- new project -->
                    <button
                        type="button"
                        @click="addProject()"
                        class="group/add flex w-full flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-gray-300 bg-white px-4 py-6 text-center transition-colors duration-150 hover:border-blue-300 hover:bg-blue-50"
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
                        @orderNow="orderNow()"
                        @showBom="args => showBom(args)"
                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                        @pageLoadingOff="pageLoading = false"
                        @addProject="addProject()"
                    />

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
                    :count="quotedBatches.length"
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
                    :count="orderedBatches.length"
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
                        @orderNow="orderNow(batch['batch']['id'])"
                        @showBom="args => showBom(args)"
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
    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :confirmLabel="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
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
