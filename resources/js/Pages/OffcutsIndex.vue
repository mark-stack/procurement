<script setup>
    //General Imports
    import {Link, Head} from '@inertiajs/vue3';
    import {computed, ref} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import OffcutsDatatable from "@/Components/Tables/OffcutsDatatable.vue";
    import RemovedOffcutsTable from "@/Components/Tables/RemovedOffcutsTable.vue";
    import CleanoutOffcutsTable from "@/Components/Tables/CleanoutOffcutsTable.vue";
    import RemoveOffcutModal from "@/Components/Modals/RemoveOffcutModal.vue";

    //Props
    const props = defineProps({
        offcuts: Object,
        removedOffcuts: Object,
        removedTotal: Number,
        removalReasons: Array,
        /** The dead stock the quarterly cleanout put up - see App\Services\OffcutCleanout. */
        cleanout: {
            type: Array,
            default: () => [],
        },
        /** How long a piece may sit before age counts against it. */
        cleanoutShelfLifeDays: {
            type: Number,
            default: 365,
        },
        /** "cleanout" when the bell's "Review the rack" sent them here, otherwise null. */
        tab: {
            type: String,
            default: null,
        },
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    //"inventory", "cleanout" or "removed". Tabs rather than pages: both of the other two lists are
    //only ever reached from here, and each is short enough that a page of its own would be mostly
    //chrome. Opens on whichever the server named, which is how the bell lands on the cleanout.
    const tab = ref(props.tab ?? 'inventory');

    //The row the removal modal is asking about, or null when it is closed
    const removing = ref(null);

    //Computed
    const inventory = computed(() => props.offcuts?.data ?? []);
    const removed = computed(() => props.removedOffcuts?.data ?? []);
    const cleanoutRows = computed(() => props.cleanout ?? []);

    //Methods
    //...
</script>

<template>
    <Head title="Offcuts" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <!--
                    A text link, like every other page's way back. This was a pointing-hand icon,
                    which is not a control anywhere else in the application.
                -->
                <Link
                    class="inline-flex items-center gap-x-2 text-sm font-medium text-gray-600 transition-colors duration-200 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400"
                    :href="route('projects.index')"
                >
                    <span aria-hidden="true">&larr;</span> Current projects
                </Link>

                <!-- The page had no heading at all, and no statement of what it is for -->
                <header class="mt-4">
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Offcuts</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                        The steel in your yard that a nest has already paid for. Every batch nested
                        from here on draws on this list before it buys anything, so a piece listed
                        here that is not really in the rack is a bar the next job will be short.
                    </p>
                </header>

                <!-- Tabs -->
                <div class="mt-6 border-b border-gray-200 dark:border-gray-700">
                    <!--
                        Two buttons rather than role="tablist": these swap what the section below
                        shows without navigating, and a real tablist owes the arrow keys behaviour
                        that nothing here implements. aria-pressed says which one is on.
                    -->
                    <div class="-mb-px flex gap-x-6">
                        <button
                            type="button"
                            :aria-pressed="tab === 'inventory'"
                            class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors duration-200"
                            :class="tab === 'inventory'
                                ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                            @click="tab = 'inventory'"
                        >
                            Offcut inventory
                            <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">{{ inventory.length }}</span>
                        </button>

                        <!--
                            Only shown when there is something on it. A permanent tab reading zero
                            invites somebody to go looking for steel to scrap, which is the opposite
                            of what the list is for.
                        -->
                        <button
                            v-if="cleanoutRows.length > 0"
                            type="button"
                            :aria-pressed="tab === 'cleanout'"
                            class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors duration-200"
                            :class="tab === 'cleanout'
                                ? 'border-amber-500 text-amber-700 dark:text-amber-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                            @click="tab = 'cleanout'"
                        >
                            Cleanout
                            <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">{{ cleanoutRows.length }}</span>
                        </button>

                        <button
                            type="button"
                            :aria-pressed="tab === 'removed'"
                            class="border-b-2 px-1 pb-3 text-sm font-medium transition-colors duration-200"
                            :class="tab === 'removed'
                                ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200'"
                            @click="tab = 'removed'"
                        >
                            Removed
                            <span class="ml-1 text-xs text-gray-400 dark:text-gray-500">{{ removedTotal }}</span>
                        </button>
                    </div>
                </div>

                <section class="mt-4 overflow-hidden bg-white border border-gray-200 rounded-xl dark:border-gray-700 dark:bg-gray-900">
                    <OffcutsDatatable
                        v-if="tab === 'inventory'"
                        :data="inventory"
                        @remove="removing = $event"
                    />

                    <CleanoutOffcutsTable
                        v-else-if="tab === 'cleanout'"
                        :data="cleanoutRows"
                        :shelf-life-days="cleanoutShelfLifeDays"
                    />

                    <RemovedOffcutsTable
                        v-else
                        :data="removed"
                        :total="removedTotal"
                    />
                </section>
            </div>
        </div>
    </AuthenticatedLayout>

    <!--
        Taking a piece of steel out of inventory. Keyed by the row so the form starts empty for each
        one - a reason left over from the last removal is the wrong reason recorded on this one.
    -->
    <RemoveOffcutModal
        v-if="removing"
        :key="removing.id"
        :offcut="removing"
        :reasons="removalReasons"
        @removed="removing = null"
        @cancel="removing = null"
    />
</template>
