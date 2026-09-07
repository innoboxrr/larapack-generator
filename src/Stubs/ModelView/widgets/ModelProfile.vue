<template>

    <div v-if="record" class="space-y-6">

        <div class="border rounded-xl shadow-sm p-6 bg-white dark:bg-gray-800 dark:border-gray-700">

            <div class="flex justify-between items-center border-b pb-4 mb-6 dark:border-gray-600">

                <div>
                    <h2 class="text-2xl font-semibold text-gray-900 dark:text-white">
                        {{ t('Details') }}
                    </h2>
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ t('Additional information') }}
                    </p>
                </div>

                <span class="text-xs px-3 py-1 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                    ID: {{ record.id }}
                </span>

            </div>

            <dl class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                <div>
                    <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">
                        {{ t('Created at') }}
                    </dt>
                    <dd class="mt-1 text-sm text-gray-900 dark:text-white">
                        {{ record.created_at }}
                    </dd>
                </div>
                <!-- Agrega mas campos aqui segun sea necesario -->
            </dl>

        </div>

    </div>

</template>

<script setup>

    import { computed, onMounted, ref } from 'vue'
    import t from 'innoboxrr-i18n'

    import { showModel } from '../index'

    const props = defineProps({
        camelCaseModelName: {
            type: Object,
            default: null,
        },
        camelCaseModelNameId: {
            type: [Number, String],
            default: null,
        },
    })

    // La version anterior asignaba el resultado sobre el prop, lo que Vue
    // prohibe: el dato cargado vive en un ref propio.
    const fetched = ref(null)

    const record = computed(() => props.camelCaseModelName ?? fetched.value)

    onMounted(async () => {

        if (props.camelCaseModelName || ! props.camelCaseModelNameId) {
            return
        }

        fetched.value = await showModel(props.camelCaseModelNameId)

    })

</script>
