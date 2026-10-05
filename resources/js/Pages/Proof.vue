<script setup>
    //General Imports
    import {Head} from '@inertiajs/vue3';
    import {computed, ref} from "vue";

    //Component Imports
    import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
    import ProofPiece from '@/Components/Proof/ProofPiece.vue';

    //Props
    const props = defineProps({
        /** Three lifecycles, already run. See Services\NestingProof. */
        scenarios: Array,
        /** The settings every scenario was nested under, pinned so the page is the same everywhere. */
        settings: Object,
    });

    //Variables
    const activeIndex = ref(0);

    //Computed
    const scenario = computed(() => props.scenarios[activeIndex.value]);

    /*
     * One scale for the whole tab.
     *
     * Every drawing is measured against the longest piece the scenario touches, so the chain visibly
     * shortens from one step to the next. See ProofPiece.
     */
    const scaleMm = computed(() => {
        let longest = 0;

        scenario.value.steps.forEach(step => {
            [...step.bars, ...step.draws].forEach(piece => {
                longest = Math.max(longest, piece.lengthMm);
            });
        });

        return longest || 1;
    });

    const allChecksPass = computed(() =>
        props.scenarios.every(one => one.checks.every(check => check.passed))
    );

    const failedCount = computed(() =>
        props.scenarios.reduce(
            (total, one) => total + one.checks.filter(check => !check.passed).length,
            0,
        )
    );

    //Methods
    function formatMm(value) {
        return `${Number(value).toLocaleString()}mm`;
    }

    function formatMoney(value) {
        return `$${Number(value).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2})}`;
    }

    /*
     * What a generation means in words. "Generation 1" is a drop off a bar that was bought; everything
     * above it is the thing this page exists to show.
     */
    function generationLabel(generation) {
        if(generation <= 1){
            return 'Offcut';
        }

        if(generation === 2){
            return 'Offcut of an offcut';
        }

        return `Offcut of an offcut, ${generation - 1} deep`;
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
</script>

<template>
    <Head title="Proof" />

    <AuthenticatedLayout>
        <section class="mx-auto w-full max-w-6xl px-4 pb-16 pt-8 sm:px-6 lg:px-8">

            <!-- Heading -->
            <header class="mb-6">
                <h1 class="text-2xl font-bold tracking-tight text-gray-900">
                    Proof the nesting works
                </h1>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                    Three fabrication yards, three sections, and a run of jobs arriving one after another in each.
                    Every plan below was produced by the live nesting algorithm when this page loaded &mdash; the
                    same call the quoting screens make &mdash; and every step is drawn to scale so the arithmetic
                    can be checked rather than taken on trust.
                </p>
                <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                    The part worth watching is what happens to the steel that is <em>left over</em>. A bar is bought
                    and cut, and its drop goes on the rack with a mark. A later job draws that remnant and cuts into
                    it, leaving a remnant of a remnant. A later job still draws <em>that</em>. Nothing in the
                    application shows this, because in the application it only ever happens one batch at a time.
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
                            All {{ scenarios.length * scenarios[0].checks.length }} checks passed on this run.
                        </template>
                        <template v-else>
                            {{ failedCount }} {{ failedCount === 1 ? 'check' : 'checks' }} failed on this run.
                        </template>
                    </p>
                    <p class="mt-1">
                        <template v-if="allChecksPass">
                            Every cut asked for was made, every bar and offcut balances to the millimetre, no steel
                            appeared or vanished across any run, the scrap threshold was applied to every remnant,
                            and in all three yards an offcut cut from an offcut came back and was cut again.
                        </template>
                        <template v-else>
                            The scenarios are fixed, so a failure here is a change in the algorithm rather than a
                            change in the inputs. The failing checks are marked on the tabs below.
                        </template>
                    </p>
                </div>
            </div>

            <!-- How the scenarios are run. Said plainly, because a proof that overstates itself is not one -->
            <div class="mb-6 rounded-xl border border-gray-200 bg-gray-50 p-4 text-xs leading-relaxed text-gray-600">
                <p>
                    <strong class="font-semibold text-gray-800">What is real here.</strong>
                    Each plan comes out of the nesting algorithm itself, and the rule deciding which remnants get a
                    mark is the one the application applies when it writes them: at or over the scrap threshold it is
                    banked, under it goes in the bin.
                </p>
                <p class="mt-2">
                    <strong class="font-semibold text-gray-800">What is not.</strong>
                    The rack is carried from job to job in memory rather than written to the database, and the
                    settings below are pinned instead of read off your business. That is deliberate: it means these
                    pages answer the same way on every installation, so the only thing that can change an answer is a
                    change to the algorithm.
                </p>

                <dl class="mt-3 flex flex-wrap gap-x-6 gap-y-2">
                    <div>
                        <dt class="inline font-semibold text-gray-700">Scrap threshold</dt>
                        <dd class="inline tabular-nums"> {{ formatMm(settings.scrapThresholdMm) }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Saw kerf</dt>
                        <dd class="inline tabular-nums"> {{ formatMm(settings.kerfMm) }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Stock lengths</dt>
                        <dd class="inline tabular-nums"> {{ settings.stockLengths.map(l => l.toLocaleString()).join(' / ') }}mm</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Steel</dt>
                        <dd class="inline tabular-nums"> ${{ settings.materialCostPerTonne.toLocaleString() }}/t + ${{ settings.deliveryCostPerTonne.toLocaleString() }}/t freight</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Labour</dt>
                        <dd class="inline tabular-nums"> ${{ settings.labourRatePerHour.toLocaleString() }}/hr</dd>
                    </div>
                    <div>
                        <dt class="inline font-semibold text-gray-700">Candidate plans per nest</dt>
                        <dd class="inline tabular-nums"> {{ settings.nestingIterations.toLocaleString() }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Tabs -->
            <div class="mb-6 border-b border-gray-200">
                <div class="-mb-px flex flex-wrap gap-1" role="tablist" aria-label="Nesting proofs">
                    <button
                        v-for="(one, index) in scenarios"
                        :key="one.key"
                        type="button"
                        role="tab"
                        :aria-selected="activeIndex === index"
                        :class="[
                            'flex items-center gap-2 border-b-2 px-4 py-2.5 text-sm font-semibold transition',
                            activeIndex === index
                                ? 'border-gray-900 text-gray-900'
                                : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700',
                        ]"
                        @click="activeIndex = index"
                    >
                        <i
                            class="fa-solid text-xs"
                            :class="one.checks.every(check => check.passed)
                                ? 'fa-circle-check text-emerald-600'
                                : 'fa-circle-exclamation text-red-600'"
                        ></i>
                        <span>{{ one.title }}</span>
                    </button>
                </div>
            </div>

            <!-- The active scenario -->
            <div :key="scenario.key">
                <header class="mb-5">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center rounded-full bg-gray-900 px-2.5 py-0.5 text-xs font-semibold text-white">
                            {{ scenario.section.label }}
                        </span>
                        <span class="text-xs text-gray-500">{{ scenario.section.kgPerM }}kg/m</span>
                    </div>
                    <p class="mt-3 max-w-3xl text-sm leading-relaxed text-gray-700">
                        {{ scenario.claim }}
                    </p>
                    <p class="mt-2 max-w-3xl text-sm leading-relaxed text-gray-600">
                        <strong class="font-semibold text-gray-800">What to watch.</strong>
                        {{ scenario.watch }}
                    </p>
                </header>

                <!-- Projects legend: the letters that appear on every drawing below -->
                <div class="mb-5 flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border border-gray-200 bg-white px-4 py-3 text-xs">
                    <span class="font-semibold uppercase tracking-wide text-gray-500">Projects</span>
                    <span v-for="project in scenario.projects" :key="project.letter" class="inline-flex items-center gap-1.5">
                        <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-sky-100 font-bold text-sky-900">
                            {{ project.letter }}
                        </span>
                        <span class="text-gray-700">{{ project.name }}</span>
                    </span>
                </div>

                <!-- Legend for the drawings -->
                <div class="mb-6 flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-gray-600">
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-3 w-6 rounded-sm border border-gray-900 bg-sky-100"></span>
                        A part, with its project letter
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-3 w-6 rounded-sm border border-gray-900 bg-emerald-100"></span>
                        Banked: marked and racked for a later job
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-3 w-6 rounded-sm border border-gray-900 bg-rose-100"></span>
                        Binned: under the scrap threshold
                    </span>
                    <span class="inline-flex items-center gap-1.5">
                        <span class="inline-block h-4 w-0.5 bg-gray-900"></span>
                        One saw cut ({{ formatMm(settings.kerfMm) }})
                    </span>
                </div>

                <!-- Steps -->
                <ol class="space-y-4">
                    <li
                        v-for="step in scenario.steps"
                        :key="step.number"
                        class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm"
                    >
                        <header class="flex items-start gap-3 border-b border-gray-200 bg-gray-50 px-4 py-3">
                            <span class="mt-0.5 inline-flex h-6 w-6 flex-none items-center justify-center rounded-full bg-gray-900 text-xs font-bold text-white">
                                {{ step.number }}
                            </span>
                            <div class="min-w-0">
                                <h2 class="text-sm font-semibold text-gray-900">{{ step.name }}</h2>
                                <p class="mt-1 text-xs leading-relaxed text-gray-600">{{ step.note }}</p>
                            </div>
                        </header>

                        <div class="px-4 py-4">
                            <!-- Inputs: what was asked for, and what was on the rack when it was asked -->
                            <div class="mb-4 grid gap-4 sm:grid-cols-2">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">The job</h3>
                                    <ul class="mt-2 space-y-1 text-sm text-gray-700">
                                        <li v-for="(line, index) in step.required" :key="index" class="tabular-nums">
                                            <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-sky-100 text-xs font-bold text-sky-900">
                                                {{ line.letter }}
                                            </span>
                                            <span class="ml-2 font-medium">{{ line.qty }} &times; {{ formatMm(line.lengthMm) }}</span>
                                            <span class="ml-1 text-xs text-gray-500">{{ line.project }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">On the rack beforehand</h3>
                                    <p v-if="step.rackBefore.length === 0" class="mt-2 text-sm italic text-gray-500">
                                        Nothing &mdash; the whole job has to be bought.
                                    </p>
                                    <ul v-else class="mt-2 flex flex-wrap gap-2">
                                        <li
                                            v-for="piece in step.rackBefore"
                                            :key="piece.mark"
                                            class="inline-flex items-center gap-2 rounded-lg px-2 py-1 text-xs ring-1"
                                            :class="generationClass(piece.generation)"
                                        >
                                            <span class="font-bold">{{ piece.mark }}</span>
                                            <span class="tabular-nums">{{ formatMm(piece.lengthMm) }}</span>
                                            <span v-if="piece.parentMark" class="opacity-70">off {{ piece.parentMark }}</span>
                                        </li>
                                    </ul>
                                </div>
                            </div>

                            <!-- Bought -->
                            <div v-if="step.bars.length > 0" class="mb-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Bought and cut
                                </h3>
                                <div v-for="(bar, index) in step.bars" :key="`bar-${index}`" class="mt-2">
                                    <p class="text-sm font-medium text-gray-900 tabular-nums">
                                        One {{ formatMm(bar.lengthMm) }} bar
                                        <span v-if="bar.banked" class="ml-1 text-xs font-normal text-emerald-700">
                                            &rarr; banked as <strong class="font-bold">{{ bar.bankedMark }}</strong>
                                        </span>
                                        <span v-else-if="bar.dropMm > 0" class="ml-1 text-xs font-normal text-gray-500">
                                            &rarr; {{ formatMm(bar.dropMm) }} binned, under the threshold
                                        </span>
                                    </p>
                                    <ProofPiece :piece="bar" :scale-mm="scaleMm" />
                                </div>
                            </div>

                            <!-- Drawn off the rack. The reason this page exists -->
                            <div v-if="step.draws.length > 0" class="mb-4">
                                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                                    Drawn off the rack
                                </h3>
                                <div v-for="(draw, index) in step.draws" :key="`draw-${index}`" class="mt-2">
                                    <p class="flex flex-wrap items-center gap-2 text-sm font-medium text-gray-900">
                                        <span class="tabular-nums">
                                            <strong class="font-bold">{{ draw.mark }}</strong>, {{ formatMm(draw.lengthMm) }}
                                        </span>
                                        <span
                                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1"
                                            :class="generationClass(draw.generation)"
                                        >
                                            {{ generationLabel(draw.generation) }}
                                        </span>
                                        <span v-if="draw.banked" class="text-xs font-normal text-emerald-700">
                                            &rarr; what is left becomes <strong class="font-bold">{{ draw.bankedMark }}</strong>,
                                            {{ generationLabel(draw.childGeneration).toLowerCase() }}
                                        </span>
                                        <span v-else class="text-xs font-normal text-gray-500">
                                            &rarr; {{ formatMm(draw.dropMm) }} binned, so this mark is retired
                                        </span>
                                    </p>
                                    <ProofPiece :piece="draw" :scale-mm="scaleMm" />
                                </div>
                            </div>

                            <!-- Outputs -->
                            <div class="flex flex-wrap items-start justify-between gap-4 border-t border-gray-100 pt-3">
                                <div>
                                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-500">On the rack afterwards</h3>
                                    <p v-if="step.rackAfter.length === 0" class="mt-2 text-sm italic text-gray-500">
                                        Empty &mdash; nothing left was worth keeping.
                                    </p>
                                    <ul v-else class="mt-2 flex flex-wrap gap-2">
                                        <li
                                            v-for="piece in step.rackAfter"
                                            :key="piece.mark"
                                            class="inline-flex items-center gap-2 rounded-lg px-2 py-1 text-xs ring-1"
                                            :class="generationClass(piece.generation)"
                                        >
                                            <span class="font-bold">{{ piece.mark }}</span>
                                            <span class="tabular-nums">{{ formatMm(piece.lengthMm) }}</span>
                                            <span v-if="piece.parentMark" class="opacity-70">off {{ piece.parentMark }}</span>
                                        </li>
                                    </ul>
                                </div>

                                <dl class="flex gap-6 text-right text-xs">
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-gray-500">Bought</dt>
                                        <dd class="mt-1 text-sm tabular-nums text-gray-900">{{ formatMm(step.purchasedMm) }}</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-gray-500">Off the rack</dt>
                                        <dd class="mt-1 text-sm tabular-nums text-gray-900">{{ formatMm(step.drawnMm) }}</dd>
                                    </div>
                                    <!--
                                        Effective efficiency, not used/total: an offcut that goes back on the rack is
                                        deferred rather than wasted, so only kerf and scrap count against a nest.
                                    -->
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-gray-500">Not destroyed</dt>
                                        <dd class="mt-1 text-sm tabular-nums text-gray-900">{{ step.effectiveEfficiency }}%</dd>
                                    </div>
                                    <div>
                                        <dt class="font-semibold uppercase tracking-wide text-gray-500">Cost</dt>
                                        <dd class="mt-1 text-sm tabular-nums font-semibold text-gray-900">{{ formatMoney(step.cost) }}</dd>
                                    </div>
                                </dl>
                            </div>
                        </div>
                    </li>
                </ol>

                <!-- Lineage: every piece of steel the scenario racked, and what became of it -->
                <section class="mt-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
                    <header class="border-b border-gray-200 bg-gray-50 px-4 py-3">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-700">
                            Where every remnant came from, and where it went
                        </h2>
                        <p class="mt-1 text-xs leading-relaxed text-gray-600">
                            Each row is one piece of steel that carried a mark. Indentation is its generation: a row
                            indented under another was cut out of it.
                        </p>
                    </header>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[36rem] text-left text-sm">
                            <caption class="sr-only">Offcut lineage for {{ scenario.title }}</caption>
                            <thead>
                                <tr class="border-b border-gray-200 text-xs uppercase tracking-wide text-gray-500">
                                    <th scope="col" class="px-4 py-2 font-semibold">Mark</th>
                                    <th scope="col" class="px-4 py-2 text-right font-semibold">Length</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">Cut from</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">Racked at</th>
                                    <th scope="col" class="px-4 py-2 font-semibold">Fate</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <tr v-for="row in scenario.lineage" :key="row.mark">
                                    <td class="px-4 py-2">
                                        <span :style="{paddingLeft: ((row.generation - 1) * 16) + 'px'}" class="inline-block">
                                            <span
                                                class="inline-flex items-center rounded px-1.5 py-0.5 font-bold ring-1"
                                                :class="generationClass(row.generation)"
                                            >{{ row.mark }}</span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-2 text-right tabular-nums text-gray-700">{{ formatMm(row.lengthMm) }}</td>
                                    <td class="px-4 py-2 text-gray-700">
                                        <template v-if="row.parentMark">
                                            offcut <strong class="font-semibold">{{ row.parentMark }}</strong>
                                        </template>
                                        <template v-else-if="row.bornInStep === 0">
                                            a job before this scenario
                                        </template>
                                        <template v-else>a bar bought new</template>
                                    </td>
                                    <td class="px-4 py-2 text-gray-700">
                                        <template v-if="row.bornInStep === 0">already there</template>
                                        <template v-else>step {{ row.bornInStep }}</template>
                                    </td>
                                    <td class="px-4 py-2">
                                        <span v-if="row.onRack" class="text-gray-700">
                                            still on the rack
                                        </span>
                                        <span v-else class="text-gray-700">
                                            cut in step {{ row.consumedInStep }}
                                        </span>
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
                            Run against the plans above, not written down beside them. A change to the algorithm that
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
