import { useEffect, useRef, useState } from 'react'
import JSValidator from 'innoboxrr-js-validator'
import t from 'innoboxrr-i18n'
import {
    ButtonComponent,
    TextInputComponent,
//import_more_components//
} from 'innoboxrr-react-form-elements'

import { buttonClass, inputClass } from '../../../theme'
import { usePascalCaseModelNameStore } from '../store'

export default function CreateForm({
    formId = 'createPascalCaseModelNameForm',
//props//
    onSubmit,
}) {
    const create = usePascalCaseModelNameStore((state) => state.create)

    const [disabled, setDisabled] = useState(false)
    const validator = useRef(null)

    const [form, setForm] = useState({
//form_fields//
    })

    const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }))

    useEffect(() => {
        validator.current = new JSValidator(formId).init()
    }, [formId])

    const handleSubmit = async (event) => {
        event.preventDefault()

        if (! validator.current?.status) {
            return
        }

        setDisabled(true)

        try {

            onSubmit?.(await create({
//submit_data//
            }))

        } catch (error) {

            // 422 son los errores de validacion del FormRequest de Laravel:
            // se pintan sobre el mismo formulario en lugar de descartarse.
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
                customClass={buttonClass}
                disabled={disabled}
                value={t('Create')} />

        </form>
    )
}
