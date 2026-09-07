import { useState } from 'react'
import t from 'innoboxrr-i18n'
import {
    TextInputComponent,
//import_more_components//
} from 'innoboxrr-react-form-elements'

import { buttonClass, inputClass } from '../../../theme'

const initialState = () => ({
    id: null,
//form_fields//
})

export default function FilterForm({ formId = 'camelCaseModelNameFilterForm', onSubmit }) {
    const [form, setForm] = useState(initialState)

    const setField = (field, value) => setForm((current) => ({ ...current, [field]: value }))

    // El datatable espera recibir el objeto de filtros completo, no solo los
    // que tienen valor: un campo vaciado tiene que limpiar su filtro.
    const submit = (filters) => onSubmit?.({ ...filters })

    return (
        <form
            id={formId}
            onSubmit={(event) => {
                event.preventDefault()
                submit(form)
            }}>

            <div className="uk-flex uk-flex-left uk-child-width-1-4@m uk-child-width-1-1@s" uk-grid="">

                <div>
                    <TextInputComponent
                        customClass={inputClass}
                        type="text"
                        name="id"
                        label="ID"
                        placeholder="ID"
                        value={form.id ?? ''}
                        onChange={(value) => setField('id', value)} />
                </div>

{/* Add more inputs */}

            </div>

            <div className="uk-flex uk-flex-right uk-child-width-auto@m uk-child-width-1-1@m" uk-grid="">
                <div>
                    <button type="submit" className={buttonClass}>
                        {t('Search')}
                    </button>
                </div>
                <div>
                    <button
                        type="button"
                        className={`${buttonClass} bg-gray-400`}
                        onClick={() => {
                            const reset = initialState()

                            setForm(reset)
                            submit(reset)
                        }}>
                        {t('Reset')}
                    </button>
                </div>
            </div>

        </form>
    )
}
