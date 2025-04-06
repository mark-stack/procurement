<script setup>
    //General Imports
    import {ref} from "vue";
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
                height = Math.round(11.69 * dpi); // fallback calculation
        }

        return height;
    }

    function pagePlanning(item){
        //Quantities
        let offcuts = Object.values(item.nested.bestResultOffcuts.utilisedOffcutBars);
        let newStock = Object.values(item.nested.utilisedBars);

        //Heights
        let heading = 28;
        let availableHeightForRows = availableNestingHeight() - heading;
        let lastPageAvailableHeight = availableHeightForRows;
        let rowsPerPage = Math.floor(availableHeightForRows/86);

        //Placement
        let currentPageNumber = 1;
        let pagePlanning = [];

        /*
            Has offcuts?
         */
        if(offcuts.length > 0){
            let index = 0;
            while (index < offcuts.length) {
                let thisPage = pagePlanning[currentPageNumber-1];
                if(thisPage){
                    thisPage.offcuts = offcuts.slice(index, index + rowsPerPage);
                }
                else{
                    pagePlanning.push(
                        {
                            offcuts:offcuts.slice(index, index + rowsPerPage)
                        }
                    );
                }

                //Increment page
                index += rowsPerPage;

                //Last page (overwrites)
                lastPageAvailableHeight = availableHeightForRows - (offcuts.length * 86);
            }
        }

        /*
            Has new stock?
         */
        if(newStock.length > 0){
            let index = 0;

            // First page
            let qtyFitThisPage = Math.floor(lastPageAvailableHeight/86);

            if(qtyFitThisPage > 0){

                //Has a page #1
                let relativeFirstPage = pagePlanning[currentPageNumber - 1];
                if(relativeFirstPage){
                    relativeFirstPage.newStock = newStock.slice(index, index + qtyFitThisPage);
                }
                //Need to create page #1
                else{
                    pagePlanning.push(
                        {
                            offcuts:[],
                            newStock:newStock.slice(index, index + qtyFitThisPage),
                        }
                    );
                }

                //Increment page
                index += qtyFitThisPage;
                currentPageNumber++;
            }

            //Remaining pages
            while (index < newStock.length) {
                let thisPage = pagePlanning[currentPageNumber - 1];
                if(thisPage){
                    thisPage.newStock = newStock.slice(index, index + rowsPerPage);
                }
                else{
                    pagePlanning.push(
                        {
                            offcuts:[],
                            newStock:newStock.slice(index, index + rowsPerPage),
                        }
                    );
                }

                //Increment page
                index += rowsPerPage;
                currentPageNumber++;
            }
        }

        return pagePlanning;
    }
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
                            <td>{{ (usage.METERAGE.totalPurchasedMaterial/1000).toLocaleString() }}m</td>
                            <td>{{ (usage.METERAGE.totalUsedMaterial/1000).toLocaleString() }}m</td>
                            <td>{{ (usage.METERAGE.totalReusable/1000).toLocaleString() }}m</td>
                            <td>{{ (usage.METERAGE.totalScrap/1000).toLocaleString() }}m</td>
                            <td>{{ usage.METERAGE.efficiency }}%</td>
                        </tr>
                    </table>
                </div>

                <!-- certs -->
                <div class="mt-5 grid grid-cols-2 pr-6 pl-6">
                    <div>
                        <h2 class="font-bold">Material Certificates from new stock:</h2>
                        <ul v-if="newStockOrdersWithCertificates.length > 0">
                            <li v-for="certificate in newStockOrdersWithCertificates">
                                {{certificate.supplier.name}}: {{certificate.material_cert_numbers}}
                            </li>
                        </ul>
                        <p v-else>
                            No certificates yet
                        </p>
                    </div>
                    <div>
                        <h2 class="font-bold">Material Certificates from reused offcuts:</h2>
                        <template v-if="offcutOrdersWithCertificates === 'NO_OFFCUTS'">
                            This batch sued no offcuts
                        </template>
                        <template v-else>
                            <ul v-if="Object.values(offcutOrdersWithCertificates).length > 0">
                                <li v-for="(material_cert_numbers,supplierName) in offcutOrdersWithCertificates">
                                    {{supplierName}}: {{material_cert_numbers}}
                                </li>
                            </ul>
                            <p v-else class="text-orange-700">
                                Offcuts are not traceable!
                            </p>
                        </template>
                    </div>
                </div>

                <!-- see below -->
                <div class="text-center p-36">
                    <p class="text-xl mb-3">
                        Cutting diagrams below
                    </p>
                    <i class="fa-regular fa-hand-point-down text-5xl"></i>
                </div>
            </div>

            <template v-for="(batchGroup,batchLabel) in piecesGroupedBySupplierGroup.assigned">
                <div class="grid grid-cols-1">
                    <div v-for="item in batchGroup">
                        <!-- 1st page of material. e.g 200PFC -->
                        <div
                            v-for="(page,index) in pagePlanning(item)"
                            :class="printPreview ? 'pagePrint' : 'pageDisplay'"
                            size="A4"
                        >
                            <PrintingHeader
                                :item="item"
                                :batch="batch"
                                :index="index"
                                :qty="pagePlanning(item).length"
                            />

                            <PrintingSpec
                                :item="item"
                            />

                            <div
                                class="bg-white pl-4 pr-4"
                                :style="'height:'+(availableNestingHeight())+'px'"
                            >
                                <!-- Nesting -->
                                <div class="col-span-4">
                                    <!-- Nesting algorithm: meterage -->
                                    <div v-if="item.algo === 'METERAGE'">
                                        <!-- offcuts -->
                                        <div v-if="Object.values(item.nested.bestResultOffcuts.utilisedOffcutBars).length > 0">
                                            <h2 class="font-bold text-xl">Offcut usage</h2>
                                            <VisualNestingOffcuts
                                                v-for="offcut in page.offcuts"
                                                :offcut="offcut"
                                                :measurementUnit="item.nominal_units"
                                            />
                                        </div>

                                        <!-- New Stock -->
                                        <template v-if="Object.values(item.nested.utilisedBars).length > 0">
                                            <h2 class="font-bold text-xl">New stock usage</h2>
                                            <!-- new stock nesting -->
                                            <VisualNestingWithBars
                                                :utilisedBars="page.newStock"
                                                :measurementUnit="item.nominal_units"
                                            />
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>
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
