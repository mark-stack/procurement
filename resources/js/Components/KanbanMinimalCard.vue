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
    const emit = defineEmits(['toggleArchive','editMode','pageLoadingOn','pageLoadingOff','showBom','showNesting','addProject','quoteNow']);
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
        class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition-shadow duration-200 hover:shadow-md"
    >
        <!-- card header: batch identity on the left, efficiency on the right -->
        <div
            v-if="batchInfo || (usageStats && atLeastOneProjectIsYours)"
            class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-100 bg-gray-50 px-3 py-2"
        >
            <span
                v-if="batchInfo"
                class="inline-flex items-center gap-1.5 rounded-md bg-white px-2 py-1 text-[11px] font-semibold uppercase tracking-wide text-gray-600 ring-1 ring-inset ring-gray-200"
            >
                <i class="fa-solid fa-layer-group text-[10px] text-gray-400"></i>
                Batch {{ batchInfo.batch.id }}
            </span>
            <template v-if="usageStats && atLeastOneProjectIsYours">
                <span
                    v-if="usageStats.METERAGE?.efficiency > 0"
                    class="inline-flex items-center gap-1.5 rounded-full bg-green-50 px-2 py-1 text-[11px] font-semibold text-green-800 ring-1 ring-inset ring-green-200"
                >
                    <i class="fa-solid fa-arrow-trend-up text-[10px]"></i>
                    {{usageStats.METERAGE.efficiency}}% efficiency
                </span>
                <span
                    v-else
                    class="inline-flex items-center gap-1.5 rounded-full bg-gray-100 px-2 py-1 text-[11px] font-medium text-gray-500 ring-1 ring-inset ring-gray-200"
                >
                    <i class="fa-solid fa-circle-notch fa-spin text-[10px]"></i>
                    Calculating efficiency
                </span>
            </template>
        </div>

        <!-- projects on this card -->
        <div class="space-y-2 p-3">
            <div
                v-for="(project,index) in projects"
                :key="project.id"
                class="rounded-lg border border-gray-200 bg-gray-50 p-3 transition-colors duration-150 hover:border-gray-300"
            >
                <h4
                    class="truncate text-sm font-semibold text-gray-900"
                    :title="shared.capitalizeWords(project.name)"
                >
                    {{ shared.capitalizeWords(project.name) }}
                </h4>
                <p v-if="project.reference" class="mt-0.5 truncate text-xs text-gray-500">
                    Ref: {{ project.reference }}
                </p>

                <div class="mt-3 grid grid-cols-7 gap-1.5">
                    <CardButtonGreen
                        @click="$emit('pageLoadingOn',null);$emit('showBom',project)"
                        :label="project.qtyMaterialRows + ' pieces'"
                        :highlight="false"
                        :icon="false"
                        class="col-span-3"
                    />
                    <CardButtonRed
                        @click="$emit('toggleArchive',project)"
                        label="Archive"
                        :fullWidth="true"
                        :disabled="kanbanColumn !== 'NESTING'"
                        class="col-span-2"
                    />
                    <CardButtonYellow
                        class="col-span-2"
                        @click="$emit('editMode',project)"
                        label="Edit"
                        :fullWidth="true"
                    />
                </div>
            </div>
        </div>
<!--        <div class="mt-3">-->
<!--            <p-->
<!--                v-if="kanbanColumn === 'NESTING'"-->
<!--                class="text-blue-700 font-semibold text-sm"-->
<!--                @click="$emit('addProject')"-->
<!--                style="cursor: pointer;"-->
<!--            >-->
<!--                + add project-->
<!--            </p>-->
<!--            <p-->
<!--                v-else-->
<!--                class="text-gray-500 font-semibold text-sm"-->
<!--            >-->
<!--                + add project-->
<!--            </p>-->
<!--        </div>-->
        <div class="mt-auto grid w-full grid-cols-2 items-start gap-2 border-t border-gray-100 bg-gray-50 px-3 py-3">
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
                :href="route('batch.nesting',[batchInfo.batch.id,'current',1])"
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
                class="w-full col-span-1"
            >
                <CardButtonForward
                    label="Start quoting"
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

            <!-- re-nest $emit('pageLoadingOn',3);  -->
            <CardButtonRed
                v-if="batchInfo?.prerequisiteUndoStartQuoting"
                @click="$emit('pageLoadingOn',null); breakBatch()"
                label="Re-nest"
                class="col-span-2"
                :fullWidth="true"
                :disabled="false"
            />

            <!-- All delivered (suggest mark as done) -->
            <div
                v-if="kanbanColumn === 'DELIVERED' && props.batchInfo.steelMerchantDeliveredButNoCertsYet"
                class="col-span-2 flex gap-2 rounded-lg border border-orange-200 bg-orange-50 p-2.5 text-xs leading-relaxed text-orange-800"
            >
                <i class="fa-solid fa-triangle-exclamation mt-0.5 flex-none text-orange-500"></i>
                <span>The steel merchant order has no attached material certs. This is required to keep all offcuts 100% traceable.</span>
            </div>

            <CardButtonForward
                v-if="kanbanColumn === 'DELIVERED' && allDelivered() && !props.batchInfo.steelMerchantDeliveredButNoCertsYet"
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
