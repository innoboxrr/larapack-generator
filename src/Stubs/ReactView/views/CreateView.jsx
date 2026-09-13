import { useEffect } from 'react'
import { useOutletContext } from 'react-router-dom'

import CreateForm from '../forms/CreateForm.jsx'
import { getPolicy } from '../index'

/**
 * El alta de PascalCaseModelName.
 *
 * Vive dentro del drawer que la vista del índice abre sobre la tabla, así que
 * no pinta migas ni título —los pone el drawer— ni decide a dónde ir después:
 * avisa con `onUpdateData`, y el índice cierra el drawer, recarga la tabla y se
 * lo dice al usuario. La ruta conserva su id, así que un enlace a
 * AdminCreatePascalCaseModelName sigue abriendo el alta.
 */
export default function CreateView() {
    const { onUpdateData } = useOutletContext() ?? {}

    useEffect(() => {
        getPolicy('create').then((policy) => {
            if (! policy.create) {
                // navigate('/not-authorized')
            }
        })
    }, [])

    return <CreateForm onSubmit={(camelCaseModelName) => onUpdateData?.(camelCaseModelName)} />
}
