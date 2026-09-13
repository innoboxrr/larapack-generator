<template>

    <div id="AdminPluralPascalCaseModelNameWrapper">

        <!-- El detalle de un registro, y su edición, son una página propia. -->
        <RouterView v-if="isRecord" />

        <div v-else class="fe-section-sm">

            <Breadcrumbs :pages="breadcrumbs" />

            <DataTable ref="table" :hide-columns="hideColumns" />

            <!-- @larapack:if create -->
            <!-- El alta se abre encima de la tabla, que sigue ahí detrás con su
                 página, su orden y sus filtros: al cerrar, el usuario está
                 donde lo dejó. -->
            <DrawerComponent
                :open="isCreating"
                :title="t('Create :name', { name: t('SingularModelLabel') })"
                :close-label="t('Close')"
                @update:open="(open) => open || backToIndex()">
                <RouterView @update-data="onCreated" />
            </DrawerComponent>
            <!-- @larapack:endif -->

            <CommandPaletteComponent
                v-model:open="paletteOpen"
                :items="commands"
                :placeholder="t('Search a command')"
                :empty-text="t('No results')"
                :label="t('Command palette')" />

        </div>

    </div>

</template>

<script setup>

    import { computed, ref } from 'vue'
    import { RouterView, useRoute, useRouter } from 'vue-router'
    import t from 'innoboxrr-i18n'
    import { CommandPaletteComponent } from 'innoboxrr-form-elements'
    // @larapack:if create
    import { DrawerComponent } from 'innoboxrr-form-elements'
    // @larapack:endif
    // @larapack:if create|export
    import { notifySuccess } from 'innoboxrr-form-core'
    // @larapack:endif
    // @larapack:if export
    import { notifyError } from 'innoboxrr-form-core'
    import { RequestCancelledError } from 'innoboxrr-http-request'
    import { exportModel } from '../index'
    // @larapack:endif

    import DataTable from '../widgets/DataTable.vue'

    import Breadcrumbs from '../../../components/Breadcrumbs.vue'

    const route = useRoute()
    const router = useRouter()

    // La tabla se recarga en su sitio. Antes se la remontaba cambiándole la
    // key, y eso la devolvía a la primera página y sin filtros.
    const table = ref(null)

    const paletteOpen = ref(false)

    const isRecord = computed(() => [
        'AdminShowPascalCaseModelName',
        'AdminEditPascalCaseModelName',
    ].includes(route.name))

    // @larapack:if create
    const isCreating = computed(() => route.name === 'AdminCreatePascalCaseModelName')

    // @larapack:endif
    const hideColumns = computed(() => [])

    const backToIndex = () => router.push({ name: 'AdminPluralPascalCaseModelName' })

    // @larapack:if create
    const onCreated = () => {

        notifySuccess(t('Record created'))

        backToIndex()

        table.value?.refresh()

    }

    // @larapack:endif
    // @larapack:if export
    // La misma exportación que la barra de la tabla, desde la paleta.
    const requestExport = async () => {

        try {
            await exportModel()
        } catch (error) {
            if (! (error instanceof RequestCancelledError)) {
                notifyError(error?.response?.data?.message ?? t('The export could not be generated.'))
            }

            return
        }

        notifySuccess(t('The export is being prepared. You will be notified when it is ready.'))

    }

    // @larapack:endif
    // Ctrl+K o Cmd+K, desde cualquier sitio del índice.
    const commands = computed(() => [
        // @larapack:if create
        {
            id: 'create',
            label: t('Create :name', { name: t('SingularModelLabel') }),
            group: t('PluralModelLabel'),
            icon: 'plus',
            action: () => router.push({ name: 'AdminCreatePascalCaseModelName' }),
        },
        // @larapack:endif
        // @larapack:if export
        {
            id: 'export',
            label: t('Export'),
            group: t('PluralModelLabel'),
            icon: 'download',
            action: requestExport,
        },
        // @larapack:endif
        {
            id: 'refresh',
            label: t('Refresh'),
            group: t('PluralModelLabel'),
            icon: 'refresh',
            action: () => table.value?.refresh(),
        },
    ])

    const breadcrumbs = computed(() => [
        {
            link: router.resolve({ name: 'AdminPluralPascalCaseModelName' }).fullPath,
            title: t('PluralModelLabel'),
        },
    ])

</script>
