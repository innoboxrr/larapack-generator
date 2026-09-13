import { useEffect } from 'react'
import { useOutletContext, useParams } from 'react-router-dom'

import EditForm from '../forms/EditForm.jsx'
import { getPolicy } from '../index'

/**
 * La edición de PascalCaseModelName.
 *
 * Vive dentro del drawer que el detalle abre sobre la ficha. No decide a dónde
 * ir después: avisa con `onUpdateData`, y el detalle cierra el drawer, recarga
 * el registro y se lo dice al usuario.
 */
export default function EditView() {
    const { id } = useParams()
    const { onUpdateData } = useOutletContext() ?? {}

    useEffect(() => {
        getPolicy('update', id).then((policy) => {
            if (! policy.update) {
                // navigate('/not-authorized')
            }
        })
    }, [id])

    // La clave fuerza a remontar el formulario al cambiar de registro: si no,
    // conservaría el estado del anterior.
    return (
        <EditForm
            key={id}
            camelCaseModelNameId={id}
            onSubmit={(camelCaseModelName) => onUpdateData?.(camelCaseModelName)} />
    )
}
