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
    import OrderByPill from "@/Components/OrderByPill.vue";
    import EfficiencyPill from "@/Components/EfficiencyPill.vue";

    //Props
    const props = defineProps({
        projects: Object,
        kanbanColumn: String,
        usageStats: Object,
        prerequisiteStartQuoting: Boolean,
        batchInfo: Object,
        /*
         * The day this column has to stop waiting and buy - the earliest fabrication date on the
         * card less the days quoting and delivery take. Computed server side off the one constant
         * that decides it (KanbanFormatter::orderingTriggerDate), so the board cannot promise a date
         * the warnings are not keeping to. Null on a card where no project has a fabrication date.
         */
        orderingTriggerDate: String,
    });

    //Form
    const formBreakBatch = useForm({});
    const formMarkAsPastProject = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting','addProject','quoteNow','showQuoteOrders']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);
    const expandBatchDetails = ref(null);

    //Shared methods
    import shared from "@/Shared/shared.js";
    import useConfirm from "@/Shared/useConfirm.js";
    import startQuotingDialog from "@/Shared/startQuotingDialog.js";
    import reNestDialog from "@/Shared/reNestDialog.js";

    //Confirmation
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    //Read as a computed, not a function - the template tested the function object itself, which is
    //always truthy, and the body read user.id off the computed rather than user.value.id
    const atLeastOneProjectIsYours = computed(() => props.batchInfo
        ? shared.atLeastOneProjectIsYours(props.batchInfo.projects.data,user.value.id)
        : true);

    /*
     * The day this column has to stop waiting and buy is OrderByPill's, date and countdown both -
     * the Nesting page draws the same deadline, and the pill's colour is a warning about it.
     */

    /**
     * The day this batch takes itself off the board, as a date and a countdown.
     *
     * Said out loud for the same reason the ordering trigger above is: a card that is about to
     * disappear on its own should say so first. Nobody presses "Move to done" on the day the steel
     * lands - there is nothing left to do by then - so the schedule does it five days after the last
     * delivery was booked in, and the days in between are for chasing certs and querying dockets with
     * the card still in front of you.
     *
     * Null where the sweep is holding off (DeliveredBatchArchiving::archiveDueDate), in which case the
     * card genuinely is not going anywhere and promises nothing.
     */
    const archiveDueLabel = computed(() => props.batchInfo?.archiveDueDate
        ? moment(props.batchInfo.archiveDueDate).format("D MMM YY")
        : null);

    const daysUntilArchive = computed(() => props.batchInfo?.archiveDueDate
        ? moment(props.batchInfo.archiveDueDate).startOf('day').diff(moment().startOf('day'),'days')
        : null);

    //Methods
    /**
     * Whose project this is. Every column here draws the whole business's work - the Nesting one
     * puts every colleague's project in a single card - and the board said so nowhere: the owner's
     * name appeared only in the tooltip of a disabled Archive button, so the one way to find out
     * who to go and ask was to hover a button you could not press.
     */
    function isMine(project){
        return project.user_id === user.value.id;
    }

    function ownerLabel(project){
        if(isMine(project)){
            return "You";
        }

        const projectManager = project.projectManager?.name;

        return projectManager ? shared.capitalizeWords(projectManager) : "Another project manager";
    }

    /**
     * Who uploaded the material list, when that was not the manager named above - a draftsman
     * detailing the job for a colleague.
     *
     * Worth a line on the card because the two people can do different things to it: the manager
     * owns the job and the uploader owns the spreadsheet, so "whose card is this" and "who do I ask
     * about the materials" now have different answers. Null on the projects most businesses have,
     * where the manager uploaded their own.
     */
    /**
     * When the shop starts cutting this job.
     *
     * The date every deadline on this card is really measured from - it is what the ordering trigger
     * in the header is counted back from, and on a card of several projects it is the only way to
     * see which one of them is driving that date. Null on projects created before the question was
     * asked (see the add_date_fabrication_begins migration), where the honest answer is nothing
     * rather than a guess.
     */
    function fabricationLabel(project){
        if(!project.date_fabrication_begins){
            return null;
        }

        return moment(project.date_fabrication_begins).format("D MMM YY");
    }

    /**
     * Whether this project is the one setting the ordering trigger - the earliest fabrication date
     * on the card. Marked because the header gives a date without saying whose it is, and on a
     * four-project card that is the first thing you want to know.
     */
    function drivesOrderingTrigger(project){
        if(!project.date_fabrication_begins || props.projects.length < 2){
            return false;
        }

        const earliest = Object.values(props.projects)
            .map(p => p.date_fabrication_begins)
            .filter(Boolean)
            .sort()[0];

        return project.date_fabrication_begins === earliest;
    }

    function uploaderLabel(project){
        if(!project.created_by_user_id){
            return null;
        }

        return project.created_by_user_id === user.value.id
            ? "uploaded by you"
            : "uploaded by " + shared.capitalizeWords(project.uploadedByName ?? "a colleague");
    }

    /**
     * Archiving is the owner's call and only before the project is nested - the server refuses
     * anything else, and a button that can only answer 403 reads as a broken button. The column
     * was already the test here; the owner half is new, because the archived list you restore
     * from only holds your own projects.
     */
    function canArchive(project){
        return props.kanbanColumn === 'NESTING' && isMine(project);
    }

    function archiveTitle(project){
        if(props.kanbanColumn !== 'NESTING'){
            return 'This project is on a batch - re-nest the batch first if you want to archive it';
        }

        if(!isMine(project)){
            const projectManager = project.projectManager?.name;

            return projectManager
                ? `Only ${shared.capitalizeWords(projectManager)} can archive this project`
                : 'Only the project manager can archive this project';
        }

        return 'Take this project off the board. You can restore it later';
    }

    /**
     * Editing is the owner's call, the same as archiving. The two buttons sit side by side and
     * used to disagree: Archive greyed itself out on a colleague's project while Edit stayed live
     * next to it, and Edit is not the smaller of the two - the name is how everyone else finds the
     * project, and the materials date drives the owner's deadlines and reminders. The server
     * refuses this now, so a live button could only ever answer 403.
     */
    function canEdit(project){
        return isMine(project);
    }

    function editTitle(project){
        if(!canEdit(project)){
            const projectManager = project.projectManager?.name;

            return projectManager
                ? `Only ${shared.capitalizeWords(projectManager)} can edit this project`
                : 'Only the project manager can edit this project';
        }

        return 'Change the name, reference or materials date';
    }

    /**
     * "Start quoting" does not nest your card - it nests everything in the column, every
     * colleague's project included, into one batch under your name, which settles their material
     * grouping, their suppliers and their delivery dates. It fired on the click, with nothing
     * naming what it was about to take. Re-nest and "Move to done" either side of it both ask
     * first, and neither reaches across as far as this one does.
     *
     * The wording itself is shared with the open batch card on /nesting, which starts quoting the
     * same way - see startQuotingDialog.
     */
    function confirmQuoteNow(){
        askToConfirm(startQuotingDialog(
            props.projects.map(project => ({name: project.name, mine: isMine(project)})),
            () => {
                emit('pageLoadingOn',null);
                emit('quoteNow');
            },
            //The day this card's own Order by pill is counting down to - see startQuotingDialog
            props.orderingTriggerDate ?? null,
        ));
    }

    /**
     * Re-nesting is the most destructive button on the board and the label does not say so - it reads
     * as "recalculate the nesting". "Move to done" below already asks before something one-way; this
     * destroys far more and used to fire on the click.
     *
     * The wording is shared with the open menu on /nesting, which unpicks a batch the same way - see
     * reNestDialog.
     */
    function confirmBreakBatch(){
        askToConfirm(reNestDialog(
            props.batchInfo.batch.id,
            props.projects,
            () => breakBatch(),
        ));
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
        class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-300 bg-white shadow-sm transition-shadow duration-200 hover:shadow-md"
    >
        <!-- card header: batch identity on the left, efficiency on the right -->
        <div
            v-if="batchInfo || orderingTriggerDate || (usageStats && atLeastOneProjectIsYours)"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 bg-gray-50 px-3 py-2"
        >
            <!--
                When this column has to stop waiting and buy.

                The column's own tooltip tells you to hold off as long as you can, because every day
                more material arrives is a better nest and a better price - and until now nothing
                said when holding off starts costing the job its critical path. By this date the
                materials still have to be quoted, ordered and delivered before the saw starts, so
                it is the last day "Start quoting" is early enough. Nothing presses it for you - the
                fabrication deadline warnings email and bell whoever's job starts first.
            -->
            <OrderByPill :date="orderingTriggerDate" />

            <!--
                When this card takes itself off the board.

                The last column's counterpart to the badge above. Everything on this batch is in and
                the only thing left to do is admit it, which is not a job anybody remembers - so five
                days after the last delivery was booked in the batch closes itself and its projects
                become past projects. The days in between are for chasing the certs and querying the
                docket, with the card still here to do it from.

                Absent while the sweep is holding off - a delivery still out, a receipt nobody dated,
                or missing material certs - because then nothing is going to happen on its own.
            -->
            <span
                v-if="archiveDueLabel"
                class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200"
                :title="daysUntilArchive <= 0
                    ? 'Everything on this batch is in - it moves to past projects on the next nightly run'
                    : 'On this date this batch moves to past projects automatically. Close it sooner with Move to done, or add certs and query dockets before then.'"
            >
                <i class="fa-solid fa-box-archive text-[10px] text-gray-400"></i>
                Closes {{ archiveDueLabel }}
                <span v-if="daysUntilArchive > 0" class="font-medium opacity-75">
                    ({{ daysUntilArchive }}d)
                </span>
                <span v-else class="font-medium opacity-75">(tonight)</span>
            </span>

            <span
                v-if="batchInfo"
                class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600 ring-1 ring-inset ring-gray-200"
            >
                <i class="fa-solid fa-layer-group text-[10px] text-gray-400"></i>
                Batch {{ batchInfo.batch.id }}
            </span>
            <!--
                Only once the usage request has answered, and only on a card with your own work on
                it. "loading" is unconditional: on this board a figure of zero means the nest is
                still settling, not that nothing was saved - see EfficiencyPill for the third state,
                which the Nesting page uses for batches that have no saved nest at all.
            -->
            <EfficiencyPill
                v-if="usageStats && atLeastOneProjectIsYours"
                :efficiency="usageStats.METERAGE?.efficiency"
                :loading="true"
            />
        </div>

        <!--
            The projects on this card. Their edges do the work of saying where one project ends and
            the next begins: two stacked in a batch were a gray-50 fill inside a gray-200 hairline,
            on a white card, and read as one block with two headings in it.
        -->
        <div class="space-y-2 p-3">
            <div
                v-for="(project,index) in projects"
                :key="project.id"
                class="rounded-lg border border-gray-300 bg-gray-50 p-3 transition-colors duration-150 hover:border-gray-400"
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

                <!--
                    Whose project this is. Every column draws the whole business's work, so without
                    this the board is an undifferentiated pile - and the two disabled buttons below
                    make no sense until you know the answer.
                -->
                <p class="mt-1.5 flex items-center gap-1.5 text-xs">
                    <i
                        class="fa-solid fa-user text-[9px]"
                        :class="isMine(project) ? 'text-blue-700' : 'text-gray-400'"
                    ></i>
                    <span
                        class="truncate font-medium"
                        :class="isMine(project) ? 'text-blue-800' : 'text-gray-500'"
                        :title="ownerLabel(project)"
                    >
                        {{ ownerLabel(project) }}
                    </span>
                    <span
                        v-if="uploaderLabel(project)"
                        class="truncate text-gray-500"
                        :title="uploaderLabel(project)"
                    >
                        · {{ uploaderLabel(project) }}
                    </span>
                </p>

                <!--
                    When the shop starts cutting this one.

                    The date behind the ordering trigger in the header, per project, so a card of
                    several makes it obvious which job is driving it. Small, because it is reference
                    rather than an action.

                    Nesting only. It is the column where the date still decides something - what is
                    waiting here, and until when. Past this point the batch is committed and the
                    delivery dates on the quotes are what the shop is watching instead.
                -->
                <p
                    v-if="kanbanColumn === 'NESTING' && fabricationLabel(project)"
                    class="mt-1 flex items-center gap-1.5 text-[11px] text-gray-500"
                >
                    <i class="fa-regular fa-calendar text-[9px] text-gray-400"></i>
                    <span class="truncate">Fabrication starts {{ fabricationLabel(project) }}</span>
                    <span
                        v-if="drivesOrderingTrigger(project)"
                        class="flex-none font-medium text-gray-600"
                        title="The earliest fabrication date on this card, so this is the project setting the ordering date above"
                    >
                        · earliest
                    </span>
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
                        :title="editTitle(project)"
                        :disabled="!canEdit(project)"
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
        <div class="mt-auto grid w-full grid-cols-2 items-start gap-2 border-t border-gray-200 bg-gray-50 px-3 py-3">
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
                    :icon="true"
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
                    :icon="true"
                />
            </Link>

            <!-- start quoting -->
            <div
                v-if="prerequisiteStartQuoting"
                class="w-full col-span-1"
            >
                <CardButtonForward
                    label="Start quoting"
                    title="Nest everything in this column into one batch and move it to Quoting"
                    @click="confirmQuoteNow()"
                />
            </div>

            <!--
                Quote/order modal. Opens in place on the board - this used to navigate to
                /quote-order-management/{batch}, a page whose only content was a modal you had to
                leave the board to see.
            -->
            <div
                v-if="kanbanColumn === 'QUOTING'"
                class="w-full"
            >
                <CardButtonForward
                    label="Quotes"
                    @click="$emit('showQuoteOrders',batchInfo.batch.id)"
                />
            </div>

            <div
                v-if="kanbanColumn === 'ORDERING' || kanbanColumn === 'DELIVERED'"
                class="w-full"
            >
                <CardButtonGreen
                    v-if="kanbanColumn === 'DELIVERED' && allDelivered()"
                    label="Orders"
                    @click="$emit('showQuoteOrders',batchInfo.batch.id)"
                />
                <CardButtonForward
                    v-else
                    label="Orders"
                    @click="$emit('showQuoteOrders',batchInfo.batch.id)"
                />
            </div>

            <!--
                Re-nest. Greyed out rather than hidden when the prerequisite fails, the way Archive is
                above - it used to vanish with no explanation, on a card that still showed it yesterday.
                The flag is only computed for the quoting column, so cards without it draw nothing.
            -->
            <CardButtonRed
                v-if="batchInfo?.prerequisiteUndoStartQuoting !== undefined"
                @click="confirmBreakBatch()"
                :label="formBreakBatch.processing ? 'Re-nesting...' : 'Re-nest'"
                :back="true"
                :title="batchInfo.prerequisiteUndoStartQuoting
                    ? 'Unpick this batch and send its projects back to nesting'
                    : 'This batch can no longer be re-nested - it has been ordered, a project was archived, or a later batch has already used its offcuts'"
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
            :note="confirmDialog.note"
            :confirmLabel="confirmDialog.confirmLabel"
            :tone="confirmDialog.tone"
            @confirm="confirmDialogAccepted()"
            @cancel="confirmDialogCancelled()"
        />
    </div>
</template>
