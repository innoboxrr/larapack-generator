<template>

    <div class="bg-white dark:bg-gray-800 border rounded-lg overflow-hidden dark:border-gray-700">

        <div class="flex justify-end p-4">
            <ActionMenu :items="actions" />
        </div>

        <div class="flex flex-col items-center px-6 pb-8">

            <div class="w-28 h-28 mb-4 rounded-full shadow-md flex items-center justify-center bg-gray-200 dark:bg-gray-600 text-gray-400 text-2xl">
                <IconComponent name="box" :size="32" />
            </div>

            <h5 class="text-lg font-semibold text-gray-900 dark:text-white text-center">
                {{ camelCaseModelName?.name ?? 'PascalCaseModelName' }}
            </h5>

        </div>

    </div>

</template>

<script setup>

    import { computed } from 'vue'
    // @larapack:if delete
    import { useRouter } from 'vue-router'
    // @larapack:endif
    import t from 'innoboxrr-i18n'

    import { IconComponent } from 'innoboxrr-form-elements'

    import ActionMenu from '../../../components/ActionMenu.vue'
    // @larapack:if delete
    import { usePascalCaseModelNameStore } from '../store'
    // @larapack:endif

    const props = defineProps({
        camelCaseModelName: {
            type: Object,
            required: true,
        },
    })

    // @larapack:if delete
    const router = useRouter()

    // @larapack:endif
    // @larapack:if delete
    const store = usePascalCaseModelNameStore()

    // @larapack:endif
    // @larapack:if delete
    const remove = async () => {

        await store.remove(props.camelCaseModelName.id)

        router.push({ name: 'AdminPluralPascalCaseModelName' })

    }

    // @larapack:endif
    const actions = computed(() => [
        {
            type: 'router',
            to: {
                name: 'AdminShowPascalCaseModelName',
                params: { id: props.camelCaseModelName.id },
            },
            label: t('Show'),
        },
        // @larapack:if update
        {
            type: 'router',
            to: {
                name: 'AdminEditPascalCaseModelName',
                params: { id: props.camelCaseModelName.id },
            },
            label: t('Edit'),
        },
        // @larapack:endif
        // @larapack:if delete
        {
            type: 'event',
            action: remove,
            label: t('Delete'),
            danger: true,
        },
        // @larapack:endif
    ])

</script>
