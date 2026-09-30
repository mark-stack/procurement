<script setup>
    //General Imports
    import { Head, useForm, usePage } from '@inertiajs/vue3';
    import { computed, ref, watch } from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import InputLabel from "@/Components/InputLabel.vue";
    import InputError from "@/Components/InputError.vue";
    import Checkbox from "@/Components/Checkbox.vue";
    import ConfirmModal from "@/Components/Modals/ConfirmModal.vue";
    import useConfirm from "@/Shared/useConfirm.js";

    //Props
    /*
     * "business" is two fields, not the model: a Business serializes 23 columns including
     * every cost and pricing setting, and this page reads the id and the domain.
     *
     * Templates belong to a business. An upload is matched against that business's own rows
     * and nothing else, so this list is the whole of what its uploads can import.
     */
    const props = defineProps({
        templates: Array,
        business: Object,
        sources: Array,
        types: Array,
    });

    //Form
    /*
     * "screenshot" used to default to a 140KB base64 PNG pasted inline here. It shipped
     * in the bundle and, because the old rule was only "min:50", submitting without
     * touching the field silently stored that placeholder as the real screenshot.
     */
    const blankTemplate = {
        name: null,
        source: "TEKLA",
        type: "CAD_BILL_OF_MATERIALS",
        //The two fields detection is made of
        heading_cell: null,
        expected_heading_labels: [""],
        first_description_cell: null,
        first_material_cell: null,
        first_grade_cell: null,
        first_surface_cell: null,
        first_length_required_cell: null,
        first_width_required_cell: null,
        first_sub_qty_cell: null,
        //Advanced
        skip_or_finish_check_cell: null,
        should_skip_row: null,
        is_last_data_row: null,
        compound_description_prefix: null,
        compound_description_suffix: null,
        compound_description_cells: [],
        assembly_mark_rule: "NONE",
        assembly_mark_cell: null,
        screenshot: null,
        length_width_units: "m",
        web_source: null,
        /*
         * Live on create.
         *
         * This was false, and that was right when a template could be recorded without anybody
         * having watched it read a spreadsheet: you wrote the record, then checked it against a real
         * file, then flipped it on. Passing a test is now the only way to create one at all, so the
         * check has already happened by the time this field is read - and leaving it off meant every
         * template was recorded, verified, and dead, with the only sign a red 0 on the admin users
         * list. Untick it to record one without switching it on.
         */
        active: true,
    };
    const formTemplate = useForm({ ...blankTemplate });
    const formTemplateDelete = useForm({});
    //The sample spreadsheet the form can be read out of, rather than typed out of
    const formSample = useForm({ sample: null });

    /*
     * The seven columns, named once. Each is a cell of the first row of data, and the
     * offset the importer reads is that cell measured against the heading cell.
     */
    const columnFields = [
        { field: "first_description_cell", label: "Description", hint: "What the item is - \"250PFC\"" },
        { field: "first_sub_qty_cell", label: "Sub qty", hint: "How many pieces the row is for" },
        { field: "first_length_required_cell", label: "Length", hint: "Length of each piece" },
        { field: "first_width_required_cell", label: "Width", hint: "Plate and sheet only" },
        { field: "first_material_cell", label: "Material", hint: "Only if it has its own column" },
        { field: "first_grade_cell", label: "Grade", hint: "\"GRADE 300\", \"S355\"" },
        { field: "first_surface_cell", label: "Surface", hint: "Finish or treatment" },
    ];

    /*
     * The fields a test's verdict turns on - everything that changes which cells are read and what
     * comes out of them, and nothing else.
     *
     * This list, and the tidying below it, mirror TemplateTestCertificate on the server: it signs a
     * passing test over exactly these values, so these are the ones whose editing makes a passing test
     * stop describing the form. The name, the screenshot, whether it is live and the documentation link
     * are all deliberately outside it - typing a name after a test has passed must not send an admin
     * back to upload the sample again.
     */
    const testedCellFields = [
        "heading_cell",
        ...columnFields.map((column) => column.field),
        "skip_or_finish_check_cell",
        "assembly_mark_cell",
    ];
    const testedTextFields = [
        "should_skip_row",
        "is_last_data_row",
        "compound_description_prefix",
        "compound_description_suffix",
        "assembly_mark_rule",
        "length_width_units",
    ];
    //Whether each repeater's rows are cell references, which are compared without regard to case
    const testedListFields = {
        expected_heading_labels: false,
        compound_description_cells: true,
    };

    //Every field the parser has an opinion about: everything a spreadsheet can be read for, plus its picture
    const prefillFields = [
        "name",
        "source",
        "type",
        "heading_cell",
        "expected_heading_labels",
        ...columnFields.map((column) => column.field),
        "skip_or_finish_check_cell",
        "should_skip_row",
        "is_last_data_row",
        "compound_description_prefix",
        "compound_description_suffix",
        "compound_description_cells",
        "assembly_mark_rule",
        "assembly_mark_cell",
        "length_width_units",
        //Drawn from the sheet rather than read out of it - see SpreadsheetImage
        "screenshot",
    ];

    //Variables
    const editId = ref(null);
    /*
     * The name of the row being edited, as it was when Edit was pressed - not formTemplate.name,
     * which is the field being typed into. The bar has to keep saying which record is open while
     * that field is being renamed.
     */
    const editName = ref(null);
    //Which fields the parser filled in, and from where. Cleared when the form is.
    const provenance = ref({});
    const advancedOpen = ref(false);
    //Whether the row being edited has a thumbnail, so "leave blank to keep" has something to show
    const editHasScreenshot = ref(false);
    /*
     * Test shares "formTemplate" with Create - it submits the same fields, so its errors belong
     * under the same inputs - which means the form's own "processing" cannot say which of the two
     * is in flight.
     */
    const testing = ref(false);
    //The form as it was when Test ran, so the result can say when it no longer describes the form
    const testedSnapshot = ref(null);
    /*
     * The last test result, kept here rather than read straight off the flash prop.
     *
     * A template cannot be created until its test has passed, and a refused create - a name already
     * taken, a screenshot that is not an image - is a round trip that consumes the flash. Read from
     * the flash alone, the result and with it the unlocked Create button would vanish every time the
     * form was refused for a reason that has nothing to do with the test.
     */
    const lastTest = ref(null);

    //Shared Methods
    const {confirmDialog, askToConfirm, confirmDialogAccepted, confirmDialogCancelled} = useConfirm();

    //Computed
    /*
     * What the parser made of the last sample uploaded: the values it filled in, where each came
     * from, and every check it ran against the file. Flashed, so it is gone on the next visit -
     * which is right, because it describes one upload and not the form's current contents.
     */
    const proposal = computed(() => usePage().props.flash?.templateProposal ?? null);

    const proposalFindings = computed(() => proposal.value?.findings ?? []);
    const proposalErrors = computed(() => proposalFindings.value.filter((finding) => finding.level === "error"));
    const proposalWarnings = computed(() => proposalFindings.value.filter((finding) => finding.level === "warning"));
    const proposalChecksPassed = computed(() => proposalFindings.value.filter((finding) => finding.level === "ok"));

    /*
     * What the importer made of the last sample it was run over with these values: every row it
     * extracted, what would become of each, and the checks that needed a real extraction to answer.
     * Flashed for the same reason the proposal is - it describes one press of one button.
     */
    const testResult = computed(() => usePage().props.flash?.templateTest ?? null);

    const testFindings = computed(() => lastTest.value?.findings ?? []);
    const testErrors = computed(() => testFindings.value.filter((finding) => finding.level === "error"));
    const testWarnings = computed(() => testFindings.value.filter((finding) => finding.level === "warning"));
    const testChecksPassed = computed(() => testFindings.value.filter((finding) => finding.level === "ok"));

    //The named checks, and how many of them are ticks. Shown as the panel's headline.
    const testChecks = computed(() => lastTest.value?.checks ?? []);
    const testChecksPassedCount = computed(() => testChecks.value.filter((check) => check.status === "pass").length);
    const testChecksFailed = computed(() => testChecks.value.filter((check) => check.status === "fail"));

    /*
     * The result describes the values that were tested. Editing a cell after reading it does not
     * make the result wrong, it makes it about something else, and that has to be visible - and now
     * also unlocks nothing, because the server signs a pass over the values it was given.
     */
    const testIsStale = computed(() => testedSnapshot.value !== null
        && testedSnapshot.value !== extractionSnapshot());

    /*
     * Whether this form may be saved. Editing a recorded template is not gated - the gate is on
     * recording one - so in edit mode this is always true.
     *
     * Only ever a courtesy: the store request refuses a template whose test did not pass whatever the
     * button is doing, because a disabled button is not a gate. See StoreTemplateRequest.
     */
    const testPassed = computed(() => Boolean(lastTest.value?.ok && lastTest.value?.passed && lastTest.value?.token));
    const canSubmit = computed(() => Boolean(editId.value) || (testPassed.value && !testIsStale.value));

    //Why Create is not available yet, in the order the admin runs into them
    const blockedReason = computed(() => {
        if(editId.value || canSubmit.value){
            return null;
        }

        if(!lastTest.value){
            return formSample.sample
                ? "Press Test. A template can only be created once its test has passed - every check has to be a tick or a warning."
                : "Choose a sample spreadsheet above and press Test. A template can only be created once its test has passed - every check has to be a tick or a warning.";
        }

        if(!testPassed.value){
            return "This test did not pass. Fix the checks marked with a cross and test again - a template that would import nothing cannot be created.";
        }

        return "The form has changed since the test that passed, so that test is about different values. Test again to create it.";
    });

    /*
     * Whether reading the chosen file would throw away work.
     *
     * Reading one replaces every field of the form with what the parser makes of it. On an empty form
     * that is the whole point and the fastest way to start, which is why choosing a file now reads it
     * without being asked twice. It is not something to do behind the back of an admin who has just
     * typed a column of cell references by hand, or who is editing a recorded template and has picked
     * a sample only to test it against - both of those would lose their work to a file chooser.
     *
     * Values that came from a previous read do not count: they are the parser's, and replacing them
     * with the parser's reading of a different file is exactly what choosing that file meant.
     */
    const readingWouldDiscardWork = computed(() => Boolean(editId.value)
        || (formTemplate.isDirty && Object.keys(provenance.value).length === 0));

    //How many of them this business's uploads are actually matched against
    const liveCount = computed(() => props.templates
        .filter((template) => template.detection.detects).length);

    /*
     * A parsed sample arrives as a flash prop on the response to the upload, so it is applied when
     * the page renders with one rather than in the upload's own callback. "immediate", because the
     * page re-renders after that visit and the watcher would otherwise miss the only value it will
     * ever see.
     */
    watch(proposal, (parsed) => {
        if(!parsed || !parsed.ok){
            return;
        }

        applyPrefill(parsed.prefill);
        provenance.value = parsed.provenance ?? {};

        //The parser fills these in, so an admin who never opens the section cannot see them
        advancedOpen.value = Boolean(
            parsed.prefill.should_skip_row
            || parsed.prefill.is_last_data_row
            || parsed.prefill.assembly_mark_cell
            || (parsed.prefill.compound_description_cells ?? []).length,
        );
    }, { immediate: true });

    /*
     * A test result arrives as a flash prop on the response to pressing Test, and is kept from then on
     * - see lastTest. "immediate" for the same reason the proposal's watcher has it: the page re-renders
     * with the flash, and a watcher without it misses the only value it will ever see.
     */
    watch(testResult, (result) => {
        if(!result){
            return;
        }

        lastTest.value = result;

        /*
         * The screenshot drawn from the sample this test ran over. Applied rather than offered: it is a
         * picture of the file the template was just verified against, which is the whole of what the
         * thumbnail is for. When editing, the stored one is shown beside it with a button to keep it.
         */
        if(result.screenshot){
            formTemplate.screenshot = result.screenshot;
        }
    }, { immediate: true });

    //Methods
    /*
     * The form reduced to the values a test's verdict turns on, tidied the way the server tidies them
     * before it signs them: trimmed, cells upper cased, blank repeater rows dropped. So "b7" becoming
     * "B7" is not a change, and neither is a row an admin added to the labels and left empty.
     */
    function extractionSnapshot(){
        const snapshot = {};

        testedCellFields.forEach((field) => {
            snapshot[field] = canonicalValue(formTemplate[field], true);
        });
        testedTextFields.forEach((field) => {
            snapshot[field] = canonicalValue(formTemplate[field], false);
        });
        Object.entries(testedListFields).forEach(([field, upper]) => {
            snapshot[field] = (formTemplate[field] ?? [])
                .map((value) => canonicalValue(value, upper))
                .filter((value) => value !== null);
        });

        return JSON.stringify(snapshot);
    }
    function canonicalValue(value, upper){
        if(typeof value !== "string" && typeof value !== "number"){
            return null;
        }

        const text = String(value).trim();

        if(!text){
            return null;
        }

        return upper ? text.toUpperCase() : text;
    }
    /*
     * Picking a file in the chooser is the whole of asking for it to be read - see the markup, and
     * readingWouldDiscardWork for the one case where it is offered rather than done.
     *
     * The file is kept either way: it is also the sample Test runs the importer over, which is the
     * reason an admin editing a template picks one at all.
     */
    function chooseSample(file){
        formSample.sample = file ?? null;

        if(file && !readingWouldDiscardWork.value){
            readSample();
        }
    }
    function readSample(){
        formSample.post(route("admin.businesses.templates.propose",props.business.id),{
            preserveScroll: true,
            //forceFormData: an upload cannot travel as JSON
            forceFormData: true,
        });
    }
    /*
     * Run the importer over the chosen sample with the values in the form, without saving them.
     *
     * Submitted as formTemplate so its validation errors land under the fields they are about, which
     * means the sample has to be added on the way out - hence the transform, which every other
     * submission from this form then has to set for itself.
     */
    function runTest(){
        const sample = formSample.sample;
        const tested = extractionSnapshot();

        testing.value = true;

        formTemplate
            //editing_template_id: so a template being edited is not reported as a duplicate of itself
            .transform((data) => ({ ...data, sample, editing_template_id: editId.value }))
            .post(route("admin.businesses.templates.test",props.business.id),{
                preserveScroll: true,
                forceFormData: true,
                onSuccess: () => {
                    testedSnapshot.value = tested;
                },
                onFinish: () => {
                    testing.value = false;
                },
            });
    }
    function toneClasses(tone){
        return {
            ok: "bg-emerald-100/60 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-300",
            warning: "bg-amber-100/60 text-amber-700 dark:bg-amber-900/30 dark:text-amber-300",
            error: "bg-red-100/60 text-red-700 dark:bg-red-900/30 dark:text-red-300",
        }[tone] ?? "";
    }
    /*
     * A check's mark. Only the tick means "asked and answered"; a dash is a check that could not be
     * asked at all, which is a different thing from one that passed and must not look like one.
     */
    function checkMark(status){
        return {
            pass: "✓",
            warning: "⚠",
            fail: "✗",
            skipped: "–",
        }[status] ?? "•";
    }
    function checkClasses(status){
        return {
            pass: "text-emerald-600 dark:text-emerald-400",
            warning: "text-amber-700 dark:text-amber-300",
            fail: "text-red-700 dark:text-red-400",
            skipped: "text-gray-400 dark:text-gray-500",
        }[status] ?? "text-gray-600 dark:text-gray-300";
    }
    /*
     * Everything the parser has an opinion about, the screenshot included - it no longer has to look
     * at a spreadsheet to produce one, it draws the sheet it just read.
     *
     * Not "active", which is a decision about how a template is used rather than a reading of a file.
     */
    function applyPrefill(prefill){
        prefillFields.forEach((field) => {
            if(prefill[field] !== undefined){
                formTemplate[field] = prefill[field];
            }
        });

        //A repeater with no rows shows nothing at all, and the labels are required
        if(!formTemplate.expected_heading_labels?.length){
            formTemplate.expected_heading_labels = [""];
        }

        formTemplate.clearErrors();
    }
    /*
     * Where a field's value came from. Cell references worked out from a matched heading row are
     * arithmetic and the importer will read the same ones; a model's reading of the sheet is a
     * suggestion. Saying which is which is the difference between checking and trusting.
     */
    function provenanceLabel(field){
        return {
            detection: "from a matching template",
            ai: "suggested by AI",
            file: "from the file name",
        }[provenance.value[field]] ?? null;
    }
    function addHeadingLabel(){
        formTemplate.expected_heading_labels.push("");
    }
    function removeHeadingLabel(index){
        formTemplate.expected_heading_labels.splice(index,1);

        if(!formTemplate.expected_heading_labels.length){
            formTemplate.expected_heading_labels.push("");
        }
    }
    function addCompoundCell(){
        formTemplate.compound_description_cells.push("");
    }
    function removeCompoundCell(index){
        formTemplate.compound_description_cells.splice(index,1);
    }
    function screenshotUrl(templateId){
        return route("admin.businesses.templates.screenshot",[props.business.id,templateId]);
    }
    function submit(){
        //Edit mode
        if(editId.value){
            submitUpdate();
        }
        //Create mode
        else{
            submitStore();
        }
    }
    function submitStore(){
        let url = route("admin.businesses.templates.store",props.business.id);
        /*
         * Not the test's payload - runTest() leaves a transform behind that adds the sample file - but
         * it does carry the token that test handed back, which is what the store request checks the
         * values being saved against. Nothing is stored from it.
         */
        formTemplate.transform((data) => ({ ...data, template_test_token: lastTest.value?.token ?? null })).post(url, {
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
            },
        });
    }
    function submitDelete(template){
        askToConfirm({
            title: "Delete this template?",
            message: template.detection.detects
                ? "This template is live. Deleting it means uploads of this spreadsheet stop importing for this business."
                : "This template is not matched against uploads, so deleting it changes nothing about importing.",
            confirmLabel: "Delete template",
            tone: "danger",
            onConfirmed: () => {
                let url = route("admin.businesses.templates.destroy",[props.business.id,template.id]);
                formTemplateDelete.delete(url, {
                    preserveScroll: true,
                });
            },
        });
    }
    function submitUpdate(){
        let url = route("admin.businesses.templates.update",[props.business.id,editId.value]);
        //Same reason as submitStore(): the sample belongs to the test and to nothing else
        formTemplate.transform((data) => data).put(url, {
            preserveScroll: true,
            onSuccess: () => {
                resetForm();
            },
        });
    }
    function initiateUpdate(template){
        editId.value = template.id;
        editName.value = template.name;

        /*
         * Every editable field, "active" included. Leaving "active" out meant it fell
         * back to the form default of false, so saving any edit deactivated the template.
         */
        prefillFields.forEach((field) => {
            formTemplate[field] = template[field];
        });

        formTemplate.expected_heading_labels = [...(template.expected_heading_labels ?? [])];
        formTemplate.compound_description_cells = [...(template.compound_description_cells ?? [])];

        if(!formTemplate.expected_heading_labels.length){
            formTemplate.expected_heading_labels = [""];
        }

        /*
         * Not the screenshot. It is not in the index props any more, and re-sending up to
         * 750KB of base64 to change one word was the reason it had to be. Blank means keep
         * the stored one; the request drops the field rather than blanking the column.
         */
        formTemplate.screenshot = null;
        editHasScreenshot.value = template.has_screenshot;
        formTemplate.web_source = template.web_source;
        formTemplate.active = template.active;

        formTemplate.clearErrors();
        //These values came out of the record being edited, not out of a parsed sample
        provenance.value = {};
        //Any test result on screen was run against the values this has just replaced
        testedSnapshot.value = null;
        lastTest.value = null;
        advancedOpen.value = Boolean(
            template.should_skip_row
            || template.is_last_data_row
            || template.assembly_mark_cell
            || (template.compound_description_cells ?? []).length,
        );

        window.scrollTo({ top: 0, behavior: "smooth" });
    }
    function resetForm(){
        //defaults() first, so reset() does not restore a previously edited template
        formTemplate.defaults({ ...blankTemplate, expected_heading_labels: [""], compound_description_cells: [] });
        formTemplate.reset();
        formTemplate.clearErrors();
        editId.value = null;
        editName.value = null;
        //The badges describe values that are no longer in the form
        provenance.value = {};
        advancedOpen.value = false;
        editHasScreenshot.value = false;
        testedSnapshot.value = null;
        //The next template is its own template, and has to pass its own test
        lastTest.value = null;
    }
</script>

<template>
    <Head title="Templates" />

    <AuthenticatedLayout>
        <div class="py-12">
            <div class="mx-auto max-w-5xl sm:px-6 lg:px-8">
                <section class="bg-white dark:bg-gray-900">
                    <!--
                        Edit mode used to be a wash of yellow over the whole section and a heading
                        that changed one word. Both scrolled away, and neither said which of the
                        rows below was open. This bar does, and it stays on screen for the length
                        of a form that is several screens long - which is also why Save is on it.
                    -->
                    <div
                        v-if="editId"
                        class="sticky top-0 z-20 flex flex-wrap items-center justify-between px-4 py-3 border-b shadow-sm gap-x-4 gap-y-2 border-amber-300 bg-amber-50/95 backdrop-blur dark:border-amber-700 dark:bg-amber-950/90"
                    >
                        <div class="flex items-center min-w-0 gap-x-3">
                            <span class="px-2 py-0.5 text-xs font-semibold tracking-wide uppercase rounded-full shrink-0 text-amber-900 bg-amber-200 dark:bg-amber-800 dark:text-amber-100">
                                Editing
                            </span>
                            <p class="text-sm font-medium truncate text-amber-900 dark:text-amber-100">
                                {{editName}}
                            </p>
                        </div>

                        <div class="flex items-center gap-x-2">
                            <button
                                type="button"
                                class="px-3 py-1.5 text-sm font-medium transition-colors duration-200 border rounded-md border-amber-400 text-amber-900 hover:bg-amber-100 focus:outline-none dark:border-amber-700 dark:text-amber-100 dark:hover:bg-amber-900/40"
                                @click="resetForm()"
                            >
                                Cancel
                            </button>
                            <!-- The same submit as the one at the foot of the form, within reach of the top of it -->
                            <button
                                type="button"
                                :disabled="formTemplate.processing"
                                class="px-3 py-1.5 text-sm font-medium text-white transition-colors duration-200 bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none disabled:opacity-50"
                                @click="submit()"
                            >
                                {{formTemplate.processing && !testing ? 'Saving…' : 'Save changes'}}
                            </button>
                        </div>
                    </div>

                    <div class="max-w-3xl px-6 py-10 mx-auto">
                        <h1 class="text-2xl font-semibold text-center text-gray-800 dark:text-gray-100 sm:text-3xl">
                            {{editId ? 'Edit' : 'Record'}} Template for {{business.domain}}
                        </h1>
                        <p v-if="!editId" class="max-w-md mx-auto mt-3 text-center text-gray-500 dark:text-gray-400">
                            Describe an Excel template so uploads of it import.
                        </p>

                        <!--
                            This used to say the opposite - "reference only", because detection was
                            driven by config/TableTemplates.php and a row here changed nothing. The
                            rows are the detection now, so the warning that mattered is the other
                            one: saving this is a live change to what real uploads do.
                        -->
                        <p class="px-4 py-3 mt-6 text-sm border rounded-md text-emerald-800 bg-emerald-50 border-emerald-200 dark:bg-emerald-900/20 dark:text-emerald-300 dark:border-emerald-800">
                            <strong>This is live.</strong>
                            Uploads are matched against every active template below &mdash; this
                            business. A template you create here is active from the moment it is
                            saved, because it cannot be saved until its test has passed; untick
                            &ldquo;Active&rdquo; to record one without switching it on. No code
                            change is needed either way.
                        </p>

                        <!--
                            Recorded, tested, and switched off - which looks like nothing is wrong
                            from this screen, because every row is a row somebody finished. The only
                            place it showed was a red 0 in the Templates column of the admin users
                            list, which reads as "no templates recorded" and is a different problem.
                        -->
                        <p
                            v-if="templates.length && !liveCount"
                            class="px-4 py-3 mt-3 text-sm border rounded-md text-amber-800 bg-amber-50 border-amber-200 dark:bg-amber-900/20 dark:text-amber-300 dark:border-amber-800"
                        >
                            &#9888; <strong>None of these {{templates.length}} templates is active</strong>, so uploads
                            from this business still import nothing. Edit one, tick
                            &ldquo;Active&rdquo; and save it to switch it on.
                        </p>

                        <!--
                            Reading the form out of a sample spreadsheet, rather than typing a
                            column of cell references out of one by eye. Writes nothing: it fills
                            the fields below, and the form underneath still has to be submitted.
                        -->
                        <div class="mt-6 border border-gray-200 rounded-lg dark:border-gray-700">
                            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                <h2 class="font-medium text-gray-800 dark:text-gray-100">Fill this in from a sample</h2>
                                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                    Choose one of this business's spreadsheets and it is read straight
                                    away. If a template already matches it, its columns are placed
                                    exactly; anything else is read by AI and marked as a suggestion.
                                    The file's contents are sent to OpenAI.
                                </p>
                            </div>

                            <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                                <!--
                                    Choosing the file is the whole gesture. There was a "Read
                                    spreadsheet" button next to this, and nobody ever chose a sample
                                    and then decided against reading it - it was a second click to
                                    confirm the first one.
                                -->
                                <input
                                    type="file"
                                    accept=".xls,.xlsx,.csv"
                                    class="text-sm text-gray-600 dark:text-gray-300"
                                    :disabled="formSample.processing"
                                    @input="chooseSample($event.target.files[0])"
                                />

                                <span v-if="formSample.processing" class="text-sm text-emerald-700 dark:text-emerald-400">
                                    Reading&hellip;
                                </span>

                                <!--
                                    Reading by hand, which is now the exception rather than the
                                    button. Two of them:

                                    - it was not read automatically, because reading replaces every
                                      field and there is something here worth keeping. Said out loud
                                      and offered, rather than done quietly or not mentioned;
                                    - it was, and this is the way back - a read that failed at OpenAI
                                      would otherwise need a different file chosen before this one
                                      could be tried again, because choosing the same file twice is
                                      not something a file chooser reports.
                                -->
                                <span v-else-if="formSample.sample" class="flex flex-wrap items-center text-sm gap-x-2">
                                    <span v-if="readingWouldDiscardWork" class="text-gray-500 dark:text-gray-400">
                                        Not read &mdash; it would replace
                                        {{editId ? 'the template you are editing' : 'the values already in the form'}}.
                                    </span>
                                    <button type="button" class="text-blue-500 underline" @click="readSample()">
                                        {{readingWouldDiscardWork ? 'Read it anyway' : 'Read again'}}
                                    </button>
                                </span>

                                <InputError :message="formSample.errors.sample"/>
                                <!-- The same file both buttons use: read the form out of it, then test the form against it -->
                                <small class="w-full text-xs text-gray-500 dark:text-gray-400">
                                    The file chosen here is also what <strong>Test</strong> below runs the
                                    importer over, and a passing test is what lets a template be created.
                                    The rows it extracts are sent to OpenAI to be read as a materials list.
                                </small>
                            </div>

                            <!-- What came back: the values, where each came from, and every check run on the file -->
                            <div v-if="proposal" class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                                <p v-if="!proposal.ok" class="text-sm text-red-600 dark:text-red-400">
                                    {{proposal.message}}
                                </p>

                                <template v-else>
                                    <p class="text-sm text-gray-700 dark:text-gray-300">
                                        Read <strong>{{proposal.file}}</strong>.
                                        <span v-if="proposal.detection">
                                            It already matches <strong>{{proposal.detection.name}}</strong>,
                                            whose heading row is row {{proposal.detection.heading_row}} starting at column
                                            {{proposal.detection.heading_column}}, so the cells below are the ones the
                                            importer itself reads.
                                        </span>
                                        <span v-else>
                                            No template matches it yet, so the cells below are suggestions &mdash;
                                            and saving them is what makes this spreadsheet import.
                                        </span>
                                    </p>

                                    <!-- The model, its confidence and what it says it did - or why it was not asked -->
                                    <p v-if="proposal.ai.used" class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                        <span class="inline-flex px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                            {{proposal.ai.model}} &middot; {{proposal.ai.confidence}} confidence
                                        </span>
                                        <span class="block mt-1">{{proposal.ai.notes}}</span>
                                    </p>
                                    <p v-else-if="proposal.ai.error" class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                        {{proposal.ai.error}}
                                    </p>

                                    <!-- Refused on save: a record that could not import anything -->
                                    <ul v-if="proposalErrors.length" class="mt-3 space-y-1 text-sm text-red-700 dark:text-red-400">
                                        <li v-for="(finding,index) in proposalErrors" :key="'error-'+index">&#10007; {{finding.message}}</li>
                                    </ul>

                                    <!-- Saved anyway, on purpose - a real spreadsheet is allowed to be odd -->
                                    <ul v-if="proposalWarnings.length" class="mt-3 space-y-1 text-sm text-amber-700 dark:text-amber-300">
                                        <li v-for="(finding,index) in proposalWarnings" :key="'warning-'+index">&#9888; {{finding.message}}</li>
                                    </ul>

                                    <ul v-if="proposalChecksPassed.length" class="mt-3 space-y-1 text-sm text-emerald-700 dark:text-emerald-400">
                                        <li v-for="(finding,index) in proposalChecksPassed" :key="'ok-'+index">&#10003; {{finding.message}}</li>
                                    </ul>
                                </template>
                            </div>
                        </div>

                        <div class="mt-4">
                            <form @submit.prevent="submit()">
                                <div class="grid grid-cols-3 gap-3 mt-4">
                                    <!-- name -->
                                    <div>
                                        <InputLabel value="Name*"/>
                                        <input
                                            v-model="formTemplate.name"
                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            type="text"
                                            placeholder="Tekla Assembly List"
                                            maxlength="255"
                                        />
                                        <InputError :message="formTemplate.errors.name"/>
                                        <small v-if="provenanceLabel('name')" class="block text-xs text-gray-500 dark:text-gray-400">{{provenanceLabel('name')}}</small>
                                    </div>

                                    <!-- source -->
                                    <div>
                                        <InputLabel value="Produced by*"/>
                                        <select
                                            v-model="formTemplate.source"
                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option v-for="source in sources" :key="source" :value="source">{{source}}</option>
                                        </select>
                                        <InputError :message="formTemplate.errors.source"/>
                                    </div>

                                    <!-- type -->
                                    <div>
                                        <InputLabel value="Kind of document*"/>
                                        <select
                                            v-model="formTemplate.type"
                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option v-for="type in types" :key="type" :value="type">{{type}}</option>
                                        </select>
                                        <InputError :message="formTemplate.errors.type"/>
                                    </div>
                                </div>

                                <!--
                                    Detection. These two fields are the whole of it, which is why
                                    they sit in their own box rather than in the grid above: the
                                    labels find the table anywhere in a sheet, and the heading cell
                                    is the origin every column below is measured from.
                                -->
                                <div class="p-4 mt-4 border border-indigo-200 rounded-lg bg-indigo-50/40 dark:border-indigo-800 dark:bg-indigo-900/10">
                                    <h3 class="font-medium text-gray-800 dark:text-gray-100">How an upload is recognised</h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        A file matches when one of its rows carries these labels, in this order.
                                        They are compared exactly, so copy them as written &mdash; &ldquo;Length (mm)&rdquo;
                                        and &ldquo;Length[mm]&rdquo; are different labels.
                                    </p>

                                    <div class="grid grid-cols-1 gap-3 mt-3 sm:grid-cols-3">
                                        <div class="sm:col-span-2">
                                            <InputLabel value="Heading labels, in order*"/>
                                            <div
                                                v-for="(label,index) in formTemplate.expected_heading_labels"
                                                :key="'label-'+index"
                                                class="flex items-center mt-1 gap-x-2"
                                            >
                                                <input
                                                    v-model="formTemplate.expected_heading_labels[index]"
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    type="text"
                                                    :placeholder="'Label ' + (index + 1)"
                                                    maxlength="255"
                                                />
                                                <button
                                                    type="button"
                                                    class="px-2 py-1 text-sm text-gray-500 hover:text-red-500"
                                                    @click="removeHeadingLabel(index)"
                                                >
                                                    &times;
                                                </button>
                                            </div>
                                            <button
                                                type="button"
                                                class="mt-2 text-sm text-blue-500 underline"
                                                @click="addHeadingLabel()"
                                            >
                                                Add a label
                                            </button>
                                            <InputError :message="formTemplate.errors.expected_heading_labels"/>
                                            <small v-if="provenanceLabel('expected_heading_labels')" class="block text-xs text-gray-500 dark:text-gray-400">{{provenanceLabel('expected_heading_labels')}}</small>
                                        </div>

                                        <div>
                                            <InputLabel value="Heading cell*"/>
                                            <input
                                                v-model="formTemplate.heading_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g A6"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.heading_cell"/>
                                            <small class="block mt-1 text-xs text-gray-500 dark:text-gray-400">
                                                The cell the first label above sits in. Every column
                                                is measured from it, so the template still works when
                                                the table moves down the page.
                                            </small>
                                            <small v-if="provenanceLabel('heading_cell')" class="block text-xs text-gray-500 dark:text-gray-400">{{provenanceLabel('heading_cell')}}</small>
                                        </div>
                                    </div>
                                </div>

                                <!--
                                    The columns, as cells of the FIRST ROW OF DATA. All on one row:
                                    a record naming three different rows describes no table, and is
                                    refused.
                                -->
                                <div class="p-4 mt-4 border border-gray-200 rounded-lg dark:border-gray-700">
                                    <h3 class="font-medium text-gray-800 dark:text-gray-100">Which column is which</h3>
                                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                        A cell from the <strong>first row of data</strong> for each column the
                                        spreadsheet has. Leave a column blank if there is no such column.
                                    </p>

                                    <div class="grid grid-cols-2 gap-3 mt-3 sm:grid-cols-4">
                                        <div v-for="column in columnFields" :key="column.field">
                                            <InputLabel :value="column.label"/>
                                            <input
                                                v-model="formTemplate[column.field]"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B7"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors[column.field]"/>
                                            <small class="block text-xs text-gray-500 dark:text-gray-400">{{column.hint}}</small>
                                            <small v-if="provenanceLabel(column.field)" class="block text-xs text-gray-500 dark:text-gray-400">{{provenanceLabel(column.field)}}</small>
                                        </div>
                                    </div>
                                </div>

                                <!--
                                    Everything a spreadsheet only occasionally needs. Collapsed, and
                                    opened by the parser when it has something to put in it - an
                                    admin who never opens it would otherwise not see what it read.
                                -->
                                <details :open="advancedOpen" class="p-4 mt-4 border border-gray-200 rounded-lg dark:border-gray-700">
                                    <summary class="font-medium text-gray-800 cursor-pointer dark:text-gray-100">
                                        Advanced &mdash; where the table stops, marks, built-up descriptions
                                    </summary>

                                    <div class="grid grid-cols-1 gap-3 mt-4 sm:grid-cols-3">
                                        <div>
                                            <InputLabel value="Skip / end check cell"/>
                                            <input
                                                v-model="formTemplate.skip_or_finish_check_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g B7"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.skip_or_finish_check_cell"/>
                                            <small class="block text-xs text-gray-500 dark:text-gray-400">
                                                The column the two rules below watch. Blank follows the description column.
                                            </small>
                                        </div>

                                        <div>
                                            <InputLabel value="Skip rows whose check cell reads"/>
                                            <input
                                                v-model="formTemplate.should_skip_row"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g Subtotal"
                                                maxlength="255"
                                            />
                                            <InputError :message="formTemplate.errors.should_skip_row"/>
                                            <small class="block text-xs text-gray-500 dark:text-gray-400">
                                                Blank skips rows whose check cell is empty.
                                            </small>
                                        </div>

                                        <div>
                                            <InputLabel value="Stop when the check cell reads"/>
                                            <input
                                                v-model="formTemplate.is_last_data_row"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g Total"
                                                maxlength="255"
                                            />
                                            <InputError :message="formTemplate.errors.is_last_data_row"/>
                                            <small class="block text-xs text-gray-500 dark:text-gray-400">
                                                Blank stops at two blank check cells in a row.
                                            </small>
                                        </div>

                                        <div>
                                            <InputLabel value="Assembly mark"/>
                                            <select
                                                v-model="formTemplate.assembly_mark_rule"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            >
                                                <option value="NONE">No mark</option>
                                                <option value="COLUMN">From a column, per row</option>
                                                <option value="FIXED">From one cell, for every row</option>
                                            </select>
                                            <InputError :message="formTemplate.errors.assembly_mark_rule"/>
                                        </div>

                                        <div v-if="formTemplate.assembly_mark_rule !== 'NONE'">
                                            <InputLabel :value="formTemplate.assembly_mark_rule === 'COLUMN' ? 'Mark cell (first data row)' : 'Mark cell (the one cell)'"/>
                                            <input
                                                v-model="formTemplate.assembly_mark_cell"
                                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                type="text"
                                                placeholder="e.g A7"
                                                maxlength="7"
                                            />
                                            <InputError :message="formTemplate.errors.assembly_mark_cell"/>
                                        </div>
                                    </div>

                                    <!--
                                        For tables with no description column at all. The bolt
                                        summaries are the example: "M" + diameter + grade + length
                                        + "mm" is the only thing that says what the row is.
                                    -->
                                    <div class="pt-4 mt-4 border-t border-gray-200 dark:border-gray-700">
                                        <InputLabel value="Description built from other columns"/>
                                        <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                            Only for a table with no description column. The cells are joined
                                            with spaces, in order, between the prefix and the suffix.
                                        </p>

                                        <div class="flex flex-wrap items-end gap-2 mt-2">
                                            <div class="w-24">
                                                <InputLabel value="Prefix"/>
                                                <input
                                                    v-model="formTemplate.compound_description_prefix"
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    type="text"
                                                    placeholder="M"
                                                    maxlength="50"
                                                />
                                            </div>

                                            <div
                                                v-for="(cell,index) in formTemplate.compound_description_cells"
                                                :key="'compound-'+index"
                                                class="w-24"
                                            >
                                                <InputLabel :value="'Cell ' + (index + 1)"/>
                                                <div class="flex items-center gap-x-1">
                                                    <input
                                                        v-model="formTemplate.compound_description_cells[index]"
                                                        class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                        type="text"
                                                        placeholder="A8"
                                                        maxlength="7"
                                                    />
                                                    <button
                                                        type="button"
                                                        class="text-sm text-gray-500 hover:text-red-500"
                                                        @click="removeCompoundCell(index)"
                                                    >
                                                        &times;
                                                    </button>
                                                </div>
                                            </div>

                                            <div class="w-24">
                                                <InputLabel value="Suffix"/>
                                                <input
                                                    v-model="formTemplate.compound_description_suffix"
                                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                                    type="text"
                                                    placeholder="mm"
                                                    maxlength="50"
                                                />
                                            </div>

                                            <button
                                                type="button"
                                                class="pb-2 text-sm text-blue-500 underline"
                                                @click="addCompoundCell()"
                                            >
                                                Add a cell
                                            </button>
                                        </div>
                                        <InputError :message="formTemplate.errors.compound_description_cells"/>
                                    </div>
                                </details>

                                <div class="grid grid-cols-3 gap-3 mt-4">
                                    <!--
                                        screenshot

                                        Drawn, not pasted. This used to be a single-line input asking
                                        for 750KB of base64 and a link to somebody's CodePen for
                                        converting a screenshot into one, per template. Reading or
                                        testing a sample now draws the picture from the sheet itself,
                                        so there is nothing here to fill in - only something to look at.
                                    -->
                                    <div class="col-span-2">
                                        <InputLabel value="Screenshot"/>
                                        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                            Drawn from the sample spreadsheet, with the heading row and the
                                            first row of data marked and every recorded column tagged.
                                        </p>

                                        <div class="flex flex-wrap items-start mt-2 gap-x-6 gap-y-3">
                                            <!-- The one this save would store -->
                                            <figure v-if="formTemplate.screenshot" class="flex items-center gap-x-2">
                                                <img
                                                    class="object-contain w-32 h-20 border rounded bg-gray-50 border-emerald-300 dark:border-emerald-700 dark:bg-gray-800"
                                                    :src="formTemplate.screenshot"
                                                    alt="Screenshot drawn from the sample spreadsheet"
                                                />
                                                <figcaption class="text-xs text-gray-500 dark:text-gray-400">
                                                    <span class="block">{{editId ? 'New, from the sample just read' : 'Stored with this template'}}</span>
                                                    <!-- Replacing a curated screenshot is a decision, so it stays undoable -->
                                                    <button
                                                        v-if="editId && editHasScreenshot"
                                                        type="button"
                                                        class="mt-1 text-blue-500 underline"
                                                        @click="formTemplate.screenshot = null"
                                                    >
                                                        Keep the stored one instead
                                                    </button>
                                                </figcaption>
                                            </figure>

                                            <!-- What is stored today, so "keep the stored one" means something -->
                                            <figure v-if="editId && editHasScreenshot" class="flex items-center gap-x-2">
                                                <img
                                                    class="object-contain w-32 h-20 border border-gray-200 rounded bg-gray-50 dark:border-gray-700 dark:bg-gray-800"
                                                    :src="screenshotUrl(editId)"
                                                    alt="Current screenshot"
                                                />
                                                <figcaption class="text-xs text-gray-500 dark:text-gray-400">Currently stored</figcaption>
                                            </figure>

                                            <p
                                                v-if="!formTemplate.screenshot && !(editId && editHasScreenshot)"
                                                class="text-xs text-gray-500 dark:text-gray-400"
                                            >
                                                None yet &mdash; read or test a sample spreadsheet above and one is drawn from it.
                                                A template with no screenshot is listed without a thumbnail, and nothing else changes.
                                            </p>
                                        </div>

                                        <InputError :message="formTemplate.errors.screenshot"/>
                                    </div>

                                    <!-- length_width_units -->
                                    <div>
                                        <InputLabel value="Length/width units*"/>
                                        <select
                                            v-model="formTemplate.length_width_units"
                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                        >
                                            <option value="m">Meters (m)</option>
                                            <option value="mm">Millimeters (mm)</option>
                                        </select>
                                        <InputError :message="formTemplate.errors.length_width_units"/>

                                        <!--
                                            Recorded, not applied. CsvService::normaliseLengthWidthRequired()
                                            ignores the template's units on purpose, and normalisedLength()
                                            applies "below 20 must be meters" whatever they say. Saying so
                                            here, because the field otherwise reads as a setting.
                                        -->
                                        <small class="block mt-1 text-gray-500 dark:text-gray-400">
                                            Recorded for reference. The importer does not read this: lengths
                                            are converted by the &ldquo;below 20 must be meters&rdquo; rule
                                            in <code>normalisedLength()</code> regardless.
                                        </small>
                                    </div>

                                    <!-- web_source -->
                                    <div class="col-span-3">
                                        <InputLabel value="Where this report format is documented"/>
                                        <input
                                            v-model="formTemplate.web_source"
                                            class="w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"
                                            type="text"
                                            placeholder="https://…"
                                        />
                                        <InputError :message="formTemplate.errors.web_source"/>
                                    </div>

                                    <!--
                                        The two decisions a spreadsheet cannot be read for. Active
                                        used to decide nothing at all; it is now what puts the
                                        template in front of real uploads.
                                    -->
                                    <div class="col-span-3 flex flex-wrap items-center gap-x-6 gap-y-2">
                                        <div class="flex items-center gap-x-2">
                                            <Checkbox
                                                :checked="formTemplate.active"
                                                @update:checked="formTemplate.active = $event"
                                            />
                                            <InputLabel value="Active — match uploads against this"/>
                                            <InputError :message="formTemplate.errors.active"/>
                                        </div>

                                    </div>
                                </div>

                                <!--
                                    Test before Create, and now Test *or no* Create: a record can pass
                                    every check on this page and still import no steel, so the only
                                    thing that says it works is running the importer over a real
                                    spreadsheet and seeing materials come out. It saves nothing either
                                    way. Editing a recorded template is not gated - the gate is on
                                    recording one - so Update is always available.
                                -->
                                <div class="flex flex-wrap items-center gap-3 mt-4">
                                    <button
                                        type="button"
                                        :disabled="formTemplate.processing || !formSample.sample"
                                        class="px-4 py-2 text-sm font-medium tracking-wide transition-colors duration-300 transform border rounded-md text-emerald-800 border-emerald-700 hover:bg-emerald-50 focus:outline-none disabled:opacity-50 dark:text-emerald-300 dark:hover:bg-emerald-900/20"
                                        @click="runTest()"
                                    >
                                        {{testing ? 'Testing…' : (editId ? 'Test' : 'Test (required)')}}
                                    </button>

                                    <button
                                        type="submit"
                                        :disabled="formTemplate.processing || !canSubmit"
                                        class="flex-1 px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform bg-blue-700 rounded-md hover:bg-blue-600 focus:outline-none focus:bg-blue-600 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        <span v-if="editId">Updat{{formTemplate.processing && !testing ? 'ing...' : 'e'}}</span>
                                        <span v-else>Creat{{formTemplate.processing && !testing ? 'ing...' : 'e'}}</span>
                                    </button>
                                </div>

                                <!-- Why Create is not available, which is the whole of what the gate owes an admin -->
                                <p v-if="blockedReason" class="mt-2 text-xs text-amber-700 dark:text-amber-300">
                                    &#9888; {{blockedReason}}
                                </p>
                                <p v-else-if="!formSample.sample" class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                                    Choose a sample spreadsheet above to test this template against one.
                                </p>
                                <p v-else-if="!editId" class="mt-2 text-xs text-emerald-700 dark:text-emerald-400">
                                    &#10003; Every check passed for these values, so this template can be created.
                                    <template v-if="formTemplate.active">
                                        It goes live on save &mdash; uploads of this spreadsheet start importing.
                                    </template>
                                    <template v-else>
                                        &ldquo;Active&rdquo; is unticked, so it is recorded without being matched against uploads.
                                    </template>
                                </p>
                                <InputError :message="formTemplate.errors.sample"/>
                                <InputError :message="formTemplate.errors.template_test_token"/>
                            </form>

                            <!--
                                What the importer did with these values. Not a rehearsal of it: the
                                rows below came out of CsvService itself, and the verdict on each one
                                is the gate it would fall at in a real import.
                            -->
                            <div v-if="lastTest" class="mt-6 border border-gray-200 rounded-lg dark:border-gray-700">
                                <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h2 class="font-medium text-gray-800 dark:text-gray-100">
                                            What this template imports
                                        </h2>

                                        <!-- The verdict, in one word, because it is what Create turns on -->
                                        <span
                                            :class="testPassed && !testIsStale ? toneClasses('ok') : toneClasses('error')"
                                            class="px-3 py-1 text-xs font-semibold rounded-full"
                                        >
                                            {{testPassed && !testIsStale ? 'Test passed' : 'Test not passed'}}
                                        </span>
                                    </div>

                                    <p v-if="!lastTest.ok" class="mt-1 text-sm text-red-600 dark:text-red-400">
                                        {{lastTest.message}}
                                    </p>

                                    <template v-else>
                                        <p class="mt-1 text-sm text-gray-700 dark:text-gray-300">
                                            <strong>{{lastTest.headline}}</strong>
                                            Read from {{lastTest.file}}, saving nothing.
                                        </p>

                                        <!--
                                            The values have moved on since this was run, so the rows
                                            below describe a template that is no longer in the form.
                                        -->
                                        <p v-if="testIsStale" class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                            &#9888; The form has changed since this test ran. Test again to see what the current values extract.
                                        </p>

                                        <div v-if="lastTest.summary.counts.length" class="flex flex-wrap gap-2 mt-3">
                                            <span
                                                v-for="count in lastTest.summary.counts"
                                                :key="count.status"
                                                :class="toneClasses(count.tone)"
                                                class="px-3 py-1 text-xs rounded-full"
                                            >
                                                {{count.count}} {{count.label.toLowerCase()}}
                                            </span>
                                        </div>
                                    </template>
                                </div>

                                <!--
                                    The checks, every one named and answered. This is the gate made
                                    visible: a cross is a template that cannot be created, a warning is
                                    something odd that is allowed to be odd, and a dash is a check that
                                    could not be asked at all - which is not the same as one that passed.
                                -->
                                <div v-if="testChecks.length" class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">Checks performed</h3>
                                        <span class="text-xs text-gray-500 dark:text-gray-400">
                                            {{testChecksPassedCount}} of {{testChecks.length}} passed<span v-if="testChecksFailed.length">, {{testChecksFailed.length}} blocking</span>
                                        </span>
                                    </div>

                                    <ul class="mt-3 space-y-2">
                                        <li v-for="check in testChecks" :key="check.key" class="flex text-sm gap-x-2">
                                            <span :class="checkClasses(check.status)" class="w-4 font-semibold text-center shrink-0">
                                                {{checkMark(check.status)}}
                                            </span>
                                            <span class="min-w-0">
                                                <span :class="checkClasses(check.status)">{{check.label}}</span>
                                                <small class="block text-xs text-gray-500 dark:text-gray-400">{{check.detail}}</small>
                                            </span>
                                        </li>
                                    </ul>
                                </div>

                                <!--
                                    What a reader who knows steel made of the rows themselves. The
                                    checks above carry its answers; this is its own words, and the
                                    specific rows it objected to.
                                -->
                                <div v-if="lastTest.review" class="px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                                    <h3 class="text-sm font-semibold text-gray-800 dark:text-gray-100">What AI made of the extracted rows</h3>

                                    <template v-if="lastTest.review.used">
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                                            <span class="inline-flex px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300">
                                                {{lastTest.review.model}} &middot; {{lastTest.review.verdict}} &middot; {{lastTest.review.confidence}} confidence
                                            </span>
                                            <span class="block mt-1">{{lastTest.review.summary}}</span>
                                        </p>

                                        <ul v-if="lastTest.review.issues.length" class="mt-2 space-y-1 text-sm text-amber-700 dark:text-amber-300">
                                            <li v-for="(issue,index) in lastTest.review.issues" :key="'review-issue-'+index">&#9888; {{issue}}</li>
                                        </ul>
                                    </template>

                                    <!-- Unreachable, which is not evidence against a template and blocks nothing -->
                                    <p v-else class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{lastTest.review.error}}
                                    </p>
                                </div>

                                <template v-if="lastTest.ok">
                                    <!--
                                        The same three lists the parser shows, answered by a real
                                        extraction. Cell by cell rather than check by check: the list
                                        above says whether the lengths came off every row, and this one
                                        says that D7 holds 9000.
                                    -->
                                    <div
                                        v-if="testFindings.length"
                                        class="px-4 py-3 border-b border-gray-200 dark:border-gray-700"
                                    >
                                        <h3 class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-100">Cell by cell</h3>

                                        <ul v-if="testErrors.length" class="space-y-1 text-sm text-red-700 dark:text-red-400">
                                            <li v-for="(finding,index) in testErrors" :key="'test-error-'+index">&#10007; {{finding.message}}</li>
                                        </ul>

                                        <ul v-if="testWarnings.length" class="mt-2 space-y-1 text-sm text-amber-700 dark:text-amber-300">
                                            <li v-for="(finding,index) in testWarnings" :key="'test-warning-'+index">&#9888; {{finding.message}}</li>
                                        </ul>

                                        <ul v-if="testChecksPassed.length" class="mt-2 space-y-1 text-sm text-emerald-700 dark:text-emerald-400">
                                            <li v-for="(finding,index) in testChecksPassed" :key="'test-ok-'+index">&#10003; {{finding.message}}</li>
                                        </ul>
                                    </div>

                                    <!-- The materials themselves, row by row, as the importer read them -->
                                    <div v-if="lastTest.rows.length" class="overflow-auto max-h-96">
                                        <table class="min-w-full text-sm divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <th scope="col" class="px-3 py-2 font-normal text-left text-gray-500 dark:text-gray-400">Row</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-left text-gray-500 dark:text-gray-400">Description</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-left text-gray-500 dark:text-gray-400">Read as</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-right text-gray-500 dark:text-gray-400">Qty</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-right text-gray-500 dark:text-gray-400">Length</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-right text-gray-500 dark:text-gray-400">Width</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-left text-gray-500 dark:text-gray-400">Mark</th>
                                                    <th scope="col" class="px-3 py-2 font-normal text-left text-gray-500 dark:text-gray-400">Verdict</th>
                                                </tr>
                                            </thead>
                                            <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                                <tr v-for="(row,index) in lastTest.rows" :key="'test-row-'+index">
                                                    <td class="px-3 py-2 text-gray-500 align-top whitespace-nowrap dark:text-gray-400">
                                                        {{row.sheet_row}}
                                                        <!-- Which of the tables in the file this row came out of -->
                                                        <small v-if="lastTest.tables.length > 1" class="block text-xs">table {{row.table}}</small>
                                                    </td>
                                                    <td class="px-3 py-2 align-top">
                                                        <span class="font-medium text-gray-800 dark:text-gray-100">{{row.description}}</span>
                                                        <!--
                                                            The columns that are not critical but are
                                                            read: an empty one here is a column that
                                                            is either absent or in the wrong place.
                                                        -->
                                                        <small v-if="row.material || row.grade || row.surface" class="block text-xs text-gray-500 dark:text-gray-400">
                                                            {{[row.material,row.grade,row.surface].filter((value) => value).join(' · ')}}
                                                        </small>
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-600 align-top whitespace-nowrap dark:text-gray-300">
                                                        {{row.product_category ?? '—'}}
                                                        <!-- More than one is what the customer has to clarify before nesting -->
                                                        <small v-if="row.matches > 1" class="block text-xs">{{row.matches}} matches</small>
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-gray-600 align-top whitespace-nowrap dark:text-gray-300">
                                                        {{row.sub_qty ?? '—'}}
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-gray-600 align-top whitespace-nowrap dark:text-gray-300">
                                                        {{row.length_required ?? '—'}}
                                                        <!--
                                                            What the importer stores, after the
                                                            "below 20 must be meters" rule. Shown
                                                            when it differs, because a 9 becoming
                                                            9000 is the conversion most worth seeing.
                                                        -->
                                                        <small v-if="row.length_mm !== null && row.length_mm !== row.length_required" class="block text-xs">
                                                            &rarr; {{row.length_mm}}mm
                                                        </small>
                                                    </td>
                                                    <td class="px-3 py-2 text-right text-gray-600 align-top whitespace-nowrap dark:text-gray-300">
                                                        {{row.width_required ?? '—'}}
                                                    </td>
                                                    <td class="px-3 py-2 text-gray-600 align-top dark:text-gray-300">
                                                        {{row.assembly_mark ?? '—'}}
                                                    </td>
                                                    <td class="px-3 py-2 align-top">
                                                        <span :class="toneClasses(row.tone)" class="inline-flex px-2 py-0.5 text-xs rounded-full whitespace-nowrap">
                                                            {{row.status_label}}
                                                        </span>
                                                        <small v-if="row.note" class="block max-w-sm mt-1 text-xs text-gray-500 whitespace-normal dark:text-gray-400">
                                                            {{row.note}}
                                                        </small>
                                                    </td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>

                                    <p
                                        v-if="lastTest.summary.extracted > lastTest.summary.checked"
                                        class="px-4 py-3 text-xs text-gray-500 border-t border-gray-200 dark:border-gray-700 dark:text-gray-400"
                                    >
                                        Showing the first {{lastTest.summary.checked}} of {{lastTest.summary.extracted}} extracted rows.
                                    </p>
                                </template>
                            </div>
                        </div>
                    </div>
                </section>


                <section class="container mx-auto mt-5">
                    <div class="flex items-center gap-x-3">
                        <h2 class="text-lg font-medium text-gray-800 dark:text-white">Templates</h2>

                        <span class="px-3 py-1 text-xs text-blue-600 bg-blue-100 rounded-full dark:bg-gray-800 dark:text-blue-400">{{templates.length}} recorded</span>
                        <span class="px-3 py-1 text-xs text-emerald-600 bg-emerald-100 rounded-full dark:bg-gray-800 dark:text-emerald-400">{{liveCount}} matched against uploads</span>
                    </div>

                    <div class="flex flex-col mt-6">
                        <div class="-mx-4 -my-2 overflow-x-auto sm:-mx-6 lg:-mx-8">
                            <div class="inline-block min-w-full py-2 align-middle md:px-6 lg:px-8">
                                <div class="overflow-hidden border border-gray-200 dark:border-gray-700 md:rounded-lg">
                                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                        <thead class="bg-gray-50 dark:bg-gray-800">
                                            <tr>
                                                <th scope="col" class="py-3.5 px-4 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Name
                                                </th>

                                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Detects
                                                </th>

                                                <th scope="col" class="px-4 py-3.5 text-sm font-normal text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                                    Live
                                                </th>


                                                <th scope="col" class="relative py-3.5 px-4">
                                                    <span class="sr-only">Edit</span>
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                                            <tr v-for="template in templates" :key="template.id">
                                                    <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                        <div class="flex items-center gap-x-3">
                                                            <!--
                                                                The screenshot is how an admin recognises a template, so show it at a
                                                                readable size. It comes from its own cacheable endpoint rather than
                                                                inline in the props - 750KB of base64 per template, on every visit.

                                                                "contain", and a link to the full size: a drawn screenshot is as wide as
                                                                the sheet it draws, and a Tekla report reaching column S is six times
                                                                wider than it is tall. Cropped to a 128x80 box it showed column A.
                                                            -->
                                                            <a v-if="template.has_screenshot"
                                                               :href="screenshotUrl(template.id)"
                                                               target="_blank"
                                                               title="Open the full size screenshot">
                                                                <img class="object-contain w-32 h-20 border border-gray-200 rounded bg-gray-50 dark:border-gray-700 dark:bg-gray-800"
                                                                     :src="screenshotUrl(template.id)"
                                                                     loading="lazy"
                                                                     :alt="template.name">
                                                            </a>
                                                            <!--
                                                                No screenshot to ask for. The seeded templates have none -
                                                                they are ours, not photographs of a customer's file - and
                                                                an <img> pointed at the endpoint would 404 for each.
                                                            -->
                                                            <div v-else class="flex items-center justify-center w-32 h-20 text-xs text-gray-400 border border-gray-200 border-dashed rounded dark:border-gray-700">
                                                                No screenshot
                                                            </div>
                                                            <div>
                                                                <h2 class="font-medium text-gray-800 dark:text-white">
                                                                    {{ template.name }}
                                                                </h2>
                                                                <small class="block text-gray-500 dark:text-gray-400">{{template.source}}</small>
                                                            </div>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-4 text-sm">
                                                        <!-- The labels an upload has to carry to be read by this template -->
                                                        <div v-if="template.expected_heading_labels?.length" class="max-w-md text-gray-700 whitespace-normal dark:text-gray-300">
                                                            <code class="text-xs">{{template.expected_heading_labels.join(' · ')}}</code>
                                                            <small class="block text-gray-500 dark:text-gray-400">
                                                                from {{template.heading_cell}}
                                                            </small>
                                                        </div>

                                                        <!--
                                                            Recorded before templates drove detection, so it describes a
                                                            spreadsheet without being able to find one. Worth keeping and
                                                            worth saying, or an admin wonders why the file never imports.
                                                        -->
                                                        <small
                                                            v-for="(reason,index) in template.detection.blocked_by"
                                                            :key="'blocked-'+index"
                                                            class="block max-w-md text-red-700 whitespace-normal dark:text-red-400"
                                                        >
                                                            &#10007; {{reason}}
                                                        </small>

                                                        <!--
                                                            Everything odd about the row that is not bad enough to refuse.
                                                            None of it stops the row being saved, so this list is the only
                                                            place the disagreement is ever seen.
                                                        -->
                                                        <small
                                                            v-for="(warning,index) in template.detection.warnings"
                                                            :key="'warning-'+index"
                                                            class="block max-w-md text-amber-700 whitespace-normal dark:text-amber-300"
                                                        >
                                                            &#9888; {{warning}}
                                                        </small>
                                                    </td>
                                                    <td class="px-4 py-4 text-sm font-medium text-gray-700 whitespace-nowrap">
                                                        <div
                                                            :class="template.detection.detects ? 'bg-emerald-100/60' : 'bg-yellow-100/60'"
                                                            class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"
                                                        >
                                                            <span
                                                                :class="template.detection.detects ? 'bg-emerald-500' : 'bg-yellow-500'"
                                                                class="h-1.5 w-1.5 rounded-full"
                                                            ></span>

                                                            <h2
                                                                :class="template.detection.detects ? 'text-emerald-500' : 'text-yellow-500'"
                                                                class="text-sm font-normal "
                                                            >
                                                                {{template.detection.detects ? 'Yes' : (template.active ? 'Cannot' : 'No')}}
                                                            </h2>
                                                        </div>
                                                    </td>
                                                    <td class="px-4 py-4 text-sm whitespace-nowrap">
                                                        <div class="flex items-center gap-x-6">
                                                            <button
                                                                type="button"
                                                                @click="submitDelete(template)"
                                                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                                                            >
                                                                <span class="sr-only">Delete template</span>
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                                </svg>
                                                            </button>

                                                            <button
                                                                type="button"
                                                                @click="initiateUpdate(template)"
                                                                class="text-gray-500 transition-colors duration-200 dark:hover:text-yellow-500 dark:text-gray-300 hover:text-yellow-500 focus:outline-none"
                                                            >
                                                                <span class="sr-only">Edit template</span>
                                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-5 h-5">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                                                </svg>
                                                            </button>
                                                        </div>
                                                    </td>
                                                </tr>

                                            <tr v-if="templates.length === 0">
                                                <td colspan="4" class="px-4 py-6 text-sm text-center text-gray-500 dark:text-gray-400">
                                                    No templates recorded yet, so no upload will import.
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>


            </div>
        </div>
    </AuthenticatedLayout>

    <ConfirmModal
        v-if="confirmDialog"
        :title="confirmDialog.title"
        :message="confirmDialog.message"
        :confirmLabel="confirmDialog.confirmLabel"
        :tone="confirmDialog.tone"
        @confirm="confirmDialogAccepted()"
        @cancel="confirmDialogCancelled()"
    />
</template>
