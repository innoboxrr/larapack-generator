import { useMemo } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

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
        },
    ], [camelCaseModelName.id, remove, navigate])

    return (
        <div className="bg-white dark:bg-gray-800 border rounded-lg overflow-hidden dark:border-gray-700">

            <div className="flex justify-end p-4 gap-2">
                {actions.map((action) => (
                    action.type === 'router'
                        ? <Link key={action.label} to={action.to} className="text-sm text-blue-600">{action.label}</Link>
                        : <button key={action.label} type="button" className="text-sm text-red-600" onClick={action.action}>{action.label}</button>
                ))}
            </div>

            <div className="flex flex-col items-center px-6 pb-8">

                <div className="w-28 h-28 mb-4 rounded-full shadow-md flex items-center justify-center bg-gray-200 dark:bg-gray-600 text-gray-400 text-2xl">
                    <i className="fas fa-box"></i>
                </div>

                <h5 className="text-lg font-semibold text-gray-900 dark:text-white text-center">
                    {camelCaseModelName?.name ?? 'PascalCaseModelName'}
                </h5>

            </div>

        </div>
    )
}
