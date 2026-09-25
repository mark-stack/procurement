<script setup>
    //General Imports
    import {ref} from "vue";
    import moment from "moment";

    //Component Imports
    import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout.vue";
    import Modal from "@/Layouts/Modal.vue";
    import Nesting from "@/Components/Nesting/Nesting.vue";

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
    });

    //Form
    //

    //Variables
    const currentSupplierGroup = ref(Object.keys(props.piecesGroupedBySupplierGroup.assigned)[0]);
    const height = window.innerHeight - 250;

    //Shared data

    //Methods
    //
</script>

<template>
    <AuthenticatedLayout>
        <Modal :fakeModal="true" :redirect="redirect" ariaLabel="Nesting">
            <Nesting
                :width="width"
                :projectsReadyForBatching="projectsReadyForBatching"
                :lettersProjectArray="lettersProjectArray"
                :usage="usage"
                :piecesGroupedBySupplierGroup="piecesGroupedBySupplierGroup"
                :currentSupplierGroup="currentSupplierGroup"
                :redirect="redirect"
                :batch="batch"
            />
        </Modal>
    </AuthenticatedLayout>
</template>

