<script setup>
    //General Imports
    import {ref, computed, watch} from 'vue';

    //Component Imports
    //...

    //Props
    const props = defineProps({
        data: Array,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const searchQuery = ref('');
    const sortKey = ref('unique_mark'); //The mark is what the yard reads back, so it leads
    const sortOrder = ref('asc');
    const currentPage = ref(1);
    const pageSize = ref(20);

    /*
     * Only these are sortable, and the header says so.
     *
     * Every <th> used to carry cursor:pointer and a click handler; three of them sorted nothing,
     * so a third of the header was a button that did not work.
     */
    const sortableColumns = {
        unique_mark: 'Mark',
        label: 'Section',
        length: 'Length',
        generation: 'Cut from',
    };

    //Events
    const emit = defineEmits(['remove']);

    //Shared Methods
    //...

    //Methods
    // Every one of these is nullable on the offcut, so read them as strings before comparing
    const text = (value) => (value ?? '').toString();

    function getCerts(row){
        let certs = [];

        // A list of orders
        (row.newStockOrdersWithCertificates ?? []).forEach(order => {
            certs.push(order.material_cert_numbers);
        });

        // {used_offcuts, certificates: [{supplier_name, material_cert_numbers}]}
        (row.offcutOrdersWithCertificates?.certificates ?? []).forEach(certificate => {
            certs.push(certificate.material_cert_numbers);
        });

        return certs.filter(Boolean).join(", ");
    }

    /*
     * How far the steel has already been cut down, and the marks it wore on the way.
     *
     * An offcut of an offcut of an offcut is ordinary - there is no limit on it beyond the scrap
     * threshold - but nothing on this page used to say so, and a 2.5m offcut that had been through
     * four batches looked exactly like one straight off a 12m bar.
     */
    function generationOf(row){
        return row.generation ?? ((row.cut_from_marks ?? []).length + 1);
    }

    function getCutFromMarks(row){
        return (row.cut_from_marks ?? []).map(mark => '"' + mark + '"').join(' ← ');
    }

    function getProjectNames(row){
        let projectNames = [];

        Object.values(row.batch_projects ?? {}).forEach(project => {
            projectNames.push(project.name);
        });

        return projectNames.join(", ");
    }

    /*
     * Millimetres, grouped. A bare "11700" beside a "2400" is two numbers to count digits on; the
     * yard works in mm, so the metres go underneath rather than replacing it.
     */
    function millimetres(length){
        return Math.round(Number(length) || 0).toLocaleString();
    }

    function metres(length){
        return ((Number(length) || 0) / 1000).toFixed(2);
    }

    //Everything a row can be found by, including the two columns that were shown but not searchable
    function haystack(row){
        return [
            row.unique_mark,
            row.label,
            row.length,
            row.batch_from_id,
            getProjectNames(row),
            getCerts(row),
            (row.cut_from_marks ?? []).join(' '),
        ].map(text).join(' ').toLowerCase();
    }

    //Computed
    const filteredData = computed(() => {
        const query = searchQuery.value.trim().toLowerCase();

        const matched = query === ''
            ? [...props.data]
            : props.data.filter(row => haystack(row).includes(query));

        return matched.sort((a, b) => {
            let comparison = 0;

            if (sortKey.value === 'label') {
                comparison = text(a.label).localeCompare(text(b.label));
            }
            else if (sortKey.value === 'length') {
                comparison = (Number(a.length) || 0) - (Number(b.length) || 0);
            }
            else if (sortKey.value === 'generation') {
                comparison = generationOf(a) - generationOf(b);
            }
            else {
                comparison = text(a.unique_mark).localeCompare(text(b.unique_mark));
            }

            //Ties resolved by id, so a re-sort never shuffles rows that compare equal
            if (comparison === 0) {
                comparison = (a.id ?? 0) - (b.id ?? 0);
            }

            return sortOrder.value === 'asc' ? comparison : -comparison;
        });
    });

    /*
     * How much steel the rows below add up to - the one number this page exists to answer, and the
     * one it never showed. Computed over the FILTERED set, so searching a section turns the summary
     * into "how much 200PFC have I got", which is the question somebody about to order more is asking.
     */
    const totalLength = computed(() => filteredData.value.reduce(
        (total, row) => total + (Number(row.length) || 0), 0,
    ));

    const isFiltered = computed(() => searchQuery.value.trim() !== '');

    // An empty table is still one (empty) page, so it does not read "Page 1 of 0" with both buttons dead
    const totalPages = computed(() => Math.max(1, Math.ceil(filteredData.value.length / pageSize.value)));

    const paginatedData = computed(() => {
        const start = (currentPage.value - 1) * pageSize.value;

        return filteredData.value.slice(start, start + pageSize.value);
    });

    //1-based, and 0 of 0 rather than 1 of 0 when nothing matched
    const firstOnPage = computed(() => filteredData.value.length === 0
        ? 0
        : (currentPage.value - 1) * pageSize.value + 1);

    const lastOnPage = computed(() => Math.min(currentPage.value * pageSize.value, filteredData.value.length));

    //Methods
    const sortBy = (key) => {
        if (sortKey.value === key) {
            sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortOrder.value = 'asc';
        }
    };

    //What a screen reader announces on the header, and what the arrow beside it means
    const ariaSort = (key) => {
        if (sortKey.value !== key) {
            return 'none';
        }

        return sortOrder.value === 'asc' ? 'ascending' : 'descending';
    };

    const previousPage = () => {
        if (currentPage.value > 1) {
            currentPage.value--;
        }
    };

    const nextPage = () => {
        if (currentPage.value < totalPages.value) {
            currentPage.value++;
        }
    };

    // Watch search query to reset to page 1 when it changes
    watch(searchQuery, () => currentPage.value = 1);
    watch(pageSize, () => currentPage.value = 1);

    /*
     * Removing the last row of the last page used to leave the table on a page that no longer
     * exists - an empty grid under "Page 4 of 3", with Previous the only way back.
     */
    watch(totalPages, (pages) => {
        if (currentPage.value > pages) {
            currentPage.value = pages;
        }
    });
</script>

<template>
    <div>
        <!-- Search, and what the rows below add up to -->
        <div class="flex flex-col gap-3 px-4 py-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="w-full sm:max-w-xs">
                <label for="offcut-search" class="sr-only">Search offcuts</label>
                <input
                    id="offcut-search"
                    v-model="searchQuery"
                    type="search"
                    placeholder="Mark, section, length, project..."
                    class="block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 shadow-sm text-sm focus:border-blue-500 focus:ring-blue-500"
                >
            </div>

            <!--
                The total, and the total of what. A count with no length beside it does not say
                whether the yard is holding an afternoon's work or a truckload.
            -->
            <p class="text-sm text-gray-600 dark:text-gray-300" aria-live="polite">
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ filteredData.length }}</span>
                {{ filteredData.length === 1 ? 'offcut' : 'offcuts' }},
                <span class="font-semibold text-gray-900 dark:text-gray-100">{{ metres(totalLength) }} m</span>
                of steel
                <span v-if="isFiltered" class="text-gray-500 dark:text-gray-400">
                    (of {{ data.length }})
                </span>
            </p>
        </div>

        <!-- Six columns do not fit a phone, so the table scrolls rather than the page -->
        <div class="overflow-x-auto border-t border-gray-200 dark:border-gray-700">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th
                            v-for="(heading, key) in sortableColumns"
                            :key="key"
                            scope="col"
                            :aria-sort="ariaSort(key)"
                            class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400"
                        >
                            <!-- A button, so the sort is reachable by keyboard and announced as a control -->
                            <button
                                type="button"
                                class="inline-flex items-center gap-x-1 hover:text-gray-900 dark:hover:text-gray-100 focus:outline-none focus:underline"
                                @click="sortBy(key)"
                            >
                                <span>{{ heading }}</span>
                                <span v-if="sortKey === key" aria-hidden="true">
                                    {{ sortOrder === 'asc' ? '↑' : '↓' }}
                                </span>
                            </button>
                        </th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">
                            From batch
                        </th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">
                            Certificates
                        </th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                    <!-- Keyed by the offcut, not by its position - the list re-orders on every sort -->
                    <tr v-for="row in paginatedData" :key="row.id">
                        <!--
                            The mark leads, in a monospaced face. It is hand-written on the end of
                            the steel and read back a letter at a time, and the alphabet it is drawn
                            from has no I, O, S or U precisely because of that.
                        -->
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-mono font-semibold text-gray-900 dark:text-gray-100">
                                {{ row.unique_mark }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">
                            {{ (row.label || '').trim() }}
                        </td>

                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span class="text-gray-900 dark:text-gray-100">{{ millimetres(row.length) }} mm</span>
                            <span class="block text-xs text-gray-500 dark:text-gray-400">{{ metres(row.length) }} m</span>
                        </td>

                        <!--
                            How many times this steel has already been cut down. A first-generation
                            offcut and a fourth-generation one look identical in every other column.
                        -->
                        <td class="px-4 py-3 text-sm whitespace-nowrap">
                            <span
                                v-if="generationOf(row) === 1"
                                class="text-gray-500 dark:text-gray-400"
                            >New stock</span>
                            <template v-else>
                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">
                                    Gen {{ generationOf(row) }}
                                </span>
                                <span class="block text-xs text-gray-500 dark:text-gray-400">
                                    {{ getCutFromMarks(row) }}
                                </span>
                            </template>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            <span class="whitespace-nowrap">#{{ row.batch_from_id }}</span>
                            <!-- "#12: " with nothing after it used to read as a missing value -->
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ getProjectNames(row) || 'No projects on this batch' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            <span v-if="getCerts(row)">{{ getCerts(row) }}</span>
                            <!--
                                A blank cell read as "not loaded yet". Untraceable steel is a fact
                                worth stating, because it is the fact that stops it being used.
                            -->
                            <span v-else class="text-amber-700 dark:text-amber-300">Not traceable</span>
                        </td>

                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                            <button
                                type="button"
                                class="font-medium text-gray-500 transition-colors duration-200 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400 focus:outline-none focus:underline"
                                @click="emit('remove', row)"
                            >
                                Remove<span class="sr-only"> offcut {{ row.unique_mark }} from inventory</span>
                            </button>
                        </td>
                    </tr>

                    <!-- Nothing here at all, which is not the same as nothing matching a search -->
                    <tr v-if="data.length === 0">
                        <td colspan="7" class="px-4 py-8 text-sm text-center text-gray-500 dark:text-gray-400">
                            No offcuts in inventory yet. An offcut goes in here once its batch has
                            been nested and the steel it came off has been marked delivered.
                        </td>
                    </tr>

                    <tr v-else-if="filteredData.length === 0">
                        <td colspan="7" class="px-4 py-8 text-sm text-center text-gray-500 dark:text-gray-400">
                            Nothing matches &ldquo;{{ searchQuery }}&rdquo;.
                            <button
                                type="button"
                                class="font-medium text-blue-600 hover:underline dark:text-blue-400"
                                @click="searchQuery = ''"
                            >Clear the search</button>
                            to see all {{ data.length }}.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <div
            v-if="filteredData.length > 0"
            class="flex flex-col gap-3 px-4 py-3 border-t border-gray-200 sm:flex-row sm:items-center sm:justify-between dark:border-gray-700"
        >
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Showing {{ firstOnPage }}&ndash;{{ lastOnPage }} of {{ filteredData.length }}
            </p>

            <div class="flex items-center gap-x-3">
                <label class="text-sm text-gray-500 dark:text-gray-400">
                    <span class="sr-only">Rows per page</span>
                    <select
                        v-model.number="pageSize"
                        class="py-1 text-sm border-gray-300 rounded-md dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100 focus:border-blue-500 focus:ring-blue-500"
                    >
                        <option :value="20">20 per page</option>
                        <option :value="50">50 per page</option>
                        <option :value="100">100 per page</option>
                    </select>
                </label>

                <div class="flex items-center gap-x-2">
                    <button
                        type="button"
                        :disabled="currentPage === 1"
                        class="px-3 py-1 text-sm border border-gray-300 rounded-md dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="previousPage"
                    >Previous</button>

                    <span class="text-sm text-gray-500 dark:text-gray-400">
                        {{ currentPage }} / {{ totalPages }}
                    </span>

                    <button
                        type="button"
                        :disabled="currentPage === totalPages"
                        class="px-3 py-1 text-sm border border-gray-300 rounded-md dark:border-gray-600 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-800 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="nextPage"
                    >Next</button>
                </div>
            </div>
        </div>
    </div>
</template>
