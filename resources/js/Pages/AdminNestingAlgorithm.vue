<script setup>
    //General Imports
    import {Head} from '@inertiajs/vue3';
    import {computed, ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';

    //Props
    const props = defineProps({
        business: Object,
        settings: Array,
        referenceLengthMm: Number,
        sectionCurves: Array,
        labourBySection: Array,
        workedExamples: Array,
        invariant: Object,
        sections: Object,
    });

    /*
     * Chart geometry, in viewBox units. The svg scales to its container, so these are not pixels - the
     * right-hand gutter is wide because each series carries a direct label at its end, on top of the legend.
     *
     * TWO STACKED PANELS sharing one x axis, rather than two lines on one y axis.
     *
     * What a drop retains and what it costs to keep are both dollars, but they differ by one to two orders
     * of magnitude: on a 500UB the retained value peaks at $1,296 while the labour peaks at $18.50, so on a
     * shared scale the cost line used 1.4% of the height and sat on the axis - and the crossover, which is
     * the only thing this chart exists to show, was invisible. Two y scales on one chart is never the answer;
     * small multiples are. The crossover is drawn through both panels so it reads as one figure.
     */
    const PLOT = {
        width: 760,
        height: 300,
        left: 54,
        right: 132,
    };

    const PANELS = [
        {key: 'worth', title: 'What the drop retains', top: 26, height: 128, series: 'worth', cls: 'worth', bankedOnly: false},
        {key: 'keep', title: 'What keeping it costs', top: 196, height: 64, series: 'keepCost', cls: 'keep', bankedOnly: true},
    ];

    //Where the x axis sits: under the lower panel
    const AXIS_Y = 196 + 64;

    //Variables
    //Index of the point under the cursor, or null when the pointer is off the plot
    const hoverIndex = ref(null);
    //Which section the chart and its table are showing. Everything below follows this
    const sectionIndex = ref(0);

    //Computed
    const scrapThresholdMm = computed(() =>
        props.settings.find(setting => setting.key === 'scrap_threshold_mm')?.value ?? 0
    );

    const retentionCapPct = computed(() => {
        const cap = props.settings.find(setting => setting.key === 'offcut_retention_cap')?.value ?? 0;

        return Math.round(cap * 100);
    });

    const scrapRecoveryPct = computed(() => {
        const rate = props.settings.find(setting => setting.key === 'scrap_recovery_rate')?.value ?? 0;

        return Math.round(rate * 100);
    });

    const plotWidth = computed(() => PLOT.width - PLOT.left - PLOT.right);

    //The section on show. Its points and its table move together, so the two cannot disagree
    const section = computed(() => props.sectionCurves[sectionIndex.value]);
    const points = computed(() => section.value.points);

    const lengthDomain = computed(() => {
        const lengths = points.value.map(point => point.lengthMm);

        return {min: Math.min(...lengths), max: Math.max(...lengths)};
    });

    /*
     * Each panel scaled to its own series, rounded up to something a tick can land on. The lower panel only
     * draws where a drop actually goes on the rack: below the threshold it is destroyed, so a cost line there
     * would imply a rack slot that is never taken.
     */
    const panels = computed(() => PANELS.map(panel => {
        const relevant = points.value.filter(point => !panel.bankedOnly || point.banked);
        const peak = Math.max(...relevant.map(point => point[panel.series]), 0.01);
        const step = niceStep(peak / 2);
        const max = step * Math.ceil(peak / step);

        return {
            ...panel,
            max,
            path: pathFor(panel, max),
            //Three gridlines a panel: enough to read a value off, few enough to stay recessive
            ticks: [0, 0.5, 1].map(share => ({
                share,
                value: max * share,
                label: formatAxisMoney(max * share),
                y: panel.top + (1 - share) * panel.height,
            })),
            end: relevant[relevant.length - 1],
        };
    }));

    const lastPoint = computed(() => points.value[points.value.length - 1]);

    const hoveredPoint = computed(() =>
        hoverIndex.value === null ? null : points.value[hoverIndex.value]
    );

    //Only ticks the domain actually covers, so a business with a different threshold still reads correctly
    const xTicks = computed(() => [2000, 4000, 6000, 8000, 10000, 12000]
        .filter(length => length >= lengthDomain.value.min && length <= lengthDomain.value.max)
        .map(length => ({length, label: `${length / 1000}m`, x: xFor(length)}))
    );

    //Methods
    /*
     * A round-ish step for the axis, so ticks read as $5 / $50 / $750 rather than $4.83.
     *
     * The multiples are deliberately fine-grained. With only [1, 2, 2.5, 5, 10] a $1,296 peak rounded to a
     * $2,000 axis and the line used two thirds of the panel; these keep every section between 80% and 98%.
     */
    function niceStep(rough) {
        const magnitude = Math.pow(10, Math.floor(Math.log10(Math.max(rough, 0.01))));

        return [1, 1.5, 2, 2.5, 3, 4, 5, 6, 7.5, 10]
            .find(multiple => magnitude * multiple >= rough) * magnitude;
    }

    function pathFor(panel, max) {
        let started = false;

        return points.value.reduce((path, point) => {
            if (panel.bankedOnly && !point.banked) {
                return path;
            }

            const command = started ? 'L' : 'M';
            started = true;

            return `${path}${command}${xFor(point.lengthMm).toFixed(2)},${yIn(panel, point[panel.series], max).toFixed(2)}`;
        }, '');
    }

    function xFor(lengthMm) {
        const {min, max} = lengthDomain.value;
        const span = max - min || 1;

        return PLOT.left + ((lengthMm - min) / span) * plotWidth.value;
    }

    //A value's y within one panel. Each panel has its own scale, which is the point of stacking them
    function yIn(panel, dollars, max) {
        return panel.top + (1 - (dollars / (max ?? panel.max))) * panel.height;
    }

    //Axis labels drop the cents: a $1,296 scale does not need them, and a $5 one reads better without
    function formatAxisMoney(value) {
        return `$${Number(value).toLocaleString(undefined, {maximumFractionDigits: value < 10 ? 1 : 0})}`;
    }

    function formatMm(value) {
        return `${Number(value).toLocaleString()}mm`;
    }

    function formatMoney(value) {
        return `$${Number(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function formatMinutes(value) {
        return `${Number(value).toLocaleString(undefined, {maximumFractionDigits: 1})} min`;
    }

    /*
     * Nearest point to the cursor along x.
     *
     * Measured against the rendered box and rescaled into viewBox units, because the svg is responsive -
     * using the raw offset would drift further from the curve the wider the screen got.
     */
    function trackPointer(event) {
        const box = event.currentTarget.getBoundingClientRect();

        if (!box.width) {
            return;
        }

        const x = ((event.clientX - box.left) / box.width) * PLOT.width;

        let nearest = 0;
        let smallestGap = Infinity;

        points.value.forEach((point, index) => {
            const gap = Math.abs(xFor(point.lengthMm) - x);

            if (gap < smallestGap) {
                smallestGap = gap;
                nearest = index;
            }
        });

        hoverIndex.value = nearest;
    }

    //Which option in a worked example is the one the nest would take, per section weight
    function cheapest(options, weight) {
        return options.reduce(
            (best, option) => (option.costs[weight] < best.costs[weight] ? option : best),
            options[0],
        );
    }

    function isCheapest(options, option, weight) {
        return cheapest(options, weight).label === option.label;
    }
</script>

<template>
    <Head title="Nesting algorithm" />

    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8 sm:px-6 lg:px-8">

            <!-- Heading -->
            <header class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                    How nesting chooses a plan
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                    Nesting builds many candidate plans for the same pieces and keeps the cheapest. Cost is
                    counted in <strong class="font-semibold text-gray-900">dollars &mdash; steel plus the time
                    it takes</strong>, because that is the only currency in which labour and material can be
                    weighed against each other. The question it is really answering is whether the labour of
                    preserving a remnant is worth less than the remnant: nobody should spend twenty minutes
                    saving $9 of equal angle, while a $3,000 beam can carry a good deal of extra handling.
                </p>
                <p class="mt-2 text-xs text-gray-500">
                    Every figure on this page is computed by the live cost model, so it cannot drift from what
                    nesting actually does.
                    <template v-if="business.isUnsaved">
                        No business is attached to your account, so these are the platform defaults.
                    </template>
                    <template v-else>
                        Showing the settings for <strong class="font-semibold text-gray-700">{{ business.name }}</strong>.
                    </template>
                </p>
            </header>

            <!-- Invariant warning -->
            <div
                v-if="!invariant.holds"
                role="alert"
                class="mb-6 flex gap-3 rounded-xl border border-red-300 bg-red-50 p-4 text-sm leading-relaxed text-red-900"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none"></i>
                <div>
                    <p class="font-semibold">These settings will make the nest buy steel in order to rack it.</p>
                    <p class="mt-1">
                        A drop's marginal retained value reaches
                        <strong>{{ invariant.marginalRetainedValue }}</strong> per millimetre, which is at or above
                        the purchase weight of <strong>{{ invariant.purchaseCostWeight }}</strong>. Buying one more
                        millimetre of bar and banking it then pays for itself, and the nest will buy a long bar to
                        make a short cut. Lower the retention cap, or raise the purchase weight.
                    </p>
                </div>
            </div>

            <!-- The cost of a plan -->
            <section class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                        What a plan costs
                    </h2>
                </header>
                <div class="divide-y divide-gray-100">
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">1</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">Every piece gets made</p>
                            <p>
                                A cut that nothing can hold carries a penalty nothing else can outweigh. A plan is
                                no use at any price if the pieces do not come out of it, so this is settled before
                                a single millimetre is compared.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">2</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">Steel bought</p>
                            <p>
                                The only line the business actually pays. This is what stops the nest buying its
                                way out of cutting into inventory.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">3</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">Steel destroyed, less what the bin pays back</p>
                            <p>
                                Never discounted by what the piece it came off was carried at &mdash; a millimetre in
                                the skip is the same millimetre whether it came off a bar just bought or a stub that
                                had sat on the rack for years. But scrap is not a write-off: a solid drop is weighed
                                in and credited at
                                <strong class="font-semibold text-gray-900">{{ scrapRecoveryPct }}%</strong> of the
                                new price, which makes the bin a legitimately cheap way out of a remnant nobody
                                wants. Saw kerf earns nothing &mdash; swarf mixed with coolant is not what a
                                merchant pays for.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">4</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">Inventory value given up, less what went back</p>
                            <p>
                                Each offcut taken off the rack gives up its own value and earns back whatever its
                                drop is worth; each drop off a new bar is value added. Measured as a
                                <em>difference</em>, which is why cutting a 2,000mm stub down to 1,500mm costs far
                                less than taking the same 500mm off a 9,000mm length - the stub was worth little to
                                begin with.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">5</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">The labour a racked offcut will cost over its life</p>
                            <p>
                                Marking it, recording it, and shifting it about before it is finally used or
                                scrapped &mdash; charged on the net change to the rack. Banking a drop off an offcut
                                is close to free, since one piece replaced another. Consuming a stub outright is a
                                <strong class="font-semibold text-gray-900">credit</strong>: a mark retired for good.
                                A drop off a new bar is one more piece to find, verify and move on every future job.
                                Weighed against what the drop is worth above, this is the rule the model turns on.
                            </p>
                        </div>
                    </div>
                    <div class="flex gap-4 px-4 py-3">
                        <span class="mt-0.5 flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">6</span>
                        <div class="text-sm leading-relaxed text-gray-700">
                            <p class="font-semibold text-gray-900">The labour of the job itself</p>
                            <p>
                                Fetching each offcut out of the rack, taking each bar off the lift, and every cut on
                                the saw &mdash; each as a duration, priced at the shop rate. Cutting scales with the
                                section's mass per metre, because a 500UB web is not a 65mm angle. Moving scales with
                                the mass of the actual piece: a 6m angle is carried, a 12m 500UB at over a tonne is a
                                crane, slings and a second pair of hands.
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <!-- What a remnant is worth against what it costs to keep -->
            <section class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                        Is a remnant worth keeping?
                    </h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                        What a drop retains as inventory, against the labour it will cost on the rack &mdash; both in
                        dollars, against a {{ formatMm(referenceLengthMm) }} stock length.
                    </p>
                </header>

                <div class="px-4 py-4">
                    <!--
                        Section picker. Both lines and the table below it follow this, because the dollar figures
                        are the point of the chart and they move by a factor of sixteen between these sections.
                    -->
                    <div class="mb-4">
                        <p id="section-picker-label" class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-500">
                            Section
                        </p>
                        <div class="flex flex-wrap gap-2" role="group" aria-labelledby="section-picker-label">
                            <button
                                v-for="(curve, index) in sectionCurves"
                                :key="curve.label"
                                type="button"
                                class="rounded-lg border px-3 py-1.5 text-sm transition-colors duration-150 focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-400 focus-visible:ring-offset-1"
                                :class="index === sectionIndex
                                    ? 'border-blue-300 bg-blue-50 font-semibold text-blue-900'
                                    : 'border-gray-300 bg-white text-gray-700 hover:border-gray-400'"
                                :aria-pressed="index === sectionIndex"
                                @click="sectionIndex = index; hoverIndex = null"
                            >
                                {{ curve.label }}
                                <span class="ml-1 text-xs font-normal text-gray-500">
                                    {{ formatMoney(curve.costPerMetre) }}/m
                                </span>
                            </button>
                        </div>
                    </div>

                    <p class="mb-3 max-w-3xl text-sm leading-relaxed text-gray-700">
                        A drop that clears the scrap threshold by a millimetre is banked, but it retains
                        <strong class="font-semibold text-gray-900">almost nothing</strong> &mdash; so producing one
                        is not a win. Value climbs steeply as the drop gets genuinely usable and flattens towards
                        {{ retentionCapPct }}% of the steel, which it never exceeds: an offcut is deferred value on a
                        rack, not steel in a bar.
                    </p>

                    <p class="mb-4 max-w-3xl rounded-lg border border-gray-200 bg-gray-50 p-3 text-sm leading-relaxed text-gray-700">
                        <strong class="font-semibold text-gray-900">Where the two lines cross is the answer.</strong>
                        Left of it, a remnant of {{ section.label }} costs more in marking, recording and shifting
                        than it will ever give back. Right of it, it pays for its keep.
                        <template v-if="section.worthRackingFromMm !== null">
                            On this section that is
                            <strong class="font-semibold text-gray-900">{{ formatMm(section.worthRackingFromMm) }}</strong>
                            &mdash; about {{ formatMoney(section.worthRackingValue) }} of steel.
                        </template>
                        <template v-else>
                            On this section they never cross: no drop up to a full stock length pays for its own keep.
                        </template>
                    </p>

                    <figure class="viz-root">
                        <!-- Legend: always present with two series, and both lines are directly labelled too -->
                        <figcaption class="mb-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-gray-600">
                            <span class="inline-flex items-center gap-1.5">
                                <span class="viz-key viz-key-worth" aria-hidden="true"></span>
                                What the drop retains
                            </span>
                            <span class="inline-flex items-center gap-1.5">
                                <span class="viz-key viz-key-keep" aria-hidden="true"></span>
                                What keeping it costs
                            </span>
                            <span v-if="section.worthRackingFromMm !== null" class="inline-flex items-center gap-1.5">
                                <span class="viz-key viz-key-crossover" aria-hidden="true"></span>
                                Pays for its keep from {{ formatMm(section.worthRackingFromMm) }}
                            </span>
                        </figcaption>

                        <svg
                            :viewBox="`0 0 ${PLOT.width} ${PLOT.height}`"
                            class="block h-auto w-full"
                            role="img"
                            :aria-label="`For ${section.label}, what a drop retains rises steeply with its length while the labour of keeping it stays nearly flat. ${section.worthRackingFromMm === null ? 'They never meet, so no drop pays for its keep.' : 'They meet at ' + formatMm(section.worthRackingFromMm) + ', which is where a remnant starts paying for its keep.'} The table below lists the same values.`"
                            @pointermove="trackPointer"
                            @pointerleave="hoverIndex = null"
                        >
                            <!-- One panel per series, each on its own scale. See PLOT/PANELS. -->
                            <g v-for="panel in panels" :key="panel.key">
                                <!-- Panel title, so neither panel depends on the legend alone -->
                                <text
                                    :x="PLOT.left"
                                    :y="panel.top - 8"
                                    class="viz-panel-title"
                                    :class="`viz-panel-title-${panel.cls}`"
                                >{{ panel.title }}</text>

                                <!-- Gridlines: hairline, solid, recessive -->
                                <line
                                    v-for="tick in panel.ticks"
                                    :key="`grid-${panel.key}-${tick.share}`"
                                    :x1="PLOT.left"
                                    :x2="PLOT.width - PLOT.right"
                                    :y1="tick.y"
                                    :y2="tick.y"
                                    class="viz-grid"
                                />
                                <text
                                    v-for="tick in panel.ticks"
                                    :key="`ylabel-${panel.key}-${tick.share}`"
                                    :x="PLOT.left - 10"
                                    :y="tick.y + 4"
                                    text-anchor="end"
                                    class="viz-axis-text"
                                >{{ tick.label }}</text>

                                <!--
                                    Everything left of the threshold is destroyed rather than banked, so neither
                                    series exists there. Shaded so the step out of scrap is visible.
                                -->
                                <rect
                                    :x="PLOT.left"
                                    :y="panel.top"
                                    :width="Math.max(0, xFor(scrapThresholdMm) - PLOT.left)"
                                    :height="panel.height"
                                    class="viz-dead-zone"
                                />
                                <line
                                    :x1="xFor(scrapThresholdMm)"
                                    :x2="xFor(scrapThresholdMm)"
                                    :y1="panel.top"
                                    :y2="panel.top + panel.height"
                                    class="viz-threshold"
                                />

                                <!--
                                    The crossover, through both panels so it reads as one figure rather than two.
                                    Drawn before the lines so it never sits on top of them.
                                -->
                                <line
                                    v-if="section.worthRackingFromMm !== null"
                                    :x1="xFor(section.worthRackingFromMm)"
                                    :x2="xFor(section.worthRackingFromMm)"
                                    :y1="panel.top"
                                    :y2="panel.top + panel.height"
                                    class="viz-crossover"
                                />

                                <!-- Hover crosshair, also through both panels -->
                                <line
                                    v-if="hoveredPoint"
                                    :x1="xFor(hoveredPoint.lengthMm)"
                                    :x2="xFor(hoveredPoint.lengthMm)"
                                    :y1="panel.top"
                                    :y2="panel.top + panel.height"
                                    class="viz-crosshair"
                                />

                                <!-- The series: 2px, round join and cap -->
                                <path :d="panel.path" class="viz-line" :class="`viz-line-${panel.cls}`" />

                                <!-- Direct label at the end of the line, on top of the legend -->
                                <g v-if="panel.end">
                                    <circle
                                        :cx="xFor(panel.end.lengthMm)"
                                        :cy="yIn(panel, panel.end[panel.series])"
                                        r="4"
                                        class="viz-dot"
                                        :class="`viz-dot-${panel.cls}`"
                                    />
                                    <text
                                        :x="xFor(panel.end.lengthMm) + 12"
                                        :y="yIn(panel, panel.end[panel.series]) + 4"
                                        class="viz-end-label"
                                        :class="`viz-end-label-${panel.cls}`"
                                    >{{ formatMoney(panel.end[panel.series]) }}</text>
                                </g>

                                <!-- Hover marker, with a surface ring so it stays legible over the line -->
                                <circle
                                    v-if="hoveredPoint && (!panel.bankedOnly || hoveredPoint.banked)"
                                    :cx="xFor(hoveredPoint.lengthMm)"
                                    :cy="yIn(panel, hoveredPoint[panel.series])"
                                    r="5"
                                    class="viz-dot"
                                    :class="`viz-dot-${panel.cls}`"
                                />
                            </g>

                            <!-- Shared x axis, once, under the lower panel -->
                            <line
                                :x1="PLOT.left"
                                :x2="PLOT.width - PLOT.right"
                                :y1="AXIS_Y"
                                :y2="AXIS_Y"
                                class="viz-grid"
                            />
                            <text
                                v-for="tick in xTicks"
                                :key="`xlabel-${tick.length}`"
                                :x="tick.x"
                                :y="AXIS_Y + 18"
                                text-anchor="middle"
                                class="viz-axis-text"
                            >{{ tick.label }}</text>
                            <text
                                :x="PLOT.left + (plotWidth / 2)"
                                :y="PLOT.height - 4"
                                text-anchor="middle"
                                class="viz-axis-text"
                            >drop length</text>

                            <!--
                                The threshold named once, at the top. The crossover is named in the legend
                                instead: in the plot it landed on the lower panel's title at every section
                                whose crossover sits near the left, which is most of them.
                            -->
                            <text
                                :x="xFor(scrapThresholdMm) + 6"
                                :y="14"
                                class="viz-axis-text"
                            >scrap threshold {{ formatMm(scrapThresholdMm) }}</text>
                        </svg>

                        <figcaption class="mt-2 min-h-[2.5rem] text-xs leading-relaxed text-gray-600">
                            <template v-if="hoveredPoint">
                                A <strong class="font-semibold text-gray-900">{{ formatMm(hoveredPoint.lengthMm) }}</strong>
                                drop of {{ section.label }}
                                <template v-if="hoveredPoint.banked">
                                    retains <strong class="font-semibold text-gray-900">{{ formatMoney(hoveredPoint.worth) }}</strong>
                                    ({{ Math.round(hoveredPoint.retention * 100) }}% of its steel) and will cost
                                    <strong class="font-semibold text-gray-900">{{ formatMoney(hoveredPoint.keepCost) }}</strong>
                                    to keep &mdash;
                                    <template v-if="hoveredPoint.worth >= hoveredPoint.keepCost">worth having.</template>
                                    <template v-else>not worth producing.</template>
                                </template>
                                <template v-else>
                                    is under the scrap threshold, so it is destroyed rather than banked.
                                </template>
                            </template>
                            <template v-else>
                                Hover the chart to read a drop length.
                            </template>
                        </figcaption>
                    </figure>

                    <!-- Table view of the chart -->
                    <div class="mt-4 overflow-x-auto">
                        <table class="w-full min-w-[40rem] text-left text-sm">
                            <caption class="sr-only">
                                Retained value and cost to keep, at selected drop lengths, for {{ section.label }}
                            </caption>
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="py-2 pr-3 font-semibold">Drop</th>
                                    <th scope="col" class="py-2 pr-3 font-semibold">Outcome</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Keeps</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Retains</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Costs to keep</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Or bin it for</th>
                                    <th scope="col" class="py-2 font-semibold">&nbsp;</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in section.rows" :key="row.lengthMm">
                                    <td class="py-2 pr-3 font-medium text-gray-900">{{ formatMm(row.lengthMm) }}</td>
                                    <td class="py-2 pr-3">
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold"
                                            :class="row.banked ? 'bg-blue-50 text-blue-800' : 'bg-gray-100 text-gray-700'"
                                        >{{ row.banked ? 'Banked' : 'Destroyed' }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">{{ row.retentionPct }}%</td>
                                    <td
                                        class="py-2 pr-3 text-right tabular-nums"
                                        :class="row.keepCost !== null && row.worth < row.keepCost ? 'text-gray-400' : 'font-semibold text-gray-900'"
                                    >{{ formatMoney(row.worth) }}</td>
                                    <!--
                                        Side by side these two are the decision. Where keeping it costs more than it
                                        retains, the remnant is not worth producing in the first place.
                                    -->
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        <template v-if="row.keepCost !== null">{{ formatMoney(row.keepCost) }}</template>
                                        <span v-else class="text-gray-400">&mdash;</span>
                                    </td>
                                    <!--
                                        Binning forfeits the steel less what the bin pays back, which is why racking
                                        still wins at every length here: you cannot recover 87% of it by throwing it away.
                                    -->
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        {{ formatMoney(row.binCost) }}
                                        <span class="block text-xs text-gray-500">
                                            {{ formatMoney(row.scrapIncome) }} back
                                        </span>
                                    </td>
                                    <td class="py-2 text-xs text-gray-500">{{ row.note }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
                        <strong class="font-semibold text-gray-800">Why this matters.</strong>
                        Scrap used to be penalised and banked material was not, so the threshold was a cliff: a
                        {{ formatMm(scrapThresholdMm - 1) }} drop cost its whole length and a
                        {{ formatMm(scrapThresholdMm + 1) }} drop cost nothing at all. With a thousand search
                        iterations per nest, the solver reliably found the arrangement that landed just past the
                        line &mdash; the least useful reusable length there is. The rack filled with stubs, and the
                        reported yield went up while it happened.
                    </p>
                </div>
            </section>
            <!-- Labour against material -->
            <section class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                        Labour against material, by section
                    </h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                        What the same operations cost on real sections, and the shortest remnant that is worth the
                        labour of keeping it. Times are for a {{ formatMm(referenceLengthMm) }} piece.
                    </p>
                </header>

                <div class="px-4 py-4">
                    <p class="mb-3 max-w-3xl text-sm leading-relaxed text-gray-700">
                        This is the rule stated plainly: a remnant is worth keeping only while it is worth more than
                        the labour of keeping it. On light angle that floor sits past two metres &mdash; anything
                        shorter costs more in marking, recording and shifting than the steel will ever give back. On
                        a heavy beam almost anything over the scrap threshold is worth having, because a metre of it
                        is worth far more than the quarter hour it takes to move.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[42rem] text-left text-sm">
                            <caption class="sr-only">Labour and material cost by section</caption>
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="py-2 pr-3 font-semibold">Section</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Steel</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">One cut</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Fetch an offcut</th>
                                    <th scope="col" class="py-2 pr-3 text-right font-semibold">Fetch a bar</th>
                                    <th scope="col" class="py-2 font-semibold">Worth racking from</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in labourBySection" :key="row.label">
                                    <td class="py-2 pr-3">
                                        <span class="font-medium text-gray-900">{{ row.label }}</span>
                                        <span class="ml-1 text-xs text-gray-500">{{ row.kgPerM }}kg/m</span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        {{ formatMoney(row.costPerMetre) }}<span class="text-xs text-gray-500">/m</span>
                                        <span class="block text-xs text-gray-500">
                                            {{ formatMoney(row.barCost) }} a bar
                                        </span>
                                        <span class="block text-xs text-gray-500">
                                            {{ formatMoney(row.scrapIncomePerMetre) }}/m back as scrap
                                        </span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        {{ formatMoney(row.cutCost) }}
                                        <span class="block text-xs text-gray-500">{{ formatMinutes(row.cutMinutes) }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        {{ formatMoney(row.drawCost) }}
                                        <span class="block text-xs text-gray-500">{{ formatMinutes(row.drawMinutes) }}</span>
                                    </td>
                                    <td class="py-2 pr-3 text-right tabular-nums text-gray-700">
                                        {{ formatMoney(row.barHandlingCost) }}
                                        <span class="block text-xs text-gray-500">{{ formatMinutes(row.barMinutes) }}</span>
                                    </td>
                                    <td class="py-2">
                                        <template v-if="row.worthRackingFromMm !== null">
                                            <span class="font-semibold text-gray-900">{{ formatMm(row.worthRackingFromMm) }}</span>
                                            <span class="block text-xs text-gray-500">
                                                {{ formatMoney(row.worthRackingValue) }} of steel
                                            </span>
                                        </template>
                                        <span v-else class="text-xs text-gray-500">never worth racking</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p class="mt-3 rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs leading-relaxed text-gray-600">
                        <strong class="font-semibold text-gray-800">Read the last column as a floor, not a rule.</strong>
                        It is the point at which a remnant starts paying for its own keep. Below it, the nest will
                        still rather bank a drop than bin it &mdash; binning forfeits every millimetre of the steel,
                        so you would have to be very pessimistic about ever using it to prefer the skip. What the
                        floor governs is whether the nest should <em>arrange itself</em> to produce a remnant at all,
                        which is where it bites: engineering a stub gains almost nothing and costs a rack slot for
                        years.
                    </p>
                </div>
            </section>

            <!-- Worked examples -->
            <section class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                        The same decision on light and heavy steel
                    </h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                        Costed at {{ sections.light }}kg/m (light angle) and {{ sections.heavy }}kg/m (a heavy
                        beam). The cheaper option is the one the nest takes &mdash; and it is not always the same
                        one, because what the steel is worth rises far faster with the section than the labour of
                        dealing with it does.
                    </p>
                </header>

                <div class="divide-y divide-gray-100">
                    <article v-for="example in workedExamples" :key="example.title" class="px-4 py-4">
                        <h3 class="text-sm font-semibold text-gray-900">{{ example.title }}</h3>
                        <p class="mt-0.5 text-xs text-gray-500">{{ example.question }}</p>

                        <div class="mt-3 grid gap-3 sm:grid-cols-2">
                            <div
                                v-for="option in example.options"
                                :key="option.label"
                                class="rounded-xl border p-3"
                                :class="isCheapest(example.options, option, 'light') || isCheapest(example.options, option, 'heavy')
                                    ? 'border-gray-300 bg-white'
                                    : 'border-gray-200 bg-gray-50'"
                            >
                                <p class="text-sm font-semibold text-gray-900">{{ option.label }}</p>
                                <p class="mt-1 text-xs leading-relaxed text-gray-600">{{ option.blurb }}</p>

                                <dl class="mt-3 space-y-1.5">
                                    <div
                                        v-for="weight in ['light','heavy']"
                                        :key="weight"
                                        class="flex items-center justify-between gap-2 rounded-lg px-2 py-1 text-xs"
                                        :class="isCheapest(example.options, option, weight)
                                            ? 'bg-blue-50 text-blue-900'
                                            : 'text-gray-500'"
                                    >
                                        <dt class="capitalize">
                                            {{ weight }} &middot; {{ sections[weight] }}kg/m
                                        </dt>
                                        <dd class="flex items-center gap-1.5 tabular-nums font-semibold">
                                            {{ formatMoney(option.costs[weight]) }}
                                            <i
                                                v-if="isCheapest(example.options, option, weight)"
                                                class="fa-solid fa-check"
                                                :title="'Chosen on ' + weight + ' sections'"
                                            ></i>
                                        </dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </article>
                </div>
            </section>

            <!-- Settings -->
            <section class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                        The dials
                    </h2>
                    <p class="mt-1 text-xs leading-relaxed text-gray-600">
                        Per business. Only the scrap threshold changes what physically happens in the yard &mdash;
                        the rest change which plan gets chosen.
                    </p>
                </header>

                <ul class="divide-y divide-gray-100">
                    <li v-for="setting in settings" :key="setting.key" class="px-4 py-3 sm:flex sm:gap-4">
                        <div class="sm:w-56 sm:flex-none">
                            <p class="text-sm font-semibold text-gray-900">{{ setting.label }}</p>
                            <p class="mt-0.5 flex items-baseline gap-1">
                                <span class="text-lg font-bold tabular-nums text-gray-900">{{ setting.value }}</span>
                                <span v-if="setting.unit" class="text-xs text-gray-500">{{ setting.unit }}</span>
                            </p>
                            <code class="mt-0.5 block text-[11px] text-gray-400">{{ setting.key }}</code>
                        </div>
                        <p class="mt-1 text-xs leading-relaxed text-gray-600 sm:mt-0">{{ setting.blurb }}</p>
                    </li>
                </ul>
            </section>

            <!-- Limits -->
            <section class="overflow-hidden rounded-xl border border-orange-200 bg-orange-50 shadow-sm">
                <header class="border-b border-orange-200 px-4 py-3">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-orange-900">
                        What this does not account for
                    </h2>
                </header>
                <div class="px-4 py-3 text-sm leading-relaxed text-orange-900">
                    <p>
                        A nest is built for one product specification at a time, so anything that is a property of
                        the whole order is invisible to it:
                    </p>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li><strong class="font-semibold">Delivery fees.</strong> A per-drop cost cannot be weighed inside a single product's nest.</li>
                        <li><strong class="font-semibold">Minimum order values</strong> and bundle quantities per supplier, so a plan may not be orderable as it stands.</li>
                        <li><strong class="font-semibold">Consolidating products onto a shared stock length</strong>, which is what would collapse several deliveries into one.</li>
                    </ul>
                    <p class="mt-2">
                        All three need a pass over every nest in the batch together, rather than one product at a
                        time. Yield figures elsewhere in the app also still count a banked offcut as
                        &ldquo;not waste&rdquo;, so a plan that fills the rack can still read as efficient.
                    </p>
                </div>
            </section>
        </section>
    </AuthenticatedLayout>
</template>

<style scoped>
    /*
     * Chart roles as custom properties, so the palette lives in one place rather than being spread through
     * the markup as raw hex. Light only: darkMode is configured in tailwind but nothing ever puts the class
     * on the document, so a dark variant here would be unreachable.
     *
     * Series hue validated against the light chart surface for lightness band, chroma, and 3:1 contrast.
     */
    .viz-root {
        --viz-series-1: #2a78d6;
        --viz-series-2: #eb6834;
        --viz-surface: #ffffff;
        --viz-grid: #e5e7eb;
        --viz-text: #6b7280;
        --viz-dead: #f3f4f6;
    }

    /* Hairline, solid, recessive - never dashed */
    .viz-grid {
        stroke: var(--viz-grid);
        stroke-width: 1;
    }

    .viz-axis-text {
        fill: var(--viz-text);
        font-size: 11px;
    }

    .viz-dead-zone {
        fill: var(--viz-dead);
    }

    .viz-threshold {
        stroke: #9ca3af;
        stroke-width: 1;
    }

    /* Both series: 2px, round join and cap */
    .viz-line {
        fill: none;
        stroke-width: 2;
        stroke-linejoin: round;
        stroke-linecap: round;
    }

    .viz-line-worth { stroke: var(--viz-series-1); }
    .viz-line-keep  { stroke: var(--viz-series-2); }

    /* Markers carry a 2px surface ring so they stay legible where the lines cross each other */
    .viz-dot {
        stroke: var(--viz-surface);
        stroke-width: 2;
    }

    .viz-dot-worth { fill: var(--viz-series-1); }
    .viz-dot-keep  { fill: var(--viz-series-2); }

    .viz-end-label {
        font-size: 12px;
        font-weight: 600;
    }

    .viz-end-label-worth { fill: var(--viz-series-1); }
    .viz-end-label-keep  { fill: var(--viz-series-2); }

    /* Panel titles, so each panel is named even without the legend */
    .viz-panel-title {
        font-size: 11px;
        font-weight: 600;
    }

    .viz-panel-title-worth { fill: var(--viz-series-1); }
    .viz-panel-title-keep  { fill: var(--viz-series-2); }

    /* Legend keys: a short line-key, so identity never rests on the text colour */
    .viz-key {
        display: inline-block;
        width: 14px;
        height: 3px;
        border-radius: 2px;
    }

    .viz-key-worth { background: var(--viz-series-1); }
    .viz-key-keep  { background: var(--viz-series-2); }

    .viz-crosshair {
        stroke: #9ca3af;
        stroke-width: 1;
    }

    /*
     * The crossover: an annotation rather than a gridline, so it is dashed to tell it apart from the solid
     * threshold rule beside it. Recessive, and drawn under the lines.
     */
    .viz-crossover {
        stroke: #6b7280;
        stroke-width: 1;
        stroke-dasharray: 4 3;
    }

    .viz-key-crossover {
        background: repeating-linear-gradient(to right, #6b7280 0 4px, transparent 4px 7px);
    }
</style>
