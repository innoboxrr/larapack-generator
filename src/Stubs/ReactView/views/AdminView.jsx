import { useCallback, useState } from 'react'
import { Outlet, useMatches } from 'react-router-dom'

import DataTable from '../widgets/DataTable.jsx'

export default function AdminView() {
    const matches = useMatches()

    // El indice es la ruta hoja cuando no hay ninguna hija montada.
    const isIndex = matches.at(-1)?.id === 'AdminPluralPascalCaseModelName'

    // Forzar el remontaje del datatable es la forma de recargarlo tras una
    // alta o una baja hecha en una vista hija.
    const [crudKey, setCrudKey] = useState(0)

    const refresh = useCallback(() => setCrudKey((key) => key + 1), [])

    return (
        <div id="AdminPluralPascalCaseModelNameWrapper">

            {isIndex ? (
                <div className="uk-section uk-section-xsmall">
                    <DataTable key={crudKey} hideColumns={[]} />
                </div>
            ) : (
                <Outlet context={{ onUpdateData: refresh }} />
            )}

        </div>
    )
}
