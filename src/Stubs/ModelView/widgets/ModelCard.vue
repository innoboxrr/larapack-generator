<template>

    <div class="bg-white dark:bg-gray-800 border rounded-lg overflow-hidden dark:border-gray-700">

        <div class="flex justify-end p-4">
            <ActionMenu :items="actions" />
        </div>

        <div class="flex flex-col items-center px-6 pb-8">

            <div class="w-28 h-28 mb-4 rounded-full shadow-md flex items-center justify-center bg-gray-200 dark:bg-gray-600 text-gray-400 text-2xl">
                <i class="fas fa-box"></i>
            </div>

            <h5 class="text-lg font-semibold text-gray-900 dark:text-white text-center">
                {{ camelCaseModelName?.name ?? 'PascalCaseModelName' }}
            </h5>

        </div>

    </div>

</template>

<script setup>

    import { computed } from 'vue'
    import { useRouter } from 'vue-router'
    import t from 'innoboxrr-i18n'

    import ActionMenu from '../../../components/ActionMenu.vue'
    import { usePascalCaseModelNameStore } from '../store'

    const props = defineProps({
        camelCaseModelName: {
            type: Object,
            required: true,
        },
    })

    const router = useRouter()

    const store = usePascalCaseModelNameStore()

    const remove = async () => {

        await store.remove(props.camelCaseModelName.id)

        router.push({ name: 'AdminPluralPascalCaseModelName' })

    }

    const actions = computed(() => [
        {
            type: 'router',
            to: {
                name: 'AdminShowPascalCaseModelName',
                params: { id: props.camelCaseModelName.id },
            },
            label: t('Show'),
        },
        {
            type: 'router',
            to: {
                name: 'AdminEditPascalCaseModelName',
                params: { id: props.camelCaseModelName.id },
            },
            label: t('Edit'),
        },
        {
            type: 'event',
            action: remove,
            label: t('Delete'),
            danger: true,
        },
    ])

</script>
