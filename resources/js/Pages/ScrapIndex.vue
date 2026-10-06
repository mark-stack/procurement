<script setup>
    //General Imports
    import {Link, Head, router} from '@inertiajs/vue3';
    import {computed} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        /**
         * Everything destroyed in the window, already added up - see App\Services\ScrapReport. Shape
         * is {from, to, totals, by_source, by_month, by_category, by_project}, and each of those
         * carries the same six figures: pieces, length_mm, weight_kg, value, recovered_value,
         * net_loss.
         *
         * This page draws the first three and deliberately ignores the money. Every row in the
         * scraps table is priced when it is written - a yield figure that cannot be costed is not
         * much of an objective - but a dollar figure on a screen reads as an invoice, and these are
         * a valuation: landed steel cost against what a merchant would pay, both of which move with
         * the price book and neither of which anybody was billed. Weight is the quantity the yard
         * recognises and the one a scrap docket is written in.
         */
        report: {
            type: Object,
            required: true,
        },
        /** How many months the figures cover. */
        months: {
            type: Number,
            default: 12,
        },
        /** The windows that may be asked for. */
        windows: {
            type: Array,
            default: () => [3, 6, 12, 24],
        },
    });

    //Computed
    const totals = computed(() => props.report.totals ?? {});
    const byMonth = computed(() => props.report.by_month ?? []);
    const bySource = computed(() => props.report.by_source ?? []);
    const byCategory = computed(() => props.report.by_category ?? []);
    const byProject = computed(() => props.report.by_project ?? []);

    const nothingYet = computed(() => (totals.value.pieces ?? 0) === 0);

    /*
     * The tallest month in the window, which is what every bar is drawn against. Relative rather
     * than absolute: there is no meaningful scale for "a lot of scrap" - a structural shop and a
     * handrail shop are orders of magnitude apart - and the only question the row answers is which
     * months were worse than the others.
     */
    const peak = computed(() => Math.max(...byMonth.value.map(month => Number(month.weight_kg) || 0), 0));

    //Methods
    /*
     * Whole kilograms. The decimal was spurious precision: a drop is weighed by multiplying its
     * length by a catalogue mass per metre, so the tenth of a kilogram is an artefact of the
     * arithmetic rather than anything a weighbridge would agree with. Rounded here rather than in
     * the report, which keeps its precision so the shares of a drop split between jobs still add
     * back up before they are displayed.
     */
    function kilograms(weight){
        return Math.round(Number(weight) || 0).toLocaleString();
    }

    function metres(millimetres){
        return (Number(millimetres || 0) / 1000).toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 1});
    }

    //"2026-03" reads as a date nobody says out loud
    function monthName(key){
        const [year, month] = String(key).split('-');

        return new Date(Number(year), Number(month) - 1, 1)
            .toLocaleDateString(undefined, {month: 'short', year: 'numeric'});
    }

    function barWidth(weight){
        return peak.value > 0 ? `${Math.max((Number(weight) || 0) / peak.value * 100, 0)}%` : '0%';
    }

    function showWindow(months){
        router.get(route('scrap.index'), {months}, {preserveScroll: true, preserveState: true});
    }
</script>

<template>
    <Head title="Scrap" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <Link
                    class="inline-flex items-center gap-x-2 text-sm font-medium text-gray-600 transition-colors duration-200 hover:text-blue-600 dark:text-gray-300 dark:hover:text-blue-400"
                    :href="route('offcuts.index')"
                >
                    <span aria-hidden="true">&larr;</span> Offcuts
                </Link>

                <header class="mt-4">
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Scrap</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                        Steel this yard has destroyed: the ends a nest left too short to bank, and the
                        dead stock weighed in off the rack. Each piece is weighed at the moment it
                        happens, against the section it actually was - so nothing below moves when
                        the catalogue is edited afterwards.
                    </p>
                </header>

                <!-- The window. Months, because the question is whether it is getting better -->
                <div class="mt-6 flex flex-wrap items-center gap-2">
                    <span class="text-sm text-gray-500 dark:text-gray-400">Showing</span>

                    <button
                        v-for="window in windows"
                        :key="window"
                        type="button"
                        :aria-pressed="months === window"
                        class="rounded-lg border px-3 py-1 text-sm font-medium transition-colors duration-200"
                        :class="months === window
                            ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-900/20 dark:text-blue-300'
                            : 'border-gray-300 text-gray-600 hover:border-gray-400 dark:border-gray-600 dark:text-gray-300'"
                        @click="showWindow(window)"
                    >
                        {{ window }} months
                    </button>

                    <span class="text-xs text-gray-400 dark:text-gray-500">
                        {{ report.from }} to {{ report.to }}
                    </span>
                </div>

                <!--
                    An empty report is a real answer and says so. A page of zeroes with no sentence
                    on it reads as something that has not loaded.
                -->
                <section
                    v-if="nothingYet"
                    class="mt-6 rounded-xl border border-gray-200 bg-white px-4 py-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
                >
                    <p class="font-medium text-gray-900 dark:text-gray-100">Nothing destroyed in this window.</p>
                    <p class="mt-1 max-w-3xl">
                        Either nothing has been nested in these months, or every drop was long enough
                        to go back on the rack. Batches nested before scrap was recorded do not appear
                        here until an administrator has run the backfill over them.
                    </p>
                </section>

                <template v-else>
                    <!--
                        The headline. Weight leads because it is the figure a yield objective is set
                        against and the one a scrap docket is written in; length and count are the
                        same steel said two other ways, and between them they say whether a month was
                        one bad bar or forty ordinary ones.
                    -->
                    <section class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-4 dark:border-amber-900/40 dark:bg-amber-900/10">
                            <p class="text-xs uppercase tracking-wide text-amber-800 dark:text-amber-300">Destroyed</p>
                            <p class="mt-1 text-2xl font-semibold text-amber-900 dark:text-amber-200">{{ kilograms(totals.weight_kg) }} kg</p>
                            <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-300/80">over {{ months }} months</p>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">End to end</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ metres(totals.length_mm) }} m</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">of steel cut off and binned</p>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Pieces</p>
                            <p class="mt-1 text-2xl font-semibold text-gray-900 dark:text-gray-100">{{ totals.pieces }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">separate drops, not one total</p>
                        </div>
                    </section>

                    <!-- Why it was destroyed. Two different problems with two different answers -->
                    <section class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div
                            v-for="source in bySource"
                            :key="source.source"
                            class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">{{ source.label }}</p>
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ source.hint }}</p>
                            <p class="mt-2 text-lg font-semibold text-gray-900 dark:text-gray-100">
                                {{ kilograms(source.weight_kg) }} kg
                                <span class="text-sm font-normal text-gray-500 dark:text-gray-400">
                                    · {{ metres(source.length_mm) }} m · {{ source.pieces }} pieces
                                </span>
                            </p>
                        </div>
                    </section>

                    <!-- Month by month -->
                    <section class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Month by month</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Bars are drawn against the worst month in this window, not against a
                                target - what matters is which months were worse than the rest.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Month</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Weight</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Length</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Pieces</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="month in byMonth" :key="month.month">
                                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">{{ monthName(month.month) }}</td>

                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-x-3">
                                                <div class="h-2 w-40 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                                    <div class="h-full rounded-full bg-amber-500/80" :style="{width: barWidth(month.weight_kg)}"></div>
                                                </div>
                                                <span class="text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">{{ kilograms(month.weight_kg) }} kg</span>
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ metres(month.length_mm) }} m</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ month.pieces }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- By section -->
                    <section class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">By product category</h2>
                            <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                Heaviest first. A category near the top is either being bought in the
                                wrong lengths or being cut in jobs too small to fill a bar.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Category</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Pieces</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Length</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Weight</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="category in byCategory" :key="category.product_category">
                                        <td class="px-4 py-3 text-sm font-medium text-gray-900 whitespace-nowrap dark:text-gray-100">{{ category.product_category }}</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ category.pieces }}</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ metres(category.length_mm) }} m</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ kilograms(category.weight_kg) }} kg</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!-- By job -->
                    <section class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">By job</h2>
                            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                                A bar opened for three jobs leaves one end, and no one of the three
                                left it there - so each drop is split between the jobs in proportion
                                to the steel each took off that bar. The kilograms add back up to the
                                headline bar the rounding; the piece counts deliberately do not,
                                because a drop shared three ways is still one piece of steel in a skip.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Job</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Pieces</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Length</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Weight</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="row in byProject" :key="row.project_id ?? 'unattributed'">
                                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                                            {{ row.project }}

                                            <!--
                                                Said on the row rather than in a footnote. Dead stock
                                                belongs to the rack, not to whichever job happened to
                                                produce the offcut a year ago.
                                            -->
                                            <span v-if="row.project_id === null" class="block text-xs text-gray-500 dark:text-gray-400">
                                                weighed in off the rack, or off a bar too old to name
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ row.pieces }}</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ metres(row.length_mm) }} m</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ kilograms(row.weight_kg) }} kg</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
