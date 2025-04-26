<script setup>
    //General imports
    import { ref } from 'vue'

    //Variables
    const logos = ref([
        {
            name: 'Autodesk Inventor',
            src: 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQGpEZAq9fZ1zDypMMAxyX_7Mbk26OQZO1dVw&s',
        },
        {
            name: 'Advance Steel',
            src: 'https://financesonline.com/uploads/2019/11/Autodesk-Advanced-Steel-logo1.png',
        },
        {
            name: 'Tekla',
            src: 'https://static.tildacdn.com/tild3466-3234-4564-a365-323036653236/tekla-vector-logo1.png',
        },
        {
            name: 'ProSteel',
            src: "https://www.reisoftwareth.com/imgadmins/source/ProStructures_logo_1.png?1602738750645",
        },
        {
            name: "Revit",
            src: "https://logonoid.com/images/revit-logo.png",
        },
        {
            name: "StruCAD",
            src: "https://konohasteel.com/images/software/strucad.png",
        }
    ])
</script>

<template>
    <div class="grid grid-cols-3 md:grid-cols-6 justify-center gap-8 p-4 bg-white shadow-md rounded-2xl">
        <div
            v-for="logo in logos"
            :key="logo.name"
            class="flex items-center justify-center"
        >
            <img
                :src="logo.src"
                :alt="logo.name"
                class="h-12 object-contain max-w-[120px] transition-transform duration-300 hover:scale-105"
            />
        </div>
    </div>
</template>
