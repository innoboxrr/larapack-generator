import { useMemo, useState } from 'react'
import DataTable from 'innoboxrr-react-datatable'
import { ClickToEditComponent } from 'innoboxrr-react-form-elements'
import route from 'innoboxrr-route-resolver'

import { tableLabels } from '../../../i18n.js'
import FilterForm from '../forms/FilterForm.jsx'
import * as model from '../index'

// La celda da `save`, el nombre del contrato compartido con Vue; el componente
// de React lo llama `onSave`.
const ClickToEdit = ({ save, ...props }) => <ClickToEditComponent {...props} onSave={save} />

// Las columnas que se editan en su celda piden `component: 'ClickToEdit'`.
// El contrato no importa el componente porque es el mismo para Vue y para
// React: se lo pone aquí cada framework.
const tableModel = {
    ...model,
    dataTableComponents: () => ({ ClickToEdit }),
}

// Las casillas sólo salen si hay algo que hacer con lo seleccionado.
const selectable = model.bulkActions().length > 0

/**
 * `ref` llega hasta la tabla (React 19 lo pasa como prop): quien la monta la
 * recarga con `ref.current.refresh()` tras un alta, sin remontarla, así que
 * conserva la página, el orden y los filtros.
 */
export default function PascalCaseModelNameDataTable({
    ref = null,
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
            ref={ref}
            dataUrl={dataUrl}
            dataMethod="get"
            policyUrl={policyUrl}
            policyMethod="get"
            model={tableModel}
            selectable={selectable}
            externalFilters={mergedExternalFilters}
            formFilters={formFilters}
            extraParams={extraParams}
            hideColumns={hideColumns}
            cardWrapper={cardWrapper}
            showTopbar={showTopbar}
            hasActions={hasActions}
            hasFilter={hasFilter}
            labels={tableLabels()}
            filterForm={<FilterForm onSubmit={setFormFilters} />} />
    )
}
