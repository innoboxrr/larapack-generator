<template>
    <div class="bg-white dark:bg-gray-800 border rounded-lg overflow-hidden border dark:border-gray-700">
        <!-- Dropdown Actions -->
        <div class="flex justify-end p-4">
            <dropdown-button-component
                :items="[
                    {
                        type: 'router',
                        to: {
                            name: 'AdminShowPascalCaseModelName',
                            params: { id: camelCaseModelName.id },
                        },
                        label: 'Mostrar',
                    },
                    {
                        type: 'router',
                        to: {
                            name: 'AdminEditPascalCaseModelName',
                            params: { id: camelCaseModelName.id },
                        },
                        label: 'Editar',
                    },
                    {
                        type: 'event',
                        action: deletePascalCaseModelName,
                        label: 'Eliminar',
                    },
                ]"
            />
        </div>

        <!-- Card Content -->
        <div class="flex flex-col items-center px-6 pb-8">
            <!-- Avatar / Icon Placeholder -->
            <div
                class="w-28 h-28 mb-4 rounded-full shadow-md flex items-center justify-center bg-gray-200 dark:bg-gray-600 text-gray-400 text-2xl">
                <i class="fas fa-box"></i>
            </div>

            <h5 class="text-lg font-semibold text-gray-900 dark:text-white text-center">
                Título de la tarjeta
            </h5>

            <!-- Action Buttons -->
            <div class="mt-6 flex space-x-4" v-if="false">
                <a
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-blue-600 rounded-lg hover:bg-blue-700 focus:ring-4 focus:ring-blue-300 dark:bg-blue-700 dark:hover:bg-blue-800 dark:focus:ring-blue-800 hover:text-white">
                    Botón A
                </a>
                <a
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-gray-900 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 focus:ring-4 focus:ring-gray-200 dark:bg-gray-800 dark:text-white dark:border-gray-600 dark:hover:bg-gray-700 dark:hover:border-gray-700 dark:focus:ring-gray-700">
                    Botón B
                </a>
            </div>
        </div>
    </div>
</template>

<script>
import { deleteModel } from '@models/kebabcasemodelname';
export default {
    props: {
        camelCaseModelName: {
            type: Object,
            required: false,
        },
        camelCaseModelNameId: {
            type: [Number, String],
            required: false
        }
    },
    created() {
        if (!this.camelCaseModelName && !this.camelCaseModelNameId) {
            console.error("Se debe proporcionar 'camelCaseModelName' o 'camelCaseModelNameId'.");
        }
    },
    methods: {
        deletePascalCaseModelName() {
            deleteModel(this.camelCaseModelName).then(() => {
                this.$router.push({ name: 'AdminPluralPascalCaseModelName' });
            });
        }
    }
}
</script>
