<script setup>
    //General Imports
    import {computed, ref} from "vue";
    import {useForm, usePage, router} from "@inertiajs/vue3";

    //Component Imports
    import Modal from "@/Layouts/Modal.vue";

    //Props
    const props = defineProps({
        width: String,
        project: Object,
        bomData: Object,
    });

    //Forms
    const formStore = useForm({
        excel: null,
    });
    let formClarifications = useForm(Object.assign({}, props.partialProductMatches, {deletedIds:[]}));
    let formCustomisations = useForm(Object.assign({}, props.requiresCustom, {deletedIds:[]}));
    // const formDownload = useForm({
    //     //
    // });

    //Shared data
    const warning = computed(() => usePage().props.flash.warning);
    // const downloadedData = computed(() => usePage().props.flash.downloadedData);

    //Variables
    const emit = defineEmits(['closeModalOnSuccess']);
    const isDragging = ref(false);
    const uploading = ref(false);
    const fileInput = ref(null);
    //const allChecked = ref(false); //todo
    //const showClarifications = ref(hasClarifications()); //todo
    //const showUserCustomProducts = ref(hasUserCustomProducts()); //todo
    const isAdmin = usePage().props.auth.isAdmin;
    let downloaded = [];

    //Shared Methods
    //

    //Methods
    const triggerFileInput = () => {
        fileInput.value.click();
    };

    const handleDragOver = () => {
        isDragging.value = true;
    };

    const handleDragLeave = () => {
        isDragging.value = false;
    };

    const handleDrop = (event) => {
        isDragging.value = false;
        const file = event.dataTransfer.files[0];
        if(file){
            formStore.excel = file;
            processFile(file);
        }
    };

    function processFile(file){
        let allowedFileTypes = [
            "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
        ];
        if (!allowedFileTypes.includes(file.type)) {
            alert('Please upload a valid Excel file.');
            return;
        }

        let url = route("products.store",props.project.id);

        formStore.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                uploading.value = false;

                clearFileInput();

                //Clarifications
                //todo
                // formClarifications = useForm(Object.assign({}, props.partialProductMatches, {deletedIds:[]}));
                // console.log("after store",formClarifications.deletedIds);
                // if(props.partialProductMatches.length > 0){
                //     showClarifications.value = true;
                // }
                // else{
                //     showUserCustomProducts.value = true;
                // }
            },
            onError: errors => {
                console.log('errors',errors);
                uploading.value = false;
                clearFileInput();
            },
        });

        uploading.value = true;
    }

    function clearFileInput() {
        const fileInput = document.getElementById("dropzone-file");
        if(fileInput){
            fileInput.value = ""; // Clear the file input
            console.log("File input cleared!");
        }
    }

    function handleFileSelect(){
        const file = formStore.excel;
        if(file){
            processFile(file);
        }
    }

    function hasClarifications(){
        return props.partialProductMatches.length > 0;
    }

    function thisDownloadedBomData(bomData){
        let data = null;

        if(bomData){
            let rawData = Object.values(bomData).find(item => item.project_id == props.project.id);
            if(rawData){
                data = rawData.data;
            }
        }

        return data;
    }

    // function download(){
    //     let url = route("download",props.project.id);
    //
    //     formDownload.post(url, {
    //         preserveScroll: true,
    //         onSuccess: () => {
    //             console.log('success');
    //             if(downloadedData.value){
    //                 console.log("downloadedData",downloadedData.value);
    //             }
    //         },
    //         onError: errors => {
    //             console.log('errors',errors);
    //         },
    //     });
    // }
</script>

<template>
    <Modal>
        <div :style="'width:'+width+'px'">

            <div class="dark:bg-gray-900 rounded-xl">
                <div class="px-6 pt-4 pb-4 mx-auto text-center">
                    <h1 class="text-3xl font-semibold text-gray-800 dark:text-gray-100">
                        Bill of Materials
                    </h1>


                    <div class="mx-auto max-w-5xl mt-2">
                        <div>
                            <div
                                id="dropzone"
                                @click="triggerFileInput"
                                @dragover.prevent="handleDragOver"
                                @dragleave="handleDragLeave"
                                @drop.prevent="handleDrop"
                                class="bg-white"
                                :style="(isDragging ? 'border-color: #00f;color: #00f;' : 'color: #aaa;') + (formStore.processing ? 'pointer-events: none;' : '')"
                            >
                                <div class="w-full mx-auto text-center">
                                    <div v-if="uploading" class="text-green-500">
                                        <div class="flex gap-x-2 justify-center load-6">
                                            <div class="letter-holder">
                                                <div class="l-1 letter">I</div>
                                                <div class="l-2 letter">m</div>
                                                <div class="l-3 letter">p</div>
                                                <div class="l-4 letter">o</div>
                                                <div class="l-5 letter">r</div>
                                                <div class="l-6 letter">t</div>
                                                <div class="l-7 letter">i</div>
                                                <div class="l-8 letter">n</div>
                                                <div class="l-9 letter">g</div>
                                                <div class="l-10 letter">.</div>
                                                <div class="l-11 letter">.</div>
                                                <div class="l-12 letter">.</div>
                                            </div>
                                            <div>
                                                it can take 10 seconds or so
                                            </div>
                                        </div>
                                    </div>
                                    <div v-else>
                                        {{isDragging ? 'Drop it here!' : 'Material list in Excel format: Click to upload, or drag & drop here'}}
                                    </div>
                                </div>
                            </div>
                            <input
                                id="dropzone-file"
                                type="file"
                                ref="fileInput"
                                accept=".xlsx,.xls"
                                @input="formStore.excel = $event.target.files[0]; handleFileSelect()"
                                hidden
                            />
                        </div>


                        <div v-if="warning" class="text-center text-orange-500 mt-2">
                            {{warning}}
                        </div>
                    </div>



                    <!-- download data -->
                    {{thisDownloadedBomData(bomData) ?? 'Loading...'}}
                </div>
            </div>
        </div>
    </Modal>
</template>

<style scoped>
#dropzone {
    width: 100%;
    height: 100px;
    border: 2px dashed #ccc;
    border-radius: 10px;
    text-align: center;
    line-height: 100px;
    /*color: #aaa;*/
    cursor: pointer;
    transition: border-color 0.3s;
}

/*
#dropzone.drop-active {
    border-color: #00f;
    color: #00f;
}
*/

button {
    margin-top: 10px;
}


/* -----------------------------------------
  Loader
-------------------------------------------- */

.load-wrapp p {
    padding: 0 0 20px;
}

.letter {
    float: left;
    font-size: 16px;
}

.load-6 .letter {
    animation-name: loadingF;
    animation-duration: 1.6s;
    animation-iteration-count: infinite;
    animation-direction: linear;
}

.l-1 {
    animation-delay: 0.48s;
}
.l-2 {
    animation-delay: 0.6s;
}
.l-3 {
    animation-delay: 0.72s;
}
.l-4 {
    animation-delay: 0.84s;
}
.l-5 {
    animation-delay: 0.96s;
}
.l-6 {
    animation-delay: 1.08s;
}
.l-7 {
    animation-delay: 1.2s;
}
.l-8 {
    animation-delay: 1.32s;
}
.l-9 {
    animation-delay: 1.44s;
}
.l-10 {
    animation-delay: 1.56s;
}
.l-11 {
    animation-delay: 1.56s;
}
.l-12 {
    animation-delay: 1.56s;
}

@keyframes loadingA {
0 {
    height: 15px;
}
50% {
    height: 35px;
}
100% {
    height: 15px;
}
}

@keyframes loadingB {
0 {
    width: 15px;
}
50% {
    width: 35px;
}
100% {
    width: 15px;
}
}

@keyframes loadingC {
0 {
    transform: translate(0, 0);
}
50% {
    transform: translate(0, 15px);
}
100% {
    transform: translate(0, 0);
}
}

@keyframes loadingD {
0 {
    transform: rotate(0deg);
}
50% {
    transform: rotate(180deg);
}
100% {
    transform: rotate(360deg);
}
}

@keyframes loadingE {
0 {
    transform: rotate(0deg);
}
100% {
    transform: rotate(360deg);
}
}

@keyframes loadingF {
    0% {
        opacity: 0;
    }
    100% {
        opacity: 1;
    }
}

@keyframes loadingG {
    0% {
        transform: translate(0, 0) rotate(0deg);
    }
    50% {
        transform: translate(70px, 0) rotate(360deg);
    }
    100% {
        transform: translate(0, 0) rotate(0deg);
    }
}

@keyframes loadingH {
    0% {
        width: 15px;
    }
    50% {
        width: 35px;
        padding: 4px;
    }
    100% {
        width: 15px;
    }
}

@keyframes loadingI {
    100% {
        transform: rotate(360deg);
    }
}

@keyframes bounce {
    0%,
    100% {
        transform: scale(0);
    }
    50% {
        transform: scale(1);
    }
}

@keyframes loadingJ {
    0%,
    100% {
        transform: translate(0, 0);
    }

    50% {
        transform: translate(80px, 0);
        background-color: #f5634a;
        width: 25px;
    }
}
</style>
