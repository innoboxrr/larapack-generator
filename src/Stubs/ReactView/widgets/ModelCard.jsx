import { useMemo } from 'react'
import { useNavigate } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

import { IconComponent } from 'innoboxrr-react-form-elements'

import ActionMenu from '../../../components/ActionMenu.jsx'
import { usePascalCaseModelNameStore } from '../store'

export default function ModelCard({ camelCaseModelName }) {
    const navigate = useNavigate()
    const remove = usePascalCaseModelNameStore((state) => state.remove)

    const actions = useMemo(() => [
        {
            type: 'router',
            to: buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }),
            label: t('Show'),
        },
        {
            type: 'router',
            to: buildPath('AdminEditPascalCaseModelName', { id: camelCaseModelName.id }),
            label: t('Edit'),
        },
        {
            type: 'event',
            action: async () => {
                await remove(camelCaseModelName.id)

                navigate(buildPath('AdminPluralPascalCaseModelName'))
            },
            label: t('Delete'),
            danger: true,
        },
    ], [camelCaseModelName.id, remove, navigate])

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
