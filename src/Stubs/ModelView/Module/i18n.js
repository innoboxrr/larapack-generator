/**
 * Los textos del modulo packageName.
 *
 * El modulo trae sus traducciones y la aplicacion las carga una vez, antes que
 * las suyas para poder corregirlas:
 *
 *     import { addTranslations, setLocale } from 'innoboxrr-i18n'
 *     import { translations } from 'packageName'
 *
 *     addTranslations(translations)
 *     addTranslations(import.meta.glob('/resources/locales/*.json', { eager: true }))
 *     setLocale(document.documentElement.lang)
 *
 * LaraPack mantiene src/locales al generar: suma las claves nuevas y no toca
 * las que ya tienen traduccion. Las del dominio (el nombre del modelo, sus
 * campos) llegan vacias a es.json, y mientras lo esten se ve la clave.
 */

import t from 'innoboxrr-i18n'

import en from './locales/en.json'
import es from './locales/es.json'

export const translations = { en, es }

/**
 * Los textos de la tabla. Los datatables los traen en espanol fijo; pasandolos
 * desde aqui siguen el idioma de la aplicacion, como el resto de la pantalla.
 */
export const tableLabels = () => ({
    actions: t('Actions'),
    rowActions: t('Record actions'),
    refresh: t('Refresh'),
    filters: t('Filters'),
    selectAll: t('Select all on this page'),
    selectRow: t('Select record'),
    selection: t('Selection'),
    selected: t('selected'),
    clearSelection: t('Clear selection'),
    empty: t('No results'),
    retry: t('Retry'),
    of: t('of'),
    page: t('Page'),
    previous: t('Previous page'),
    next: t('Next page'),
    forbidden: t('You are not allowed to see these records.'),
    offline: t('Could not connect to the server.'),
    failed: t('The records could not be loaded.'),
    policiesFailed: t('The permissions could not be checked.'),
    notAllowed: t('You are not allowed to do this.'),
    actionFailed: t('The action could not be completed.'),
})
