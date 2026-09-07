import { useState } from 'react'
import t from 'innoboxrr-i18n'
import {
    ButtonComponent,
    TextInputComponent,
//import_more_components//
} from 'innoboxrr-react-form-elements'

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

            <div className="fe-filter-grid">

                <div>
                    <TextInputComponent
                        type="text"
                        name="id"
                        label="ID"
                        placeholder="ID"
                        value={form.id ?? ''}
                        onChange={(value) => setField('id', value)} />
                </div>

{/* Add more inputs */}

            </div>

            <div className="fe-filter-actions">
                <div>
                    <ButtonComponent value={t('Search')} />
                </div>
                <div>
                    <ButtonComponent
                        type="button"
                        variant="secondary"
                        value={t('Reset')}
                        onClick={() => {
                            const reset = initialState()

                            setForm(reset)
                            submit(reset)
                        }} />
                </div>
            </div>

        </form>
    )
}
