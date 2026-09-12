import { useMemo } from 'react'
// @larapack:if delete
import { useNavigate } from 'react-router-dom'
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
        },
        // @larapack:if update
        {
            type: 'router',
            to: buildPath('AdminEditPascalCaseModelName', { id: camelCaseModelName.id }),
            label: t('Edit'),
        },
        // @larapack:endif
        // @larapack:if delete
        {
            type: 'event',
            action: async () => {
                await remove(camelCaseModelName.id)

                navigate(buildPath('AdminPluralPascalCaseModelName'))
            },
            label: t('Delete'),
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
        <div className="bg-white dark:bg-gray-800 border rounded-lg overflow-hidden dark:border-gray-700">

            <div className="flex justify-end p-4">
                <ActionMenu items={actions} />
            </div>

            <div className="flex flex-col items-center px-6 pb-8">

                <div className="w-28 h-28 mb-4 rounded-full shadow-md flex items-center justify-center bg-gray-200 dark:bg-gray-600 text-gray-400 text-2xl">
                    <IconComponent name="box" size={32} />
                </div>

                <h5 className="text-lg font-semibold text-gray-900 dark:text-white text-center">
                    {camelCaseModelName?.name ?? 'PascalCaseModelName'}
                </h5>

            </div>

        </div>
    )
}
