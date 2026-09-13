<template>

    <section class="fe-card">

        <div class="fe-flex fe-justify-end fe-p-sm">
            <ActionMenu :items="actions" :label="t('Actions')" />
        </div>

        <div class="fe-card-body fe-text-center">

            <IconComponent name="box" :size="48" />

            <h2 class="fe-mt-sm">
                {{ camelCaseModelName?.name ?? t('SingularModelLabel') }}
            </h2>

        </div>

    </section>

</template>

<script setup>

    import { computed } from 'vue'
    // @larapack:if delete
    import { useRouter } from 'vue-router'
    import { notifyError, notifySuccess } from 'innoboxrr-form-core'
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

    const store = usePascalCaseModelNameStore()

    const remove = async () => {

        try {
            await store.remove(props.camelCaseModelName.id)
        } catch (error) {
            // Cancelar la confirmación no es un error que haya que contar.
            if (error?.name !== 'RequestCancelledError') {
                notifyError(error?.response?.data?.message ?? t('The item could not be deleted'))
            }

            return
        }

        notifySuccess(t('Record deleted'))

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
            icon: 'show',
        },
        // @larapack:if update
        {
            type: 'router',
            to: {
                name: 'AdminEditPascalCaseModelName',
                params: { id: props.camelCaseModelName.id },
            },
            label: t('Edit'),
            icon: 'edit',
        },
        // @larapack:endif
        // @larapack:if delete
        {
            type: 'event',
            action: remove,
            label: t('Delete'),
            icon: 'delete',
            danger: true,
        },
        // @larapack:endif
    ])

</script>
