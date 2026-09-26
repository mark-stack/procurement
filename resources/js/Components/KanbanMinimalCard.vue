<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed, ref} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/Buttons/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/Buttons/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import CardButtonForward from "@/Components/Buttons/CardButtonForward.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";

    //Props
    const props = defineProps({
        projects: Object,
        kanbanColumn: String,
        usageStats: Object,
        prerequisiteStartQuoting: Boolean,
        batchInfo: Object,
    });

    //Form
    const formBreakBatch = useForm({});
    const formMarkAsPastProject = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting','addProject','quoteNow']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);
    const expandBatchDetails = ref(null);

    //Shared methods
    import shared from "@/Shared/shared.js";
    import useConfirm from "@/Shared/useConfirm.js";

    //Confirmation
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    //Read as a computed, not a function - the template tested the function object itself, which is
    //always truthy, and the body read user.id off the computed rather than user.value.id
    const atLeastOneProjectIsYours = computed(() => props.batchInfo
        ? shared.atLeastOneProjectIsYours(props.batchInfo.projects.data,user.value.id)
        : true);

    //Methods
    /**
     * Archiving is the owner's call and only before the project is nested - the server refuses
     * anything else, and a button that can only answer 403 reads as a broken button. The column
     * was already the test here; the owner half is new, because the archived list you restore
     * from only holds your own projects.
     */
    function canArchive(project){
        return props.kanbanColumn === 'NESTING' && project.user_id === user.value.id;
    }

    function archiveTitle(project){
        if(props.kanbanColumn !== 'NESTING'){
            return 'This project is on a batch - re-nest the batch first if you want to archive it';
        }

        if(project.user_id !== user.value.id){
            const projectManager = project.projectManager?.name;

            return projectManager
                ? `Only ${shared.capitalizeWords(projectManager)} can archive this project`
                : 'Only the project manager can archive this project';
        }

        return 'Take this project off the board. You can restore it later';
    }

    /**
     * Re-nesting is the most destructive button on the board and the label does not say so - it reads
     * as "recalculate the nesting". It deletes the batch, every quote on it, every order, the order
     * approvals, and the offcuts and bars it cut, and none of that comes back. "Move to done" below
     * already asks before something one-way; this destroys far more and used to fire on the click.
     */
    function confirmBreakBatch(){
        const projectNames = props.projects.map(project => shared.capitalizeWords(project.name)).join(", ");

        askToConfirm({
            title: "Re-nest this batch?",
            message: `Batch ${props.batchInfo.batch.id} (${projectNames}) goes back to Nesting. Its quotes, draft orders and the offcuts it produced are deleted. This cannot be undone.`,
            confirmLabel: "Re-nest",
            tone: "danger",
            onConfirmed: () => breakBatch(),
        });
    }

    function breakBatch(){
        //A queued second click posts again against a batch that is already gone
        if(formBreakBatch.processing){
            return;
        }

        //Held until the response lands - the overlay has no timeout of its own
        emit('pageLoadingOn',null);

        let url = route("batches.destroy",props.batchInfo.batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            /*
             * onFinish, not onSuccess/onError. The prerequisite gate aborts 403 and a second click 404s,
             * and Inertia calls onError for neither - so the full-page overlay stayed up with nothing
             * left to dismiss it. On success the new props are already applied by the time this runs.
             */
            onFinish: () => {
                emit('pageLoadingOff');
            },
        });
    }

    function allDelivered(){
        return props.batchInfo.allDelivered;
    }

    /**
     * Closing a batch is one way - nothing in the app moves it back onto the board - so it asks
     * first, the same as archiving a project does for something that can be undone.
     */
    function confirmMarkAsPastProject(){
        const projectNames = props.projects.map(project => shared.capitalizeWords(project.name)).join(", ");

        askToConfirm({
            title: "Move this batch to done?",
            message: `Batch ${props.batchInfo.batch.id} (${projectNames}) leaves your board for Past Projects. This cannot be undone.`,
            confirmLabel: "Move to done",
            tone: "primary",
            onConfirmed: () => markAsPastProject(),
        });
    }

    function markAsPastProject(){
        //The button is hidden while this runs, but a queued second click would still post twice
        if(formMarkAsPastProject.processing){
            return;
        }

        let url = route("mark.as.past.project",props.batchInfo.batch.id);
        formMarkAsPastProject.post(url, {
            preserveScroll: true,
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }
</script>

<template>
    <!-- Simple card -->
    <div
        class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow duration-200 hover:shadow-md"
    >
        <!-- card header: batch identity on the left, efficiency on the right -->
        <div
            v-if="batchInfo || (usageStats && atLeastOneProjectIsYours)"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-3 py-2"
        >
            <span
                v-if="batchInfo"
                class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600 ring-1 ring-inset ring-gray-200"
            >
                <i class="fa-solid fa-layer-group text-[10px] text-gray-400"></i>
                Batch {{ batchInfo.batch.id }}
            </span>
            <template v-if="usageStats && atLeastOneProjectIsYours">
                <span
                    v-if="usageStats.METERAGE?.efficiency > 0"
                    class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2 py-1 text-[11px] font-semibold text-green-800 ring-1 ring-inset ring-green-200"
                >
                    <i class="fa-solid fa-arrow-trend-up text-[10px]"></i>
                    {{usageStats.METERAGE.efficiency}}% efficiency
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-500 ring-1 ring-inset ring-gray-200"
                >
                    <i class="fa-solid fa-circle-notch fa-spin text-[10px]"></i>
                    Calculating efficiency
                </span>
            </template>
        </div>

        <!-- projects on this card -->
        <div class="space-y-2 p-3">
            <div
                v-for="(project,index) in projects"
                :key="project.id"
                class="rounded-lg border border-gray-200 bg-gray-50 p-3 transition-colors duration-150 hover:border-gray-300"
            >
                <h4
                    class="truncate text-sm font-semibold text-gray-900"
                    :title="shared.capitalizeWords(project.name)"
                >
                    {{ shared.capitalizeWords(project.name) }}
                </h4>
                <p v-if="project.reference" class="mt-0.5 truncate text-xs text-gray-500">
                    Ref: {{ project.reference }}
                </p>

                <div class="mt-3 grid grid-cols-7 gap-1.5">
                    <CardButtonGreen
                        @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                        :label="project.qtyMaterialRows + ' pieces'"
                        :highlight="false"
                        :icon="false"
                        class="col-span-3"
                    />
                    <CardButtonRed
                        @click="$emit('toggleArchive',project)"
                        label="Archive"
                        :title="archiveTitle(project)"
                        :fullWidth="true"
                        :disabled="!canArchive(project)"
                        class="col-span-2"
                    />
                    <CardButtonYellow
                        class="col-span-2"
                        @click="$emit('editMode',project)"
                        label="Edit"
                        :fullWidth="true"
                    />
                </div>
            </div>
        </div>
<!--        <div class="mt-3">-->
<!--            <p-->
<!--                v-if="kanbanColumn === 'NESTING'"-->
<!--                class="text-blue-700 font-semibold text-sm"-->
<!--                @click="$emit('addProject')"-->
<!--                style="cursor: pointer;"-->
<!--            >-->
<!--                + add project-->
<!--            </p>-->
<!--            <p-->
<!--                v-else-->
<!--                class="text-gray-500 font-semibold text-sm"-->
<!--            >-->
<!--                + add project-->
<!--            </p>-->
<!--        </div>-->
        <div class="mt-auto grid w-full grid-cols-2 items-start gap-2 border-t border-gray-100 bg-gray-50 px-3 py-3">
            <!-- Nesting -->
            <Link
                v-if="kanbanColumn === 'NESTING'"
                :href="route('suggested.nesting')"
                class="w-full col-span-1"
                @click="loadingButton = 'NESTING_DETAILS'"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>
            <Link
                v-else
                :href="route('batch.nesting',[batchInfo.batch.id,'current',1])"
                @click="loadingButton = 'NESTING_DETAILS'"
                class="w-full col-span-1"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>

            <!-- start quoting -->
            <div
                v-if="prerequisiteStartQuoting"
                class="w-full col-span-1"
            >
                <CardButtonForward
                    label="Start quoting"
                    @click="$emit('pageLoadingOn',null);$emit('quoteNow')"
                />
            </div>

            <!-- Quote/order modal -->
            <Link
                v-if="kanbanColumn === 'QUOTING'"
                :href="route('quote.order.management',batchInfo.batch.id)"
                class="w-full"
                @click="loadingButton = 'QUOTING'"
            >
                <CardButtonForward
                    :label="loadingButton === 'QUOTING' ? 'Opening...' : 'Quotes'"
                    :insideLink="true"
                    @click="loadingButton = 'QUOTING'"
                />
            </Link>

            <Link
                v-if="kanbanColumn === 'ORDERING' || kanbanColumn === 'DELIVERED'"
                :href="route('quote.order.management',batchInfo.batch.id)"
                class="w-full"
                @click="loadingButton = 'ORDERING'"
            >
                <CardButtonGreen
                    v-if="kanbanColumn === 'DELIVERED' && allDelivered()"
                    :label="loadingButton === 'ORDERING' ? 'Opening...' : 'Orders'"
                    @click="loadingButton = 'ORDERING'"
                />
                <CardButtonForward
                    v-else
                    :label="loadingButton === 'ORDERING' ? 'Opening...' : 'Orders'"
                    :insideLink="true"
                    @click="loadingButton = 'ORDERING'"
                />
            </Link>

            <!--
                Re-nest. Greyed out rather than hidden when the prerequisite fails, the way Archive is
                above - it used to vanish with no explanation, on a card that still showed it yesterday.
                The flag is only computed for the quoting column, so cards without it draw nothing.
            -->
            <CardButtonRed
                v-if="batchInfo?.prerequisiteUndoStartQuoting !== undefined"
                @click="confirmBreakBatch()"
                :label="formBreakBatch.processing ? 'Re-nesting...' : 'Re-nest'"
                :title="batchInfo.prerequisiteUndoStartQuoting
                    ? 'Unpick this batch and send its projects back to nesting'
                    : 'This batch can no longer be re-nested - an order has been sent, a project was archived, or a later batch has already used its offcuts'"
                class="col-span-2"
                :fullWidth="true"
                :disabled="!batchInfo.prerequisiteUndoStartQuoting || formBreakBatch.processing"
            />

            <!-- All delivered (suggest mark as done) -->
            <div
                v-if="kanbanColumn === 'DELIVERED' && props.batchInfo.steelMerchantDeliveredButNoCertsYet"
                class="col-span-2 flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                <span>The steel merchant order has no attached material certs. This is required to keep all offcuts 100% traceable.</span>
            </div>

            <CardButtonForward
                v-if="kanbanColumn === 'DELIVERED' && allDelivered() && !props.batchInfo.steelMerchantDeliveredButNoCertsYet"
                :label="formMarkAsPastProject.processing ? 'Moving...' : 'Move to done'"
                :disabled="formMarkAsPastProject.processing"
                @click="confirmMarkAsPastProject()"
                class="col-span-2"
            />

            <!-- email buttons (should be just one for steel merchant) -->
<!--            <template v-for="supplierGroup in info?.quotesData?.supplierGroupCards">-->

<!--                <button-->
<!--                    @click="shared.sendSupplierBatchEmail(supplierGroup.info.batchGroup)"-->
<!--                    class="col-span-3 bg-green-50 hover:bg-green-100 border-[1px] border-green-200 w-full text-center pt-1 h-6 px-2 text-xs font-semibold text-green-400 hover:text-green-500 rounded-full"-->
<!--                    style="cursor: pointer;padding-top: 2px;"-->
<!--                >-->
<!--                    <i class="fa-regular fa-envelope text-sm pr-1"></i>-->
<!--                    <span class="text-xs">{{supplierGroup.info.supplierGroup}}</span>-->
<!--                </button>-->
<!--                <p class="text-xs text-gray-500">-->
<!--                    {{supplierGroup.info.includedProducts}}-->
<!--                </p>-->
<!--            </template>-->
        </div>

        <!-- Teleports to body, so it sits inside the card only to keep this a single-root component -->
        <ConfirmModal
            v-if="confirmDialog"
            :title="confirmDialog.title"
            :message="confirmDialog.message"
            :confirmLabel="confirmDialog.confirmLabel"
            :tone="confirmDialog.tone"
            @confirm="confirmDialogAccepted()"
            @cancel="confirmDialogCancelled()"
        />
    </div>
</template>
