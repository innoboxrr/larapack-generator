import { useEffect, useState } from 'react'
import t from 'innoboxrr-i18n'

import { showModel } from '../index'

export default function ModelProfile({ camelCaseModelName = null, camelCaseModelNameId = null }) {
    // La version anterior de Vue asignaba el resultado sobre el prop, lo que
    // Vue prohibe y React ni siquiera permite: el dato cargado vive aparte.
    const [fetched, setFetched] = useState(null)

    const record = camelCaseModelName ?? fetched

    useEffect(() => {
        if (camelCaseModelName || ! camelCaseModelNameId) {
            return
        }

        let alive = true

        showModel(camelCaseModelNameId).then((loaded) => {
            if (alive) {
                setFetched(loaded)
            }
        })

        return () => {
            alive = false
        }
    }, [camelCaseModelName, camelCaseModelNameId])

    if (! record) {
        return null
    }

    return (
        <div className="space-y-6">

            <div className="border rounded-xl shadow-sm p-6 bg-white dark:bg-gray-800 dark:border-gray-700">

                <div className="flex justify-between items-center border-b pb-4 mb-6 dark:border-gray-600">

                    <div>
                        <h2 className="text-2xl font-semibold text-gray-900 dark:text-white">
                            {t('Details')}
                        </h2>
                        <p className="text-sm text-gray-500 dark:text-gray-400">
                            {t('Additional information')}
                        </p>
                    </div>

                    <span className="text-xs px-3 py-1 rounded-full bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300">
                        ID: {record.id}
                    </span>

                </div>

                <dl className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-6">
                    <div>
                        <dt className="text-sm font-medium text-gray-500 dark:text-gray-400">
                            {t('Created at')}
                        </dt>
                        <dd className="mt-1 text-sm text-gray-900 dark:text-white">
                            {record.created_at}
                        </dd>
                    </div>
                    {/* Agrega mas campos aqui segun sea necesario */}
                </dl>

            </div>

        </div>
    )
}
