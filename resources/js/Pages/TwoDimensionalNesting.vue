<script setup>
    //General Imports
    import {Head} from '@inertiajs/vue3';
    import {computed, ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import SheetPlan from '@/Components/Nesting2D/SheetPlan.vue';

    //Props
    const props = defineProps({
        /** Three plate lifecycles, already run. See Services\TwoDimensionalNestingProof. */
        scenarios: Array,
        /** The settings every scenario was nested under, pinned so the page is the same everywhere. */
        settings: Object,
    });

    //Variables
    const activeIndex = ref(0);

    //Computed
    const scenario = computed(() => props.scenarios[activeIndex.value]);

    /*
     * One scale for the whole tab, so the chain visibly shrinks from one step to the next. See SheetPlan.
     */
    const scaleMm = computed(() => {
        let widest = 0;

        scenario.value.steps.forEach(step => {
            step.pieces.forEach(piece => {
                widest = Math.max(widest, piece.width);
            });
        });

        return widest || 1;
    });

    const allChecksPass = computed(() =>
        props.scenarios.every(one => one.checks.every(check => check.passed))
    );

    const checkCount = computed(() =>
        props.scenarios.reduce((total, one) => total + one.checks.length, 0)
    );

    const failedCount = computed(() =>
        props.scenarios.reduce(
            (total, one) => total + one.checks.filter(check => !check.passed).length,
            0,
        )
    );

    //Methods
    function m2(mm2) {
        return `${(Number(mm2) / 1000000).toFixed(3)}m²`;
    }

    function mm(value) {
        return `${Number(value).toLocaleString()}mm`;
    }

    function money(value) {
        return `$${Number(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    function size(width, height) {
        return `${Number(width).toLocaleString()}×${Number(height).toLocaleString()}`;
    }

    /*
     * What a generation means in words. "Generation 1" is a remnant off a sheet that was bought;
     * everything above it is the thing this page exists to show.
     */
    function generationLabel(generation) {
        if(generation <= 1){
            return 'Remnant';
        }

        if(generation === 2){
            return 'Remnant of a remnant';
        }

        return `Remnant of a remnant, ${generation - 1} deep`;
    }

    function generationClass(generation) {
        if(generation <= 1){
            return 'bg-emerald-50 text-emerald-800 ring-emerald-200';
        }

        if(generation === 2){
            return 'bg-violet-50 text-violet-800 ring-violet-200';
        }

        return 'bg-amber-50 text-amber-900 ring-amber-200';
    }

    /*
     * Why this rectangle went in the bin, in the words of the test that binned it.
     *
     * The short side is named first because it is nearly always the reason, and it is the reason worth
     * saying out loud: the piece is wide enough to look like steel and too narrow to be steel. Only when
     * the shape passes does the area get the blame.
     */
    function binnedReason(rect) {
        const shortSide = Math.min(rect.width, rect.height);

        if(shortSide < props.settings.minOffcutSideMm){
            return `only ${shortSide}mm on its short side`;
        }

        return `only ${m2(rect.width * rect.height)}`;
    }

    function tabClass(index) {
        return index === activeIndex.value
            ? 'border-gray-900 bg-white text-gray-900'
            : 'border-transparent text-gray-500 hover:text-gray-800';
    }
</script>

<template>
    <Head title="2D Nesting" />

    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8 sm:px-6 lg:px-8">

            <!-- Heading -->
            <header class="mb-6">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                        2D Nesting
                    </h1>
                    <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-semibold uppercase tracking-wide text-amber-900 ring-1 ring-amber-200">
                        Proof of concept
                    </span>
                </div>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                    The bar nesting is built on one idea: what is left over is marked and racked if it is a
                    worthwhile piece, and binned if it is not. This page asks whether that idea survives the
                    second dimension. Three plate shops, three runs of jobs arriving one after another, and every
                    plan below produced by the 2D engine when the page loaded.
                </p>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                    The short answer is that it mostly does &mdash; and the second tab is where it does not.
                    <strong class="font-semibold text-gray-800">A remnant needs two numbers to describe it,</strong>
                    so &ldquo;worthwhile&rdquo; stops being one threshold. A 3,000&times;192 band has more steel in it
                    than most of the pieces on these racks and is worth nothing at all, because nothing anybody
                    orders is under 192mm wide.
                </p>
            </header>

            <!-- Verdict: the one line somebody reading over your shoulder needs -->
            <div
                class="mb-6 flex gap-3 rounded-xl border p-4 text-sm leading-relaxed"
                :class="allChecksPass
                    ? 'border-emerald-300 bg-emerald-50 text-emerald-900'
                    : 'border-red-300 bg-red-50 text-red-900'"
                :role="allChecksPass ? null : 'alert'"
            >
                <i
                    class="mt-0.5 flex-none fa-solid"
                    :class="allChecksPass ? 'fa-circle-check' : 'fa-triangle-exclamation'"
                ></i>
                <div>
                    <p class="font-semibold">
                        <template v-if="allChecksPass">
                            All {{ checkCount }} checks passed on this run.
                        </template>
                        <template v-else>
                            {{ failedCount }} {{ failedCount === 1 ? 'check' : 'checks' }} failed on this run.
                        </template>
                    </p>
                    <p class="mt-1">
                        <template v-if="allChecksPass">
                            Every part asked for was placed, every sheet and remnant balances to the square
                            millimetre, no two parts claim the same steel, nothing was cut off an edge, no steel
                            appeared or vanished across any run, the three-part test was applied to every remnant,
                            and in all three shops a remnant cut from a remnant came back and was cut again.
                        </template>
                        <template v-else>
                            The scenarios are fixed, so a failure here is a change in the engine rather than a
                            change in the inputs. The failing checks are marked on the tabs below.
                        </template>
                    </p>
                </div>
            </div>

            <!-- What this is and is not. Said plainly, because a proof of concept that oversells itself is worse than none -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-gray-600">
                <p>
                    <strong class="font-semibold text-gray-800">What is real here.</strong>
                    Every plan comes out of the 2D engine itself, scored in dollars and chosen as the cheapest of
                    {{ settings.iterations.toLocaleString() }} candidates, and the rule deciding which remnants get
                    a mark is the engine's own. The cuts are <em>guillotine</em> cuts &mdash; edge to edge, every
                    time &mdash; which is a deliberate restriction and the reason the whole thing hangs together:
                    only a rectangle can be marked, measured, racked and found again. Parts are turned 90&deg;
                    where that nests better, marked <span class="font-semibold">&#8635;</span> on the drawings;
                    the engine takes a per-part flag for plate that cannot be turned, which these scenarios
                    do not exercise.
                </p>
                <p class="mt-2">
                    <strong class="font-semibold text-gray-800">What is not.</strong>
                    Everything else. There is no plate product, no table of sheet remnants, no migration, no
                    settings column behind any figure below, and no screen a customer can reach. The rack is
                    carried from job to job in memory and the settings are pinned rather than read off your
                    business, so this page answers the same way on every installation &mdash; which means the only
                    thing that can change an answer is a change to the engine.
                </p>
                <p class="mt-2">
                    <strong class="font-semibold text-gray-800">What still needs deciding.</strong>
                    The two thresholds and the shape penalty are guesses by somebody who has never stood in front
                    of a plate rack. They are the difference between a rack of useful steel and a rack of strips,
                    and nothing here measured them.
                </p>

                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                    <div>
                        <dt class="inline font-semibold text-gray-700">Minimum remnant side</dt>
                        <dd class="inline tabular-nums"> {{ mm(settings.minOffcutSideMm) }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Minimum remnant area</dt>
                        <dd class="inline tabular-nums"> {{ m2(settings.minOffcutAreaMm2) }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Kerf</dt>
                        <dd class="inline tabular-nums"> {{ mm(settings.kerfMm) }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Sheet sizes</dt>
                        <dd class="inline tabular-nums">
                            {{ settings.stockSheets.map(s => size(s.width, s.height)).join(' / ') }}
                        </dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Plate</dt>
                        <dd class="inline tabular-nums"> {{ settings.kgPerM2 }}kg/m² at ${{ settings.materialCostPerTonne.toLocaleString() }}/t</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Labour</dt>
                        <dd class="inline tabular-nums"> ${{ settings.labourRatePerHour.toLocaleString() }}/hr</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Candidate plans per job</dt>
                        <dd class="inline tabular-nums"> {{ settings.iterations.toLocaleString() }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Tabs -->
            <div class="mb-6 border-b border-gray-200">
                <div class="-mb-px flex flex-wrap gap-1" role="tablist" aria-label="2D nesting scenarios">
                    <button
                        v-for="(one, index) in scenarios"
                        :key="one.key"
                        type="button"
                        role="tab"
                        :aria-selected="index === activeIndex"
                        class="flex items-center gap-2 rounded-t-lg border-b-2 px-4 py-2.5 text-sm font-medium transition"
                        :class="tabClass(index)"
                        @click="activeIndex = index"
                    >
                        {{ one.title }}
                        <i
                            v-if="one.checks.some(check => !check.passed)"
                            class="fa-solid fa-triangle-exclamation text-red-600"
                            aria-label="This scenario has a failing check"
                        ></i>
                    </button>
                </div>
            </div>

            <!-- The active scenario -->
            <div :key="scenario.key">

                <!-- What it claims, and what to look at -->
                <section class="mb-6 rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                    <p class="text-sm leading-relaxed text-gray-800">{{ scenario.claim }}</p>
                    <p class="mt-2 text-xs leading-relaxed text-gray-600">
                        <strong class="font-semibold text-gray-700">What to watch.</strong>
                        {{ scenario.watch }}
                    </p>

                    <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-xs text-gray-600">
                        <div>
                            <dt class="inline font-semibold text-gray-700">Material</dt>
                            <dd class="inline"> {{ scenario.plate }}</dd>
                        </div>
                        <div>
                            <dt class="inline font-semibold text-gray-700">Projects</dt>
                            <dd class="inline">
                                <template v-for="(project, index) in scenario.projects" :key="project.letter">
                                    <template v-if="index > 0">, </template>
                                    <strong class="font-semibold text-gray-800">{{ project.letter }}</strong>
                                    {{ project.name }}
                                </template>
                            </dd>
                        </div>
                    </dl>
                </section>

                <!-- Every job, in the order it arrived -->
                <section
                    v-for="step in scenario.steps"
                    :key="step.number"
                    class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                >
                    <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                            <h2 class="text-sm font-semibold text-gray-900">
                                Step {{ step.number }} &mdash; {{ step.name }}
                            </h2>
                            <span class="text-xs tabular-nums text-gray-600">
                                {{ money(step.cost) }} &middot;
                                {{ step.utilisation }}% into parts &middot;
                                {{ step.yield }}% not destroyed
                            </span>
                            <span
                                v-if="step.unmadeParts > 0"
                                class="rounded-full bg-red-100 px-2 py-0.5 text-xs font-semibold text-red-800"
                            >
                                {{ step.unmadeParts }} part(s) nothing could hold
                            </span>
                        </div>
                        <p class="mt-1.5 text-xs leading-relaxed text-gray-600">{{ step.note }}</p>
                    </header>

                    <div class="px-4 py-4">

                        <!-- What was asked for, and what was on the rack when it arrived -->
                        <div class="mb-4 grid gap-4 sm:grid-cols-2">
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">Asked for</h3>
                                <ul class="mt-1.5 space-y-1 text-xs text-gray-700">
                                    <li v-for="(line, index) in step.required" :key="index" class="tabular-nums">
                                        <strong class="font-semibold">{{ line.qty }}&times;</strong>
                                        {{ size(line.widthMm, line.heightMm) }}
                                        <span class="font-bold">{{ line.letter }}</span>
                                        <span class="text-gray-500">&mdash; {{ line.project }}</span>
                                    </li>
                                </ul>
                            </div>
                            <div>
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">On the rack</h3>
                                <ul v-if="step.rackBefore.length > 0" class="mt-1.5 space-y-1 text-xs text-gray-700">
                                    <li v-for="piece in step.rackBefore" :key="piece.mark" class="tabular-nums">
                                        <span class="font-bold italic">{{ piece.mark }}</span>
                                        {{ size(piece.widthMm, piece.heightMm) }}
                                        <span class="text-gray-500">({{ m2(piece.areaMm2) }})</span>
                                        <span
                                            class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                                            :class="generationClass(piece.generation)"
                                        >{{ generationLabel(piece.generation) }}</span>
                                    </li>
                                </ul>
                                <p v-else class="mt-1.5 text-xs italic text-gray-500">Nothing.</p>
                            </div>
                        </div>

                        <!-- The plan -->
                        <div v-for="(piece, index) in step.pieces" :key="index" class="mt-4 border-t border-gray-100 pt-3 first:border-t-0 first:pt-0">
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span
                                    class="rounded px-2 py-0.5 font-semibold ring-1"
                                    :class="piece.fromRack
                                        ? 'bg-indigo-50 text-indigo-800 ring-indigo-200'
                                        : 'bg-gray-100 text-gray-700 ring-gray-300'"
                                >
                                    {{ piece.fromRack ? 'Drawn off the rack' : 'Sheet bought' }}
                                </span>
                                <span class="font-semibold tabular-nums text-gray-800">
                                    {{ size(piece.width, piece.height) }}
                                </span>
                                <template v-if="piece.fromRack">
                                    <span class="font-bold italic text-gray-700">{{ piece.mark }}</span>
                                    <span
                                        class="rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                                        :class="generationClass(piece.generation)"
                                    >{{ generationLabel(piece.generation) }}</span>
                                </template>
                                <span class="tabular-nums text-gray-500">
                                    {{ piece.placements.length }} part(s)
                                </span>
                            </div>

                            <SheetPlan :piece="piece" :scale-mm="scaleMm" />

                            <!-- What it put back, and what it destroyed -->
                            <p v-if="piece.banked.length > 0" class="text-xs leading-relaxed text-emerald-800">
                                <i class="fa-solid fa-tag mr-1"></i>
                                Marked and racked:
                                <template v-for="(rect, i) in piece.banked" :key="i">
                                    <template v-if="i > 0">, </template>
                                    <strong class="font-bold italic">{{ rect.mark }}</strong>
                                    <span class="tabular-nums"> {{ size(rect.width, rect.height) }} ({{ m2(rect.width * rect.height) }})</span>
                                </template>
                            </p>
                            <p v-if="piece.scrap.length > 0" class="mt-0.5 text-xs leading-relaxed text-rose-800">
                                <i class="fa-solid fa-trash mr-1"></i>
                                Binned:
                                <template v-for="(rect, i) in piece.scrap" :key="i">
                                    <template v-if="i > 0">, </template>
                                    <span class="tabular-nums">{{ size(rect.width, rect.height) }}</span>
                                    <span class="text-rose-600">&mdash; {{ binnedReason(rect) }}</span>
                                </template>
                            </p>
                        </div>

                        <!-- And the rack it leaves behind -->
                        <div class="mt-4 border-t border-gray-100 pt-3">
                            <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">On the rack afterwards</h3>
                            <ul v-if="step.rackAfter.length > 0" class="mt-1.5 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-700">
                                <li v-for="piece in step.rackAfter" :key="piece.mark" class="tabular-nums">
                                    <span class="font-bold italic">{{ piece.mark }}</span>
                                    {{ size(piece.widthMm, piece.heightMm) }}
                                    <span
                                        class="ml-1 rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                                        :class="generationClass(piece.generation)"
                                    >{{ generationLabel(piece.generation) }}</span>
                                    <span v-if="piece.parentMark" class="text-gray-500">
                                        off {{ piece.parentMark }}
                                    </span>
                                </li>
                            </ul>
                            <p v-else class="mt-1.5 text-xs italic text-gray-500">Nothing.</p>
                        </div>
                    </div>
                </section>

                <!-- Every mark the scenario issued, and what became of it -->
                <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                            Every piece this shop racked
                        </h2>
                        <p class="mt-1 text-xs leading-relaxed text-gray-600">
                            The whole lifecycle in one table: where each piece came from, what it was cut out of,
                            and whether it is still there at the end of the run.
                        </p>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead class="bg-gray-50 text-left text-gray-600">
                                <tr>
                                    <th class="px-4 py-2 font-semibold">Mark</th>
                                    <th class="px-4 py-2 font-semibold">Size</th>
                                    <th class="px-4 py-2 font-semibold">Area</th>
                                    <th class="px-4 py-2 font-semibold">What it is</th>
                                    <th class="px-4 py-2 font-semibold">Cut from</th>
                                    <th class="px-4 py-2 font-semibold">Racked</th>
                                    <th class="px-4 py-2 font-semibold">Drawn</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="entry in scenario.lineage" :key="entry.mark">
                                    <td class="px-4 py-2 font-bold italic text-gray-800">{{ entry.mark }}</td>
                                    <td class="px-4 py-2 tabular-nums">{{ size(entry.widthMm, entry.heightMm) }}</td>
                                    <td class="px-4 py-2 tabular-nums">{{ m2(entry.areaMm2) }}</td>
                                    <td class="px-4 py-2">
                                        <span
                                            class="rounded px-1.5 py-0.5 text-[10px] font-semibold ring-1"
                                            :class="generationClass(entry.generation)"
                                        >{{ generationLabel(entry.generation) }}</span>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span v-if="entry.parentMark" class="font-bold italic text-gray-700">{{ entry.parentMark }}</span>
                                        <span v-else class="text-gray-500">a bought sheet</span>
                                    </td>
                                    <td class="px-4 py-2 tabular-nums text-gray-600">
                                        <template v-if="entry.bornInStep === 0">inherited</template>
                                        <template v-else>step {{ entry.bornInStep }}</template>
                                    </td>
                                    <td class="px-4 py-2 tabular-nums">
                                        <span v-if="entry.onRack" class="font-semibold text-emerald-700">still on the rack</span>
                                        <span v-else class="text-gray-600">step {{ entry.consumedInStep }}</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

                <!-- The checks. What makes this a proof rather than a picture -->
                <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                            What was checked
                        </h2>
                        <p class="mt-1 text-xs leading-relaxed text-gray-600">
                            Run against the plans above, not written down beside them. A change to the engine that
                            breaks one of these turns this panel red rather than quietly redrawing the page.
                        </p>
                    </header>

                    <ul class="divide-y divide-gray-100">
                        <li v-for="check in scenario.checks" :key="check.label" class="flex gap-3 px-4 py-3">
                            <i
                                class="mt-0.5 flex-none fa-solid"
                                :class="check.passed ? 'fa-circle-check text-emerald-600' : 'fa-circle-xmark text-red-600'"
                            ></i>
                            <div class="min-w-0">
                                <p class="text-sm font-medium" :class="check.passed ? 'text-gray-900' : 'text-red-800'">
                                    {{ check.label }}
                                </p>
                                <p class="mt-0.5 text-xs leading-relaxed text-gray-600 tabular-nums">{{ check.detail }}</p>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </section>
    </AuthenticatedLayout>
</template>
