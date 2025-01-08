<script setup>
    //General Imports
    //...

    //Component Imports
    //...

    //Props
    import {Link} from "@inertiajs/vue3";

    const props = defineProps({
        batch: Object,
        projects: Object,
    });

    //Form
    //...

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

    function isArchived(){
        let isArchived = false;

        if(props.projects.length === 1 && props.projects[0].archive){
            isArchived = true;
        }

        return isArchived;
    }
</script>

<template>
    <!-- card -->
    <div
        :class="isArchived() ? 'border-gray-500' : 'border-blue-500'"
        class="border-2 rounded-lg"
    >
        <!-- Body -->
        <div class="p-3">
            <span v-if="projects.length > 1" class="text-sm block text-gray-500">Batch ID: {{batch.id}}</span>
            <span v-for="project in projects" class="block">{{ cropText(project.name) }}</span>
        </div>
        <!-- Footer -->
        <div
            :class="isArchived() ? 'border-gray-500 bg-gray-100' : 'border-blue-500 bg-blue-100'"
            class="border-t-2 p-1 rounded-b-lg text-xs"
        >
            PM: [You]

            <!-- Single project actions -->
            <div v-if="projects.length === 1">
                <!-- awarded pill -->
<!--                <div-->
<!--                    :class="projects[0].archive ? 'bg-gray-100/60' : (projects[0].awarded ? 'bg-emerald-100/60' : 'bg-yellow-200/60')"-->
<!--                    class="inline-flex items-center px-3 py-1 rounded-full gap-x-2 dark:bg-gray-800"-->
<!--                >-->
<!--                    <span-->
<!--                        :class="projects[0].archive ? 'bg-gray-500' : (projects[0].awarded ? 'bg-emerald-500' : 'bg-yellow-500')"-->
<!--                        class="h-1.5 w-1.5 rounded-full"-->
<!--                    ></span>-->
<!--                    <h2-->
<!--                        :class="projects[0].archive ? 'text-gray-500' : (projects[0].awarded ? 'text-emerald-500' : 'text-yellow-800')"-->
<!--                        class="text-sm font-semibold"-->
<!--                    >-->
<!--                        {{projects[0].awarded ? 'Awarded' : 'Tender'}}-->
<!--                    </h2>-->
<!--                </div>-->

                <!-- Import materials button -->
                <div class="flex justify-center items-center gap-x-6 mt-2">
                    <p
                        v-if="projects[0].archive"
                        class="px-2 py-1 rounded border-2 bg-gray-300 border-gray-500"
                    >
                        {{projects[0].hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}
                    </p>
                    <Link
                        v-else
                        :href="route('products.index',projects[0].id)"
                        :class="projects[0].hasRawMaterialQuotes ? 'text-emerald-500 bg-emerald-100 border-emerald-300 hover:bg-emerald-200' : 'text-orange-500 bg-orange-50 border-orange-300 hover:bg-orange-100'"
                        class="px-2 py-1 rounded border-2 font-semibold"
                    >
                        {{projects[0].hasRawMaterialQuotes ? 'Imported Materials' : 'Import Materials'}}
                    </Link>
                </div>

                <!-- archive and delete -->
                <div class="flex justify-center items-center gap-x-6 mt-1">
                    <Link :href="route('products.index',projects[0].id)">
                        BOM
                    </Link>
                    <button v-if="!projects[0].archive" @click="$emit('editMode',projects[0])">
                        Edit
                    </button>
                    <button
                        @click="$emit('toggleArchive',projects[0])"
                        class="text-gray-800 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"
                    >
                        <span v-if="!projects[0].archive">Archive</span>
                        <span v-if="projects[0].archive"><i class="fa-solid fa-arrow-rotate-left"></i> restore</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
