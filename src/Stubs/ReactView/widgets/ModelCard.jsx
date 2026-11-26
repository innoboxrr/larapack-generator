import { useMemo } from 'react'
// @larapack:if record_actions
import { useEffect, useState } from 'react'
// @larapack:endif
// @larapack:if delete|record_actions
import { useNavigate } from 'react-router-dom'
import { notifyError, notifySuccess } from 'innoboxrr-form-core'
// @larapack:endif
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

import { IconComponent } from 'innoboxrr-react-form-elements'

import ActionMenu from '../../../components/ActionMenu.jsx'
// @larapack:if delete|record_actions
import { usePascalCaseModelNameStore } from '../store'
// @larapack:endif
// @larapack:if record_actions
import * as model from '../index'
// @larapack:endif

export default function ModelCard({ camelCaseModelName }) {
    // @larapack:if delete|record_actions
    const navigate = useNavigate()
    // @larapack:endif
    // @larapack:if delete
    const remove = usePascalCaseModelNameStore((state) => state.remove)
    // @larapack:endif
    // @larapack:if record_actions
    const fetchOne = usePascalCaseModelNameStore((state) => state.fetchOne)

    // Los endpoints declarados sobre el registro (recordActions() del
    // contrato). Se pregunta a la política por este registro, como hace la
    // tabla con cada fila: lo que no se puede hacer se ve deshabilitado y dice
    // por qué.
    const [allowed, setAllowed] = useState({})

    useEffect(() => {
        let active = true

        model.getPolicies(camelCaseModelName.id)
            .then((policies) => active && setAllowed(policies ?? {}))
            .catch(() => active && setAllowed({}))

        return () => {
            active = false
        }
    }, [camelCaseModelName.id])

    const runEndpoint = async (action) => {
        const id = camelCaseModelName.id

        try {
            // La función del contrato pregunta antes de llamar.
            await model[action.callback]({ id })
        } catch (error) {
            if (error?.name !== 'RequestCancelledError') {
                notifyError(error?.response?.data?.message ?? t('The action could not be completed.'))
            }

            return
        }

        notifySuccess(action.success)

        // El endpoint puede haber cambiado el registro, o haberlo quitado.
        try {
            await fetchOne(id)
        } catch {
            navigate(buildPath('AdminPluralPascalCaseModelName'))
        }
    }

    const endpointItems = model.recordActions().map((action) => ({
        type: 'event',
        id: action.id,
        action: () => runEndpoint(action),
        label: action.name,
        icon: action.icon,
        danger: action.danger === true,
        disabled: allowed[action.id] !== true,
        disabledReason: t('You are not allowed to do this.'),
    }))
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
                {/* @larapack:if !record_actions */}
                <ActionMenu items={actions} label={t('Actions')} />
                {/* @larapack:endif */}
                {/* @larapack:if record_actions */}
                <ActionMenu items={[...actions, ...endpointItems]} label={t('Actions')} />
                {/* @larapack:endif */}
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
