<script setup>
    //General Imports
    import {ref, computed, watch} from 'vue';

    //Component Imports
    //...

    //Props
    const props = defineProps({
        data: Array
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const searchQuery = ref('');
    const sortKey = ref('label'); // Default sort key
    const sortOrder = ref('asc'); // Default sort order
    const currentPage = ref(1); // Current page number
    const itemsPerPage = ref(20); // Number of items per page

    //Shared Methods
    //...

    //Methods
    const filteredData = computed(() => {
        return props.data
            .filter((row) => {
                return (
                    row.label.toLowerCase().includes(searchQuery.value.toLowerCase()) ||
                    row.length.toString().includes(searchQuery.value) ||
                    row.unique_mark.toString().includes(searchQuery.value)
                );
            })
            .sort((a, b) => {
                let comparison = 0;
                if (sortKey.value === 'label') {
                    comparison = a.label.localeCompare(b.label);
                }
                else if (sortKey.value === 'length') {
                    comparison = a.length - b.length;
                }
                else if (sortKey.value === 'unique_mark') {
                    comparison = a.unique_mark.localeCompare(b.unique_mark);
                }
                return sortOrder.value === 'asc' ? comparison : -comparison;
            });
    });

    const sortBy = (key) => {
        if (sortKey.value === key) {
            sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
        } else {
            sortKey.value = key;
            sortOrder.value = 'asc';
        }
    };

    const sortClass = (key) => {
        return sortKey.value === key ? (sortOrder.value === 'asc' ? 'asc' : 'desc') : '';
    };

    function getCerts(row){
        let certs = [];
        if(row.newStockOrdersWithCertificates){
            if(typeof row.newStockOrdersWithCertificates === 'object'){
                row.newStockOrdersWithCertificates.forEach(order => {
                    certs.push(order.material_cert_numbers);
                });
            }
        }
        if(row.offcutOrdersWithCertificates){
            if(typeof row.offcutOrdersWithCertificates === 'object'){
                row.offcutOrdersWithCertificates.forEach(order => {
                    certs.push(order.material_cert_numbers);
                });
            }
        }

        return certs.join(", ");
    }

    function getProjectNames(row){
        let projectNames = [];

        Object.values(row.batch_projects).forEach(project => {
            projectNames.push(project.name);
        });

        return projectNames.join(", ");
    }

    // Go to previous page
    const previousPage = () => {
        if (currentPage.value > 1) {
            currentPage.value--;
        }
    };

    // Go to next page
    const nextPage = () => {
        if (currentPage.value < totalPages.value) {
            currentPage.value++;
        }
    };

    // Get total number of pages
    const totalPages = computed(() => {
        return Math.ceil(filteredData.value.length / itemsPerPage.value);
    });

    // Paginate data based on the current page
    const paginatedData = computed(() => {
        const start = (currentPage.value - 1) * itemsPerPage.value;
        const end = start + itemsPerPage.value;
        return filteredData.value.slice(start, end);
    });

    // Watch search query to reset to page 1 when it changes
    watch(searchQuery, () => {
        currentPage.value = 1;
    });
</script>

<template>
    <div>
        <!-- Search Bar -->
        <input
            v-model="searchQuery"
            type="text"
            placeholder="Search..."
            class="search-input"
        />

        <!-- Data Table -->
        <table class="data-table">
            <thead>
                <tr>
                    <th @click="sortBy('label')" :class="sortClass('label')">label</th>
                    <th @click="sortBy('length')" :class="sortClass('length')">Length (mm)</th>
                    <th @click="sortBy('unique_mark')" :class="sortClass('unique_mark')">Marked</th>
                    <th>From Batch/Projects</th>
                    <th>Certificates</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="(row, index) in paginatedData" :key="index">
                    <td>{{ row.label }}</td>
                    <td>{{ row.length }}</td>
                    <td>{{ row.unique_mark }}</td>
                    <td>#{{row.batch_from_id}}: {{getProjectNames(row)}}</td>
                    <td>{{ getCerts(row) }}</td>
                </tr>
            </tbody>
        </table>

        <!-- Pagination Controls -->
        <div class="pagination-controls">
            <button @click="previousPage" :disabled="currentPage === 1">Previous</button>
            <span>Page {{ currentPage }} of {{ totalPages }}</span>
            <button @click="nextPage" :disabled="currentPage === totalPages">Next</button>
        </div>
    </div>
</template>

<style scoped>
    .search-input {
        margin: 20px 0;
        padding: 5px;
        width: 200px;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
    }

    .data-table th,
    .data-table td {
        padding: 10px;
        border: 1px solid #ccc;
        text-align: left;
    }

    .data-table th {
        cursor: pointer;
    }

    .asc::after {
        content: ' ↑';
    }

    .desc::after {
        content: ' ↓';
    }

    .pagination-controls {
        margin-top: 20px;
        text-align: center;
    }

    .pagination-controls button {
        padding: 5px 10px;
        margin: 0 5px;
        cursor: pointer;
    }

    .pagination-controls button:disabled {
        cursor: not-allowed;
        opacity: 0.5;
    }
</style>
