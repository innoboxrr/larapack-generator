import { useEffect } from 'react'
import { useNavigate, useOutletContext, useParams } from 'react-router-dom'
import { buildPath } from 'innoboxrr-react-datatable'

import EditForm from '../forms/EditForm.jsx'
import { getPolicy } from '../index'

export default function EditView() {
    const { id } = useParams()
    const navigate = useNavigate()
    const { onUpdateData } = useOutletContext() ?? {}

    useEffect(() => {
        getPolicy('update', id).then((policy) => {
            if (! policy.update) {
                // navigate('/not-authorized')
            }
        })
    }, [id])

    const onUpdated = (camelCaseModelName) => {
        onUpdateData?.(camelCaseModelName)

        navigate(buildPath('AdminShowPascalCaseModelName', { id: camelCaseModelName.id }))
    }

    return (
        <div className="flex justify-center items-center">
            <div className="max-w-2xl w-full">
                <div className="card bg-white dark:bg-slate-600 border rounded-lg px-8 pt-6 pb-8 mb-4 dark:border-slate-800">

                    {/* La clave fuerza a remontar el formulario al cambiar de
                        registro: si no, conservaria el estado del anterior. */}
                    <EditForm key={id} camelCaseModelNameId={id} onSubmit={onUpdated} />

                </div>
            </div>
        </div>
    )
}
