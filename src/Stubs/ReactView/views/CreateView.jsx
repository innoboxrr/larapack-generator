import { useEffect } from 'react'
import { useNavigate, useOutletContext } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'
import t from 'innoboxrr-i18n'

import CreateForm from '../forms/CreateForm.jsx'
import { getPolicy } from '../index'

export default function CreateView() {
    const navigate = useNavigate()
    const { onUpdateData } = useOutletContext() ?? {}

    useEffect(() => {
        getPolicy('create').then((policy) => {
            if (! policy.create) {
                // navigate('/not-authorized')
            }
        })
    }, [])

    const onCreated = (camelCaseModelName) => {
        onUpdateData?.(camelCaseModelName)

        navigate(buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }))
    }

    return (
        <div>
            <div className="flex justify-center items-center mt-8">
                <div className="max-w-2xl w-full">
                    <div className="card bg-white dark:bg-slate-600 border rounded-lg px-8 pt-6 pb-8 mb-4 dark:border-slate-800">

                        <h2 className="text-4xl font-bold dark:text-white mb-6">
                            {t('Create PluralPascalCaseModelName')}
                        </h2>

                        <CreateForm onSubmit={onCreated} />

                    </div>
                </div>
            </div>
        </div>
    )
}
