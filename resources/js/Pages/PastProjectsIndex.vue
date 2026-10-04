<script setup>
    //General Imports
    import {ref} from "vue";
    import {Head, Link} from "@inertiajs/vue3";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";

    //Props
    const props = defineProps({
        pastBatches: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    /**
     * Which card is opening its nest, by batch id - not a single flag for the page.
     *
     * One shared flag put "Calculating..." on every button at once, which on a list that only
     * ever grows is most of the screen saying it is busy because one row is. The board does the
     * same thing with loadingBatchId, for the same reason.
     */
    const loadingBatchId = ref(null);

    //Shared Methods
    //...

    //Methods
    /**
     * The jobs on the batch, as the card's heading.
     *
     * A batch is bought as one and can carry several projects, so the heading is a list - the same
     * shape the board's cards use. Held together here as well as drawn below, because it is the
     * hover title too: the names are truncated to the card's width and a batch of a dozen jobs
     * would otherwise be unreadable at exactly the moment you want to know what is on it.
     */
    function projectNames(batch){
        return (batch.projects ?? []).map(project => project.name).join(", ");
    }
</script>

<template>
    <Head title="Past Projects" />

    <AuthenticatedLayout>
        <!--
            The dashboard's own container and card shape - see NestingIndex.vue. This screen was a
            four-column table with the same spanner icon repeated in three of the cells, and read as
            a different application to the one page the rest of the app now is.
        -->
        <section class="container max-w-4xl px-4 mx-auto py-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h1 class="text-lg font-semibold text-gray-800">
                    Past projects
                </h1>

                <!-- The list only grows, so say how big it is rather than leaving it to scrolling -->
                <span v-if="pastBatches.length" class="text-xs text-gray-500">
                    {{ pastBatches.length }} closed {{ pastBatches.length === 1 ? 'batch' : 'batches' }}
                </span>
            </div>

            <!-- One card per closed batch, full width, newest first - the order the controller sends -->
            <div class="flex flex-col gap-3 mt-4">
                <div
                    v-for="batch in pastBatches"
                    :key="batch.id"
                    class="bg-white border border-gray-200 shadow-sm rounded-xl"
                >
                    <div class="flex items-center justify-between gap-4 px-4 py-3">
                        <!-- The jobs, who they belonged to, and what the batch did - same left half as the board -->
                        <div class="min-w-0">
                            <span
                                :title="projectNames(batch)"
                                class="block text-sm font-semibold text-gray-800 truncate"
                            >
                                {{ projectNames(batch) || 'No projects on this batch' }}
                            </span>

                            <!--
                                The owners of the projects, not whoever pressed "Start quoting" -
                                see PastProjectsController, which joins the distinct managers into
                                this line precisely because a batch can span two of them.
                            -->
                            <span class="block text-xs text-gray-500 truncate">
                                {{ batch.projectManagers }}'s
                            </span>

                            <!-- Who nested it, which is a different question and only worth a line when the answer differs -->
                            <span
                                v-if="batch.batchedBy && batch.batchedBy !== batch.projectManagers"
                                class="block text-xs text-gray-400 truncate"
                            >
                                Nested by {{ batch.batchedBy }}
                            </span>

                            <!--
                                What the batch was, in the pills the board puts under its headings.
                                Nothing here is a deadline any more - this batch is closed - so they
                                are all plain grey: the colour on the board's pills is news about
                                whether a job will make its date, and there is no such news left.
                            -->
                            <div class="flex flex-wrap items-center gap-2 mt-2">
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200">
                                    <i class="fa-solid fa-layer-group text-[10px] text-gray-400"></i>
                                    Batch {{ batch.id }}
                                </span>

                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200">
                                    <i class="fa-solid fa-truck text-[10px] text-gray-400"></i>
                                    {{ batch.ordersQty }} {{ batch.ordersQty === 1 ? 'order' : 'orders' }}
                                </span>

                                <!-- When it was nested, which is when created_at was written - see the controller -->
                                <span class="inline-flex items-center gap-1.5 rounded-md bg-gray-50 px-2 py-1 text-[11px] font-semibold text-gray-600 ring-1 ring-inset ring-gray-200">
                                    <i class="fa-regular fa-calendar text-[10px] text-gray-400"></i>
                                    Nested {{ batch.createdAt }}
                                </span>
                            </div>
                        </div>

                        <!--
                            The one thing left to do with a closed batch: look at what it cut. Sized
                            and placed like the board's own button row, so the two screens line up
                            down the same edge.
                        -->
                        <div class="flex flex-wrap items-center justify-end gap-2 shrink-0">
                            <Link
                                :href="route('batch.nesting',[batch.id,'past',1])"
                                class="w-28"
                                @click="loadingBatchId = batch.id"
                            >
                                <CardButtonBlue
                                    :label="loadingBatchId === batch.id ? 'Calculating...' : 'Nest'"
                                    :highlight="false"
                                    :icon="true"
                                />
                            </Link>
                        </div>
                    </div>
                </div>

                <!--
                    The nav only offers this page once a batch has closed (see hasPastProjects), so
                    this is all but unreachable - but "all but" is why it is here rather than an
                    empty page that looks like it failed to load.
                -->
                <div
                    v-if="!pastBatches.length"
                    class="px-4 py-8 text-sm text-center text-gray-500 bg-white border border-gray-200 shadow-sm rounded-xl"
                >
                    Nothing here yet. A batch lands in past projects once its steel is all in and it
                    is marked done.
                </div>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
