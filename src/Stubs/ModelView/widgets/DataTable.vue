<template>

    <DataTable
        ref="table"
        :data-url="dataUrl"
        data-method="get"
        :policy-url="policyUrl"
        policy-method="get"
        :model="tableModel"
        :selectable="selectable"
        :external-filters="mergedExternalFilters"
        :form-filters="formFilters"
        :extra-params="extraParams"
        :hide-columns="hideColumns"
        :card-wrapper="cardWrapper"
        :show-topbar="showTopbar"
        :has-actions="hasActions"
        :has-filter="hasFilter"
        :labels="tableLabels()">

        <template #filterForm>
            <FilterForm @submit="updateFormFilters" />
        </template>

    </DataTable>

</template>

<script setup>

    import { computed, ref } from 'vue'
    import DataTable from 'innoboxrr-vue-datatable'
    import { ClickToEditComponent } from 'innoboxrr-form-elements'
    import route from 'innoboxrr-route-resolver'

    import { tableLabels } from '../../../i18n.js'
    import FilterForm from '../forms/FilterForm.vue'
    import * as model from '../index'

    // Las columnas que se editan en su celda piden `component: 'ClickToEdit'`.
    // El contrato no importa el componente porque es el mismo para Vue y para
    // React: se lo pone aquí cada framework.
    const tableModel = {
        ...model,
        dataTableComponents: () => ({ ClickToEdit: ClickToEditComponent }),
    }

    // Las casillas sólo salen si hay algo que hacer con lo seleccionado.
    const selectable = model.bulkActions().length > 0

    const props = defineProps({
        showTopbar: {
            type: Boolean,
            default: true,
        },
        hasActions: {
            type: Boolean,
            default: true,
        },
        hasFilter: {
            type: Boolean,
            default: true,
        },
        // Vue 3 exige factoria para los valores por defecto de objeto y array;
        // declararlos como literal comparte la misma instancia entre montajes.
        externalFilters: {
            type: Object,
            default: () => ({}),
        },
        extraParams: {
            type: Object,
            default: () => ({}),
        },
        hideColumns: {
            type: Array,
            default: () => [],
        },
        cardWrapper: {
            type: Boolean,
            default: true,
        },
    })

    const table = ref(null)

    const dataUrl = route(`${model.API_ROUTE_PREFIX}index`)
    const policyUrl = route(`${model.API_ROUTE_PREFIX}policies`)

    const formFilters = ref({})

    const updateFormFilters = (filters) => {
        formFilters.value = filters
    }

    const mergedExternalFilters = computed(() => ({
        ...props.externalFilters,
        // Filtros propios del widget
    }))

    // Quien monta la tabla la recarga tras un alta sin remontarla, así que
    // conserva la página, el orden y los filtros.
    defineExpose({
        refresh: () => table.value?.refresh(),
    })

</script>
