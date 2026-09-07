import { useCallback, useEffect } from 'react'
import { Outlet, useMatches, useParams } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

import Breadcrumbs from '../../../components/Breadcrumbs.jsx'
import ModelCard from '../widgets/ModelCard.jsx'
import ModelProfile from '../widgets/ModelProfile.jsx'
import { usePascalCaseModelNameStore } from '../store'

export default function ShowView() {
    const { id } = useParams()
    const matches = useMatches()

    const camelCaseModelName = usePascalCaseModelNameStore((state) => state.current)
    const fetchOne = usePascalCaseModelNameStore((state) => state.fetchOne)

    const isShowView = matches.at(-1)?.id === 'AdminShowPascalCaseModelName'

    const load = useCallback(async () => {
        const loaded = await fetchOne(id)

        document.title = loaded?.name ?? 'PascalCaseModelName'

        return loaded
    }, [id, fetchOne])

    // Navegar de un registro a otro sin desmontar la vista tiene que recargar,
    // de ahi que `id` este en las dependencias y no sea una carga unica.
    useEffect(() => {
        load()
    }, [load])

    if (! camelCaseModelName) {
        return null
    }

    const breadcrumbs = [
        {
            link: buildPath('AdminPluralPascalCaseModelName'),
            title: 'PluralPascalCaseModelName',
        },
        {
            link: buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }),
            title: camelCaseModelName.name ?? 'PascalCaseModelName',
        },
    ]

    if (! isShowView) {
        breadcrumbs.push({
            link: buildPath('AdminEditPascalCaseModelName', { id: camelCaseModelName.id }),
            title: t('Edit'),
        })
    }

    return (
        <div>
            <Breadcrumbs pages={breadcrumbs} />

            <div className="fe-container-wide">
                <div className="fe-split">

                    <div>
                        <ModelCard camelCaseModelName={camelCaseModelName} />
                    </div>

                    <div>
                        {isShowView
                            ? <ModelProfile camelCaseModelName={camelCaseModelName} />
                            : <Outlet context={{ onUpdateData: load }} />}
                    </div>

                </div>
            </div>
        </div>
    )
}
