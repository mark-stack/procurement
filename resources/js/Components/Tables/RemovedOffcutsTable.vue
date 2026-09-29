<script setup>
    //General Imports
    import {router} from '@inertiajs/vue3';
    import {ref} from 'vue';

    //Component Imports
    //...

    //Props
    const props = defineProps({
        /** The most recently removed offcuts, newest first - the server caps how many travel. */
        data: Array,
        /** How many removals there are altogether, so a capped list never reads as the whole story. */
        total: {
            type: Number,
            default: 0,
        },
    });

    //Variables
    //The row currently being put back, so only its own button says so
    const restoring = ref(null);

    //Methods
    function millimetres(length){
        return Math.round(Number(length) || 0).toLocaleString();
    }

    /*
     * Straight back in, with no confirmation.
     *
     * Restoring is the undo, not the destructive half - the worst it can do is offer a nest a piece
     * of steel that then has to be removed again. Asking twice here would make the correction harder
     * than the mistake.
     */
    function restore(row){
        restoring.value = row.id;

        router.post(route('offcuts.restore', row.id), {}, {
            preserveScroll: true,
            onFinish: () => restoring.value = null,
        });
    }
</script>

<template>
    <div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Mark</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Section</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Length</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">What happened</th>
                        <th scope="col" class="px-4 py-3 text-sm font-normal text-left text-gray-500 dark:text-gray-400">Removed</th>
                        <th scope="col" class="px-4 py-3">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody class="bg-white divide-y divide-gray-200 dark:divide-gray-700 dark:bg-gray-900">
                    <tr v-for="row in data" :key="row.id">
                        <td class="px-4 py-3 whitespace-nowrap">
                            <span class="font-mono font-semibold text-gray-500 line-through dark:text-gray-400">
                                {{ row.unique_mark }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap dark:text-gray-400">
                            {{ (row.label || '').trim() }}
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap dark:text-gray-400">
                            {{ millimetres(row.length) }} mm
                        </td>

                        <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-200">
                            {{ row.removed_reason_label }}
                            <span v-if="row.removed_note" class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ row.removed_note }}
                            </span>
                        </td>

                        <!--
                            Who and when, on the row. A removal is one person's claim that a piece of
                            steel is not where the system says it is, and the next person to go
                            looking for it needs to know whose claim to go and ask about.
                        -->
                        <td class="px-4 py-3 text-sm text-gray-700 whitespace-nowrap dark:text-gray-200">
                            {{ row.removed_at_label }}
                            <span class="block text-xs text-gray-500 dark:text-gray-400">
                                {{ row.removed_by || 'by a user who has since left' }}
                            </span>
                        </td>

                        <td class="px-4 py-3 text-sm text-right whitespace-nowrap">
                            <button
                                type="button"
                                :disabled="restoring === row.id"
                                class="font-medium text-gray-500 transition-colors duration-200 hover:text-blue-600 dark:text-gray-400 dark:hover:text-blue-400 focus:outline-none focus:underline disabled:opacity-50"
                                @click="restore(row)"
                            >
                                {{ restoring === row.id ? 'Putting back...' : 'Put back' }}
                                <span class="sr-only"> offcut {{ row.unique_mark }} into inventory</span>
                            </button>
                        </td>
                    </tr>

                    <tr v-if="data.length === 0">
                        <td colspan="6" class="px-4 py-8 text-sm text-center text-gray-500 dark:text-gray-400">
                            Nothing has been taken out of inventory by hand. When an offcut is
                            stolen, cut up off the books or cannot be found, remove it here so no
                            nest counts on steel that is not in the yard.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- The list is a window onto the removals, and says so rather than implying it is all of them -->
        <p
            v-if="total > data.length"
            class="px-4 py-3 text-sm text-gray-500 border-t border-gray-200 dark:border-gray-700 dark:text-gray-400"
        >
            Showing the {{ data.length }} most recent of {{ total }} removals.
        </p>
    </div>
</template>
