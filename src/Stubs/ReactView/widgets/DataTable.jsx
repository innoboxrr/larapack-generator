import { useMemo, useState } from 'react'
import DataTable from 'innoboxrr-react-datatable'
import route from 'innoboxrr-route-resolver'

import FilterForm from '../forms/FilterForm.jsx'
import * as model from '../index'

export default function PascalCaseModelNameDataTable({
    showTopbar = true,
    hasActions = true,
    hasFilter = true,
    externalFilters = {},
    extraParams = {},
    hideColumns = [],
    cardWrapper = true,
}) {
    const [formFilters, setFormFilters] = useState({})

    const dataUrl = route(`${model.API_ROUTE_PREFIX}index`)
    const policyUrl = route(`${model.API_ROUTE_PREFIX}policies`)

    const mergedExternalFilters = useMemo(() => ({
        ...externalFilters,
        // Filtros propios del widget
    }), [externalFilters])

    return (
        <DataTable
            dataUrl={dataUrl}
            dataMethod="get"
            policyUrl={policyUrl}
            policyMethod="get"
            model={model}
            externalFilters={mergedExternalFilters}
            formFilters={formFilters}
            extraParams={extraParams}
            hideColumns={hideColumns}
            cardWrapper={cardWrapper}
            showTopbar={showTopbar}
            hasActions={hasActions}
            hasFilter={hasFilter}
            filterForm={<FilterForm onSubmit={setFormFilters} />} />
    )
}
