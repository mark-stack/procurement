<script setup>
    //General Imports
    import {computed, ref} from "vue";
    import { useVueToPrint } from "vue-to-print";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import VisualNestingOffcuts from "@/Components/VisualNestingOffcuts.vue";
    import VisualNestingWithBars from "@/Components/VisualNestingWithBars.vue";
    import PrintingSpec from "@/Components/Nesting/PrintingSpec.vue";
    import PrintingHeader from "@/Components/Nesting/PrintingHeader.vue";

    //Props
    const props = defineProps({
        pieces: Object,
        projectsReadyForBatching: Object,
        piecesGroupedBySupplierGroup: Object,
        usage: Object,
        type: String,
        width: Number,
        lettersProjectArray: Object,
        redirect: String,
        batch: Object,
        newStockOrdersWithCertificates: Object,
        offcutOrdersWithCertificates: Object,
    });

    //Form
    //...

    //Shared data
    //...

    //Variables
    const printPreview = ref(true);
    const pageHeightPixels = getPageHeightPixels();
    const rowHeight = 86;

    //A nest with no meterage in it (bolts only, say) has nothing to report here
    const meterageUsage = computed(() => props.usage?.METERAGE ?? {
        totalPurchasedMaterial: 0,
        totalUsedMaterial: 0,
        totalReusable: 0,
        totalScrap: 0,
        efficiency: 0,
    });

    //Shared Methods
    //...

    //Methods
    const componentRef = ref();
    const { handlePrint } = useVueToPrint({
        content: componentRef,
        documentTitle: "AwesomeFileName",
    });

    function availableNestingHeight(){
        let pageMargin = 15;
        let header = 56;
        let margin1 = 20;
        let row1 = 112; //24 + 24 + (pieces.length * 20);
        let margin2 = 20;

        return pageHeightPixels - (pageMargin + header + margin1 + row1 + margin2);
    }

    function getPageHeightPixels() {
        const div = document.createElement("div");
        div.style.width = "1in";
        div.style.height = "1in";
        div.style.position = "absolute";
        div.style.top = "-100%"; // Hide it off-screen
        document.body.appendChild(div);

        const dpi = {
            x: div.offsetWidth,
            y: div.offsetHeight
        };

        document.body.removeChild(div);

        let height;
        switch (dpi.x) {
            case 72:
                height = 842;
                break;
            case 96:
                height = 1123;
                break;
            case 150:
                height = 1754;
                break;
            case 300:
                height = 3508;
                break;
            case 600:
                height = 7016;
                break;
            default:
                console.warn("Unsupported DPI. Using default formula.");
                height = Math.round(11.69 * dpi.y); //A4 is 11.69in tall
        }

        return height;
    }

    function rowsPerPage(){
        let fit = Math.floor((availableNestingHeight() - 28)/rowHeight);

        //Never zero or NaN, or a material would paginate into nothing at all
        return Number.isFinite(fit) && fit > 0 ? fit : 1;
    }

    function pagePlanning(item){
        /*
            Only meterage is cut from bars, so only meterage has drawings to paginate.
            Bundle and area materials have no 'bestResultOffcuts' at all.
         */
        if(item.algo !== 'METERAGE' || !item.nested || !item.nested.bestResultOffcuts){
            return [];
        }

        /*
            One flat list of rows, filled a page at a time.
            Paginating offcuts and new stock separately dropped every offcut page but the last, because
            each pass wrote over page one instead of starting a new page.
         */
        let rows = [
            ...Object.values(item.nested.bestResultOffcuts.utilisedOffcutBars ?? {}).map(row => ({type:'offcut', row})),
            ...Object.values(item.nested.utilisedBars ?? {}).map(row => ({type:'newStock', row})),
        ];

        let perPage = rowsPerPage();
        let pages = [];

        for(let index = 0; index < rows.length; index += perPage){
            let thisPage = rows.slice(index, index + perPage);

            pages.push({
                offcuts: thisPage.filter(entry => entry.type === 'offcut').map(entry => entry.row),
                newStock: thisPage.filter(entry => entry.type === 'newStock').map(entry => entry.row),
            });
        }

        return pages;
    }

    /*
        Every page to print, worked out once.
        The template used to call pagePlanning() twice for every page it drew.
     */
    const printPages = computed(() => {
        let pages = [];

        for(const batchGroup of Object.values(props.piecesGroupedBySupplierGroup.assigned)){
            for(const item of Object.values(batchGroup)){
                let itemPages = pagePlanning(item);

                itemPages.forEach((page, index) => {
                    pages.push({
                        item: item,
                        page: page,
                        index: index,
                        qty: itemPages.length,
                    });
                });
            }
        }

        return pages;
    });

    /*
        Materials with no cutting diagram: bolts and the like, plus anything that falls outside every
        supplier group this business buys from. They are on the order either way, so they are listed
        rather than left off the sheet.
     */
    const materialsWithoutDiagrams = computed(() => {
        let assigned = Object.values(props.piecesGroupedBySupplierGroup.assigned)
            .flatMap(batchGroup => Object.values(batchGroup))
            .filter(item => item.algo !== 'METERAGE');

        return [
            ...assigned,
            ...Object.values(props.piecesGroupedBySupplierGroup.unassigned ?? {}),
        ];
    });
</script>

<template>
    <AuthenticatedLayout>
        <div class="h-10"></div>
        <div class="text-center p-3">
            <button
                @click="handlePrint"
                class="bg-green-50 px-3 py-2 rounded border-2 border-green-200"
            >
                Print <i class="fa-solid fa-print"></i>
            </button>
            <p class="text-xs text-gray-700">
                Set printer to A4
            </p>
        </div>

        <div
            ref="componentRef"
            style="width: 21cm;"
            class="mx-auto mb-5"
        >
            <div
                :class="printPreview ? 'pagePrint' : 'pageDisplay'"
                size="A4"
            >
                <!-- Header -->
                <div class="grid grid-cols-3 pt-4 pb-5 pr-6 pl-6">
                    <div class="font-bold"></div>
                    <div class="text-center">
                        Batch: {{batch.id}}
                    </div>
                    <div class="text-right">
                        SteelNesting.com.au
                    </div>
                </div>

                <!-- Nesting summary -->
                <div class="text-center mt-3 pr-6 pl-6">
                    <h1 class="text-2xl font-bold">Nesting Summary</h1>
                </div>


                <!-- batch created by -->
                <div class="col-span-2 mt-5 pr-6 pl-6">
                    <h2><b>Batch generated by:</b> {{batch.user.name}} ({{batch.user.email}})</h2>
                </div>

                <!-- included projects -->
                <div class="col-span-2 mt-5 pr-6 pl-6">
                    <h2 class="font-bold">Included Projects:</h2>
                    <ul>
                        <li v-for="(project,index) in projectsReadyForBatching.data">
                            - Project '<b>{{lettersProjectArray[project.id]}}</b>': <i>{{project.name}}</i> ({{project.projectManager.name}}'s project)
                        </li>
                    </ul>
                </div>
                <!-- Usage stats-->
                <div class="col-span-3 mt-5 text-left pr-6 pl-6">
                    <table class="w-full">
                        <tr>
                            <th>Total Material</th>
                            <th>Total Used Material</th>
                            <th>Total Reusable</th>
                            <th>Total Scrap</th>
                            <th>Efficiency</th>
                        </tr>
                        <tr>
                            <td>{{ (meterageUsage.totalPurchasedMaterial/1000).toLocaleString() }}m</td>
                            <td>{{ (meterageUsage.totalUsedMaterial/1000).toLocaleString() }}m</td>
                            <td>{{ (meterageUsage.totalReusable/1000).toLocaleString() }}m</td>
                            <td>{{ (meterageUsage.totalScrap/1000).toLocaleString() }}m</td>
                            <td>{{ meterageUsage.efficiency }}%</td>
                        </tr>
                    </table>
                </div>

                <!-- certs -->
                <div class="mt-5 grid grid-cols-2 pr-6 pl-6">
                    <div>
                        <h2 class="font-bold">Material Certificates from new stock:</h2>
                        <ul v-if="newStockOrdersWithCertificates.length > 0">
                            <li v-for="certificate in newStockOrdersWithCertificates">
                                {{certificate.supplier?.name ?? 'Unknown supplier'}}: {{certificate.material_cert_numbers}}
                            </li>
                        </ul>
                        <p v-else>
                            No certificates yet
                        </p>
                    </div>
                    <div>
                        <h2 class="font-bold">Material Certificates from reused offcuts:</h2>
                        <template v-if="!offcutOrdersWithCertificates.used_offcuts">
                            This batch used no offcuts
                        </template>
                        <template v-else>
                            <ul v-if="offcutOrdersWithCertificates.certificates.length > 0">
                                <li v-for="certificate in offcutOrdersWithCertificates.certificates">
                                    {{certificate.supplier_name}}: {{certificate.material_cert_numbers}}
                                </li>
                            </ul>
                            <p v-else class="text-orange-700">
                                Offcuts are not traceable!
                            </p>
                        </template>
                    </div>
                </div>

                <!-- materials with no cutting diagram -->
                <div v-if="materialsWithoutDiagrams.length > 0" class="mt-5 pr-6 pl-6">
                    <h2 class="font-bold">Also on this batch (no cutting required):</h2>
                    <ul>
                        <li v-for="item in materialsWithoutDiagrams">
                            - {{item.product_derived_label ?? item.product_category}}
                            <span v-if="item.nested && item.nested.totalBought">
                                &mdash; {{item.nested.totalBought.toLocaleString()}} off
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- see below -->
                <div class="text-center p-24">
                    <p class="text-xl mb-3">
                        Cutting diagrams below
                    </p>
                    <i class="fa-regular fa-hand-point-down text-5xl"></i>
                </div>
            </div>

            <!-- 1 page per material. e.g 200PFC -->
            <div
                v-for="entry in printPages"
                :class="printPreview ? 'pagePrint' : 'pageDisplay'"
                size="A4"
            >
                <PrintingHeader
                    :item="entry.item"
                    :batch="batch"
                    :index="entry.index"
                    :qty="entry.qty"
                />

                <PrintingSpec
                    :item="entry.item"
                />

                <div
                    class="bg-white pl-4 pr-4"
                    :style="'height:'+(availableNestingHeight())+'px'"
                >
                    <!-- Nesting -->
                    <div class="col-span-4">
                        <!-- offcuts on this page -->
                        <div v-if="entry.page.offcuts.length > 0">
                            <h2 class="font-bold text-xl">Offcut usage</h2>
                            <VisualNestingOffcuts
                                v-for="offcut in entry.page.offcuts"
                                :offcut="offcut"
                                :measurementUnit="entry.item.nominal_units"
                                :batched="true"
                            />
                        </div>

                        <!-- new stock on this page -->
                        <template v-if="entry.page.newStock.length > 0">
                            <h2 class="font-bold text-xl">New stock usage</h2>
                            <VisualNestingWithBars
                                :utilisedBars="entry.page.newStock"
                                :measurementUnit="entry.item.nominal_units"
                                :batched="true"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <div class="text-center p-3 mb-10">
            <button
                @click="handlePrint"
                class="bg-green-50 px-3 py-2 rounded border-2 border-green-200"
            >
                Print <i class="fa-solid fa-print"></i>
            </button>
            <p class="text-xs text-gray-700">
                Set printer to A4
            </p>
        </div>
    </AuthenticatedLayout>
</template>

<style scoped>
    .pageDisplay {
        background: white;
        display: block;
        margin: 0 auto;
        margin-bottom: 0.5cm;
        box-shadow: 0 0 0.5cm rgba(0,0,0,0.5);
        width: 21cm;
        height: 29.7cm;
        padding: 15px;
    }
    .pagePrint {
        background: white;
        display: block;
        margin: 0;
        margin-bottom: 0;
        box-shadow: 0 0 0.5cm rgba(0,0,0,0.5);
        width: 21cm;
        height: 29.7cm;
        padding: 15px;
    }
</style>
