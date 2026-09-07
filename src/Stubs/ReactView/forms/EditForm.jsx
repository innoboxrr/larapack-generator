import { useEffect, useRef, useState } from 'react'
import JSValidator from 'innoboxrr-js-validator'
import t from 'innoboxrr-i18n'
import {
    ButtonComponent,
    TextInputComponent,
//import_more_components//
} from 'innoboxrr-react-form-elements'

import { usePascalCaseModelNameStore } from '../store'

export default function EditForm({
    formId = 'editPascalCaseModelNameForm',
    camelCaseModelNameId,
//props//
    onSubmit,
}) {
    const fetchOne = usePascalCaseModelNameStore((state) => state.fetchOne)
    const update = usePascalCaseModelNameStore((state) => state.update)

    const [disabled, setDisabled] = useState(false)
    const validator = useRef(null)

    const [form, setForm] = useState({
//form_fields//
    })

    const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }))

    useEffect(() => {
        let alive = true

        const load = async () => {
            const camelCaseModelName = await fetchOne(camelCaseModelNameId)

            if (! alive) {
                return
            }

            // Solo se rellenan las claves que el formulario declara; asi un
            // campo nuevo en la API no se cuela en el payload de
            // actualizacion.
            setForm((current) => Object.fromEntries(
                Object.keys(current).map((field) => [
                    field,
                    camelCaseModelName[field] !== undefined ? camelCaseModelName[field] : current[field],
                ])
            ))

            validator.current = new JSValidator(formId).init()
        }

        load()

        return () => {
            alive = false
            validator.current?.destroy()
        }
    }, [camelCaseModelNameId, fetchOne, formId])

    const handleSubmit = async (event) => {
        event.preventDefault()

        if (! validator.current?.validate()) {
            return
        }

        setDisabled(true)

        try {

            onSubmit?.(await update(camelCaseModelNameId, {
//submit_data//
            }))

        } catch (error) {

            if (error.response?.status === 422) {
                validator.current.appendExternalErrors(error.response.data.errors)
            }

        } finally {

            setDisabled(false)

        }
    }

    return (
        <form id={formId} onSubmit={handleSubmit}>

{/* Add more inputs */}

            <ButtonComponent
                disabled={disabled}
                value={t('Update')} />

        </form>
    )
}
