import { useCallback, useEffect } from 'react'
import { Outlet, useMatches, useNavigate, useParams } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import { SkeletonComponent } from 'innoboxrr-react-form-elements'
// @larapack:if update
import { DrawerComponent } from 'innoboxrr-react-form-elements'
import { notifySuccess } from 'innoboxrr-form-core'
import t from 'innoboxrr-i18n'
// @larapack:endif

import Breadcrumbs from '../../../components/Breadcrumbs.jsx'
import ModelCard from '../widgets/ModelCard.jsx'
import ModelProfile from '../widgets/ModelProfile.jsx'
import { usePascalCaseModelNameStore } from '../store'

export default function ShowView() {
    const { id } = useParams()
    const matches = useMatches()
    const navigate = useNavigate()

    const current = usePascalCaseModelNameStore((state) => state.current)
    const fetchOne = usePascalCaseModelNameStore((state) => state.fetchOne)

    // Solo el registro de la ruta: al pasar de uno a otro, el anterior no se
    // queda en pantalla mientras llega el nuevo.
    const camelCaseModelName = String(current?.id) === String(id) ? current : null

    const isEditing = matches.at(-1)?.id === 'AdminEditPascalCaseModelName'

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

    // @larapack:if update
    const backToRecord = () => navigate(buildPath('AdminShowPascalCaseModelName', { id }))

    const onUpdated = () => {
        notifySuccess(t('Changes saved'))

        backToRecord()

        load()
    }

    // @larapack:endif
    const breadcrumbs = [
        {
            link: buildPath('AdminPluralPascalCaseModelName'),
            title: 'PluralPascalCaseModelName',
        },
    ]

    if (camelCaseModelName) {
        breadcrumbs.push({
            link: buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }),
            title: camelCaseModelName.name ?? 'PascalCaseModelName',
        })
    }

    // @larapack:if update
    if (camelCaseModelName && isEditing) {
        breadcrumbs.push({
            link: buildPath('AdminEditPascalCaseModelName', { id: camelCaseModelName.id }),
            title: t('Edit'),
        })
    }

    // @larapack:endif
    return (
        <div>
            <Breadcrumbs pages={breadcrumbs} />

            <div className="fe-container-wide">
                {camelCaseModelName ? (
                    <div className="fe-split">
                        <div>
                            <ModelCard camelCaseModelName={camelCaseModelName} />
                        </div>

                        <div>
                            <ModelProfile camelCaseModelName={camelCaseModelName} />
                        </div>
                    </div>
                ) : (
                    // Mientras llega el registro, su forma. Antes la vista se
                    // quedaba en blanco, o enseñaba el registro anterior.
                    <div className="fe-split" aria-busy="true">
                        <SkeletonComponent shape="block" height="16rem" />
                        <SkeletonComponent lines={6} />
                    </div>
                )}
            </div>

            {/* @larapack:if update */}
            {/* La edición se abre encima de la ficha, que sigue a la vista. */}
            <DrawerComponent
                open={isEditing}
                title={t('Edit')}
                onOpenChange={(open) => open || backToRecord()}>
                <Outlet context={{ onUpdateData: onUpdated }} />
            </DrawerComponent>
            {/* @larapack:endif */}
        </div>
    )
}
