<template>

    <DataTable
        title="PascalCaseModelName"
        :data-url="dataUrl"
        data-method="get"
        :policy-url="policyUrl"
        policy-method="get"
        :model="model"
        :external-filters="mergedExternalFilters"
        :form-filters="formFilters"
        :extra-params="extraParams"
        :hide-columns="hideColumns"
        :card-wrapper="cardWrapper"
        :show-topbar="showTopbar"
        :show-title="showTitle"
        :has-actions="hasActions"
        :has-filter="hasFilter">

        <template #filterForm>
            <FilterForm @submit="updateFormFilters" />
        </template>

    </DataTable>

</template>

<script setup>

    import { computed, ref } from 'vue'
    import DataTable from 'innoboxrr-vue-datatable'
    import route from 'innoboxrr-route-resolver'

    import FilterForm from '../forms/FilterForm.vue'
    import * as model from '../index'

    const props = defineProps({
        showTopbar: {
            type: Boolean,
            default: true,
        },
        showTitle: {
            type: Boolean,
            default: true,
        },
        showBreadcrumb: {
            type: Boolean,
            default: false,
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

</script>
