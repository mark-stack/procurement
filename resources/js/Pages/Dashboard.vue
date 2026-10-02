<script setup>
    /**
     * /dashboard - the screen login lands on.
     *
     * Three sections, in the order somebody arriving in the morning needs them:
     *
     *  1. Where the business's live work stands, as four counts.
     *  2. What is waiting on somebody, most urgent first, each one a link into the board.
     *  3. The upload form, which is what this page used to be and nothing else.
     *
     * None of it is a second copy of the board. Everything here either counts or names what is on the
     * board and then links to it - the batch actions straight into its quotes/orders modal through the
     * ?quotes= link the deadline emails already use - so there is still exactly one place to nest a
     * batch, confirm a material line, send a quote or record a delivery.
     *
     * Unlike the board this works on a phone. The board asks for a desktop because four columns of
     * cards cannot be read on one, but a foreman wanting to know whether the steel has landed should
     * not need to find a computer.
     */
    //General Imports
    import {Head, Link} from '@inertiajs/vue3';
    import {computed, ref} from 'vue';
    import moment from 'moment';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import UploadMaterials from '@/Components/Dashboard/UploadMaterials.vue';

    //Shared methods
    import shared from '@/Shared/shared.js';

    //Props
    const props = defineProps({
        /**
         * The upload form's two props, passed straight through - see DashboardController.
         */
        eligibleProjects: {
            type: Array,
            default: () => [],
        },
        colleagues: {
            type: Array,
            default: () => [],
        },
        /**
         * The four steps with a count each, in board order - see DashboardFormatter::pipeline.
         * Empty for a user with no business, and then this page is the upload form alone.
         */
        pipeline: {
            type: Array,
            default: () => [],
        },
        /**
         * One row per live job of the business, yours first and the nearest first cut above the rest.
         */
        liveProjects: {
            type: Array,
            default: () => [],
        },
        /**
         * What is waiting on somebody. Project items are only here for the people who may act on them;
         * batch items are anybody's. See DashboardFormatter::actions.
         */
        actions: {
            type: Array,
            default: () => [],
        },
    });

    //How many job rows are drawn before the list is folded away
    const PROJECTS_SHOWN = 8;

    //Variables
    const showAllProjects = ref(false);

    //Computed
    const hasPipeline = computed(() => props.pipeline.length > 0);

    const liveProjectCount = computed(() => props.liveProjects.length);

    const projectsDrawn = computed(() => (showAllProjects.value
        ? props.liveProjects
        : props.liveProjects.slice(0, PROJECTS_SHOWN)));

    const hiddenProjectCount = computed(() => Math.max(liveProjectCount.value - PROJECTS_SHOWN, 0));

    /*
     * Whether this business has ever got as far as a material list. Both sections above the form are
     * hidden until it has: a brand new account would otherwise be met by four zeros and "nothing is
     * waiting on you", which is a worse first screen than the form on its own.
     */
    const hasLiveWork = computed(() => liveProjectCount.value > 0 || props.actions.length > 0);

    //Methods
    /**
     * The step a job is on, as the board words it.
     */
    function stageLabel(stage) {
        return {
            NESTING: 'Nesting',
            QUOTING: 'Quoting',
            ORDERING: 'Ordering',
            DELIVERING: 'Delivering',
        }[stage] ?? stage;
    }

    /**
     * One colour per step, kept in the same order the board's columns run in so the two screens read
     * as the same pipeline.
     */
    function stageClasses(stage) {
        return {
            NESTING: 'bg-gray-100 text-gray-700 ring-gray-300',
            QUOTING: 'bg-blue-50 text-blue-800 ring-blue-200',
            ORDERING: 'bg-indigo-50 text-indigo-800 ring-indigo-200',
            DELIVERING: 'bg-teal-50 text-teal-800 ring-teal-200',
        }[stage] ?? 'bg-gray-100 text-gray-700 ring-gray-300';
    }

    function severityClasses(severity) {
        return {
            overdue: 'border-red-300 bg-red-50',
            due: 'border-orange-300 bg-orange-50',
            open: 'border-gray-200 bg-white',
        }[severity] ?? 'border-gray-200 bg-white';
    }

    function severityIcon(severity) {
        return {
            overdue: 'fa-solid fa-circle-exclamation text-red-500',
            due: 'fa-solid fa-triangle-exclamation text-orange-500',
            open: 'fa-regular fa-circle-dot text-gray-400',
        }[severity] ?? 'fa-regular fa-circle-dot text-gray-400';
    }

    function shortDate(date) {
        return date ? moment(date).format('D MMM YY') : null;
    }

    /**
     * A date said the way somebody standing in a workshop would say it: how long until the saw needs
     * the steel, not a timestamp to subtract from today.
     */
    function daysAway(date) {
        if (!date) {
            return null;
        }

        const days = moment(date).startOf('day').diff(moment().startOf('day'), 'days');

        if (days < 0) {
            return Math.abs(days) === 1 ? 'yesterday' : Math.abs(days) + ' days ago';
        }
        if (days === 0) {
            return 'today';
        }
        if (days === 1) {
            return 'tomorrow';
        }

        return 'in ' + days + ' days';
    }
</script>

<template>
    <Head title="Dashboard"/>

    <AuthenticatedLayout>
        <div class="w-full max-w-5xl px-4 py-8 mx-auto space-y-10">
            <!-- What this page is for. Named as the nav names it, and as the tab does. -->
            <div>
                <h1 class="text-2xl font-semibold text-gray-800 dark:text-gray-100">
                    Dashboard
                </h1>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                    What is waiting on you, where your jobs stand, and the form that takes a material
                    list.
                </p>
            </div>

            <!--
                Where the work stands.

                Counts, not cards. Nesting counts projects because nothing in it has been batched yet;
                the other three count batches, which is what the board draws there.
            -->
            <section v-if="hasPipeline && hasLiveWork">
                <div class="flex items-baseline justify-between gap-4">
                    <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                        Where your jobs stand
                    </h2>
                    <Link
                        :href="route('projects.index')"
                        class="text-sm font-semibold text-blue-800 hover:underline dark:text-blue-400"
                    >
                        Open the board
                    </Link>
                </div>

                <div class="grid grid-cols-2 gap-3 mt-3 lg:grid-cols-4">
                    <Link
                        v-for="step in pipeline"
                        :key="step.key"
                        :href="route('projects.index')"
                        :title="step.hint"
                        class="px-4 py-3 bg-white border border-gray-200 rounded-xl transition-colors duration-150 hover:border-blue-300 hover:bg-blue-50 dark:bg-gray-900 dark:border-gray-700"
                    >
                        <span class="block text-2xl font-semibold text-gray-900 dark:text-gray-100">
                            {{ step.count }}
                        </span>
                        <span class="block mt-0.5 text-sm font-semibold text-gray-700 dark:text-gray-300">
                            {{ step.label }}
                        </span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            {{ step.count === 1 ? step.unit : step.unit + 's' }}
                        </span>
                    </Link>
                </div>

                <!-- One line per job, so "where is Tower B up to" is answered without opening anything -->
                <div v-if="liveProjectCount > 0" class="mt-4 overflow-hidden bg-white border border-gray-200 rounded-xl dark:bg-gray-900 dark:border-gray-700">
                    <!-- The only thing on this page wide enough to need it -->
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-xs text-gray-500 bg-gray-50 dark:bg-gray-800 dark:text-gray-400">
                                <tr>
                                    <th scope="col" class="px-4 py-2 font-semibold text-left">Project</th>
                                    <th scope="col" class="px-4 py-2 font-semibold text-left">Step</th>
                                    <th scope="col" class="px-4 py-2 font-semibold text-left">Fabrication</th>
                                    <th scope="col" class="px-4 py-2 font-semibold text-left">Material lines</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="project in projectsDrawn"
                                    :key="project.id"
                                    class="border-t border-gray-100 dark:border-gray-800"
                                >
                                    <td class="px-4 py-2.5">
                                        <span class="font-semibold text-gray-900 dark:text-gray-100">
                                            {{ shared.capitalizeWords(project.name) }}
                                        </span>
                                        <span v-if="project.reference" class="text-gray-500 dark:text-gray-400">
                                            ({{ project.reference }})
                                        </span>
                                        <!--
                                            Whose job it is, where it is not yours. Two similarly named
                                            jobs belonging to different managers is how one manager's
                                            steel ends up on another's cutting list.
                                        -->
                                        <span
                                            v-if="project.projectManagerName"
                                            class="block text-xs text-gray-500 dark:text-gray-400"
                                        >
                                            {{ shared.capitalizeWords(project.projectManagerName) }}'s
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <span
                                            :class="stageClasses(project.stage)"
                                            class="inline-block px-2 py-0.5 text-xs font-semibold rounded-full ring-1"
                                        >
                                            {{ stageLabel(project.stage) }}
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap">
                                        <template v-if="project.dateFabricationBegins">
                                            <span class="text-gray-700 dark:text-gray-300">
                                                {{ shortDate(project.dateFabricationBegins) }}
                                            </span>
                                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ daysAway(project.dateFabricationBegins) }}
                                            </span>
                                        </template>
                                        <!-- The one shape nothing will ever come and collect - see the actions above -->
                                        <span v-else class="text-xs text-orange-700">
                                            No date
                                        </span>
                                    </td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-gray-700 dark:text-gray-300">
                                        {{ project.rows }}
                                        <span
                                            v-if="project.unmatchedRows > 0"
                                            :title="project.unmatchedRows + ' not matched to a product'"
                                            class="text-xs font-semibold text-orange-700"
                                        >
                                            ({{ project.unmatchedRows }} unmatched)
                                        </span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <button
                        v-if="hiddenProjectCount > 0 || showAllProjects"
                        type="button"
                        class="w-full px-4 py-2 text-xs font-semibold text-gray-600 border-t border-gray-100 hover:bg-gray-50 dark:border-gray-800 dark:text-gray-400 dark:hover:bg-gray-800"
                        @click="showAllProjects = !showAllProjects"
                    >
                        {{ showAllProjects ? 'Show fewer' : 'Show ' + hiddenProjectCount + ' more' }}
                    </button>
                </div>
            </section>

            <!--
                What is waiting on somebody.

                Every item carries the one thing to do next and a link to where it is done. Hidden
                entirely when there is nothing - an empty list with a tick in it is still a thing to
                read past on the way to the form.
            -->
            <section v-if="actions.length > 0">
                <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">
                    Needs doing
                    <span class="ml-1 text-sm font-normal text-gray-500 dark:text-gray-400">
                        ({{ actions.length }})
                    </span>
                </h2>

                <ul class="mt-3 space-y-2">
                    <li
                        v-for="action in actions"
                        :key="action.key"
                        :class="severityClasses(action.severity)"
                        class="px-4 py-3 border rounded-xl"
                    >
                        <div class="flex items-start gap-3">
                            <i :class="severityIcon(action.severity)" class="mt-0.5 text-sm"></i>

                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-semibold text-gray-900">
                                    {{ action.title }}
                                </p>
                                <p class="mt-0.5 text-xs text-gray-700">
                                    {{ action.detail }}
                                </p>
                                <!--
                                    Which jobs the batch carries. A batch is several people's work at
                                    once, so an action about one says nothing useful without the names.
                                -->
                                <p
                                    v-if="action.projectNames?.length"
                                    class="mt-1 text-xs text-gray-600"
                                >
                                    {{ action.projectNames.map(name => shared.capitalizeWords(name)).join(', ') }}
                                    <template v-if="action.dateFabricationBegins">
                                        · fabrication {{ daysAway(action.dateFabricationBegins) }}
                                    </template>
                                </p>
                            </div>

                            <Link
                                :href="action.href"
                                class="flex-none px-3 py-1.5 text-xs font-semibold text-white bg-blue-700 rounded-lg transition-colors duration-150 hover:bg-blue-600"
                            >
                                {{ action.actionLabel }}
                            </Link>
                        </div>
                    </li>
                </ul>
            </section>

            <!--
                Nothing outstanding, but there is work on the board. Said once, quietly, because a
                fabricator who has caught up should be told so rather than left wondering whether the
                list failed to load.
            -->
            <p
                v-else-if="hasLiveWork"
                class="px-4 py-3 text-sm text-green-900 border border-green-300 rounded-xl bg-green-50"
            >
                <i class="mr-1 fa-solid fa-check"></i>
                Nothing is waiting on you. Every live job is with a supplier or on its way in.
            </p>

            <!-- The form this page used to be -->
            <UploadMaterials
                :eligibleProjects="eligibleProjects"
                :colleagues="colleagues"
            />
        </div>
    </AuthenticatedLayout>
</template>
