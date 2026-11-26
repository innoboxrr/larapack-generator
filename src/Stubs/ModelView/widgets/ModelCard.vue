<template>

    <section class="fe-card">

        <div class="fe-flex fe-justify-end fe-p-sm">
            <ActionMenu :items="actions" :label="t('Actions')" />
        </div>

        <div class="fe-card-body fe-text-center">

            <IconComponent name="box" :size="48" />

            <h2 class="fe-mt-sm">
                {{ camelCaseModelName?.displayPropName ?? t('SingularModelLabel') }}
            </h2>

        </div>

    </section>

</template>

<script setup>

    import { computed } from 'vue'
    // @larapack:if record_actions
    import { ref, watch } from 'vue'
    // @larapack:endif
    // @larapack:if delete|record_actions
    import { useRouter } from 'vue-router'
    import { notifyError, notifySuccess } from 'innoboxrr-form-core'
    // @larapack:endif
    import t from 'innoboxrr-i18n'

    import { IconComponent } from 'innoboxrr-form-elements'

    import ActionMenu from '../../../components/ActionMenu.vue'
    // @larapack:if delete|record_actions
    import { usePascalCaseModelNameStore } from '../store'
    // @larapack:endif
    // @larapack:if record_actions
    import * as model from '../index'
    // @larapack:endif

    const props = defineProps({
        camelCaseModelName: {
            type: Object,
            required: true,
        },
    })

    // @larapack:if delete|record_actions
    const router = useRouter()

    const store = usePascalCaseModelNameStore()

    // @larapack:endif
    // @larapack:if delete
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
    // @larapack:if record_actions
    // Los endpoints declarados sobre el registro (recordActions() del
    // contrato). Se pregunta a la política por este registro, como hace la
    // tabla con cada fila: lo que no se puede hacer se ve deshabilitado y dice
    // por qué.
    const allowed = ref({})

    watch(() => props.camelCaseModelName.id, async (id) => {

        try {
            allowed.value = await model.getPolicies(id) ?? {}
        } catch {
            allowed.value = {}
        }

    }, { immediate: true })

    const runEndpoint = async (action) => {

        const id = props.camelCaseModelName.id

        try {
            // La función del contrato pregunta antes de llamar.
            await model[action.callback]({ id })
        } catch (error) {
            if (error?.name !== 'RequestCancelledError') {
                notifyError(error?.response?.data?.message ?? t('The action could not be completed.'))
            }

            return
        }

        notifySuccess(action.success)

        // El endpoint puede haber cambiado el registro, o haberlo quitado.
        try {
            await store.fetchOne(id)
        } catch {
            router.push({ name: 'AdminPluralPascalCaseModelName' })
        }

    }

    const endpointItems = computed(() => model.recordActions().map((action) => ({
        type: 'event',
        id: action.id,
        action: () => runEndpoint(action),
        label: action.name,
        icon: action.icon,
        danger: action.danger === true,
        disabled: allowed.value[action.id] !== true,
        disabledReason: t('You are not allowed to do this.'),
    })))

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
        // @larapack:if record_actions
        ...endpointItems.value,
        // @larapack:endif
    ])

</script>
