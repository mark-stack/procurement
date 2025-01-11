<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link, useForm} from "@inertiajs/vue3";

    const props = defineProps({
        batch: Object,
        projects: Object,
        type: String,
    });

    //Form
    const formBreakBatch = useForm({

    });

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode']);

    //Methods
    function cropText(text, maxLength = 5) {
        if (text.length > maxLength) {
            return text.substring(0, maxLength) + "...";
        }
        return text;
    }

    function breakBatch(batch){
        let url = route("batches.destroy",batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }
</script>

<template>
    <!-- card -->
    <div class="border-2 border-blue-500 rounded-lg">
        <!-- Body -->
        <div class="p-3">
            <span class="text-sm block text-gray-500">Batch ID: {{batch.id}}</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">Quote deadline: [1/2/24]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Steel merchant] quotes: [3]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Fasteners] quotes: [0]</span>
            <span v-if="type === 'QUOTES'" class="text-sm block text-gray-800">[Timber merchant] quotes: [1]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order deadline: [1/2/24]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">All PM approval: [No]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order sent: [No]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Order confirmation: [No]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Supplier: [abc steel]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Purchase order: [18-asdd]</span>
            <span v-if="type === 'ORDERS'" class="text-sm block text-gray-800">Due approx: [1/2/24]</span>
            <Link :href="route('batch.nesting',batch.id)" class="font-bold">Nesting details <i class="fa-solid fa-list"/></Link>

            <span v-for="project in projects" class="block">{{ cropText(project.name) }}</span>
        </div>
        <!-- Footer -->
        <div class="border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">
            <!-- actions -->
            <div class="flex justify-center items-center gap-x-6 mt-1">
                <button
                    v-if="type === 'QUOTES'"
                    @click="breakBatch(batch)"
                    class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                >
                    Break Batch (re-nest)
                </button>
                <button v-if="type === 'QUOTES'">
                    Add quote request
                </button>
                <button v-if="type === 'QUOTES'">
                    Order
                </button>
                <button v-if="type === 'ORDERS'">
                    All PM's approved
                </button>
                <button v-if="type === 'ORDERS'">
                    Is ordered (add PO)
                </button>
                <button v-if="type === 'ORDERS'">
                    Is Delivered
                </button>
            </div>
        </div>
    </div>
</template>
