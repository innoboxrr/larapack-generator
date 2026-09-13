import { useRef, useState } from 'react'
import { Outlet, useMatches, useNavigate } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'
import { CommandPaletteComponent } from 'innoboxrr-react-form-elements'
// @larapack:if create
import { DrawerComponent } from 'innoboxrr-react-form-elements'
import { notifySuccess } from 'innoboxrr-form-core'
// @larapack:endif

import DataTable from '../widgets/DataTable.jsx'
import Breadcrumbs from '../../../components/Breadcrumbs.jsx'

export default function AdminView() {
    const matches = useMatches()
    const navigate = useNavigate()

    // La tabla se recarga en su sitio. Antes se la remontaba cambiándole la
    // key, y eso la devolvía a la primera página y sin filtros.
    const table = useRef(null)

    const [paletteOpen, setPaletteOpen] = useState(false)

    const leaf = matches.at(-1)?.id

    const isRecord = ['AdminShowPascalCaseModelName', 'AdminEditPascalCaseModelName'].includes(leaf)
    // @larapack:if create
    const isCreating = leaf === 'AdminCreatePascalCaseModelName'
    // @larapack:endif

    const backToIndex = () => navigate(buildPath('AdminPluralPascalCaseModelName'))

    // @larapack:if create
    const onCreated = () => {
        notifySuccess(t('Record created'))

        backToIndex()

        table.current?.refresh()
    }

    // @larapack:endif
    // Ctrl+K o Cmd+K, desde cualquier sitio del índice.
    const commands = [
        // @larapack:if create
        {
            id: 'create',
            label: t('Create :name', { name: t('SingularModelLabel') }),
            group: t('PluralModelLabel'),
            icon: 'plus',
            action: () => navigate(buildPath('AdminCreatePascalCaseModelName')),
        },
        // @larapack:endif
        {
            id: 'refresh',
            label: t('Refresh'),
            group: t('PluralModelLabel'),
            icon: 'refresh',
            action: () => table.current?.refresh(),
        },
    ]

    const breadcrumbs = [
        {
            link: buildPath('AdminPluralPascalCaseModelName'),
            title: t('PluralModelLabel'),
        },
    ]

    return (
        <div id="AdminPluralPascalCaseModelNameWrapper">

            {/* El detalle de un registro, y su edición, son una página propia. */}
            {isRecord ? <Outlet /> : (
                <div className="fe-section-sm">
                    <Breadcrumbs pages={breadcrumbs} />

                    <DataTable ref={table} hideColumns={[]} />

                    {/* @larapack:if create */}
                    {/* El alta se abre encima de la tabla, que sigue ahí detrás
                        con su página, su orden y sus filtros. */}
                    <DrawerComponent
                        open={isCreating}
                        title={t('Create :name', { name: t('SingularModelLabel') })}
                        closeLabel={t('Close')}
                        onOpenChange={(open) => open || backToIndex()}>
                        <Outlet context={{ onUpdateData: onCreated }} />
                    </DrawerComponent>
                    {/* @larapack:endif */}

                    <CommandPaletteComponent
                        open={paletteOpen}
                        items={commands}
                        placeholder={t('Search a command')}
                        emptyText={t('No results')}
                        label={t('Command palette')}
                        onOpenChange={setPaletteOpen} />
                </div>
            )}

        </div>
    )
}
