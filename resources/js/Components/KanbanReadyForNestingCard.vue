<script setup>
    //General Imports
    import {usePage} from "@inertiajs/vue3";
    import {computed} from "vue";
    import moment from "moment/moment.js";

    //Component Imports
    import CardButtonRed from "@/Components/CardButtonRed.vue";
    import CardButtonYellow from "@/Components/CardButtonYellow.vue";
    import CardButtonGreen from "@/Components/CardButtonGreen.vue";

    //Props
    const props = defineProps({
        projects: Object,
        usageStats: Number,
    });

    //Form
    //

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','quoteNow','orderNow','showBom','pageLoadingOn','showNesting']);
    const user = computed(() => usePage().props.auth.user);

    //Shared methods
    import shared from '@/Shared/shared';
    import CardButtonBlue from "@/Components/CardButtonBlue.vue";

    //Methods
    function isYourProject(project){
        return project.user_id == user.value.id;
    }
</script>

<template>
    <!-- card -->
    <div class="relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 bg-white rounded-lg bg-opacity-90 group hover:bg-opacity-100" draggable="true">
        <div class="w-full mb-2 text-center">
            <p class="text-sm text-green-500"><b>{{usageStats.efficiency}}%</b> efficiency</p>
        </div>

        <div class="grid grid-cols-1 gap-y-2 w-full text-xs font-medium text-gray-500">
            <div
                v-for="project in projects"
                class="w-full rounded-lg border-2 border-gray-300 p-2"
            >

                <div class="grid grid-cols-6">
                    <h4 class="col-span-4 text-base font-medium">
                        {{ shared.cropText(shared.capitalizeWords(project.name),15) }}
                    </h4>
                    <span class="col-span-2 text-right pt-1"><i class="fa-solid fa-user text-xs"></i> {{shared.cropText(project.projectManager.name,5)}}</span>
                </div>

                <div class="flex justify-between mt-2">
                    <div class="flex items-center">
                        <i class="fa-regular fa-calendar-days text-base"></i>
                        <div>
                            <span class="ml-1 text-xs">Quote by:</span>
                            <span class="block ml-1 leading-none text-xs">{{ moment(project.quoteRequestDeadline).format("DD-MM-YYYY")}}</span>
                        </div>
                    </div>
                    <div class="flex items-center ml-4">
                        <CardButtonGreen
                            @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                            :label="project.qtyMaterialRows"
                            :highlight="false"
                            :icon="true"
                        />
                    </div>
                </div>

                <div class="mt-3 w-full flex gap-x-2 justify-between items-center">
                    <CardButtonRed
                        v-if="isYourProject(project)"
                        @click="$emit('toggleArchive',project)"
                        label="Archive"
                    />
                    <CardButtonYellow
                        v-if="isYourProject(project)"
                        @click="$emit('editMode',project)"
                        label="Edit"
                    />
                    <CardButtonGreen
                        @click="$emit('pageLoadingOn',null);$emit('showBom',project);"
                        label="Materials"
                        :highlight="false"
                        :icon="false"
                    />
                </div>
            </div>
        </div>

        <!-- Nesting details -->
        <div class="flex w-full justify-center mt-2">
            <CardButtonBlue
                @click="$emit('pageLoadingOn',null);$emit('showNesting',0)"
                label="Nesting details"
                :highlight="false"
            />
        </div>

        <!-- Actions -->
        <div class="mt-3 w-full flex gap-x-2 justify-between items-center">
            <CardButtonGreen
                @click="$emit('pageLoadingOn',null);$emit('quoteNow')"
                label="Quote now"
                :highlight="true"
                :icon="false"
            />
        </div>
        <p class="w-full mt-1 text-xs block text-center text-orange-300">
            {{ shared.quoteDeadlineMessage(projects) }}
        </p>
    </div>


    <!-- card -->
<!--    <div class="border-2 border-blue-500 rounded-lg">-->
<!--        &lt;!&ndash; Body &ndash;&gt;-->
<!--        <div class="p-3">-->
<!--            <span class="text-sm block text-gray-500">Auto Batched Nesting</span>-->
<!--            <span class="text-sm block text-green-500">Saves 13% waste</span>-->
<!--            <span class="text-xs block text-orange-500">Suggest to wait [3 more days] to allow for more possible materials.</span>-->
<!--            <Link :href="route('suggested.nesting')" class="font-bold">Nesting details <i class="fa-solid fa-list"/></Link>-->

<!--            <div class="mt-3 grid grid-cols-1 gap-y-2">-->
<!--                &lt;!&ndash; project mini card &ndash;&gt;-->
<!--                <div v-for="project in projects" class="p-2 rounded-lg border-2 border-blue-200 bg-blue-50">-->
<!--                    <h3>{{ shared.cropText(project.name,16) }}</h3>-->
<!--                    <h6 class="text-xs text-gray-500">Quote/order by [4/2/24]</h6>-->
<!--                    <div class="text-xs">-->
<!--                        <div class="grid grid-cols-3 justify-between">-->
<!--&lt;!&ndash;                            <Link :href="route('products.index',project.id)">&ndash;&gt;-->
<!--&lt;!&ndash;                                BOM (page)&ndash;&gt;-->
<!--&lt;!&ndash;                            </Link>&ndash;&gt;-->
<!--                            <button @click="$emit('showBom',project)">-->
<!--                                BOM (modal)-->
<!--                            </button>-->
<!--                            <button-->
<!--                                @click="$emit('toggleArchive',projects[0])"-->
<!--                                class="text-gray-500 transition-colors duration-200 dark:hover:text-red-500 dark:text-gray-300 hover:text-red-500 focus:outline-none"-->
<!--                            >-->
<!--                                Archive-->
<!--                            </button>-->
<!--                            <button-->
<!--                                v-if="isYourProject(project)"-->
<!--                                class="text-gray-500"-->
<!--                                @click="$emit('editMode',project)"-->
<!--                            >-->
<!--                                Edit-->
<!--                            </button>-->
<!--                        </div>-->
<!--                    </div>-->
<!--                </div>-->
<!--            </div>-->
<!--        </div>-->
<!--        &lt;!&ndash; Footer &ndash;&gt;-->
<!--        <div class="grid grid-cols-2 border-t-2 border-blue-500 bg-blue-100 p-1 rounded-b-lg text-xs">-->
<!--            <button @click="$emit('quoteNow')">-->
<!--                Quote now-->
<!--            </button>-->
<!--            <button @click="$emit('orderNow')">-->
<!--                Order now-->
<!--            </button>-->
<!--        </div>-->
<!--    </div>-->
</template>
