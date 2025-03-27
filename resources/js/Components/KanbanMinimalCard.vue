<script setup>
    //General Imports
    import {useForm, usePage, Link} from "@inertiajs/vue3";
    import moment from "moment/moment.js";
    import {computed, ref} from "vue";

    //Component Imports
    import CardButtonGreen from "@/Components/Buttons/CardButtonGreen.vue";
    import CardButtonRed from "@/Components/Buttons/CardButtonRed.vue";
    import CardButtonBlue from "@/Components/Buttons/CardButtonBlue.vue";
    import CardButtonYellow from "@/Components/Buttons/CardButtonYellow.vue";
    import CardButtonForward from "@/Components/Buttons/CardButtonForward.vue";

    //Props
    const props = defineProps({
        projects: Object,
        kanbanColumn: String,
        usageStats: Object,
        prerequisiteStartQuoting: Boolean,
        batchInfo: Object,
    });

    //Form
    const formBreakBatch = useForm({});
    const formMarkAsPastProject = useForm({});

    //Shared data
    //...

    //Variables
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting','addProject']);
    const user = computed(() => usePage().props.auth.user);
    const loadingButton = ref(null);
    const expandProject = ref(null);
    const expandBatchDetails = ref(null);

    //Shared methods
    import shared from "@/Shared/shared.js";

    //Methods
    function atLeastOneProjectIsYours(){
        return props.batchInfo
            ? shared.atLeastOneProjectIsYours(props.batchInfo.projects.data,user.id)
            : true;
    }

    function breakBatch(){
        let url = route("batches.destroy",props.batchInfo.batch.id);
        formBreakBatch.delete(url, {
            preserveScroll: true,
            onSuccess: () => {
                console.log('success after re-nest');
            },
            onError: errors => {
                console.log('errors',errors);
            },
        });
    }

    function allDelivered(){
        return props.batchInfo.allDelivered;
    }

    function markAsPastProject(){
        let url = route("mark.as.past.project",props.batchInfo.batch.id);
        formMarkAsPastProject.post(url, {
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
    <!-- Simple card -->
    <div
        class="bg-white relative flex flex-col items-start pt-2 pl-4 pr-4 pb-4 rounded-lg group border-[1px] border-gray-300 shadow-lg"
    >
        <div
            v-if="usageStats && atLeastOneProjectIsYours"
            class="w-full mb-2 text-center"
        >
            <p
                v-if="usageStats.METERAGE?.efficiency > 0"
                class="text-sm text-green-500"
            >
                <b>{{usageStats.METERAGE.efficiency}}%</b> efficiency
            </p>
            <p
                v-else
                class="text-sm text-green-500"
            >
                Calculating efficiency...
            </p>
        </div>

        <div
            v-for="(project,index) in projects"
            class="w-full border-gray-200 rounded-lg border-[1px] p-2 bg-gray-50"
        >
            <h4 class="col-span-4 text-base font-medium">
                {{ shared.cropText(shared.capitalizeWords(project.name),24) }}
            </h4>

            <div class="flex gap-x-1 w-full mt-3 text-xs font-medium text-gray-900">
                <CardButtonGreen
                    @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                    :label="project.qtyMaterialRows + ' pieces'"
                    :highlight="false"
                    :icon="false"
                />
                <CardButtonRed
                    @click="$emit('toggleArchive',project)"
                    label="Archive"
                    :fullWidth="false"
                    :disabled="kanbanColumn !== 'NESTING'"
                />
                <CardButtonYellow
                    @click="$emit('editMode',project)"
                    label="Edit"
                    :fullWidth="false"
                />
            </div>
        </div>
        <div class="mt-3">
            <p
                v-if="kanbanColumn === 'NESTING'"
                class="text-blue-700 font-semibold text-sm"
                @click="$emit('addProject')"
                style="cursor: pointer;"
            >
                + add project
            </p>
            <p
                v-else
                class="text-gray-500 font-semibold text-sm"
            >
                + add project
            </p>
        </div>
        <div class="w-full grid grid-cols-2 gap-1 border-t-[1px] border-gray-200 mt-2 pt-3 pb-3">
            <!-- Nesting -->
            <Link
                v-if="kanbanColumn === 'NESTING'"
                :href="route('suggested.nesting')"
                class="w-full col-span-1"
                @click="loadingButton = 'NESTING_DETAILS'"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>
            <Link
                v-else
                :href="route('batch.nesting',[batchInfo.batch.id,'current'])"
                @click="loadingButton = 'NESTING_DETAILS'"
                class="w-full col-span-1"
            >
                <CardButtonBlue
                    :label="loadingButton === 'NESTING_DETAILS' ? 'Calculating...' : 'Nesting'"
                    :highlight="false"
                />
            </Link>

            <!-- start quoting -->
            <div
                v-if="prerequisiteStartQuoting"
                class="w-full flex gap-x-2 justify-between items-center"
            >
                <CardButtonForward
                    label="Batch now"
                    @click="$emit('pageLoadingOn',null);$emit('quoteNow')"
                />
            </div>

            <!-- Quote/order modal -->
            <Link
                v-if="kanbanColumn === 'QUOTING'"
                :href="route('quote.order.management',batchInfo.batch.id)"
                class="w-full"
                @click="loadingButton = 'QUOTING'"
            >
                <CardButtonForward
                    :label="loadingButton === 'QUOTING' ? 'Opening...' : 'Quotes'"
                    @click="loadingButton = 'QUOTING'"
                />
            </Link>

            <Link
                v-if="kanbanColumn === 'ORDERING' || kanbanColumn === 'DELIVERED'"
                :href="route('quote.order.management',batchInfo.batch.id)"
                class="w-full"
                @click="loadingButton = 'ORDERING'"
            >
                <CardButtonGreen
                    v-if="kanbanColumn === 'DELIVERED' && allDelivered()"
                    :label="loadingButton === 'ORDERING' ? 'Opening...' : 'Orders'"
                    @click="loadingButton = 'ORDERING'"
                />
                <CardButtonForward
                    v-else
                    :label="loadingButton === 'ORDERING' ? 'Opening...' : 'Orders'"
                    @click="loadingButton = 'ORDERING'"
                />
            </Link>

            <!-- re-nest -->
            <CardButtonRed
                v-if="batchInfo?.prerequisiteUndoStartQuoting"
                @click="$emit('pageLoadingOn',3); breakBatch()"
                label="Re-nest"
                class="w-full mt-2"
                :fullWidth="true"
                :disabled="false"
            />

            <!-- All delivered (suggest mark as done) -->
            <CardButtonForward
                v-if="kanbanColumn === 'DELIVERED' && allDelivered()"
                :label="formMarkAsPastProject.processing ? 'Moving...' : 'Move to done'"
                @click="markAsPastProject()"
                class="col-span-2"
            />

            <!-- email buttons (should be just one for steel merchant) -->
<!--            <template v-for="supplierGroup in info?.quotesData?.supplierGroupCards">-->

<!--                <button-->
<!--                    @click="shared.sendSupplierBatchEmail(supplierGroup.info.batchGroup)"-->
<!--                    class="col-span-3 bg-green-50 hover:bg-green-100 border-[1px] border-green-200 w-full text-center pt-1 h-6 px-2 text-xs font-semibold text-green-400 hover:text-green-500 rounded-full"-->
<!--                    style="cursor: pointer;padding-top: 2px;"-->
<!--                >-->
<!--                    <i class="fa-regular fa-envelope text-sm pr-1"></i>-->
<!--                    <span class="text-xs">{{supplierGroup.info.supplierGroup}}</span>-->
<!--                </button>-->
<!--                <p class="text-xs text-gray-500">-->
<!--                    {{supplierGroup.info.includedProducts}}-->
<!--                </p>-->
<!--            </template>-->
        </div>
    </div>
</template>
