<script setup>
    //General Imports
    import moment from "moment";

    //Component Imports
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";

    //Props
    const props = defineProps({
        project: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','showBom','pageLoadingOn']);

    //Shared methods
    import shared from '@/Shared/shared';

    //Methods
    //
</script>

<template>
    <!-- card -->
    <div class="relative flex flex-col items-start p-4 mt-3 bg-white rounded-lg bg-opacity-90 group hover:bg-opacity-100" draggable="true">
<!--        <h4 class="text-base font-medium">-->
<!--            {{ shared.cropText(shared.capitalizeWords(project.name)) }}-->
<!--        </h4>-->
        <div class="grid grid-cols-7 text-gray-500">
            <h4 class="col-span-5 text-base font-medium">
                {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
            </h4>
            <span class="col-span-2 text-xs font-medium text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.cropText(project.projectManager.name,6)}}</span>
        </div>
        <div class="flex justify-between w-full mt-3 text-xs font-medium text-gray-500">
            <div class="flex items-center">
                <i class="fa-regular fa-calendar-days text-2xl"></i>
                <div>
                    <span class="ml-1 text-xs">Quote by:</span>
                    <span class="block ml-1 leading-none text-xs">{{ moment(project.quoteRequestDeadline).format("DD-MM-YYYY")}}</span>
                </div>
            </div>
            <div class="flex items-center ml-4">
<!--                <i class="fa-solid fa-list text-base"></i>-->
<!--                <span class="ml-1 leading-none text-sm">{{ project.qtyMaterialRows }}</span>-->
                <CardButtonGreen
                    @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                    :label="project.qtyMaterialRows"
                    :highlight="false"
                    :icon="true"
                />
            </div>
<!--            <div class="flex items-center ml-4">-->
<!--                <i class="fa-solid fa-user text-base"></i>-->
<!--                <span class="ml-1 leading-none text-xs">{{project.projectManager.name}}</span>-->
<!--            </div>-->
        </div>

        <div class="mt-3 w-full flex gap-x-2 justify-between items-center">
            <CardButtonRed
                @click="$emit('toggleArchive',project)"
                label="Archive"
            />
            <CardButtonYellow
                @click="$emit('editMode',project)"
                label="Edit"
            />
            <CardButtonGreen
                @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                label="Materials"
                :highlight="true"
                :icon="false"
            />
        </div>
    </div>
</template>
