<script setup>
    //General Imports
    import {Link, Head, router} from '@inertiajs/vue3';
    import {computed} from 'vue';

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        /**
         * The three series, already added up - see App\Services\MeasuresReport. Shape is
         * {from, to, yield, delivery, scrap, provenance, by_month}, and every row of by_month
         * carries the yield keys, the delivery keys and the month's scrap weight together.
         *
         * Nothing on this page is computed from a nest. Every figure was written down by the event
         * it measures, which is what lets a month from two years ago still say what it said then.
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
    const yieldTotals = computed(() => props.report.yield ?? {});
    const delivery = computed(() => props.report.delivery ?? {});
    const scrap = computed(() => props.report.scrap ?? {});
    const provenance = computed(() => props.report.provenance ?? {});
    const byMonth = computed(() => props.report.by_month ?? []);

    const nothingYet = computed(() => (yieldTotals.value.batches ?? 0) === 0 && (delivery.value.deliveries ?? 0) === 0);

    /*
     * Said out loud rather than footnoted. A batch nested before the cost coefficients were retained
     * carries a dollar figure struck against whatever the rates happened to be when it was read, and
     * a page that prints those beside properly retained ones is presenting a valuation as a record.
     * The steel figures - the millimetres and the percentages - are unaffected either way.
     */
    const costedWithoutSettings = computed(
        () => (provenance.value.costed ?? 0) - (provenance.value.costed_on_retained_settings ?? 0),
    );

    //Methods
    function percent(value){
        return value === null || value === undefined ? '-' : `${Number(value).toFixed(1)}%`;
    }

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

    /*
     * Bars are drawn against the full scale, not against the best month, because unlike scrap a
     * percentage HAS an absolute scale - 100% is all of it. A relative bar would redraw itself every
     * time the window changed and make a steady year look dramatic.
     */
    function barWidth(value){
        return value === null || value === undefined ? '0%' : `${Math.max(Math.min(Number(value), 100), 0)}%`;
    }

    function showWindow(months){
        router.get(route('measures.index'), {months}, {preserveScroll: true, preserveState: true});
    }
</script>

<template>
    <Head title="Measures" />

    <AuthenticatedLayout>
        <div class="py-8">
            <div class="mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
                <header>
                    <h1 class="text-2xl font-semibold text-gray-900 dark:text-gray-100">Measures</h1>
                    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                        Three things a month can be judged on: how much of the steel became a part,
                        how much of it was destroyed, and how often it was in the yard by the day it
                        was wanted. Each figure was recorded at the moment it happened, so nothing
                        here changes when a price, a catalogue row or a fabrication date is edited
                        afterwards.
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
                    An empty report is a real answer and says so, rather than drawing a page of
                    zeroes that reads as something which has not loaded.
                -->
                <section
                    v-if="nothingYet"
                    class="mt-6 rounded-xl border border-gray-200 bg-white px-4 py-6 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-400"
                >
                    <p class="font-medium text-gray-900 dark:text-gray-100">Nothing measured in this window.</p>
                    <p class="mt-1 max-w-3xl">
                        Nothing has been nested or delivered in these months. Batches nested before
                        measurements were retained do not appear here until an administrator has run
                        the backfill over them, and deliveries are only measured from the day this
                        page existed - the day a job was promised its steel is editable, so there is
                        no honest way to go back and say whether an older one was late.
                    </p>
                </section>

                <template v-else>
                    <!--
                        The headline, one tile per measure. Each carries what it is made of
                        underneath it, because a bare percentage is a figure nobody can check.
                    -->
                    <section class="mt-6 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-emerald-200 bg-emerald-50/60 px-4 py-4 dark:border-emerald-900/40 dark:bg-emerald-900/10">
                            <p class="text-xs uppercase tracking-wide text-emerald-800 dark:text-emerald-300">Yield</p>
                            <p class="mt-1 text-2xl font-semibold text-emerald-900 dark:text-emerald-200">{{ percent(yieldTotals.efficiency) }}</p>
                            <p class="mt-1 text-xs text-emerald-800/80 dark:text-emerald-300/80">
                                {{ metres(yieldTotals.used_mm) }} m of parts out of {{ metres(yieldTotals.consumed_mm) }} m consumed,
                                over {{ yieldTotals.batches }} batches
                            </p>
                        </div>

                        <div class="rounded-xl border border-amber-200 bg-amber-50/60 px-4 py-4 dark:border-amber-900/40 dark:bg-amber-900/10">
                            <p class="text-xs uppercase tracking-wide text-amber-800 dark:text-amber-300">Destroyed</p>
                            <p class="mt-1 text-2xl font-semibold text-amber-900 dark:text-amber-200">{{ kilograms(scrap.weight_kg) }} kg</p>
                            <p class="mt-1 text-xs text-amber-800/80 dark:text-amber-300/80">
                                {{ scrap.pieces }} separate drops ·
                                <Link class="underline hover:no-underline" :href="route('scrap.index', {months})">the detail</Link>
                            </p>
                        </div>

                        <div class="rounded-xl border border-blue-200 bg-blue-50/60 px-4 py-4 dark:border-blue-900/40 dark:bg-blue-900/10">
                            <p class="text-xs uppercase tracking-wide text-blue-800 dark:text-blue-300">On time</p>
                            <p class="mt-1 text-2xl font-semibold text-blue-900 dark:text-blue-200">{{ percent(delivery.on_time_rate) }}</p>
                            <p class="mt-1 text-xs text-blue-800/80 dark:text-blue-300/80">
                                {{ delivery.on_time }} of {{ (delivery.on_time ?? 0) + (delivery.late ?? 0) }} deliveries
                                <span v-if="delivery.unpromised">· {{ delivery.unpromised }} against no date</span>
                            </p>
                        </div>
                    </section>

                    <!--
                        What the other end of the yield figure is. Steel banked is not steel wasted,
                        and the two percentages answering different questions is the whole reason
                        both are kept - see NestingFormatter::usageStats.
                    -->
                    <section class="mt-4 grid gap-4 sm:grid-cols-3">
                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Not destroyed</p>
                            <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ percent(yieldTotals.effective_efficiency) }}</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">of everything handled, parts and rack together</p>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Back on the rack</p>
                            <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ metres(yieldTotals.reusable_mm) }} m</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">remnants long enough to keep</p>
                        </div>

                        <div class="rounded-xl border border-gray-200 bg-white px-4 py-4 dark:border-gray-700 dark:bg-gray-900">
                            <p class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Bought new</p>
                            <p class="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-100">{{ metres(yieldTotals.purchased_mm) }} m</p>
                            <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">against {{ metres(yieldTotals.offcut_mm) }} m taken off the rack</p>
                        </div>
                    </section>

                    <!-- Month by month: all three series in one row each -->
                    <section class="mt-8 overflow-hidden rounded-xl border border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900">
                        <div class="border-b border-gray-200 px-4 py-4 dark:border-gray-700">
                            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">Month by month</h2>
                            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                                A batch's yield belongs to the month it was nested and its delivery to
                                the month the steel arrived, so the two halves of a row are frequently
                                about different work. A month with a dash had nothing to measure.
                            </p>
                        </div>

                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                <thead class="bg-gray-50 dark:bg-gray-800">
                                    <tr>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Month</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Yield</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Batches</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Scrap</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">On time</th>
                                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Worst</th>
                                    </tr>
                                </thead>

                                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                                    <tr v-for="month in byMonth" :key="month.month">
                                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">{{ monthName(month.month) }}</td>

                                        <td class="px-4 py-3">
                                            <div class="flex items-center gap-x-3">
                                                <div class="h-2 w-32 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                                    <div class="h-full rounded-full bg-emerald-500/80" :style="{width: barWidth(month.efficiency)}"></div>
                                                </div>
                                                <span class="text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">{{ percent(month.efficiency) }}</span>
                                            </div>
                                        </td>

                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ month.batches }}</td>
                                        <td class="px-4 py-3 text-sm text-right text-gray-700 dark:text-gray-200">{{ kilograms(month.scrap_weight_kg) }} kg</td>

                                        <td class="px-4 py-3 text-sm text-right text-gray-700 whitespace-nowrap dark:text-gray-200">
                                            {{ percent(month.on_time_rate) }}
                                            <span v-if="month.deliveries" class="block text-xs text-gray-500 dark:text-gray-400">
                                                {{ month.on_time }} of {{ month.on_time + month.late }}
                                            </span>
                                        </td>

                                        <!-- The worst miss of the month. An objective is missed by its outliers -->
                                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap"
                                            :class="month.worst_days_late ? 'text-red-600 dark:text-red-400' : 'text-gray-400 dark:text-gray-500'"
                                        >
                                            <template v-if="month.worst_days_late">{{ month.worst_days_late }}d late</template>
                                            <template v-else>-</template>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </section>

                    <!--
                        Where the figures came from. Only ever says something when there is something
                        to say - a window of batches that all retained their settings prints nothing.
                    -->
                    <section
                        v-if="costedWithoutSettings > 0"
                        class="mt-6 rounded-xl border border-gray-200 bg-gray-50 px-4 py-4 text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-400"
                    >
                        <p>
                            {{ costedWithoutSettings }} of the {{ provenance.costed }} costed batches in
                            this window were nested before the rates behind a cost were kept with the
                            nest. Their steel figures above are what was measured at the time; the
                            dollars on those batches are today's valuation of that steel rather than
                            what it was costed at.
                        </p>
                    </section>
                </template>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
