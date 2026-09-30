<script setup>
    //General Imports
    import {useForm} from '@inertiajs/vue3';
    import {computed, ref, watch} from 'vue';

    //Component Imports
    //...

    //Props
    const props = defineProps({
        /**
         * The dead stock, worst drain first. Each row is {offcut, age_days, length_mm, floor_mm,
         * kg_per_m, kg_per_m_resolved, worth, keep_cost, bin_recovers, net_drain}.
         */
        data: {
            type: Array,
            default: () => [],
        },
        /** How long a piece may sit before age counts against it, so the page can say the rule. */
        shelfLifeDays: {
            type: Number,
            default: 365,
        },
    });

    //Form
    const form = useForm({
        offcut_ids: [],
        note: '',
    });

    //Variables
    /*
     * Nothing is ticked to begin with.
     *
     * A list that arrives pre-selected turns "review the rack" into "press the button", which is the
     * opposite of what this page is for - it exists because no scheduled job is in a position to
     * decide that a piece of steel will never be used.
     */
    const selected = ref(new Set());

    //Whether the confirm step is showing, so scrapping always takes two presses
    const confirming = ref(false);

    //Computed
    const rows = computed(() => props.data ?? []);

    const selectedRows = computed(() => rows.value.filter(row => selected.value.has(row.offcut.id)));

    const allSelected = computed(() => rows.value.length > 0 && selected.value.size === rows.value.length);

    //What the yard gets back for the ticked rows, and what it gives up to get it
    const totals = computed(() => selectedRows.value.reduce((sum, row) => ({
        recovers: sum.recovers + Number(row.bin_recovers || 0),
        drain: sum.drain + Number(row.net_drain || 0),
    }), {recovers: 0, drain: 0}));

    //Methods
    function millimetres(length){
        return Math.round(Number(length) || 0).toLocaleString();
    }

    function money(amount){
        return Number(amount || 0).toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }

    /*
     * Months rather than days once it is past a year. "517 days" is a number to work out; "1 year
     * 5 months" is how long somebody has been walking past it.
     */
    function sittingFor(days){
        const count = Number(days) || 0;

        if(count < 60){
            return `${count} days`;
        }

        const years = Math.floor(count / 365);
        const months = Math.floor((count % 365) / 30);

        if(years < 1){
            return `${months} months`;
        }

        return months < 1
            ? `${years} year${years === 1 ? '' : 's'}`
            : `${years} year${years === 1 ? '' : 's'} ${months} months`;
    }

    function toggle(id){
        const next = new Set(selected.value);

        next.has(id) ? next.delete(id) : next.add(id);

        selected.value = next;
    }

    function toggleAll(){
        selected.value = allSelected.value
            ? new Set()
            : new Set(rows.value.map(row => row.offcut.id));
    }

    function scrap(){
        form.offcut_ids = selectedRows.value.map(row => row.offcut.id);

        form.post(route('offcuts.scrap'), {
            preserveScroll: true,
            onSuccess: () => {
                selected.value = new Set();
                confirming.value = false;
                form.reset('note');
            },
        });
    }

    //Watchers
    /*
     * A row that leaves the list must not stay ticked. The page re-renders after a scrap, and a
     * stale id in the selection would have the confirm line counting steel that is already gone.
     */
    watch(rows, (current) => {
        const present = new Set(current.map(row => row.offcut.id));

        selected.value = new Set([...selected.value].filter(id => present.has(id)));

        if(selected.value.size === 0){
            confirming.value = false;
        }
    });
</script>

<template>
    <div>
        <!--
            What the list is and what put a row on it. Both halves matter: somebody who reads this
            as "short offcuts" will scrap a 1.2m length of beam that is worth keeping.
        -->
        <div class="px-4 py-4 border-b border-gray-200 bg-amber-50/60 dark:border-gray-700 dark:bg-amber-900/10">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Steel that has stopped earning its place
            </h2>

            <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                Each of these has sat unused for more than {{ Math.round(shelfLifeDays / 30) }} months
                <em>and</em> is shorter than the length at which an offcut of its own section pays for
                the labour of keeping it. That length is not a fixed number - it is about 2.2m for
                light angle and about 1m for a heavy beam, because a metre of beam is worth far more
                than the quarter hour it takes to deal with. Anything long enough to pay its way is
                not here however long it has sat.
            </p>

            <p class="mt-2 max-w-3xl text-sm text-gray-600 dark:text-gray-400">
                Scrapping is reversible from the Removed tab; the steel is not. Nothing here knows
                what you are quoting next month.
            </p>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-left">
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900"
                                :checked="allSelected"
                                :disabled="rows.length === 0"
                                aria-label="Select every offcut on the cleanout list"
                                @change="toggleAll"
                            >
                        </th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Mark</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Section</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Length</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Sitting for</th>
                        <!-- The three figures that make it a candidate, side by side -->
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Worth on the rack</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Costs to keep</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-right text-gray-500 dark:text-gray-400">Bin pays</th>
                    </tr>
                </thead>

                <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                    <tr
                        v-for="row in rows"
                        :key="row.offcut.id"
                        :class="selected.has(row.offcut.id) ? 'bg-blue-50/60 dark:bg-blue-900/10' : ''"
                    >
                        <td class="px-4 py-3">
                            <input
                                type="checkbox"
                                class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900"
                                :checked="selected.has(row.offcut.id)"
                                :aria-label="`Scrap offcut ${row.offcut.unique_mark}`"
                                @change="toggle(row.offcut.id)"
                            >
                        </td>

                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">
                                {{ row.offcut.unique_mark }}
                            </span>

                            <!--
                                Cut down from another offcut. Worth showing here of all places: a
                                fourth-generation stub is the end of a chain, and its certificates
                                run back through pieces that have already been consumed.
                            -->
                            <span v-if="row.offcut.generation > 1" class="block text-xs text-gray-500 dark:text-gray-400">
                                generation {{ row.offcut.generation }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">
                            {{ (row.offcut.label || '').trim() }}

                            <!--
                                The mass came from the business default, not the catalogue, because
                                nothing in products still matches this spec. Every figure on the row
                                is costed off that mass, so the row says so rather than presenting a
                                guess as a valuation.
                            -->
                            <span
                                v-if="!row.kg_per_m_resolved"
                                class="block text-xs text-amber-700 dark:text-amber-400"
                                title="No product matches this spec any more, so the mass per metre is the business default"
                            >
                                mass assumed {{ row.kg_per_m }} kg/m
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">
                            {{ millimetres(row.length_mm) }} mm

                            <span v-if="row.floor_mm" class="block text-xs text-gray-500 dark:text-gray-400">
                                pays its way from {{ millimetres(row.floor_mm) }} mm
                            </span>
                            <span v-else class="block text-xs text-gray-500 dark:text-gray-400">
                                no length of this section pays its way
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">
                            {{ sittingFor(row.age_days) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-right text-gray-700 whitespace-nowrap dark:text-gray-200">
                            ${{ money(row.worth) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-right text-gray-700 whitespace-nowrap dark:text-gray-200">
                            ${{ money(row.keep_cost) }}
                        </td>

                        <td class="px-4 py-3 text-sm text-right text-gray-700 whitespace-nowrap dark:text-gray-200">
                            ${{ money(row.bin_recovers) }}
                        </td>
                    </tr>

                    <tr v-if="rows.length === 0">
                        <td colspan="8" class="px-4 py-8 text-sm text-center text-gray-500 dark:text-gray-400">
                            Nothing on the rack has outstayed its welcome. Every offcut in inventory
                            is either recent enough to still be in play, or long enough to pay for
                            its own keep.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The action, and what it comes to -->
        <div v-if="rows.length > 0" class="px-4 py-4 border-t border-gray-200 dark:border-gray-700">
            <p v-if="selectedRows.length === 0" class="text-sm text-gray-500 dark:text-gray-400">
                Tick the pieces that are going in the bin.
            </p>

            <div v-else>
                <p class="text-sm text-gray-700 dark:text-gray-200">
                    <strong class="font-semibold">{{ selectedRows.length }}</strong>
                    {{ selectedRows.length === 1 ? 'offcut' : 'offcuts' }} selected - about
                    <strong class="font-semibold">${{ money(totals.recovers) }}</strong> back from the
                    merchant, and <strong class="font-semibold">${{ money(totals.drain) }}</strong> of
                    handling you stop paying for.
                </p>

                <!-- Optional, and only asked for once: the reason itself already says what happened -->
                <div class="mt-3">
                    <label :for="'cleanout-note'" class="block text-xs font-medium text-gray-600 dark:text-gray-400">
                        Note (optional) - goes on every row you scrap
                    </label>
                    <input
                        id="cleanout-note"
                        v-model="form.note"
                        type="text"
                        maxlength="255"
                        placeholder="Q1 rack clearout"
                        class="mt-1 block w-full max-w-md rounded-md border-gray-300 text-sm shadow-sm focus:border-blue-500 focus:ring-blue-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100"
                    >
                    <p v-if="form.errors.note" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        {{ form.errors.note }}
                    </p>
                </div>

                <p v-if="form.errors.offcut_ids" class="mt-2 text-sm text-red-600 dark:text-red-400">
                    {{ form.errors.offcut_ids }}
                </p>

                <!--
                    Two presses. Removing one offcut by hand is a single click because it records
                    something that already happened; this decides that steel in the yard is going to
                    stop existing, several pieces at a time.
                -->
                <div class="flex flex-wrap items-center mt-4 gap-3">
                    <template v-if="!confirming">
                        <button
                            type="button"
                            class="px-3 py-2 text-sm font-semibold text-white transition-colors duration-200 bg-red-600 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2"
                            @click="confirming = true"
                        >
                            Scrap {{ selectedRows.length }} {{ selectedRows.length === 1 ? 'offcut' : 'offcuts' }}
                        </button>
                    </template>

                    <template v-else>
                        <p class="w-full text-sm font-medium text-gray-900 dark:text-gray-100">
                            Weigh in
                            {{ selectedRows.length }}
                            {{ selectedRows.length === 1 ? 'piece' : 'pieces' }}
                            of steel? No nest will offer
                            {{ selectedRows.length === 1 ? 'it' : 'them' }}
                            again.
                        </p>

                        <button
                            type="button"
                            :disabled="form.processing"
                            class="px-3 py-2 text-sm font-semibold text-white transition-colors duration-200 bg-red-600 rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 disabled:opacity-50 disabled:cursor-wait"
                            @click="scrap"
                        >
                            {{ form.processing ? 'Scrapping...' : 'Yes, scrap them' }}
                        </button>

                        <button
                            type="button"
                            :disabled="form.processing"
                            class="px-3 py-2 text-sm font-medium text-gray-700 transition-colors duration-200 border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-gray-400 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800 disabled:opacity-50"
                            @click="confirming = false"
                        >
                            Cancel
                        </button>
                    </template>
                </div>
            </div>
        </div>
    </div>
</template>
