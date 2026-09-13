import { useMemo } from 'react'
// @larapack:if delete
import { useNavigate } from 'react-router-dom'
import { notifyError, notifySuccess } from 'innoboxrr-form-core'
// @larapack:endif
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

import { IconComponent } from 'innoboxrr-react-form-elements'

import ActionMenu from '../../../components/ActionMenu.jsx'
// @larapack:if delete
import { usePascalCaseModelNameStore } from '../store'
// @larapack:endif

export default function ModelCard({ camelCaseModelName }) {
    // @larapack:if delete
    const navigate = useNavigate()
    const remove = usePascalCaseModelNameStore((state) => state.remove)
    // @larapack:endif

    const actions = useMemo(() => [
        {
            type: 'router',
            to: buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }),
            label: t('Show'),
            icon: 'show',
        },
        // @larapack:if update
        {
            type: 'router',
            to: buildPath('AdminEditPascalCaseModelName', { id: camelCaseModelName.id }),
            label: t('Edit'),
            icon: 'edit',
        },
        // @larapack:endif
        // @larapack:if delete
        {
            type: 'event',
            action: async () => {
                try {
                    await remove(camelCaseModelName.id)
                } catch (error) {
                    // Cancelar la confirmación no es un error que haya que
                    // contar.
                    if (error?.name !== 'RequestCancelledError') {
                        notifyError(error?.response?.data?.message ?? t('The item could not be deleted'))
                    }

                    return
                }

                notifySuccess(t('Record deleted'))

                navigate(buildPath('AdminPluralPascalCaseModelName'))
            },
            label: t('Delete'),
            icon: 'delete',
            danger: true,
        },
        // @larapack:endif
    // @larapack:if delete
    ], [camelCaseModelName.id, remove, navigate])
    // @larapack:endif
    // @larapack:if !delete
    ], [camelCaseModelName.id])
    // @larapack:endif

    return (
        <section className="fe-card">

            <div className="fe-flex fe-justify-end fe-p-sm">
                <ActionMenu items={actions} label={t('Actions')} />
            </div>

            <div className="fe-card-body fe-text-center">

                <IconComponent name="box" size={48} />

                <h2 className="fe-mt-sm">
                    {camelCaseModelName?.displayPropName ?? t('SingularModelLabel')}
                </h2>

            </div>

        </section>
    )
}
