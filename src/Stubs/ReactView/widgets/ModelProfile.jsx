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
        <section className="fe-card">

            <header className="fe-toolbar">

                <div>
                    <h2>{t('Details')}</h2>
                    <p className="fe-text-muted fe-text-sm">{t('Additional information')}</p>
                </div>

                <span className="fe-toolbar-spacer" />

                <span className="fe-badge">{t('ID')}: {record.id}</span>

            </header>

            <dl className="fe-card-body">
                <div>
                    <dt className="fe-text-muted fe-text-sm">{t('Created at')}</dt>
                    <dd>{record.created_at}</dd>
                </div>
                {/* Agrega mas campos aqui segun sea necesario */}
            </dl>

        </section>
    )
}
