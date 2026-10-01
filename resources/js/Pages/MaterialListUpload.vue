<script setup>
    //General Imports
    import {Head, Link, useForm, usePage} from '@inertiajs/vue3';
    import {computed, ref, watch} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        /**
         * The projects an upload may be added to - see DashboardController::eligibleProjects.
         * Already filtered to what PrerequisiteConditions::uploadMaterials would allow, so
         * everything in here is a legal target and nothing in here needs a second check.
         */
        eligibleProjects: {
            type: Array,
            default: () => [],
        },
    });

    /**
     * Limits. Kept in step with where they are actually enforced - the new project side by
     * App\Http\Requests\StoreProjectRequest, the existing project side by the rules inside
     * ProductController::store, which take one file and allow it to be twice as big.
     */
    const MAX_FILES = 5;
    const MAX_FILE_BYTES = 1024 * 1024;
    const MAX_EXISTING_FILE_BYTES = 2048 * 1024;

    //Matches Project::MAX_NAME_CHARACTERS, which is where it is actually enforced
    const MAX_NAME_CHARACTERS = 120;

    //Forms
    const formNewProject = useForm({
        name: '',
        reference: null,
        date_materials_required: null,
        tentative: false,
        excel: [],
    });

    const formExistingProject = useForm({
        excel: null,
    });

    //Shared data
    const projectFlashed = computed(() => usePage().props.flash?.project);
    const flashedWarning = computed(() => usePage().props.flash?.warning);
    const supportEmail = computed(() => usePage().props.adminEmail);
    /*
     * A lapsed trial or subscription leaves the account readable but not writable, and this page is
     * nothing but a write. The banner above already says so on every page, but the button has to
     * agree with it - BillingWriteAccessMiddleware would answer the post with a redirect to the
     * billing page, which from here reads as the upload having silently thrown the file away.
     */
    const readOnly = computed(() => !!usePage().props.billing?.readOnly);

    //Variables
    /*
     * "new" or "existing". Opens on "new" even for a business with projects already: a material
     * list arriving for a job we have never heard of is the common case, and adding to a job
     * already underway is the deliberate one.
     */
    const mode = ref('new');

    //The two file inputs, so the browser's own list can be cleared after the files are taken off it
    const newFileInput = ref(null);
    const existingFileInput = ref(null);

    //Which project to add to, as an id. Empty string while nothing is chosen.
    const existingProjectId = ref('');

    /*
     * What the last upload did, snapshotted out of the flash.
     *
     * The flash itself is gone on the next request, and this page deliberately stays put after a
     * successful upload so another list can follow - so without a copy the confirmation would
     * vanish the moment the second upload failed validation in the browser.
     */
    const lastImport = ref(null);

    //A flash survives in the page props, so it has to be dismissable by hand
    const warningDismissed = ref(false);

    //Computed
    const warning = computed(() => (warningDismissed.value ? null : flashedWarning.value));

    const hasEligibleProjects = computed(() => props.eligibleProjects.length > 0);

    const form = computed(() => (mode.value === 'new' ? formNewProject : formExistingProject));

    const projectName = computed(() => (formNewProject.name ?? '').trim());

    const oversizedNewFiles = computed(
        () => formNewProject.excel.filter(file => file.size > MAX_FILE_BYTES)
    );

    const tooManyFiles = computed(() => formNewProject.excel.length > MAX_FILES);

    const existingFileOversized = computed(
        () => !!formExistingProject.excel && formExistingProject.excel.size > MAX_EXISTING_FILE_BYTES
    );

    /**
     * Every per-file rule reports under its own key ("excel.0"), which a single errors.excel
     * lookup would swallow.
     */
    const excelErrors = computed(() => Object.entries(form.value.errors)
        .filter(([key]) => key === 'excel' || key.startsWith('excel.'))
        .map(([, message]) => message));

    /**
     * The files that read as spreadsheets but that detection threw on, refused before the project
     * was created. Flattened because the server nests the list one level
     * (ProjectController::store puts array_values() inside an array).
     */
    const invalidTemplateFiles = computed(() => {
        const reported = formNewProject.errors.invalid_template;

        if (!reported) {
            return [];
        }

        return [reported].flat(2);
    });

    const submitDisabled = computed(() => {
        if (form.value.processing || readOnly.value) {
            return true;
        }

        if (mode.value === 'new') {
            return projectName.value === ''
                || formNewProject.excel.length === 0
                || tooManyFiles.value
                || oversizedNewFiles.value.length > 0;
        }

        return existingProjectId.value === ''
            || !formExistingProject.excel
            || existingFileOversized.value;
    });

    /**
     * A disabled button with no explanation is just a dead end.
     */
    const disabledReason = computed(() => {
        if (form.value.processing) {
            return null;
        }

        if (readOnly.value) {
            return 'The account is read-only until the subscription is renewed, so nothing can be uploaded.';
        }

        if (mode.value === 'new') {
            if (projectName.value === '') {
                return 'Enter a project name to continue.';
            }
            if (formNewProject.excel.length === 0) {
                return 'Attach at least one Excel material list to continue.';
            }
            if (tooManyFiles.value) {
                return `Remove ${formNewProject.excel.length - MAX_FILES} file(s) - a maximum of ${MAX_FILES} can be uploaded at once.`;
            }
            if (oversizedNewFiles.value.length > 0) {
                return 'Remove the file(s) over the 1Mb limit to continue.';
            }

            return null;
        }

        if (existingProjectId.value === '') {
            return 'Choose which project these materials belong to.';
        }
        if (!formExistingProject.excel) {
            return 'Attach an Excel material list to continue.';
        }
        if (existingFileOversized.value) {
            return 'That file is over the 2Mb limit.';
        }

        return null;
    });

    //Methods
    function addFiles(files) {
        //Add files where unique names
        Object.values(files).forEach(file => {
            const exists = formNewProject.excel.find(attached => attached.name === file.name);

            if (!exists) {
                //Added even past the limit, so the user can see what they attached and remove one
                formNewProject.excel.push(file);
            }
        });

        //Clear the input's own list, or re-picking the same file after removing it does nothing
        if (newFileInput.value) {
            newFileInput.value.value = '';
        }
    }

    function removeFile(fileName) {
        formNewProject.excel = formNewProject.excel.filter(file => file.name !== fileName);
    }

    function setExistingFile(files) {
        formExistingProject.excel = files?.length ? files[0] : null;
    }

    function clearExistingFile() {
        formExistingProject.excel = null;

        if (existingFileInput.value) {
            existingFileInput.value.value = '';
        }
    }

    function submit() {
        //A new attempt: don't keep showing the last one's warning
        warningDismissed.value = true;
        lastImport.value = null;

        if (mode.value === 'new') {
            submitNewProject();

            return;
        }

        submitExistingProject();
    }

    function submitNewProject() {
        /**
         * "required" counts a field of spaces as filled, so without this a project could be
         * saved under a name that is blank everywhere it is displayed.
         */
        formNewProject.name = projectName.value;

        formNewProject.post(route('projects.store'), {
            preserveScroll: true,
            /*
             * Without this the component is torn down and rebuilt when the redirect back lands, so
             * the lastImport that onSuccess sets below would be written to a dead instance and the
             * user would be told nothing at all about an upload that worked. Inertia preserves
             * state on a validation error either way; this is for the successful visit.
             */
            preserveState: true,
            onSuccess: () => {
                //A fresh warning from this attempt should be visible
                warningDismissed.value = false;

                /*
                 * The project is flashed only when something actually imported. Nothing flashed
                 * means the shell was discarded and the warning above says why, so the form keeps
                 * the name - the user's next move is another file under the same name.
                 */
                if (projectFlashed.value) {
                    recordImport(projectFlashed.value, 'created');

                    formNewProject.reset();
                }

                clearNewFiles();
            },
            onError: () => {
                warningDismissed.value = false;

                /*
                 * The files go, the name stays. Inertia cannot re-populate a file input, so what
                 * is listed after a failed post would be a list of files that are no longer
                 * attached to anything.
                 */
                clearNewFiles();
            },
        });
    }

    function submitExistingProject() {
        formExistingProject.post(route('projects.products.store', existingProjectId.value), {
            preserveScroll: true,
            //See submitNewProject - the confirmation lives in this instance's state
            preserveState: true,
            onSuccess: () => {
                warningDismissed.value = false;

                if (projectFlashed.value) {
                    recordImport(projectFlashed.value, 'added');
                }

                clearExistingFile();
            },
            onError: () => {
                warningDismissed.value = false;

                clearExistingFile();
            },
        });
    }

    function clearNewFiles() {
        formNewProject.excel = [];

        if (newFileInput.value) {
            newFileInput.value.value = '';
        }
    }

    /**
     * @param project the project the server flashed back
     * @param outcome "created" for a new project, "added" for materials joining an existing one
     */
    function recordImport(project, outcome) {
        lastImport.value = {
            id: project.id,
            name: project.name,
            outcome: outcome,
        };
    }

    //Watchers
    /*
     * Switching sides should not carry the other side's complaints across. Each form keeps its
     * own files and name, so going back to one you half filled in finds it as you left it.
     */
    watch(mode, () => {
        formNewProject.clearErrors();
        formExistingProject.clearErrors();
        warningDismissed.value = true;
    });
</script>

<template>
    <Head title="Upload a material list"/>

    <AuthenticatedLayout>
        <div class="w-full max-w-2xl py-8 mx-auto">
            <h1 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">
                Upload a material list
            </h1>
            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                Attach the Excel bill of materials and we will extract it. A format we have not seen
                before is read and saved the first time it arrives.
            </p>

            <!-- What the last upload did -->
            <div
                v-if="lastImport"
                role="status"
                class="flex items-start justify-between gap-4 px-4 py-3 mt-6 text-sm text-green-900 border border-green-300 rounded-lg bg-green-50"
            >
                <p>
                    <span v-if="lastImport.outcome === 'created'">
                        <b>{{ lastImport.name }}</b> has been created and its materials extracted.
                    </span>
                    <span v-else>
                        The materials have been added to <b>{{ lastImport.name }}</b>.
                    </span>
                    <br>
                    <Link
                        :href="route('projects.index')"
                        class="font-semibold underline"
                    >
                        Open it on the projects board
                    </Link>
                    to check the list and carry on, or upload another material list below.
                </p>
                <button
                    type="button"
                    class="font-bold shrink-0"
                    aria-label="Dismiss"
                    @click="lastImport = null"
                >
                    &times;
                </button>
            </div>

            <!-- Nothing could be extracted, or a file we could not read -->
            <div
                v-if="warning"
                class="flex items-start justify-between gap-4 px-4 py-3 mt-6 text-sm text-orange-900 border border-orange-300 rounded-lg bg-orange-50"
            >
                <p>{{ warning }}</p>
                <button
                    type="button"
                    class="font-bold shrink-0"
                    aria-label="Dismiss"
                    @click="warningDismissed = true"
                >
                    &times;
                </button>
            </div>

            <!-- Which of the two uploads this is -->
            <div
                role="radiogroup"
                aria-label="What these materials are for"
                class="grid grid-cols-1 gap-2 mt-6 sm:grid-cols-2"
            >
                <button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'new'"
                    :class="mode === 'new'
                        ? 'border-blue-600 bg-blue-50 text-blue-900 dark:bg-blue-900/30 dark:text-blue-100'
                        : 'border-gray-300 bg-white text-gray-700 hover:bg-gray-50 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300'"
                    class="px-4 py-3 text-left border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
                    @click="mode = 'new'"
                >
                    <span class="block font-semibold">A new project</span>
                    <span class="block mt-1 text-xs opacity-80">Create the job and import into it</span>
                </button>
                <button
                    type="button"
                    role="radio"
                    :aria-checked="mode === 'existing'"
                    :disabled="!hasEligibleProjects"
                    :title="hasEligibleProjects ? null : 'No project of yours is still waiting to be nested'"
                    :class="[
                        mode === 'existing'
                            ? 'border-blue-600 bg-blue-50 text-blue-900 dark:bg-blue-900/30 dark:text-blue-100'
                            : 'border-gray-300 bg-white text-gray-700 dark:bg-gray-900 dark:border-gray-700 dark:text-gray-300',
                        hasEligibleProjects ? 'hover:bg-gray-50' : 'opacity-50 cursor-not-allowed',
                    ]"
                    class="px-4 py-3 text-left border rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-400"
                    @click="hasEligibleProjects ? mode = 'existing' : null"
                >
                    <span class="block font-semibold">An existing project</span>
                    <span class="block mt-1 text-xs opacity-80">
                        <template v-if="hasEligibleProjects">
                            Add to one that has not been nested yet
                        </template>
                        <template v-else>
                            Nothing of yours is waiting to be nested
                        </template>
                    </span>
                </button>
            </div>

            <div class="p-6 mt-4 bg-white border border-gray-200 rounded-lg dark:bg-gray-900 dark:border-gray-700">
                <form @submit.prevent="submit()">
                    <!-- New project -->
                    <div v-if="mode === 'new'" class="grid grid-cols-1 gap-6">
                        <!-- Name -->
                        <div>
                            <label for="new-project-name" class="ml-1 text-gray-700 dark:text-gray-200">
                                Project name *
                            </label>
                            <input
                                id="new-project-name"
                                v-model="formNewProject.name"
                                type="text"
                                :maxlength="MAX_NAME_CHARACTERS"
                                class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                placeholder="Name"
                                required
                                :disabled="formNewProject.processing"
                            >
                            <div v-if="formNewProject.errors.name" class="text-sm text-red-500">
                                {{ formNewProject.errors.name }}
                            </div>
                        </div>

                        <!-- Material lists -->
                        <div>
                            <!-- A file that read as a spreadsheet and that our own detection threw on -->
                            <template v-if="invalidTemplateFiles.length > 0">
                                <div class="p-3 text-sm text-orange-700 border-2 border-orange-300 dark:text-orange-300 rounded-2xl">
                                    <div v-if="invalidTemplateFiles.length === 1">
                                        <b><i>"{{ invalidTemplateFiles[0] }}"</i></b> could not be read.
                                        Please email the file to
                                        <a :href="'mailto:'+supportEmail" class="font-semibold text-orange-700 underline dark:text-orange-300">{{ supportEmail }}</a>
                                        so we can take a look.
                                    </div>
                                    <div v-else>
                                        These files could not be read. Please email them to
                                        <a :href="'mailto:'+supportEmail" class="font-semibold text-orange-700 underline dark:text-orange-300">{{ supportEmail }}</a>
                                        so we can take a look.
                                        <ul class="mt-1">
                                            <li v-for="file in invalidTemplateFiles" :key="file">
                                                - {{ file }}
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    class="block mx-auto mt-2 text-sm font-semibold text-center underline dark:text-gray-200"
                                    @click="formNewProject.clearErrors('invalid_template')"
                                >
                                    Ok, got it
                                </button>
                            </template>

                            <template v-else>
                                <!--
                                    sr-only rather than hidden: display:none takes the input out of
                                    the tab order, which leaves keyboard and screen reader users
                                    with no way at all to attach a file.
                                -->
                                <label
                                    class="inline-block font-semibold text-blue-700 rounded cursor-pointer dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-400 focus-within:ring-offset-2"
                                >
                                    + add Excel material lists
                                    <input
                                        ref="newFileInput"
                                        type="file"
                                        class="sr-only"
                                        multiple
                                        accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                        :disabled="formNewProject.processing"
                                        @input="addFiles($event.target.files)"
                                    >
                                </label>
                                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                    Up to {{ MAX_FILES }} .xls or .xlsx files, each under 1Mb.
                                </p>

                                <div
                                    v-for="(message,index) in excelErrors"
                                    :key="'excel-error-'+index"
                                    class="mt-2 text-sm text-red-500"
                                >
                                    {{ message }}
                                </div>
                                <div v-if="tooManyFiles" class="mt-2 text-sm font-semibold text-red-500">
                                    Maximum {{ MAX_FILES }} BOM files can be uploaded. Click the "X" to remove.
                                </div>

                                <!-- What is attached -->
                                <div
                                    v-if="formNewProject.excel.length > 0"
                                    class="pt-3 pb-1 pl-1 font-semibold"
                                >
                                    <div
                                        v-for="(file,index) in formNewProject.excel"
                                        :key="file.name"
                                        class="grid items-center grid-cols-5 p-2"
                                        :class="index > 0 ? 'border-t-[1px] border-gray-300 dark:border-gray-700' : ''"
                                    >
                                        <p
                                            :class="file.size > MAX_FILE_BYTES ? 'text-red-500' : 'dark:text-gray-200'"
                                            class="col-span-4 break-all"
                                        >
                                            {{ file.name }}
                                            <span v-if="file.size > MAX_FILE_BYTES" class="block text-xs">
                                                (Exceeds 1Mb limit - please remove)
                                            </span>
                                        </p>
                                        <button
                                            type="button"
                                            class="text-right rounded focus:outline-none focus:ring-2 focus:ring-red-400"
                                            :aria-label="'Remove ' + file.name"
                                            :disabled="formNewProject.processing"
                                            @click="removeFile(file.name)"
                                        >
                                            <i class="text-red-500 fa-solid fa-xmark"></i>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>

                    <!-- Existing project -->
                    <div v-else class="grid grid-cols-1 gap-6">
                        <!-- Which project -->
                        <div>
                            <label for="existing-project" class="ml-1 text-gray-700 dark:text-gray-200">
                                Project *
                            </label>
                            <select
                                id="existing-project"
                                v-model="existingProjectId"
                                class="w-full px-4 py-2 text-gray-700 bg-white border rounded-md dark:bg-gray-900 dark:text-gray-300 dark:border-gray-600 focus:border-blue-400 dark:focus:border-blue-300 focus:outline-none focus:ring focus:ring-blue-300 focus:ring-opacity-40"
                                required
                                :disabled="formExistingProject.processing"
                            >
                                <option value="" disabled>Choose a project</option>
                                <option
                                    v-for="project in eligibleProjects"
                                    :key="project.id"
                                    :value="project.id"
                                >
                                    {{ project.name }}<template v-if="project.reference"> ({{ project.reference }})</template>
                                    - {{ project.rows }} material {{ project.rows === 1 ? 'line' : 'lines' }}
                                </option>
                            </select>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                Only your own projects that have not been nested into a batch yet. Uploads
                                are cumulative - this adds to the list rather than replacing it.
                            </p>
                        </div>

                        <!-- Material list -->
                        <div>
                            <label
                                class="inline-block font-semibold text-blue-700 rounded cursor-pointer dark:text-blue-400 hover:text-blue-900 dark:hover:text-blue-300 focus-within:outline-none focus-within:ring-2 focus-within:ring-blue-400 focus-within:ring-offset-2"
                            >
                                + add an Excel material list
                                <input
                                    ref="existingFileInput"
                                    type="file"
                                    class="sr-only"
                                    accept=".xls,.xlsx,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                                    :disabled="formExistingProject.processing"
                                    @input="setExistingFile($event.target.files)"
                                >
                            </label>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                One .xls or .xlsx file, under 2Mb.
                            </p>

                            <div
                                v-for="(message,index) in excelErrors"
                                :key="'existing-excel-error-'+index"
                                class="mt-2 text-sm text-red-500"
                            >
                                {{ message }}
                            </div>

                            <div
                                v-if="formExistingProject.excel"
                                class="grid items-center grid-cols-5 p-2 pt-3 pl-1 font-semibold"
                            >
                                <p
                                    :class="existingFileOversized ? 'text-red-500' : 'dark:text-gray-200'"
                                    class="col-span-4 break-all"
                                >
                                    {{ formExistingProject.excel.name }}
                                    <span v-if="existingFileOversized" class="block text-xs">
                                        (Exceeds 2Mb limit - please remove)
                                    </span>
                                </p>
                                <button
                                    type="button"
                                    class="text-right rounded focus:outline-none focus:ring-2 focus:ring-red-400"
                                    :aria-label="'Remove ' + formExistingProject.excel.name"
                                    :disabled="formExistingProject.processing"
                                    @click="clearExistingFile()"
                                >
                                    <i class="text-red-500 fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <div v-if="invalidTemplateFiles.length === 0" class="mt-6">
                        <button
                            type="submit"
                            :disabled="submitDisabled"
                            style="height:40px"
                            :class="submitDisabled ? 'bg-blue-300 cursor-not-allowed' : 'bg-blue-700 hover:bg-blue-600 focus:outline-none focus:bg-blue-600'"
                            class="w-full px-4 py-2 text-sm font-medium tracking-wide text-white capitalize transition-colors duration-300 transform rounded-md"
                        >
                            {{ form.processing ? 'Extracting...' : 'Extract materials' }}
                        </button>
                        <!-- Say why the button is dead rather than leaving the user guessing -->
                        <p
                            v-if="disabledReason"
                            class="mt-2 text-sm text-center text-gray-500 dark:text-gray-400"
                        >
                            {{ disabledReason }}
                        </p>
                        <!--
                            A format we have never seen is described, tested and recorded before the
                            import runs, which is two calls to OpenAI - so this can genuinely sit
                            here for a while on a first upload.
                        -->
                        <p
                            v-if="form.processing"
                            class="mt-2 text-sm text-center text-gray-500 dark:text-gray-400"
                        >
                            Reading the spreadsheet. A format we have not seen before takes longer the
                            first time - please don't close this page.
                        </p>
                    </div>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
