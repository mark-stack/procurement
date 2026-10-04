<script setup>
    //General Imports
    import {computed, onMounted, ref} from "vue";
    import {Head, Link, useForm} from "@inertiajs/vue3";
    import axios from "axios";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import Dropdown from "@/Components/Dropdown.vue";
    import StagePill from "@/Components/StagePill.vue";
    import RequiredByPill from "@/Components/RequiredByPill.vue";
    import ActionRequiredFooter from "@/Components/ActionRequiredFooter.vue";
    import PageLoadingOverlay from "@/Components/PageLoadingOverlay.vue";
    import BatchBomModal from "@/Components/Modals/BatchBomModal.vue";
    import BatchCertificatesModal from "@/Components/Modals/BatchCertificatesModal.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import OrderListModal from "@/Components/Modals/OrderListModal.vue";
    import NewProjectModal from "@/Components/Modals/NewProjectModal.vue";

    //Shared methods
    import shared from "@/Shared/shared.js";
    import useConfirm from "@/Shared/useConfirm.js";
    import startQuotingDialog from "@/Shared/startQuotingDialog.js";
    import reNestDialog from "@/Shared/reNestDialog.js";

    //Props
    const props = defineProps({
        batches: Array,
        //The colleagues a new project can be created for, for the modal below
        colleagues: {
            type: Array,
            default: () => [],
        },
        /*
         * Whether the open batch card's menu may offer "Start quoting" - the same gate the board
         * draws its own button from, and the one QuoteController::store aborts 403 on. See
         * NestingIndexController.
         */
        prerequisiteStartQuoting: Boolean,
    });

    //Forms
    const formQuoteStore = useForm({});
    const formBreakBatch = useForm({});
    //The supplier-free marks the card menu sets - see confirmMarkQuoted()
    const formMarkQuoted = useForm({});
    const formMarkOrdered = useForm({});
    const formMarkCut = useForm({});
    //And the press that takes the card off the page altogether - see confirmMarkDone()
    const formMarkDone = useForm({});

    //Confirmation
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Variables
    /*
     * Which card's button was pressed, not whether any was. Nesting a batch is the slowest read in
     * the app, so the card that was clicked says "Calculating..." and the rest of the column is left
     * alone - a shared flag would relabel every button on the page at once.
     */
    const loadingBatchId = ref(null);

    /*
     * Held over "Start quoting", which nests everything waiting into one batch - the slowest write in
     * the app, and the one that moves work off this page. A full-page overlay rather than a label on
     * the menu item, the way the board holds the same press: the menu closes on the click, so there
     * is nothing left on screen to say the page is busy.
     */
    const pageLoading = ref(false);

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
     * A batch is yours when one of the projects on it is yours: a job you manage, or one you uploaded
     * for a colleague. The server says so per card - see NestingIndexController::mineOf().
     *
     * Remembered, because it is a view somebody chooses rather than a value they fill in: a shop
     * foreman who works off the whole business's list was turning the switch off on every visit, and
     * every press on a card redraws this page. Per browser, which is where a preference about how one
     * screen is read belongs, and it fails quiet - a locked-down browser throws on localStorage and
     * the page is still the page, opened on the default.
     */
    const ONLY_MINE_KEY = 'nesting.onlyMine';

    const onlyMine = ref(readOnlyMinePreference());

    function readOnlyMinePreference() {
        try {
            return window.localStorage.getItem(ONLY_MINE_KEY) !== 'false';
        } catch (error) {
            return true;
        }
    }

    function toggleOnlyMine(value) {
        onlyMine.value = value;

        try {
            window.localStorage.setItem(ONLY_MINE_KEY, value ? 'true' : 'false');
        } catch (error) {
            //A browser that will not keep it still has the switch; it is just back on next visit
        }
    }

    //Computed
    /*
     * The open batch, which the server always sends and always sends first - see
     * NestingIndexController. The one card on this page that is not a batch row.
     */
    const openBatch = computed(() => props.batches.find(batch => batch.id === null) ?? null);

    /*
     * The switch never hides the open batch.
     *
     * It is the only batch an upload can join, and the "+ Materials" button above the list puts work
     * on it - a switch that took it off the page would be hiding the card somebody is about to use,
     * and hiding it exactly when they have nothing on it yet. Everything else on the page is a closed
     * batch, which the switch is for: those are a colleague's work or they are yours.
     */
    const visibleBatches = computed(() => onlyMine.value
        ? props.batches.filter(batch => batch.mine || batch.id === null)
        : props.batches);

    /*
     * How many cards the switch is holding back, so turning it off is an offer rather than a guess.
     * Named for what the user would see, which is why it counts against the whole list.
     */
    const hiddenCount = computed(() => props.batches.length - visibleBatches.value.length);

    /*
     * Whether the open batch can be closed and quoted from here. The server's gate, and the two
     * things it cannot know: the card has to exist and have something on it.
     */
    const canStartQuoting = computed(() => openBatch.value !== null
        && !isEmptyOpenBatch(openBatch.value)
        && props.prerequisiteStartQuoting);

    /*
     * And why not, when it cannot. A greyed item saying nothing is a dead end somebody hovers twice
     * and then goes to the board to find out about - the gate turns on whose work is waiting, which
     * is a fact this page is otherwise careful to print on every card.
     */
    const startQuotingTitle = computed(() => {
        if (openBatch.value === null || isEmptyOpenBatch(openBatch.value)) {
            return 'Nothing is waiting on the open batch yet';
        }

        if (!props.prerequisiteStartQuoting) {
            return 'None of the projects waiting are yours - their project manager starts quoting them';
        }

        return 'Nest everything waiting into one batch and move it to Quoting';
    });

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
    //And the card itself, so the list can be asked for again after marking a merchant ordered
    const orderListBatch = ref(null);

    /*
     * Mill certificates modal. Two ways in and one modal: "Delivered", where it carries the press
     * that sets the mark, and "+ Certificates" on a batch already delivered, where it is the files
     * alone. The card is handed over whole, the modal reading the gate off it - see openCertificates.
     */
    const showCertificatesModal = ref(false);
    const certificatesBatch = ref(null);

    //New project modal, which is the board's - see addProject()
    const showNewProjectModal = ref(false);
    const newProjectBomData = ref(null);
    const projectAfterUpload = ref(null);
    const refreshNewProject = ref(false);
    /*
     * The project that same modal is editing, or null when it is being used to add one. It is the
     * same switch between the two the board had (it is gone), so both entry points open the same
     * form on the same project - see editProjectMode().
     */
    const editProject = ref(null);

    //Methods
    /*
     * The pending card has no batch row behind it, so it has no id to key on or to print. Keyed on
     * the stage instead, which is unique: there is at most one pending batch - everything waiting is
     * nested into a single batch when quoting starts.
     */
    function cardKey(batch) {
        return batch.id ?? batch.stage;
    }

    /*
     * Whether this card has nothing on it to read.
     *
     * Only ever the open batch, which is drawn whether or not anything is waiting on it. All three of
     * its buttons answer for material - the list, the stock to buy, the nest - so with none waiting
     * there is nothing behind any of them, and they are greyed rather than left to open an empty
     * table. A closed batch is deliberately not tested: every one of them was nested out of projects.
     */
    function isEmptyOpenBatch(batch) {
        return batch.id === null && batch.projects.length === 0;
    }

    /*
     * Whether this card has something outstanding on it, which is what puts a footer under it.
     *
     * Behind its own deadline, and on a step where being behind is somebody's to fix. Those are two
     * different questions, and the second is the one that decides whether a footer is worth drawing.
     *
     * Three steps are late with nothing to be done about it. DELIVERED and CUT have their steel in,
     * so there is no lateness left to act on at all. ORDERED is the one that matters: the material
     * has been bought and the only thing between the batch and its date is a merchant's lorry.
     * Telling somebody to chase it is not an instruction, it is a feeling - there is no press on this
     * page that moves it, and a footer that cannot be cleared by doing what it says is the kind a
     * reader learns to scroll past, taking the ones that can be cleared with it.
     *
     * The pill still goes red on those cards. That a bought batch is going to miss its date is worth
     * knowing - it is what somebody rings the customer about - it is just not a job on this page.
     *
     * One working day behind is enough. The deadline is the last day the step could be finished and
     * still leave time for everything after it, so a card that is past it has already lost time it
     * cannot get back by working normally - which is the point at which somebody should be told.
     */
    function actionRequired(batch) {
        return batch.daysBehindCriticalPath >= 1
            && ! ['ORDERED', 'DELIVERED', 'CUT'].includes(batch.stage);
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
     * The "open batch" label is always drawn, the card below it being always drawn. The closed one is
     * drawn only when a closed batch follows it: the switch can hide every one of them, and that
     * heading over nothing would be labelling the end of the page as something it is not.
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
        if (batch.stage === 'NESTING') {
            //Nothing waiting, so no figure was asked for and none is coming - see onMounted()
            return !isEmptyOpenBatch(batch) && pendingUsage.value === null;
        }

        return batchEfficiency.value === null;
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
     * The Material order button's second line: how many blocks that list comes in.
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
     * A batch with none of yours on it - which only the "Only my projects" switch can show you - is
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

        if (mine.length) {
            return mine;
        }

        /*
         * Then the jobs you put on the system for somebody else, which is the other half of why a
         * card is in front of you at all - see NestingIndexController::mineOf(). Without this a
         * draftsman's own card is headed by whichever job happens to be oldest and says nothing
         * about why the switch kept it.
         */
        const uploaded = batch.projects.filter(project => project.uploaded);

        return uploaded.length ? uploaded : batch.projects.slice(0, 1);
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
         * on a card carrying BOM and Material order buttons would read as a broken page.
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
     * Both the printable sheet, which is the "1" on the end of each: every card on this page is a
     * batch read end to end - its material list, its order list, its nest - and the modal the board
     * opens is a card-sized read of one batch in the middle of a board. The open batch's sheet is
     * stamped DO NOT CUT, its nest being a suggestion until quoting saves one; see
     * SuggestedNestingController.
     *
     * Always "current", there being no finished batch on this page to close back to /past-projects -
     * those are read from /past-projects itself. See NestingIndexController.
     */
    function nestingHref(batch) {
        if (batch.stage === 'NESTING') {
            return route('suggested.nesting', [1]);
        }

        return route('batch.nesting', [batch.id, 'current', 1]);
    }

    /**
     * Whether this card has a menu behind its three dots - which is to say, whether it has anything
     * in it.
     *
     * The open batch always does: it can be added to and it can be closed and quoted. Past that it is
     * whichever of the marks this batch is in a position to be given - unpicked while it is only
     * priced, called quoted, bought or delivered while nobody has bought it through a merchant, cut
     * once it has arrived however it arrived. A card in the middle of the board with a real order out
     * and nothing delivered has none of them, and keeps the space without drawing the dots, so the
     * row of buttons still lines up down the page.
     *
     * Every one of those is read off the server's gate rather than off the card's pill, null meaning
     * "not a question this card asks". They are the gates the controllers abort 403 on, so anything
     * else could draw an item leading nowhere.
     */
    function hasMenu(batch) {
        return batch.id === null
            || batch.prerequisiteUndoStartQuoting !== null
            || batch.prerequisiteMarkQuoted !== null
            || batch.prerequisiteMarkOrdered !== null
            || batch.prerequisiteMarkDelivered !== null
            || batch.prerequisiteMarkCut !== null
            || batch.prerequisiteMarkDone !== null
            || batch.canAttachCertificates !== null;
    }

    /**
     * Unpick a batch and send its projects back to the open one - the board's "Re-nest", pressed
     * from here.
     *
     * The same DELETE to the same place, because it is the same thing, and the most destructive
     * press either screen offers: the batch, its quotes, its draft orders and the offcuts and bars
     * it cut all go, and none of it comes back. It asks first in the words the board asks in - see
     * reNestDialog.
     */
    function confirmReNest(batch) {
        askToConfirm(reNestDialog(
            batch.id,
            batch.projects,
            () => reNest(batch),
        ));
    }

    function reNest(batch) {
        //A queued second click posts again against a batch that is already gone
        if (formBreakBatch.processing) {
            return;
        }

        pageLoading.value = true;

        formBreakBatch.delete(route('batches.destroy', batch.id), {
            preserveScroll: true,
            /*
             * The projects are back on the open batch and the card that held them has gone, so both
             * figures were fetched for a page that no longer exists - see downloadEfficiencies().
             */
            onSuccess: () => {
                downloadEfficiencies();
            },
            /*
             * onFinish, not onSuccess/onError. The prerequisite gate aborts 403 and a second click
             * 404s, and Inertia calls onError for neither - so the overlay would stay up with
             * nothing left to dismiss it.
             */
            onFinish: () => {
                pageLoading.value = false;
            },
        });
    }

    /**
     * What the Re-nest item says when it cannot run, which is the board's own wording: the gate
     * turns on things that have happened to the batch since it was nested, and none of them are
     * visible on the card.
     */
    function reNestTitle(batch) {
        return batch.prerequisiteUndoStartQuoting
            ? 'Unpick this batch and send its projects back to nesting'
            : 'This batch can no longer be re-nested - it has been ordered, a project was archived, or a later batch has already used its offcuts';
    }

    /**
     * "All quoted" and "All ordered" - the two steps a batch passes, said of the batch itself.
     *
     * They exist for a shop that does not buy through the quotes and orders screen. Everything there
     * hangs off a supplier - a quote is a row per merchant, an order a row per quote - so a business
     * that has entered no suppliers has nothing to tick, and its batches sit at Quoting however much
     * steel is in the rack. These record the step and name nobody.
     *
     * Both ask first, and both say what they do not do. "Ordered" beside a batch is a claim that money
     * has been spent, and the one thing somebody pressing it must not believe is that the application
     * has just bought the steel for them: no merchant is contacted, no order is placed, and the board
     * does not move the card, because its columns are built on orders that really were sent.
     */
    function confirmMarkQuoted(batch) {
        askToConfirm({
            title: "Mark this batch as quoted?",
            message: `Batch ${batch.id} (${projectNames(batch.projects)}) will read as Quoted on this`
                + ` page, recording that you have your prices.`,
            note: "No quote request is sent and no supplier is marked - this is a note on the batch.",
            confirmLabel: "All quoted",
            onConfirmed: () => markBatch(batch, 'batch.all.quoted', formMarkQuoted),
        });
    }

    function confirmMarkOrdered(batch) {
        askToConfirm({
            title: "Mark this batch as ordered?",
            message: `Batch ${batch.id} (${projectNames(batch.projects)}) will read as Ordered on this`
                + ` page, recording that its material has been bought.`,
            note: "No order is placed and no merchant is contacted - this is a note on the batch, and the"
                + " board still shows it where it is. Once it is marked, the batch can no longer be"
                + " re-nested.",
            confirmLabel: "All ordered",
            onConfirmed: () => markBatch(batch, 'batch.all.ordered', formMarkOrdered),
        });
    }

    /**
     * "Move to done" - the job is over, and the batch belongs in Past Projects.
     *
     * The board's own button, pressed from here: the same post to the same route, which is all that
     * was left of it once the board was deleted. It is the one press on this page that takes a card
     * off it, and without it nothing did - a batch stayed live however finished it was, this list
     * grew by a card a job, and Past Projects could gain nothing.
     *
     * It asks first, like the marks above, and for a stronger reason than any of them: nothing in the
     * application re-opens a closed batch. The dialog says where the work goes rather than warning
     * somebody off it - this is the ordinary end of a job, not a destructive press.
     */
    function confirmMarkDone(batch) {
        askToConfirm({
            title: "Move this batch to done?",
            message: `Batch ${batch.id} (${projectNames(batch.projects)}) will come off this page and`
                + ` be read from Past Projects, where its nesting is still printable.`,
            note: "Nothing re-opens a closed batch.",
            confirmLabel: "Move to done",
            onConfirmed: () => markBatch(batch, 'mark.as.past.project', formMarkDone),
        });
    }

    function markDoneTitle(batch) {
        return batch.prerequisiteMarkDone
            ? 'Close this batch and read it from Past Projects'
            : 'This batch still has material out for delivery, or it carries no project of yours';
    }

    /**
     * "Delivered" - the steel has turned up. Asked in a modal rather than a confirm box, because the
     * person pressing it is standing at the rack with the merchant's email open: the certificates
     * belong to this moment, and sending them off to another screen for the PDF is how a yard ends up
     * with a delivered date and no paperwork. See BatchCertificatesModal.
     */
    function openCertificates(batch) {
        certificatesBatch.value = batch;
        showCertificatesModal.value = true;
    }

    /**
     * And "Cut" - the saw has been through it. A confirm box, like the two marks above it, because
     * there is nothing to attach to this one.
     *
     * It is the one mark on the menu that is not about buying, so it is also the one a batch ordered
     * the ordinary way can be given: delivered is delivered, whether that came off goods receipts or
     * off the mark above.
     */
    function confirmMarkCut(batch) {
        askToConfirm({
            title: "Mark this batch as cut?",
            message: `Batch ${batch.id} (${projectNames(batch.projects)}) will read as Cut on this page,`
                + ` recording that its material has been through the saw.`,
            note: "The batch stays where it is - this is a note on it, not a way of closing it.",
            confirmLabel: "Cut",
            onConfirmed: () => markBatch(batch, 'batch.cut', formMarkCut),
        });
    }

    /**
     * Both presses are the same post, so they are the same function: the batch, the route and the form
     * holding it. A queued second click would be refused by the gate anyway - the mark is already set
     * by then - and that would be a 403 the page cannot explain, so it is dropped here instead.
     */
    function markBatch(batch, routeName, form) {
        if (form.processing) {
            return;
        }

        form.post(route(routeName, batch.id), {preserveScroll: true});
    }

    /**
     * What each item says when it cannot run. One sentence listing the reasons, the way reNestTitle
     * does: none of them are visible on the card, and the gate is the only thing that knows which it
     * is (see PrerequisiteConditions::markBatchQuoted).
     */
    function markQuotedTitle(batch) {
        return batch.prerequisiteMarkQuoted
            ? 'Record that the prices for this batch are in, without naming a supplier'
            : 'This batch is already marked as quoted or ordered, or it carries no project of yours';
    }

    function markOrderedTitle(batch) {
        return batch.prerequisiteMarkOrdered
            ? 'Record that the material on this batch has been bought, without naming a supplier'
            : 'This batch is already marked as ordered or delivered, or it carries no project of yours';
    }

    function markDeliveredTitle(batch) {
        return batch.prerequisiteMarkDelivered
            ? 'Record that this batch\'s material has arrived, and attach the mill certificates'
            : 'This batch is already marked as delivered, or it carries no project of yours';
    }

    function markCutTitle(batch) {
        return batch.prerequisiteMarkCut
            ? 'Record that this batch has been through the saw'
            : 'This batch is already marked as cut, or it carries no project of yours';
    }

    /**
     * Close the open batch and go and buy it - the board's "Start quoting", pressed from here.
     *
     * The same post to the same place, because it is the same thing: everything waiting is nested
     * into one batch under your name, which settles the material grouping, the suppliers and the
     * delivery dates for every job on it, a colleague's included. It asks first for that reason, in
     * the words the board asks in - see startQuotingDialog.
     *
     * It belongs on this card and nowhere else on the page: the open batch is what it sweeps up, and
     * from the moment it runs that batch takes no more material.
     */
    function confirmStartQuoting() {
        askToConfirm(startQuotingDialog(
            openBatch.value?.projects ?? [],
            () => startQuoting(),
            /*
             * The day this batch has to stop waiting and be quoted, which is what decides whether
             * the dialog's advice to wait for a bigger batch is still worth taking - see
             * startQuotingDialog. Not the required-by date the card's pill prints: that one is when
             * the steel has to be at the workshop, which is days later and a different argument.
             */
            openBatch.value?.orderingTriggerDate ?? null,
        ));
    }

    function startQuoting() {
        //A queued second click posts again against pieces the first one has already batched
        if (formQuoteStore.processing) {
            return;
        }

        pageLoading.value = true;

        formQuoteStore.post(route('quotes.store'), {
            preserveScroll: true,
            /*
             * The page redraws either way, so a rolled back "start quoting" would otherwise look
             * exactly like a successful one: the overlay clears, nothing moves off the open batch,
             * no reason given. Worded by the server - it is the one that knows whether the nesting
             * failed or a colleague simply got there first.
             */
            onError: errors => {
                console.error('Error starting quoting:', errors);

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
             * The new batch is on the page but none of the percentages are: one card has appeared
             * and the open batch has emptied, and both figures were fetched for a page that no
             * longer exists. Asked again off the props Inertia has already applied by now.
             */
            onSuccess: () => {
                downloadEfficiencies();
            },
            /*
             * onFinish, not onSuccess/onError - the prerequisite gate aborts 403 and Inertia calls
             * neither for that, which would leave the overlay up with nothing to dismiss it.
             */
            onFinish: () => {
                pageLoading.value = false;
            },
        });
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
        orderListBatch.value = batch;
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

    /**
     * The same list again, after the modal marked one of its merchants ordered.
     *
     * The list is an axios payload rather than a page prop, so the Inertia visit behind that press
     * redraws the cards and leaves the open modal showing what it fetched. The card it belongs to is
     * the one still on screen - orderListBatch holds it for exactly this.
     */
    function refreshOrderList() {
        if (orderListBatch.value !== null) {
            downloadOrderList(orderListBatch.value);
        }
    }

    function closeOrderList() {
        showOrderListModal.value = false;
        orderListData.value = null;
        orderListTitle.value = null;
        orderListCardKey.value = null;
        orderListBatch.value = null;
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
    /**
     * Both of those, from a clean slate - what the page asks for when it draws, and again when
     * "Start quoting" has rearranged what is on it.
     *
     * Nulled first, because null is what the buttons read as "calculating" and a stale figure
     * against a card that has just changed is worse than no figure: the open batch's percentage was
     * the suggestion for work that is now a batch of its own.
     */
    function downloadEfficiencies() {
        batchEfficiency.value = null;
        pendingUsage.value = null;

        downloadEfficiency();

        /*
         * Only when there is something to nest - this one runs the nesting algorithm. The open batch
         * is on the page whether or not anything is waiting on it, and an empty one has no nest to
         * ask about.
         */
        if (openBatch.value !== null && !isEmptyOpenBatch(openBatch.value)) {
            downloadPendingUsage();
        }
    }

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
        //Adding, not editing - the modal is the same component in both states
        editProject.value = null;
        showNewProjectModal.value = true;
    }

    /**
     * Rename a job, or move the date its fabrication begins - the board's edit modal, opened here.
     *
     * The same component and the same PUT to projects.update, because it is the same job: a second
     * rename form is a second place for the name rules to be half-applied. The one thing this page
     * adds is where it is opened from, which is the point of the pencil - the fabrication date is what
     * decides when this batch's steel has to be at the workshop (see RequiredByPill) and when it has to stop
     * waiting and be quoted, and until now reading that date here meant going to the board to change it.
     *
     * The card's project is what the modal is handed, which is why those fields are on it - see
     * NestingIndexController::projectCards(). Saving lands back on this page, so the heading, the
     * hover list and the "Required by" date all redraw with the new values; there is nothing to reload.
     */
    function editProjectMode(project) {
        newProjectBomData.value = null;
        projectAfterUpload.value = null;
        editProject.value = project;
        showNewProjectModal.value = true;
    }

    /**
     * The BOM of the project just created, which the modal asks for by emitting "redownload".
     *
     * That modal freezes itself on "Calculating..." until this answers, so it has to be signalled
     * either way - a failed fetch would otherwise leave it spinning with no way back. Same handshake
     * as the board's own downloadProjectBomData was.
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
        downloadEfficiencies();
    });
</script>

<template>
    <Head title="Nesting" />

    <AuthenticatedLayout>
        <!-- Held over "Start quoting" alone, which is the one press on this page that writes - see startQuoting() -->
        <PageLoadingOverlay v-if="pageLoading" />

        <section class="container max-w-4xl px-4 mx-auto py-6">
            <!-- Wraps rather than squeezes: the switch and the button do not fit a phone beside the title -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-800">
                    Nesting batches
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
                        Only my projects
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

                    <!--
                        The card is the box, and what was the card is now its top row - so that a batch
                        which is behind can carry a footer saying what to do about it without that strip
                        sitting inside the padding of the row above it or squaring off the bottom corners.
                        See ActionRequiredFooter.

                        Deliberately NOT overflow-hidden, which is the obvious way to keep the footer's
                        background inside the card's rounded corners and the wrong one: the card menu
                        hangs out of this box, and an ancestor that clips its overflow clips the menu
                        with it however high the menu's z-index is. It came off the bottom of the page
                        on the cards near the top. The footer rounds its own two corners instead -
                        see the rounded-b-xl in ActionRequiredFooter.
                    -->
                    <div class="bg-white border border-gray-200 shadow-sm rounded-xl">
                        <div
                            class="flex items-center justify-between gap-4 px-4 py-3"
                        >
                            <!--
                                The whole left half of the card: the job names, and under them the two pills
                                that say where the batch is and when its steel is due. One column rather than
                                the wrapping row this was, which held the heading and the date side by side -
                                the date moved under the heading, and a flex row around a single child was
                                left doing nothing but passing min-w-0 down to it.
                            -->
                            <div class="min-w-0">
                                <!--
                                    The jobs on the batch, not its number - see headedProjects(). The
                                    full heading is on hover, because several of your own projects on
                                    one batch is a heading too long for the card.

                                    A job at a time rather than one run of text, so each of them can
                                    carry its own pencil. The commas are drawn between them, which is
                                    what cardTitle() reads as on a card carrying several of yours.
                                -->
                                <span
                                    :title="cardTitle(batch)"
                                    class="flex items-center min-w-0 text-sm font-semibold text-gray-800"
                                >
                                    <template v-if="headedProjects(batch).length">
                                        <span
                                            v-for="(project, projectIndex) in headedProjects(batch)"
                                            :key="project.id"
                                            class="flex items-center min-w-0"
                                        >
                                            <span v-if="projectIndex > 0" class="mr-1">,</span>
                                            <span class="truncate">{{ project.name }}</span>

                                            <!--
                                                Rename it, or move the fabrication date that decides
                                                when this batch buys - the board's own modal, opened on
                                                this project. Only on your own work: the server lets
                                                nobody but the project manager past
                                                (PrerequisiteConditions::editProject), so a pencil on a
                                                colleague's job could only ever answer 403. Drawn as
                                                nothing at all rather than greyed, because a card can
                                                name a dozen jobs and a row of dead pencils is noise.
                                            -->
                                            <button
                                                v-if="project.mine"
                                                type="button"
                                                :title="'Edit ' + project.name"
                                                :aria-label="'Edit ' + project.name"
                                                @click="editProjectMode(project)"
                                                class="ml-1.5 shrink-0 text-gray-400 transition-colors duration-150 hover:text-blue-700"
                                            >
                                                <i class="fa-solid fa-pencil text-[11px]"></i>
                                            </button>
                                        </span>
                                    </template>

                                    <!-- The open batch, and a batch with no job on it at all -->
                                    <span v-else class="truncate">{{ cardTitle(batch) }}</span>
                                </span>

                                <!-- Whose job that is, when it is not yours - see headingManager() -->
                                <span
                                    v-if="headingManager(batch)"
                                    class="block text-xs text-gray-500 truncate"
                                >
                                    {{ headingManager(batch) }}'s
                                </span>

                                <!--
                                    Or, on an open batch with nothing waiting on it, what it is for.
                                    The card is drawn empty (see NestingIndexController), and a
                                    heading with nothing under it reads as a card that failed to
                                    load rather than as a batch waiting to be filled.
                                -->
                                <span
                                    v-if="isEmptyOpenBatch(batch)"
                                    class="block text-xs text-gray-400"
                                >
                                    Nothing waiting yet
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

                                    <!--
                                        Hoverable, not just readable: your own jobs in here carry the
                                        same pencil the heading does, and on the open batch card this
                                        list is the only place they are named at all. Padded rather
                                        than margined off the label, so the pointer crosses into it
                                        without passing over a gap that would close it.
                                    -->
                                    <span class="absolute left-0 z-20 hidden pt-1 top-full w-max max-w-xs group-hover:block">
                                        <span class="block p-2 bg-white border border-gray-200 shadow-lg rounded-lg">
                                            <span
                                                v-for="project in otherProjects(batch)"
                                                :key="project.id"
                                                class="flex items-center text-xs text-gray-700"
                                            >
                                                <span class="truncate">{{ project.name }}</span>
                                                <!-- Named like the heading above: yours says so, a colleague's says who -->
                                                <span class="ml-1 text-gray-400 shrink-0">
                                                    ·
                                                    {{ project.mine
                                                        ? 'you'
                                                        : (project.manager
                                                            ? shared.capitalizeWords(project.manager)
                                                            : 'another project manager') }}
                                                </span>

                                                <button
                                                    v-if="project.mine"
                                                    type="button"
                                                    :title="'Edit ' + project.name"
                                                    :aria-label="'Edit ' + project.name"
                                                    @click="editProjectMode(project)"
                                                    class="ml-1.5 shrink-0 text-gray-400 transition-colors duration-150 hover:text-blue-700"
                                                >
                                                    <i class="fa-solid fa-pencil text-[11px]"></i>
                                                </button>
                                            </span>
                                        </span>
                                    </span>
                                </span>

                                <!--
                                    Where this batch has got to, and whether it is going to make its
                                    date - the two facts about the batch itself, under the names of the
                                    jobs they are about.

                                    Beneath the heading rather than across the card from it, because
                                    they are read together: "Quoting" is only good or bad news next to
                                    the day the steel is wanted, and a column of cards is scanned down
                                    this left edge. The step comes first and the date second, which is
                                    the order the sentence goes in.

                                    Wraps on a narrow card, the two pills being a phone's width between
                                    them once the step is a long word.

                                    Drawn only when there is a pill to put in it. The open batch with
                                    nothing waiting on it has neither - no step behind it, and no job
                                    naming a fabrication date to want steel by - and an empty row would
                                    still spend its top margin, leaving a gap under the heading of the
                                    one card the page always draws.
                                -->
                                <div
                                    v-if="batch.id !== null || batch.materialsRequiredDate"
                                    class="flex flex-wrap items-center gap-2 mt-2"
                                >
                                    <!--
                                        The last step the batch has passed, not the column it is sitting
                                        in. Coloured as the board and the dashboard colour that step -
                                        see StagePill and NestingIndexController::milestoneOf().

                                        Not on the open batch: the rule above it already says what it
                                        is, and a batch that has not been quoted has no step behind it
                                        to report. Nothing holds its place now that the buttons no
                                        longer line up against it - the row simply starts at the date.
                                    -->
                                    <StagePill v-if="batch.id !== null" :stage="batch.stage" />

                                    <!--
                                        The day this card's steel has to be at the workshop - one working
                                        day before the earliest fabrication date on it. Every card carries
                                        one, the open batch included.

                                        Its colour is the other half: whether this batch, where it has got
                                        to, is still going to make that date. The deadline it is held to
                                        depends on how much of the critical path it has left to spend - the
                                        server works that out off the business's lead times and sends it
                                        alongside. Green on track, amber a day behind, red two or more.
                                        See RequiredByPill.

                                        A batch whose steel is in does not carry one at all. Delivered and
                                        Cut both drew an on-time pill until now, on the reading that the
                                        date was still what the job worked to - which put a run of green
                                        down the bottom of the column saying nothing anybody had to act
                                        on. The date is history on those cards rather than a deadline, and
                                        the step pill beside it is already the whole of the news.
                                    -->
                                    <RequiredByPill
                                        v-if="! ['DELIVERED', 'CUT'].includes(batch.stage)"
                                        :date="batch.materialsRequiredDate"
                                        :days-behind="batch.daysBehindCriticalPath"
                                        class="shrink-0"
                                    />
                                </div>
                            </div>

                            <!--
                                Wraps rather than squeezes, like the heading beside it: the three buttons
                                have fixed widths, which on a phone is more than fits on one line.
                                Right-aligned so what wraps stays against the card's edge.

                                Buttons only now. The stage pill used to sit at this end, in a slot of its
                                own width so that "Nesting" and "Delivered" being a dozen pixels apart did
                                not walk all three buttons sideways from card to card. It reads under the
                                job names instead, beside the date it is only meaningful next to, and the
                                slot that was holding its place went with it.

                                Everything on the right of the card is in here, the menu included - the card
                                is a justify-between of two halves, and a third child would leave this one
                                floating in the middle of the gap rather than against the edge.
                            -->
                            <div class="flex flex-wrap items-center justify-end gap-2 shrink-0">
                                <!--
                                    Everything on the batch, read-only and across all of its projects.
                                    CardButtonYellow is itself the button, so the click goes straight on it.
                                -->
                                <CardButtonYellow
                                    label="BOM"
                                    :sublabel="cutLabel(batch)"
                                    :disabled="isEmptyOpenBatch(batch)"
                                    :title="isEmptyOpenBatch(batch)
                                        ? 'Nothing is waiting on the open batch yet'
                                        : 'The material list for every project on this batch'"
                                    class="w-28"
                                    @click="showBom(batch)"
                                />

                                <!-- What to buy, the way the supplier emails word it - and how each order is going -->
                                <CardButtonYellow
                                    label="Material order"
                                    :sublabel="categoryLabel(batch)"
                                    :disabled="isEmptyOpenBatch(batch)"
                                    :title="isEmptyOpenBatch(batch)
                                        ? 'Nothing is waiting on the open batch yet'
                                        : 'What this batch\'s nest needs from each merchant, with their certificates and deliveries'"
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
                                    v-if="!isEmptyOpenBatch(batch)"
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

                                <!--
                                    And with nothing waiting, the same button with nowhere to go: the
                                    nesting screen would open on an empty nest. Not dropped from the row,
                                    which would leave the open batch's card a different shape from every
                                    other one on the page.
                                -->
                                <div v-else class="w-28">
                                    <CardButtonBlue
                                        label="Nest"
                                        :highlight="false"
                                        disabled
                                        title="Nothing is waiting on the open batch yet"
                                    />
                                </div>

                                <!--
                                    What this card can have done to it, past the three things its
                                    buttons read. Kept out of the row of buttons because those are reads
                                    - a list, an order list, a nest - and what is in here changes the
                                    batch.

                                    The space is held on every card and the dots are drawn only where
                                    there is something behind them (see hasMenu), so a closed batch does
                                    not open an empty box and the buttons still line up down the page.
                                -->
                                <div class="flex justify-end w-8 shrink-0">
                                    <Dropdown v-if="hasMenu(batch)" align="right" width="48">
                                        <template #trigger>
                                            <button
                                                type="button"
                                                title="More actions for this batch"
                                                aria-label="More actions for this batch"
                                                class="inline-flex items-center justify-center w-8 h-8 text-gray-400 transition-colors duration-150 rounded-lg hover:bg-gray-100 hover:text-gray-700"
                                            >
                                                <i class="fa-solid fa-ellipsis-vertical"></i>
                                            </button>
                                        </template>

                                        <template #content>
                                            <!-- The open batch: what can be put on it, and the press that closes it -->
                                            <template v-if="batch.id === null">
                                                <!--
                                                    The open batch is the only card new material can
                                                    join, so the page's "+ Materials" is repeated here -
                                                    same addProject(), same modal. Somebody reading this
                                                    card is already looking at where the upload lands,
                                                    and the button it duplicates is up at the top of the
                                                    page.
                                                -->
                                                <button
                                                    type="button"
                                                    title="Upload a material list onto the open batch"
                                                    @click="addProject()"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm font-semibold text-left text-gray-700 transition-colors duration-150 hover:bg-gray-100"
                                                >
                                                    <i class="fa-solid fa-plus text-xs"></i>
                                                    Materials
                                                </button>

                                                <!--
                                                    The board's own "Start quoting", pressed from the
                                                    card it acts on - see confirmStartQuoting(). Greyed
                                                    rather than dropped when it cannot run, so the menu
                                                    says why instead of being empty.
                                                -->
                                                <button
                                                    type="button"
                                                    :disabled="!canStartQuoting"
                                                    :title="startQuotingTitle"
                                                    @click="confirmStartQuoting()"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="canStartQuoting
                                                        ? 'font-semibold text-green-800 hover:bg-green-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-circle-arrow-down text-xs"></i>
                                                    Lock before quoting
                                                </button>
                                            </template>

                                            <!--
                                                And on a batch out with the suppliers, the three
                                                presses it has: the way back, then the two steps it can
                                                be moved on by without naming a supplier.
                                            -->
                                            <template v-else>
                                                <!--
                                                    The way back - the board's "Re-nest" (see
                                                    confirmReNest). Red, because it destroys the quotes
                                                    and draft orders on the batch and the offcuts it
                                                    cut; greyed with the reason when the batch is past
                                                    unpicking, which is what somebody opens this menu to
                                                    find out. First, because it is the one people come
                                                    to this menu for.
                                                -->
                                                <button
                                                    type="button"
                                                    :disabled="!batch.prerequisiteUndoStartQuoting"
                                                    :title="reNestTitle(batch)"
                                                    @click="confirmReNest(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="batch.prerequisiteUndoStartQuoting
                                                        ? 'font-semibold text-red-700 hover:bg-red-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-circle-arrow-up text-xs"></i>
                                                    Re-nest
                                                </button>

                                                <!--
                                                    "All quoted" - the prices are in. Coloured with the
                                                    Quoted pill it sets, so the menu item and the word
                                                    it puts on the card read as the one step. See
                                                    confirmMarkQuoted().
                                                -->
                                                <button
                                                    v-if="batch.prerequisiteMarkQuoted !== null"
                                                    type="button"
                                                    :disabled="!batch.prerequisiteMarkQuoted"
                                                    :title="markQuotedTitle(batch)"
                                                    @click="confirmMarkQuoted(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="batch.prerequisiteMarkQuoted
                                                        ? 'font-semibold text-blue-800 hover:bg-blue-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-tags text-xs"></i>
                                                    All quoted
                                                </button>

                                                <!-- And "All ordered" - it has been bought. Same again, in the Ordered pill's colour -->
                                                <button
                                                    v-if="batch.prerequisiteMarkOrdered !== null"
                                                    type="button"
                                                    :disabled="!batch.prerequisiteMarkOrdered"
                                                    :title="markOrderedTitle(batch)"
                                                    @click="confirmMarkOrdered(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="batch.prerequisiteMarkOrdered
                                                        ? 'font-semibold text-indigo-800 hover:bg-indigo-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-cart-shopping text-xs"></i>
                                                    All ordered
                                                </button>

                                                <!--
                                                    "All delivered" - the steel is in the rack. Opens
                                                    the certificates modal rather than a confirm box,
                                                    the press being in there with the paperwork it
                                                    arrives with. See openCertificates().
                                                -->
                                                <button
                                                    v-if="batch.prerequisiteMarkDelivered !== null"
                                                    type="button"
                                                    :disabled="!batch.prerequisiteMarkDelivered"
                                                    :title="markDeliveredTitle(batch)"
                                                    @click="openCertificates(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="batch.prerequisiteMarkDelivered
                                                        ? 'font-semibold text-teal-800 hover:bg-teal-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-truck text-xs"></i>
                                                    All delivered
                                                </button>

                                                <!--
                                                    And "Cut". The one mark here that is not about
                                                    buying, so the one a batch ordered the ordinary way
                                                    is offered too - see confirmMarkCut().
                                                -->
                                                <button
                                                    v-if="batch.prerequisiteMarkCut !== null"
                                                    type="button"
                                                    :disabled="!batch.prerequisiteMarkCut"
                                                    :title="markCutTitle(batch)"
                                                    @click="confirmMarkCut(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                    :class="batch.prerequisiteMarkCut
                                                        ? 'font-semibold text-emerald-800 hover:bg-emerald-50'
                                                        : 'text-gray-400 cursor-not-allowed'"
                                                >
                                                    <i class="fa-solid fa-scissors text-xs"></i>
                                                    Cut
                                                </button>

                                                <!--
                                                    The same modal again on a batch already delivered,
                                                    where there is no mark left to set and the files are
                                                    the whole of it. Drawn only where certificates can
                                                    still be attached, which is a live delivered batch -
                                                    a closed one is its own record.
                                                -->
                                                <button
                                                    v-if="batch.canAttachCertificates"
                                                    type="button"
                                                    title="Attach the mill certificates that came in with this batch"
                                                    @click="openCertificates(batch)"
                                                    class="flex items-center w-full gap-2 px-4 py-2 text-sm font-semibold text-left text-gray-700 transition-colors duration-150 hover:bg-gray-100"
                                                >
                                                    <i class="fa-solid fa-plus text-xs"></i>
                                                    Certificates
                                                </button>

                                                <!--
                                                    And the way off the page - the board's "Move to
                                                    done", which went with the board and left nothing
                                                    in the application able to finish a batch. Last
                                                    in the menu because it is the last thing that
                                                    happens to a job, and separated by a rule because
                                                    it is the only item here that makes the card
                                                    disappear. See confirmMarkDone().
                                                -->
                                                <template v-if="batch.prerequisiteMarkDone !== null">
                                                    <span class="block my-1 border-t border-gray-100"></span>

                                                    <button
                                                        type="button"
                                                        :disabled="!batch.prerequisiteMarkDone"
                                                        :title="markDoneTitle(batch)"
                                                        @click="confirmMarkDone(batch)"
                                                        class="flex items-center w-full gap-2 px-4 py-2 text-sm text-left transition-colors duration-150"
                                                        :class="batch.prerequisiteMarkDone
                                                            ? 'font-semibold text-gray-700 hover:bg-gray-100'
                                                            : 'text-gray-400 cursor-not-allowed'"
                                                    >
                                                        <i class="fa-solid fa-box-archive text-xs"></i>
                                                        Move to done
                                                    </button>
                                                </template>
                                            </template>
                                        </template>
                                    </Dropdown>
                                </div>
                            </div>
                        </div>

                        <!--
                            And, on a card that is behind, what to go and do about it.

                            Only when the batch has actually slipped - see actionRequired(). A footer
                            under every card would be a list of instructions to carry on as normal,
                            and the two or three that need doing today would be lost in it.

                            The step is what says which action it is: a batch that is late and still
                            being priced is late at the quoting, whatever is due after that. See
                            ActionRequiredFooter.
                        -->
                        <ActionRequiredFooter
                            v-if="actionRequired(batch)"
                            :stage="batch.stage"
                            :days-behind="batch.daysBehindCriticalPath"
                            :deadline="batch.criticalPathDeadline"
                        />
                    </div>
                </template>

                <!--
                    The business has live batches and none of them are yours. The page is not empty -
                    the switch is holding the rest back - so this offers the way out rather than
                    leaving the switch to be found.

                    One card visible means the open batch and nothing else, that card never being
                    filtered - so this is the switch having hidden every closed batch there is.
                -->
                <div
                    v-if="onlyMine && visibleBatches.length === 1 && hiddenCount > 0"
                    class="px-4 py-6 text-sm text-center text-gray-500 bg-white border border-gray-200 border-dashed rounded-xl"
                >
                    <p>
                        None of your projects are on a live batch.
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
                page that quietly drops a colleague's batch is how two people end up buying the same
                steel. Nothing to say when the switch is off, or when it happens to be hiding nothing -
                and nothing here when it has hidden every closed batch, which the box above says at
                more length.
            -->
            <p
                v-if="onlyMine && hiddenCount > 0 && visibleBatches.length > 1"
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

    <!-- What "Start quoting" is about to take, and whose - see confirmStartQuoting() -->
    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :note="confirmDialog.note"
        :confirmLabel="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
        :acknowledgeOnly="confirmDialog.acknowledgeOnly ?? false"
        @confirm="confirmDialogAccepted()"
        @cancel="confirmDialogCancelled()"
    />

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
        @refresh="refreshOrderList()"
    />

    <BatchCertificatesModal
        :show="showCertificatesModal"
        :batch="certificatesBatch"
        @closeModal="showCertificatesModal = false; certificatesBatch = null;"
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
        :editProject="editProject"
        :bomData="newProjectBomData"
        :refreshNewProject="refreshNewProject"
        :projectAfterUpload="projectAfterUpload"
        :colleagues="colleagues"
        @closeModal="showNewProjectModal = false; newProjectBomData = null;"
        @closeModalOnSuccess="showNewProjectModal = false;"
        @redownload="project => downloadNewProjectBom(project)"
    />
</template>
