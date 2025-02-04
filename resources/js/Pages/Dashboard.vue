<script setup>
    //General Imports
    import {Head, useForm} from '@inertiajs/vue3';
    import {ref, toRefs, watch} from "vue";
    import axios from 'axios';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import KanbanNeedsImportingCard from "@/Components/KanbanNeedsImportingCard.vue";
    import KanbanReadyForNestingCard from "@/Components/KanbanReadyForNestingCard.vue";
    import KanbanGeneralBatchCard from "@/Components/KanbanGeneralBatchCard.vue";
    import NewProjectModal from "@/Components/NewProjectModal.vue";
    import BomEditModal from "@/Components/BomEditModal.vue";
    import PageLoadingOverlay from "@/Components/PageLoadingOverlay.vue";

    //Props
    const props = defineProps({
        projects: Object,
        batches: Object,
        archivedProjects: Object,
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
    const bomData = ref([]);
    const pageLoading = ref(false);
    const usageData = ref(null);
    const underNavScreenHeight = window.innerHeight - 68;
    const kanbanHeight = underNavScreenHeight - 50;


    //Methods
    function sendRefreshModalBom(){
        refreshModalBom.value = !refreshModalBom.value; // Toggle refreshModalBom
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
        let msg = project.archive ? "Are you sure you want to restore this project?" : "Are you sure you want to archive this project? It can be restored later of you choose";
        const userConfirmed = confirm(msg);
        if (userConfirmed) {
            // User clicked "OK"
            submitArchiveToggle(project.id)
        }
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

    function showBom(project){
        //Set project
        bomProject.value = project;

        /**
         Only download new data if hasn't already
         */
        let existingDownload = undefined; //todo Object.values(bomData.value).find(item => item.project_id == bomProject.value.id);

        if(existingDownload === undefined){
            downloadProjectBomData(bomProject.value.id);
        }
        else{
            //Show modal
            showBomEditModal.value = true;
            console.log("already downloaded. show modal");
        }
    }

    async function getUsageData(){
        /**
         Axios
         */
        try {
            //todo reinstate
            // const response = await axios.get(route("download.usage.data"));
            //
            // if(response.data.usageData){
            //     usageData.value = response.data.usageData;
            // }
        } catch (error) {

        } finally {

        }
    }
    getUsageData();

    async function downloadProjectBomData(projectId){
        /**
            Axios
         */
        try {
            const response = await axios.get(route("download.bom",projectId));

            if(response.data.downloadedBomData){
                //Delete if exists
                bomData.value = Object.values(bomData.value).filter(item => item.project_id != projectId);

                //Create
                bomData.value.push(response.data.downloadedBomData);

                //Refresh modal BOM signal
                sendRefreshModalBom();
            }
        } catch (error) {
            console.error('Error fetching data:', error);

            //Remove page loader
            pageLoading.value = false;
        } finally {
            //Show modal
            showBomEditModal.value = true;

            //Remove page loader
            pageLoading.value = false;
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
</script>

<template>
    <Head title="Dashboard" />

    <AuthenticatedLayout>
        <PageLoadingOverlay
            v-if="pageLoading"
        />

        <div>
            <div class="mx-auto max-w-screen">
                <section>

                    <!-- kanban -->
                    <div class="grid grid-cols-5">
                        <!-- New projects-->
                        <div class="border-r-2 border-indigo-200 p-2">
                            <!-- header -->
                            <div>
                                <h2 class="text-xl font-bold text-center text-gray-900">
                                    <span class="text-indigo-300 text-base">1.</span> New Projects
                                </h2>
                            </div>
                            <!-- body -->
                            <div
                                class="pt-3 overflow-y-auto"
                                :style="'height:'+kanbanHeight+'px'"
                            >
                                <!-- new project -->
                                <div class="">
                                    <button
                                        type="button"
                                        @click="addProject()"
                                        class="w-full text-center p-5 border-2 text-gray-600 hover:text-gray-700 bg-gray-50 hover:bg-gray-100 border-gray-400 hover:border-gray-500 border-dashed rounded-lg font-semibold text-lg"
                                    >
                                        + Add new project
                                    </button>
                                </div>


                                <!-- cards -->
                                <template v-for="project in projects['NEW_PROJECTS'].data">
                                    <KanbanNeedsImportingCard
                                        :project="project"
                                        class="mb-3"
                                        @toggleArchive="p => toggleArchive(p)"
                                        @editMode="p => editMode(p)"
                                        @showBom="p => showBom(p)"
                                        @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                                        @pageLoadingOff="pageLoading = false"
                                    />
                                </template>
                            </div>
                        </div>
                        <!-- Ready for auto nesting -->
                        <div class="border-r-2 border-indigo-200 p-2">
                            <!-- header -->
                            <div>
                                <h2 class="text-xl font-bold text-center">
                                    <span class="text-indigo-300 text-base">2.</span> Nesting
                                </h2>
                            </div>
                            <!-- body -->
                            <div
                                class="pt-3 overflow-y-auto"
                                :style="'height:'+kanbanHeight+'px'"
                            >
                                <!-- card -->
                                <KanbanReadyForNestingCard
                                    v-if="projects['READY_FOR_NESTING'].projects.data.length > 0"
                                    :projects="projects['READY_FOR_NESTING'].projects.data"
                                    :usageStats="usageData"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @quoteNow="quoteNow()"
                                    @orderNow="orderNow()"
                                    @showBom="p => showBom(p)"
                                    @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                                    @pageLoadingOff="console.log('loading OFF'); pageLoading = false"
                                />
                                <div v-else class="text-center text-sm text-gray-500 mx-auto" style="width:200px">
                                    Projects move to here after adding materials
                                </div>
                            </div>
                        </div>
                        <!-- Quoted -->
                        <div class="border-r-2 border-indigo-200 p-2">
                            <!-- header -->
                            <div>
                                <h2 class="text-xl font-bold text-center">
                                    <span class="text-indigo-300 text-base">3.</span> Quoting
                                </h2>
                            </div>
                            <!-- body -->
                            <div
                                class="pt-3 overflow-y-auto"
                                :style="'height:'+kanbanHeight+'px'"
                            >
                                <!-- card-->
                                <KanbanGeneralBatchCard
                                    v-if="batches['QUOTED'].length > 0"
                                    v-for="batch in batches['QUOTED']"
                                    :key="batch.info.batch.id"
                                    :info="batch.info"
                                    type="QUOTES"
                                    class="mb-3"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                                    @pageLoadingOff="console.log('loading OFF'); pageLoading = false"
                                    @showBom="p => showBom(p)"
                                />
                                <div v-else class="text-center text-sm text-gray-500 mx-auto" style="width:250px">
                                    Nested batches move to here after selecting <i>"Start quoting/ordering"</i>
                                </div>
                            </div>
                        </div>
                        <!-- Ordered -->
                        <div class="border-r-2 border-indigo-200 p-2">
                            <!-- header -->
                            <div>
                                <h2 class="text-xl font-bold text-center">
                                    <span class="text-indigo-300 text-base">4.</span> Ordering
                                </h2>
                            </div>
                            <!-- body -->
                            <div
                                class="pt-3 overflow-y-auto"
                                :style="'height:'+kanbanHeight+'px'"
                            >
                                <!-- card -->
                                <KanbanGeneralBatchCard
                                    v-if="batches['ORDERED'].length > 0"
                                    v-for="batch in batches['ORDERED']"
                                    :key="batch.info.batch.id"
                                    :info="batch.info"
                                    type="ORDERS"
                                    class="mb-3"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                                    @pageLoadingOff="console.log('loading OFF'); pageLoading = false"
                                    @orderNow="orderNow(batch['batch']['id'])"
                                    @showBom="p => showBom(p)"
                                />
                                <div v-else class="text-center text-sm text-gray-500 mx-auto" style="width:200px">
                                    Nested batches move to here after adding first order
                                </div>
                            </div>
                        </div>

                        <div class="p-2">
                            <!-- header -->
                            <div>
                                <h2 class="text-xl font-bold text-center">
                                    <span class="text-indigo-300 text-base">5.</span> Delivering
                                </h2>
                            </div>
                            <!-- body -->
                            <div
                                class="pt-3 overflow-y-auto"
                                :style="'height:'+kanbanHeight+'px'"
                            >
                                <!-- card -->
                                <KanbanGeneralBatchCard
                                    v-if="batches['DELIVERED'].length > 0"
                                    v-for="batch in batches['DELIVERED']"
                                    :key="batch.info.batch.id"
                                    :info="batch.info"
                                    type="DELIVERED"
                                    class="mb-3"
                                    @toggleArchive="p => toggleArchive(p)"
                                    @editMode="p => editMode(p)"
                                    @pageLoadingOn="seconds => pageLoaderTimer(seconds)"
                                    @pageLoadingOff="console.log('loading OFF'); pageLoading = false"
                                    @orderNow="orderNow(batch['batch']['id'])"
                                    @showBom="p => showBom(p)"
                                />
                                <div v-else class="text-center text-sm text-gray-500 mx-auto" style="width:200px">
                                    Nested batches move to here after all orders are complete
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- toggle archived projects -->
<!--                    <div>-->
<!--                        <button-->
<!--                            v-if="archivedProjects.data.length > 0"-->
<!--                            @click="showArchivedProjects = !showArchivedProjects"-->
<!--                            class="text-center text-blue-500 underline mt-6 mb-2"-->
<!--                        >-->
<!--                            {{showArchivedProjects ? 'Hide' : 'Show'}} {{archivedProjects.data.length}} Archived Project{{archivedProjects.data.length > 1 ? 's' : ''}}-->
<!--                        </button>-->
<!--                        <div v-if="showArchivedProjects">-->
<!--                            <table>-->
<!--                                <tr>-->
<!--                                   <th class="p-1">Name</th>-->
<!--                                   <th class="p-1">Actions</th>-->
<!--                                </tr>-->
<!--                                <tr v-for="project in archivedProjects.data">-->
<!--                                    <td class="p-1">{{project.name}}</td>-->
<!--                                    <td class="p-1">-->
<!--                                        <span style="cursor: pointer; " class="underline text-blue-500" @click="toggleArchive(project)">restore</span>-->
<!--                                    </td>-->
<!--                                </tr>-->
<!--                            </table>-->
<!--                        </div>-->
<!--                    </div>-->
                </section>
            </div>
        </div>
    </AuthenticatedLayout>

    <!-- Modals -->
    <NewProjectModal
        v-show="showNewProjectModal"
        width="400"
        :editProject="editProject"
        @closeModal="showNewProjectModal = false"
        @closeModalOnSuccess="showNewProjectModal = false"
    />
    <BomEditModal
        v-if="showBomEditModal"
        width="1000"
        :project="bomProject"
        :bomData="bomData"
        :refreshModalBom="refreshModalBom"
        @closeModal="showBomEditModal = false"
        @closeModalOnSuccess="showBomEditModal = false"
        @redownload="projectId => downloadProjectBomData(projectId)"
    />
</template>

<style scoped>
    /* Custom scrollbar styles */
    ::-webkit-scrollbar {
        width: 8px; /* Width of the scrollbar */
    }

    ::-webkit-scrollbar-track {
        background: #f1f1f1; /* Background of the track */
    }

    ::-webkit-scrollbar-thumb {
        background: #bfbfbf; /* Color of the scrollbar thumb */
        border-radius: 10px; /* Rounded corners */
    }

    ::-webkit-scrollbar-thumb:hover {
        background: #888; /* Color when hovered */
    }
</style>
